<?php

namespace Elcreator\aIMage\Http\Controllers;

use Elcreator\aIMage\Gateway\ModelCatalog;
use Elcreator\aIMage\Models\Job;
use Elcreator\aIMage\Models\JobStep;
use Elcreator\aIMage\Models\Message;
use Elcreator\aIMage\Support\Config;
use Elcreator\aIMage\Support\JobQueue;
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

        $requestedFolder = trim((string) $request->input('output_folder')) ?: $scope->outputFolder();

        // Anchored under the write root here, not merely checked, so the value
        // stored on the job is the one every later step will actually use.
        $folder = $scope->resolveWriteFolder($requestedFolder);

        if ($folder === null || !$scope->canWrite($folder . '/probe.png')) {
            return $this->fail(
                'folder_denied',
                __('aIMage::global.error_folder_denied', ['folder' => $requestedFolder])
            );
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
            'output_folder' => $folder,
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

    /**
     * Change the models a task in flight will carry on with.
     *
     * The pickers at the top of the page belong to whichever task is open, not
     * to the page, so switching tasks shows that task's models and changing one
     * has to reach the task rather than the next thing the manager creates.
     *
     * Queued steps are re-pointed at the new image model as well. Without that
     * the change would be honoured by the planner and ignored by everything it
     * had already queued — the manager would see the model they chose and get
     * images from the one they replaced.
     */
    public function models(Request $request, string $uuid): JsonResponse
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

        $client = $this->client();

        if ($client === null) {
            return $this->fail('no_key', __('aIMage::global.error_no_key'), 409);
        }

        $catalog = $this->catalog($client);
        $changes = [];

        foreach (['text_model' => 'text', 'image_model' => 'image'] as $field => $kind) {
            $model = trim((string) $request->input($field, ''));

            if ($model === '' || $model === (string) $job->{$field}) {
                continue;
            }

            if (!$catalog->has($model)) {
                return $this->fail(
                    'unknown_model',
                    __('aIMage::global.error_unknown_model', ['model' => $model, 'kind' => $kind])
                );
            }

            $changes[$field] = $model;
        }

        if (isset($changes['image_model'])) {
            // Upscaling is pinned to the gateway's own upscaler whatever the
            // manager picks, so it never constrains this choice.
            $pending = JobStep::query()
                ->where('job_id', $job->getKey())
                ->where('status', JobStep::STATUS_QUEUED)
                ->where('type', '!=', JobStep::TYPE_UPSCALE)
                ->get();

            foreach ($pending as $step) {
                $action = $this->actionFor((string) $step->type);

                if ($action !== null && !$catalog->supports($changes['image_model'], $action)) {
                    // Refused now rather than at four in the morning, one step
                    // at a time, in a task the manager has stopped watching.
                    return $this->fail('unknown_model', __('aIMage::global.error_model_cannot_continue', [
                        'model' => $changes['image_model'],
                        'step' => __('aIMage::global.step_' . $step->type),
                    ]));
                }
            }
        }

        if ($changes !== []) {
            $job->forceFill($changes + ['updated_at' => now()])->save();

            if (isset($changes['image_model'])) {
                JobStep::query()
                    ->where('job_id', $job->getKey())
                    // Only work that has not started. A step already run was
                    // paid for on the model it ran on, and rewriting its model
                    // would misreport what produced the image on disk.
                    ->where('status', JobStep::STATUS_QUEUED)
                    ->where('type', '!=', JobStep::TYPE_UPSCALE)
                    ->update(['model' => $changes['image_model'], 'updated_at' => now()]);
            }
        }

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
            // Tool traffic and wordless planner turns are machinery, not
            // conversation. Showing them would bury the one line the manager
            // actually has to read. See Message::isConversational().
            ->whereIn('role', [Message::ROLE_USER, Message::ROLE_ASSISTANT])
            ->get()
            ->filter(static fn (Message $message) => $message->isConversational())
            ->values()
            ->map(static fn (Message $message) => [
                'role' => (string) $message->role,
                'text' => $message->displayText(),
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

    /** The catalogue action a queued step of this type needs, if it needs one. */
    private function actionFor(string $type): ?string
    {
        return match ($type) {
            JobStep::TYPE_GENERATE => ModelCatalog::ACTION_TEXT_TO_IMAGE,
            JobStep::TYPE_EDIT => ModelCatalog::ACTION_IMAGES_AND_TEXT_TO_IMAGE,
            JobStep::TYPE_VARIATE => ModelCatalog::ACTION_VARIATE_IMAGE,
            default => null,
        };
    }

    private function pickModel(Request $request, string $field, string $kind): string
    {
        $requested = trim((string) $request->input($field, ''));

        return $requested !== '' ? $requested : Config::defaultModel($kind);
    }
}
