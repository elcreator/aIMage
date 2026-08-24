<?php

namespace EvolutionCMS\aIMage\Agent;

use EvolutionCMS\aIMage\Gateway\Client;
use EvolutionCMS\aIMage\Gateway\Dialect;
use EvolutionCMS\aIMage\Gateway\Estimator;
use EvolutionCMS\aIMage\Gateway\GatewayException;
use EvolutionCMS\aIMage\Gateway\ModelCatalog;
use EvolutionCMS\aIMage\Models\Job;
use EvolutionCMS\aIMage\Models\Message;
use EvolutionCMS\aIMage\Support\Config;
use EvolutionCMS\aIMage\Support\ImageScope;

/**
 * Turns a conversation into a batch, one turn at a time.
 *
 * A turn is deliberately the unit of work rather than "plan the whole thing":
 * the worker runs turns in slices, so a planner that wants to look at a folder,
 * think, then queue two hundred steps does it across several minutes without
 * holding anything open, and a planner that goes in circles hits
 * `max_planner_turns` instead of running until the budget notices.
 *
 * The turn ends in exactly one of four ways, and each is a job status:
 *
 *   the model asked something      → awaiting_input
 *   the model declared it finished → awaiting_approval, or running if cheap
 *   the model called planning tools → planning (another turn follows)
 *   the model just talked           → planning, with a nudge appended
 *
 * That last case matters more than it looks. A model that answers a request
 * with prose instead of a tool call has produced nothing executable, and this
 * is an image plugin — the terminal result is always changed files, never a
 * conversation. So prose alone is treated as an incomplete turn and the model
 * is told so, rather than being allowed to end the job with a nice paragraph.
 */
class Planner
{
    public function __construct(
        private readonly Client $client,
        private readonly ModelCatalog $catalog,
        private readonly Estimator $estimator
    ) {
    }

    /**
     * Run one planner turn against a job.
     *
     * @return array{status: string, message: string}
     */
    public function advance(Job $job, ImageScope $scope): array
    {
        $maxTurns = (int) Config::limit('max_planner_turns', 12);

        if ((int) $job->planner_turns >= $maxTurns) {
            return $this->stop(
                $job,
                Job::STATUS_AWAITING_INPUT,
                'PLANNER_EXHAUSTED',
                'The assistant used all ' . $maxTurns . ' planning turns without finishing. '
                . 'Tell it more precisely what you want, or approve what it has queued so far.'
            );
        }

        $tools = new Tools($job, $scope, $this->catalog, $this->estimator);
        $transcript = Message::transcriptFor((int) $job->getKey());

        if ($transcript === []) {
            return $this->stop($job, Job::STATUS_AWAITING_INPUT, 'NO_INSTRUCTION', 'Describe what you want done.');
        }

        try {
            $raw = $this->client->converse([
                'model' => (string) $job->text_model,
                'system' => $this->systemPrompt($job, $scope),
                'messages' => $transcript,
                'tools' => $tools->definitions(),
                'max_tokens' => 4096,
            ]);
        } catch (GatewayException $e) {
            // A transient failure leaves the job planning so the next slice
            // retries; anything else is the manager's to resolve.
            if ($e->retryable) {
                return ['status' => Job::STATUS_PLANNING, 'message' => 'The gateway is busy; will retry.'];
            }

            return $this->stop(
                $job,
                Job::STATUS_FAILED,
                $e->isAuthFailure() ? 'KEY_REJECTED' : 'PLANNER_FAILED',
                $e->getMessage()
            );
        }

        $reply = Dialect::decodeReply($raw);

        $job->forceFill(['planner_turns' => (int) $job->planner_turns + 1])->save();

        Message::record((int) $job->getKey(), Message::ROLE_ASSISTANT, [
            'text' => $reply['text'],
            'tool_calls_json' => $reply['tool_calls'],
        ]);

        if ($reply['tool_calls'] === []) {
            return $this->handleProseOnlyTurn($job, $reply['text']);
        }

        return $this->runToolCalls($job, $tools, $reply['tool_calls']);
    }

    /**
     * Execute the turn's tool calls and decide what happens next.
     */
    private function runToolCalls(Job $job, Tools $tools, array $calls): array
    {
        $results = [];
        $control = null;
        $controlPayload = [];

        foreach ($calls as $call) {
            $outcome = $tools->dispatch((string) $call['name'], (array) $call['input']);

            $results[] = [
                'id' => (string) $call['id'],
                'content' => (string) $outcome['content'],
            ];

            // The first control tool wins. A model that asks a question and
            // declares itself finished in the same turn has contradicted
            // itself, and stopping to ask is the safe reading.
            if ($control === null && isset($outcome['control'])) {
                $control = $outcome['control'];
                $controlPayload = $outcome;
            }
        }

        // Every result is recorded, including those from the calls after a
        // control tool: the transcript has to match what the model was told,
        // or the next turn replays a conversation that never happened.
        Message::record((int) $job->getKey(), Message::ROLE_TOOL, [
            'tool_results_json' => $results,
        ]);

        if ($control === Tools::ASK_USER) {
            return $this->stop(
                $job,
                Job::STATUS_AWAITING_INPUT,
                '',
                (string) ($controlPayload['question'] ?? 'The assistant has a question.')
            );
        }

        if ($control === Tools::FINISH) {
            return $this->completePlan($job, $tools, (string) ($controlPayload['summary'] ?? ''));
        }

        return ['status' => Job::STATUS_PLANNING, 'message' => 'Planning.'];
    }

