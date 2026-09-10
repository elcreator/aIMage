<?php

namespace Elcreator\aIMage\Agent;

use Elcreator\aIMage\Gateway\Client;
use Elcreator\aIMage\Gateway\Dialect;
use Elcreator\aIMage\Gateway\Estimator;
use Elcreator\aIMage\Gateway\GatewayException;
use Elcreator\aIMage\Gateway\ModelCatalog;
use Elcreator\aIMage\Models\Job;
use Elcreator\aIMage\Models\Message;
use Elcreator\aIMage\Support\Config;
use Elcreator\aIMage\Support\ImageScope;

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

        $message = $summary !== '' ? $summary : 'Running.';

        // Cheap enough to run unattended — but the transition still has to be
        // written down. The returned array is a report, not an instruction:
        // nothing downstream reads its `status` and applies it, so a job left
        // `planning` here would be handed back to the planner on every later
        // slice, paying for a turn each time, while the steps it had already
        // queued never ran. This mirrors `JobController::approve()`, which is
        // the same transition taken by hand.
        $job->forceFill([
            'status' => Job::STATUS_RUNNING,
            'approved_at' => now(),
            'message' => mb_substr($message, 0, 255),
            'error_code' => '',
            'updated_at' => now(),
        ])->save();

        return ['status' => Job::STATUS_RUNNING, 'message' => $message];
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
            // Twice in a row is not going to become a tool call, so what it
            // said is shown to the manager — minus any function syntax it wrote
            // out as prose, which is ours and means nothing to them.
            $shown = Message::stripToolSyntax($text);

            return $this->stop(
                $job,
                Job::STATUS_AWAITING_INPUT,
                '',
                $shown !== '' ? $shown : 'The assistant did not propose any image work.'
            );
        }

        // The turn that earned the nudge was a misfire, not an answer — often
        // with the model's own attempt at tool-call syntax written out in it —
        // so it is machinery too. It stays in the transcript, where the model
        // needs to see what it did, and leaves the thread.
        $misfire = $recent->first();

        if ($misfire !== null) {
            $misfire->forceFill(['internal' => true])->save();
        }

        // Written as a `user` turn because that is the role the model has to
        // receive an instruction in, and flagged internal because the manager
        // did not type it and should not be shown it as though they had.
        Message::record((int) $job->getKey(), Message::ROLE_USER, [
            'internal' => true,
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
            'Files: paths are relative to the manager\'s own file area, and they may only see part of the site.',
            'Source paths must be real. Never invent one — call ' . Tools::LIST_IMAGES . ' and use what it returns.',
            'Destination folders are the opposite: they need not exist. A manager who says "put them in 123/45" '
            . 'means a folder "45" inside a folder "123", both created for them, and you pass "123/45" as the '
            . 'folder — do not ask whether it exists, do not look for the nearest existing folder instead, and do '
            . 'not fall back to the default because you could not find it. The same applies to edits, variations '
            . 'and upscales.',
            'Results go to "' . $outputFolder . '" unless the manager names a folder, and every destination is '
            . 'placed under ' . ($scope->writeRootRelative() === ''
                ? 'the manager\'s own file root'
                : '"' . $scope->writeRootRelative() . '"')
            . ' whatever you pass, so a folder outside it is corrected rather than obeyed. Originals are never '
            . 'overwritten.',
            'Allowed image extensions: ' . implode(', ', $scope->allowedExtensions()) . '.',
            'At most ' . (int) Config::limit('max_images_per_job', 200) . ' images per job.',
            '',
            'Ask before you guess, but only about things that change the images and that you cannot look up. "Upscale '
            . 'all the images" needs no question once you have listed them; "make it nicer" does.',
            'When every step is queued, call ' . Tools::FINISH . '.',
        ]));
    }
}
