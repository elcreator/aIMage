<?php

use Elcreator\aIMage\Console\BatchHandler;
use Elcreator\aIMage\Models\Job;
use Elcreator\aIMage\Models\JobStep;
use Elcreator\aIMage\Support\ApiKeys;
use Elcreator\aIMage\Support\JobQueue;
use EvolutionCMS\Models\SystemCliTask;

/**
 * The slice loop, and the queue row that carries it.
 *
 * The promise being tested is "describe it and walk away": a job advances a
 * little on every worker tick, survives a killed worker, never blocks on a
 * provider, and stops dead when it needs a person.
 */

beforeEach(function () {
    aimageReset();
    ApiKeys::setSiteKey('site-key');
    ApiKeys::flush();
});

// ---------------------------------------------------------------------------
// Queueing
// ---------------------------------------------------------------------------

test('enqueueing writes one system task of the registered type', function () {
    aimageUser(7, 1);
    aimageSetFileRoot('assets');
    $job = aimageJob(['user_id' => 7, 'status' => Job::STATUS_RUNNING]);

    $task = JobQueue::enqueue($job);

    // The CMS's own queue, not a private one: leases, cancellation and worker
    // health all come for free.
    expect($task)->not->toBeNull()
        ->and($task->type)->toBe(BatchHandler::TYPE)
        ->and($task->target)->toBe($job->uuid)
        ->and($task->status)->toBe('queued')
        ->and($task->payload_json['job_id'])->toBe($job->getKey())
        ->and($job->fresh()->system_task_id)->toBe($task->getKey());
});

test('enqueueing twice does not put two workers on one job', function () {
    aimageUser(7, 1);
    aimageSetFileRoot('assets');
    $job = aimageJob(['user_id' => 7, 'status' => Job::STATUS_RUNNING]);

    JobQueue::enqueue($job);
    JobQueue::enqueue($job);

    expect(SystemCliTask::query()->where('target', $job->uuid)->count())->toBe(1);
});

test('a job waiting on a person is not queued', function () {
    aimageUser(7, 1);
    aimageSetFileRoot('assets');

    foreach ([Job::STATUS_AWAITING_INPUT, Job::STATUS_AWAITING_APPROVAL, Job::STATUS_SUCCEEDED] as $status) {
        $job = aimageJob(['user_id' => 7, 'status' => $status]);

        // Re-queueing these every minute would burn planner turns re-asking a
        // question nobody has answered.
        expect(JobQueue::enqueue($job))->toBeNull();
    }
});

test('a task already in flight blocks a second one', function () {
    aimageUser(7, 1);
    aimageSetFileRoot('assets');
    $job = aimageJob(['user_id' => 7, 'status' => Job::STATUS_RUNNING]);

    $first = JobQueue::enqueue($job);
    $first->forceFill(['status' => 'running'])->save();

    JobQueue::enqueue($job);

    expect(SystemCliTask::query()->where('target', $job->uuid)->count())->toBe(1);
});

test('cancelling stops the job and its queued task', function () {
    aimageUser(7, 1);
    aimageSetFileRoot('assets');
    $job = aimageJob(['user_id' => 7, 'status' => Job::STATUS_RUNNING]);
    JobQueue::enqueue($job);

    JobQueue::cancel($job);

    expect($job->fresh()->status)->toBe(Job::STATUS_CANCELLED)
        ->and(SystemCliTask::query()->where('target', $job->uuid)->value('status'))->toBe('cancelled');
});