    /**
     * The plan is complete: price it, and decide whether it needs a signature.
     */
    private function completePlan(Job $job, Tools $tools, string $summary): array
    {
        $estimate = $tools->estimatePlan();
        $threshold = (float) Config::limit('approval_threshold_eur', 5.0);

        $job->forceFill([
            'title' => mb_substr($summary !== '' ? $summary : (string) $job->title, 0, 191),
            'estimate_json' => $estimate->toArray(),
        ])->save();

        $job->refreshCounters();

        // An unpriceable plan always needs a human. `amount` is null when a
        // model has no current price, and "unknown" must never be spent
        // silently just because it is not a large number.
        $needsApproval = $estimate->amount === null || $estimate->amount > $threshold;

        if ($needsApproval) {
            return $this->stop(
                $job,
                Job::STATUS_AWAITING_APPROVAL,
                '',
                $summary !== '' ? $summary : 'The plan is ready for your approval.'
            );
        }

        $job->forceFill(['approved_at' => now()])->save();

        return ['status' => Job::STATUS_RUNNING, 'message' => $summary !== '' ? $summary : 'Running.'];
    }

    /**
     * The model answered in prose and called nothing.
     *
     * Once — nudge it, because models sometimes narrate a plan before making
     * it. Twice in a row — stop and show the manager what it said, because at
     * that point it is not going to act and looping costs money.
     */
    private function handleProseOnlyTurn(Job $job, string $text): array
    {
        $recent = Message::query()
            ->where('job_id', $job->getKey())
            ->where('role', Message::ROLE_ASSISTANT)
            ->orderByDesc('seq')
            ->limit(2)
            ->get();

        $consecutiveProse = $recent->every(
            static fn (Message $message) => empty($message->tool_calls_json)
        ) && $recent->count() === 2;

        if ($consecutiveProse) {
            return $this->stop(
                $job,
                Job::STATUS_AWAITING_INPUT,
                '',
                $text !== '' ? $text : 'The assistant did not propose any image work.'
            );
        }

        Message::record((int) $job->getKey(), Message::ROLE_USER, [
            'text' => 'Do not reply with prose. This plugin only produces images. Either call one of the planning '
                . 'tools to queue the work, or call ask_user with a specific question.',
        ]);

        return ['status' => Job::STATUS_PLANNING, 'message' => 'Planning.'];
    }

    private function stop(Job $job, string $status, string $errorCode, string $message): array
    {
        $job->forceFill([
            'status' => $status,
            'error_code' => mb_substr($errorCode, 0, 64),
            'message' => mb_substr($message, 0, 255),
            'finished_at' => in_array($status, Job::TERMINAL, true) ? now() : null,
            'updated_at' => now(),
        ])->save();

        return ['status' => $status, 'message' => $message];
    }

    /**
     * What the planner is told about the world it is planning in.
     *
     * Concrete facts, not exhortations: which models are actually selected,
     * what they can do, where files may go, and how many images are allowed.
     * A planner that knows the real constraints plans inside them.
     */
    private function systemPrompt(Job $job, ImageScope $scope): string
    {
        $imageModel = (string) $job->image_model;
        $entry = $this->catalog->find($imageModel);
        $actions = implode(', ', (array) ($entry['actions'] ?? []));
        $controls = $this->catalog->controls($imageModel);

        $controlLines = [];

        foreach ($controls as $name => $values) {
            $controlLines[] = '  - ' . $name . ': ' . implode(', ', array_map('strval', (array) $values));
        }

        $outputFolder = (string) $job->output_folder ?: $scope->outputFolder();

        return implode("\n", array_filter([
            'You plan image work for a manager of an Evolution CMS website. You never produce images yourself: you '
            . 'queue steps, and a background worker carries them out afterwards, possibly hours later.',
            '',
            'The result of this job is always changed image files. Conversation and previews are intermediate; a job '
            . 'that ends without queued image work has failed at its purpose.',
            '',
            'Selected image model: ' . $imageModel . ($actions !== '' ? ' (supports: ' . $actions . ')' : ''),
            $controlLines !== [] ? "Its accepted controls:\n" . implode("\n", $controlLines) : '',
            'Upscaling always uses ' . ModelCatalog::UPSCALE_MODEL . ', whatever the selected model is.',
            '',
            'Files: paths are relative to the manager\'s own file area, and they may only see part of the site. Never '
            . 'invent a path — call ' . Tools::LIST_IMAGES . ' and use what it returns. Results are written to "'
            . $outputFolder . '" unless you name another folder. Originals are never overwritten.',
            'Allowed image extensions: ' . implode(', ', $scope->allowedExtensions()) . '.',
            'At most ' . (int) Config::limit('max_images_per_job', 200) . ' images per job.',
            '',
            'Ask before you guess, but only about things that change the images and that you cannot look up. "Upscale '
            . 'all the images" needs no question once you have listed them; "make it nicer" does.',
            'When every step is queued, call ' . Tools::FINISH . '.',
        ]));
    }
}
