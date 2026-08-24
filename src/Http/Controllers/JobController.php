<?php

namespace EvolutionCMS\aIMage\Http\Controllers;

use EvolutionCMS\aIMage\Models\Job;
use EvolutionCMS\aIMage\Models\JobStep;
use EvolutionCMS\aIMage\Models\Message;
use EvolutionCMS\aIMage\Support\Config;
use EvolutionCMS\aIMage\Support\JobQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Creating, watching, answering and stopping batches.
 *
 * Nothing here talks to a model. A job is created in `planning`, put on the
 * queue, and the worker does the thinking on its own time. That is what makes
 * the "describe it and walk away" promise true rather than aspirational: the
 * browser can close the moment this returns, and every later state change —
 * a clarifying question, an approval gate, two hundred images — happens
 * without it.
 *
 * It also means these endpoints stay fast enough to be polled.
 */
class JobController extends Controller
{
    public function index(): JsonResponse
    {
        if (!$this->authorized()) {
            return $this->denied();
        }

        $jobs = Job::query()
            ->where('user_id', $this->userId())
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        return $this->ok([
            'jobs' => $jobs->map(fn (Job $job) => $this->summarise($job))->all(),
        ]);
    }

    /**
     * Start a batch from the manager's first instruction.
     */
    public function store(Request $request): JsonResponse
    {
        if (!$this->authorized()) {
            return $this->denied();
        }

        $instruction = trim((string) $request->input('message'));

        if ($instruction === '') {
            return $this->fail('empty_instruction', __('aIMage::global.error_empty_instruction'));
        }

        $client = $this->client();

        if ($client === null) {
            return $this->fail('no_key', __('aIMage::global.error_no_key'), 409);
        }

        $catalog = $this->catalog($client);
        $scope = $this->scope();

        $textModel = $this->pickModel($request, 'text_model', 'text');
        $imageModel = $this->pickModel($request, 'image_model', 'image');

        // Checked here rather than at execution time: a mistyped model name
        // should stop a job before it is queued, not after a worker has picked
        // it up at four in the morning.
        foreach (['text' => $textModel, 'image' => $imageModel] as $kind => $model) {
            if ($model === '' || !$catalog->has($model)) {
                return $this->fail(
                    'unknown_model',
                    __('aIMage::global.error_unknown_model', ['model' => $model ?: '—', 'kind' => $kind])
                );
            }
        }

        $folder = trim((string) $request->input('output_folder')) ?: $scope->outputFolder();

        if (!$scope->canWrite(trim($folder, '/') . '/probe.png')) {
            return $this->fail('folder_denied', __('aIMage::global.error_folder_denied', ['folder' => $folder]));
        }

        $now = now();

        $job = Job::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $this->userId(),
            'status' => Job::STATUS_PLANNING,
            'title' => mb_substr($instruction, 0, 191),
            'text_model' => $textModel,
            'image_model' => $imageModel,
            'voice_model' => $this->pickModel($request, 'voice_model', 'voice'),
            'controls_json' => $this->controlsFrom($request),
            'output_folder' => trim($folder, '/'),
            'planner_turns' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Message::record((int) $job->getKey(), Message::ROLE_USER, [
            'text' => $instruction,
            'audio_path' => (string) $request->input('audio_path', ''),
        ]);

        JobQueue::enqueue($job);

        return $this->ok(['job' => $this->detail($job)]);
    }

    /** The whole state of one job — what the page polls. */
    public function show(string $uuid): JsonResponse
    {
        if (!$this->authorized()) {
            return $this->denied();
        }

        $job = $this->findJob($uuid);

        if ($job === null) {
            return $this->fail('not_found', __('aIMage::global.error_job_not_found'), 404);
        }

        return $this->ok(['job' => $this->detail($job)]);
    }

    /**
     * Answer the planner's question and let it carry on.
     */
    public function reply(Request $request, string $uuid): JsonResponse
    {
        if (!$this->authorized()) {
            return $this->denied();
        }

        $job = $this->findJob($uuid);

        if ($job === null) {
            return $this->fail('not_found', __('aIMage::global.error_job_not_found'), 404);
        }

        if ($job->isTerminal()) {
            return $this->fail('job_finished', __('aIMage::global.error_job_finished'));
        }

        $text = trim((string) $request->input('message'));

        if ($text === '') {
            return $this->fail('empty_instruction', __('aIMage::global.error_empty_instruction'));
        }

        Message::record((int) $job->getKey(), Message::ROLE_USER, [
            'text' => $text,
            'audio_path' => (string) $request->input('audio_path', ''),
        ]);

        // An answered question resets the turn budget: the manager has just
        // supplied information, so the planner deserves room to use it rather
        // than inheriting the exhaustion that made it ask.
        $job->forceFill([
            'status' => Job::STATUS_PLANNING,
            'planner_turns' => 0,
            'error_code' => '',
            'message' => '',
            'updated_at' => now(),
        ])->save();

        JobQueue::enqueue($job);

        return $this->ok(['job' => $this->detail($job)]);
    }