test('cancelling an in-flight task flags it rather than killing it', function () {
    aimageUser(7, 1);
    aimageSetFileRoot('assets');
    $job = aimageJob(['user_id' => 7, 'status' => Job::STATUS_RUNNING]);
    $task = JobQueue::enqueue($job);
    $task->forceFill(['status' => 'running'])->save();

    JobQueue::cancel($job);
    $task->refresh();

    // The handler checks at the top of every slice; killing a running task
    // mid-write would be worse than letting it notice.
    expect($task->status)->toBe('running')
        ->and($task->cancellation_requested_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Slices
// ---------------------------------------------------------------------------

/** Run one slice with a gateway queue of canned responses. */
function aimageSlice(Job $job, array $gatewayResponses = [], array $downloads = []): array
{
    // A cancelled job has no pending task and refuses to be queued, so fall
    // back to whatever row it last had — the handler still has to be handed
    // one to notice the cancellation.
    $task = JobQueue::pendingTaskFor($job)
        ?? JobQueue::enqueue($job)
        ?? SystemCliTask::query()->where('target', $job->uuid)->orderByDesc('id')->firstOrFail();

    $handler = new class($gatewayResponses, $downloads) extends BatchHandler {
        public function __construct(private array $gateway, private array $downloads)
        {
        }

        // The handler builds its own client from the manager's key; this
        // overrides just that seam so the suite never touches the network.
        protected function buildClient(string $apiKey): \Elcreator\aIMage\Gateway\Client
        {
            return aimageClientWithout($this->gateway);
        }

        protected function buildDownloader(): \GuzzleHttp\Client
        {
            return aimageDownloader($this->downloads);
        }
    };

    return $handler->execute($task);
}

test('a slice with no API key fails the job with a reason', function () {
    aimageUser(7, 1);
    aimageSetFileRoot('assets');
    ApiKeys::setSiteKey(null);
    ApiKeys::flush();

    $job = aimageJob(['user_id' => 7, 'status' => Job::STATUS_RUNNING]);

    aimageSlice($job);

    expect($job->fresh()->status)->toBe(Job::STATUS_FAILED)
        ->and($job->fresh()->error_code)->toBe('NO_API_KEY');
});

test('a slice executes queued steps and writes their results', function () {
    aimageUser(7, 1);
    aimageSetFileRoot('assets');

    $job = aimageJob(['user_id' => 7, 'status' => Job::STATUS_RUNNING]);
    aimageStep($job);

    aimageSlice(
        $job,
        [aimageJsonResponse(['data' => [['url' => 'https://cdn.test/a.png']]])],
        [aimageImageResponse()]
    );

    expect($job->fresh()->status)->toBe(Job::STATUS_SUCCEEDED)
        ->and($job->fresh()->steps_done)->toBe(1)
        ->and(is_file(AIMAGE_TEST_ROOT . '/assets/aimage/aimage.png'))->toBeTrue();
});

test('a job with steps still parked queues its successor and does not finish', function () {
    aimageUser(7, 1);
    aimageSetFileRoot('assets');

    $job = aimageJob(['user_id' => 7, 'status' => Job::STATUS_RUNNING]);
    aimageStep($job);

    $result = aimageSlice($job, [aimageJsonResponse(['taskId' => 'task-1'])]);

    // Parked on a provider, so the slice hands back and a later one checks.
    expect($job->fresh()->status)->toBe(Job::STATUS_RUNNING)
        ->and($result['result']['continued'])->toBeTrue()
        ->and(JobStep::query()->first()->status)->toBe(JobStep::STATUS_POLLING)
        ->and(SystemCliTask::query()->where('target', $job->uuid)->where('status', 'queued')->count())->toBe(1);
});

test('a step left running by a killed worker is put back in the queue', function () {
    aimageUser(7, 1);
    aimageSetFileRoot('assets');

    $job = aimageJob(['user_id' => 7, 'status' => Job::STATUS_RUNNING]);
    // The shape a worker killed between calling a provider and recording the
    // result leaves behind. Nothing is in flight, so re-submitting is the only
    // way it will ever finish.
    aimageStep($job, ['status' => JobStep::STATUS_RUNNING, 'attempt_count' => 1]);

    aimageSlice(
        $job,
        [aimageJsonResponse(['data' => [['url' => 'https://cdn.test/a.png']]])],
        [aimageImageResponse()]
    );

    expect(JobStep::query()->first()->status)->toBe(JobStep::STATUS_SUCCEEDED);
});

test('a job whose every step failed is a failed job', function () {
    aimageUser(7, 1);
    aimageSetFileRoot('assets');

    $job = aimageJob(['user_id' => 7, 'status' => Job::STATUS_RUNNING]);
    aimageStep($job);

    aimageSlice($job, [aimageJsonResponse(['error' => ['message' => 'bad prompt']], 400)]);

    // The manager asked for images and got none. Reporting success with
    // nothing to show would be the wrong answer.
    expect($job->fresh()->status)->toBe(Job::STATUS_FAILED)
        ->and($job->fresh()->error_code)->toBe('ALL_STEPS_FAILED');
});

test('a partly successful job succeeds and says how many failed', function () {
    aimageUser(7, 1);
    aimageSetFileRoot('assets');

    $job = aimageJob(['user_id' => 7, 'status' => Job::STATUS_RUNNING]);
    aimageStep($job);
    aimageStep($job);

    aimageSlice(
        $job,
        [
            aimageJsonResponse(['data' => [['url' => 'https://cdn.test/a.png']]]),
            aimageJsonResponse(['error' => ['message' => 'nope']], 400),
        ],
        [aimageImageResponse()]
    );

    $fresh = $job->fresh();

    expect($fresh->status)->toBe(Job::STATUS_SUCCEEDED)
        ->and($fresh->steps_done)->toBe(1)
        ->and($fresh->steps_failed)->toBe(1)
        ->and($fresh->message)->toContain('1 of 2');
});

test('a running job with no steps at all fails rather than claiming success', function () {
    aimageUser(7, 1);
    aimageSetFileRoot('assets');

    $job = aimageJob(['user_id' => 7, 'status' => Job::STATUS_RUNNING]);

    aimageSlice($job);

    expect($job->fresh()->status)->toBe(Job::STATUS_FAILED)
        ->and($job->fresh()->error_code)->toBe('EMPTY_PLAN');
});

test('a cancelled job stops without touching the gateway', function () {
    aimageUser(7, 1);
    aimageSetFileRoot('assets');

    $job = aimageJob(['user_id' => 7, 'status' => Job::STATUS_RUNNING]);
    aimageStep($job);
    JobQueue::enqueue($job);
    JobQueue::cancel($job);

    // An empty gateway queue: reaching for a response would throw.
    $result = aimageSlice($job->fresh(), []);

    expect($result['result']['status'])->toBe(Job::STATUS_CANCELLED)
        ->and(JobStep::query()->first()->status)->toBe(JobStep::STATUS_QUEUED);
});

test('a task cancelled through the CMS cancels the job too', function () {
    aimageUser(7, 1);
    aimageSetFileRoot('assets');

    $job = aimageJob(['user_id' => 7, 'status' => Job::STATUS_RUNNING]);
    aimageStep($job);
    $task = JobQueue::enqueue($job);

    // Either authority is enough: the CMS's flag, or our own job status.
    $task->forceFill(['cancellation_requested_at' => now()])->save();

    aimageSlice($job);

    expect($job->fresh()->status)->toBe(Job::STATUS_CANCELLED);
});

test('a task pointing at a job that no longer exists is skipped, not failed', function () {
    aimageUser(7, 1);
    aimageSetFileRoot('assets');

    $job = aimageJob(['user_id' => 7, 'status' => Job::STATUS_RUNNING]);
    $task = JobQueue::enqueue($job);
    $job->delete();

    $handler = new BatchHandler();
    $result = $handler->execute($task);

    expect($result['result']['skipped'])->toBeTrue();
});

test('progress is reported back to the worker', function () {
    aimageUser(7, 1);
    aimageSetFileRoot('assets');

    $job = aimageJob(['user_id' => 7, 'status' => Job::STATUS_RUNNING]);
    aimageStep($job);

    $task = JobQueue::enqueue($job);
    $reports = [];

    $handler = new class([aimageJsonResponse(['data' => [['url' => 'https://cdn.test/a.png']]])], [aimageImageResponse()]) extends BatchHandler {
        public function __construct(private array $gateway, private array $downloads)
        {
        }

        protected function buildClient(string $apiKey): \Elcreator\aIMage\Gateway\Client
        {
            return aimageClientWithout($this->gateway);
        }

        protected function buildDownloader(): \GuzzleHttp\Client
        {
            return aimageDownloader($this->downloads);
        }
    };

    $handler->execute($task, function ($step, $progress, $message, $level = 'info', array $context = []) use (&$reports) {
        $reports[] = ['step' => $step, 'progress' => $progress, 'message' => $message];
    });

    expect($reports)->not->toBeEmpty()
        ->and(array_column($reports, 'step'))->toContain('finished');
});