    /**
     * Approve a priced plan and start spending.
     */
    public function approve(string $uuid): JsonResponse
    {
        if (!$this->authorized()) {
            return $this->denied();
        }

        $job = $this->findJob($uuid);

        if ($job === null) {
            return $this->fail('not_found', __('aIMage::global.error_job_not_found'), 404);
        }

        if ((string) $job->status !== Job::STATUS_AWAITING_APPROVAL) {
            return $this->fail('not_awaiting_approval', __('aIMage::global.error_not_awaiting_approval'));
        }

        $job->forceFill([
            'status' => Job::STATUS_RUNNING,
            'approved_at' => now(),
            'message' => '',
            'error_code' => '',
            'updated_at' => now(),
        ])->save();

        JobQueue::enqueue($job);

        return $this->ok(['job' => $this->detail($job)]);
    }

    public function cancel(string $uuid): JsonResponse
    {
        if (!$this->authorized()) {
            return $this->denied();
        }

        $job = $this->findJob($uuid);

        if ($job === null) {
            return $this->fail('not_found', __('aIMage::global.error_job_not_found'), 404);
        }

        if ($job->isTerminal()) {
            return $this->ok(['job' => $this->detail($job)]);
        }

        JobQueue::cancel($job);

        return $this->ok(['job' => $this->detail($job->fresh())]);
    }

    // ------------------------------------------------------------------
    // Shaping
    // ------------------------------------------------------------------

    private function summarise(Job $job): array
    {
        return [
            'uuid' => (string) $job->uuid,
            'status' => (string) $job->status,
            'title' => (string) $job->title,
            'message' => (string) $job->message,
            'error_code' => (string) $job->error_code,
            'progress' => $job->progressPercent(),
            'steps' => [
                'total' => (int) $job->steps_total,
                'done' => (int) $job->steps_done,
                'failed' => (int) $job->steps_failed,
            ],
            'estimate' => $job->estimate_json,
            'waiting_on_human' => $job->isWaitingOnHuman(),
            'terminal' => $job->isTerminal(),
            'created_at' => optional($job->created_at)->toDateTimeString(),
            'finished_at' => optional($job->finished_at)->toDateTimeString(),
        ];
    }

    private function detail(Job $job): array
    {
        $scope = $this->scope();

        $steps = JobStep::query()
            ->where('job_id', $job->getKey())
            ->orderBy('seq')
            ->get()
            ->map(static function (JobStep $step) use ($scope) {
                $target = (string) $step->target_path;

                return [
                    'seq' => (int) $step->seq,
                    'type' => (string) $step->type,
                    'status' => (string) $step->status,
                    'model' => (string) $step->model,
                    'prompt' => (string) $step->prompt,
                    'source_path' => (string) $step->source_path,
                    'source_url' => $step->source_path ? $scope->publicUrl((string) $step->source_path) : null,
                    'target_path' => $target,
                    // The preview, and the proof the job did something: a
                    // finished step points at a file that now exists.
                    'target_url' => $target !== '' ? $scope->publicUrl($target) : null,
                    'message' => (string) $step->message,
                    'error_code' => (string) $step->error_code,
                    'attempts' => (int) $step->attempt_count,
                ];
            })
            ->all();

        $messages = Message::query()
            ->where('job_id', $job->getKey())
            ->orderBy('seq')
            // Tool traffic is machinery, not conversation. Showing it would
            // bury the one line the manager actually has to read.
            ->whereIn('role', [Message::ROLE_USER, Message::ROLE_ASSISTANT])
            ->get()
            ->map(static fn (Message $message) => [
                'role' => (string) $message->role,
                'text' => (string) $message->text,
                'spoken' => (string) $message->audio_path !== '',
                'created_at' => optional($message->created_at)->toDateTimeString(),
            ])
            ->all();

        return $this->summarise($job) + [
            'text_model' => (string) $job->text_model,
            'image_model' => (string) $job->image_model,
            'output_folder' => (string) $job->output_folder,
            'controls' => $job->controls(),
            'steps_detail' => $steps,
            'messages' => $messages,
            'queued' => JobQueue::pendingTaskFor($job) !== null,
        ];
    }

    private function controlsFrom(Request $request): array
    {
        $controls = [];

        foreach (['size', 'quality', 'background', 'aspect_ratio'] as $key) {
            $value = trim((string) $request->input($key, ''));

            if ($value !== '') {
                $controls[$key] = $value;
            }
        }

        return $controls;
    }

    private function pickModel(Request $request, string $field, string $kind): string
    {
        $requested = trim((string) $request->input($field, ''));

        return $requested !== '' ? $requested : Config::defaultModel($kind);
    }
}
