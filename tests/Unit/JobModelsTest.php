<?php

use EvolutionCMS\aIMage\Console\BatchHandler;
use EvolutionCMS\aIMage\Models\Job;
use EvolutionCMS\aIMage\Models\JobStep;
use EvolutionCMS\aIMage\Models\Message;
use EvolutionCMS\aIMage\Support\ApiKeys;
use EvolutionCMS\aIMage\Support\JobQueue;

beforeEach(fn () => aimageReset());

// ---------------------------------------------------------------------------
// Job state
// ---------------------------------------------------------------------------

test('the states that stop the worker are exactly the terminal and human ones', function () {
    foreach ([Job::STATUS_PLANNING, Job::STATUS_RUNNING] as $status) {
        expect(aimageJob(['status' => $status])->isRunnable())->toBeTrue();
    }

    foreach (Job::TERMINAL as $status) {
        $job = aimageJob(['status' => $status]);
        expect($job->isRunnable())->toBeFalse()
            ->and($job->isTerminal())->toBeTrue();
    }

    foreach (Job::WAITING_ON_HUMAN as $status) {
        $job = aimageJob(['status' => $status]);
        expect($job->isRunnable())->toBeFalse()
            ->and($job->isWaitingOnHuman())->toBeTrue()
            ->and($job->isTerminal())->toBeFalse();
    }
});

test('counters are recomputed from the steps rather than incremented', function () {
    $job = aimageJob();
    aimageStep($job, ['status' => JobStep::STATUS_SUCCEEDED]);
    aimageStep($job, ['status' => JobStep::STATUS_SUCCEEDED]);
    aimageStep($job, ['status' => JobStep::STATUS_FAILED]);
    aimageStep($job, ['status' => JobStep::STATUS_SKIPPED]);
    aimageStep($job, ['status' => JobStep::STATUS_QUEUED]);

    $job->refreshCounters();

    // Recomputing means a step retried after a worker was killed cannot
    // double-count.
    expect($job->steps_total)->toBe(5)
        ->and($job->steps_done)->toBe(2)
        ->and($job->steps_failed)->toBe(2);
});

test('recounting twice gives the same answer', function () {
    $job = aimageJob();
    aimageStep($job, ['status' => JobStep::STATUS_SUCCEEDED]);

    $job->refreshCounters();
    $job->refreshCounters();

    expect($job->fresh()->steps_done)->toBe(1);
});

test('progress counts settled steps, and a finished job with none reads as complete', function () {
    $job = aimageJob();
    aimageStep($job, ['status' => JobStep::STATUS_SUCCEEDED]);
    aimageStep($job, ['status' => JobStep::STATUS_FAILED]);
    aimageStep($job, ['status' => JobStep::STATUS_QUEUED]);
    aimageStep($job, ['status' => JobStep::STATUS_QUEUED]);
    $job->refreshCounters();

    expect($job->progressPercent())->toBe(50);

    $empty = aimageJob(['status' => Job::STATUS_SUCCEEDED]);

    expect($empty->progressPercent())->toBe(100)
        ->and(aimageJob(['status' => Job::STATUS_RUNNING])->progressPercent())->toBe(0);
});

// ---------------------------------------------------------------------------
// Steps
// ---------------------------------------------------------------------------

test('expected images follows the step type', function () {
    $job = aimageJob();

    $generate = aimageStep($job, ['type' => JobStep::TYPE_GENERATE, 'params_json' => ['n' => 4]]);
    $variate = aimageStep($job, ['type' => JobStep::TYPE_VARIATE, 'params_json' => ['n' => 3]]);
    $edit = aimageStep($job, ['type' => JobStep::TYPE_EDIT, 'params_json' => ['n' => 9]]);
    $upscale = aimageStep($job, ['type' => JobStep::TYPE_UPSCALE, 'params_json' => ['n' => 9]]);

    // Generation and variation both multiply; an edit and an upscale produce
    // exactly one result from one source however the params read.
    expect($generate->expectedImages())->toBe(4)
        ->and($variate->expectedImages())->toBe(3)
        ->and($edit->expectedImages())->toBe(1)
        ->and($upscale->expectedImages())->toBe(1);
});

test('marking a step running counts the attempt and stamps a start once', function () {
    $job = aimageJob();
    $step = aimageStep($job);

    $step->markRunning();
    $firstStart = $step->started_at;
    $step->markRunning();

    expect($step->attempt_count)->toBe(2)
        ->and($step->started_at->timestamp)->toBe($firstStart->timestamp);
});

test('requeueing clears the provider task so nothing polls a dead id', function () {
    $job = aimageJob();
    $step = aimageStep($job, [
        'status' => JobStep::STATUS_POLLING,
        'provider_task_id' => 'task-1',
    ]);

    $step->requeue('retrying');

    expect($step->status)->toBe(JobStep::STATUS_QUEUED)
        ->and($step->provider_task_id)->toBe('');
});

test('long failure text is truncated to fit its column', function () {
    $job = aimageJob();
    $step = aimageStep($job);

    $step->markFailed(str_repeat('E', 100), str_repeat('m', 800));

    expect(strlen($step->error_code))->toBe(64)
        ->and(strlen($step->message))->toBe(500);
});

// ---------------------------------------------------------------------------
// Messages
// ---------------------------------------------------------------------------

test('appending numbers turns from what is already stored', function () {
    $job = aimageJob();

    Message::record((int) $job->getKey(), Message::ROLE_USER, ['text' => 'first']);
    Message::record((int) $job->getKey(), Message::ROLE_ASSISTANT, ['text' => 'second']);

    expect(Message::query()->orderBy('seq')->pluck('seq')->all())->toBe([1, 2]);
});

test('one job\'s numbering is independent of another\'s', function () {
    $a = aimageJob();
    $b = aimageJob();

    Message::record((int) $a->getKey(), Message::ROLE_USER, ['text' => 'x']);
    Message::record((int) $b->getKey(), Message::ROLE_USER, ['text' => 'y']);

    expect(Message::query()->where('job_id', $b->getKey())->value('seq'))->toBe(1);
});

test('a transcript round-trips through the canonical shape', function () {
    $job = aimageJob();
    $id = (int) $job->getKey();

    Message::record($id, Message::ROLE_USER, ['text' => 'upscale everything']);
    Message::record($id, Message::ROLE_ASSISTANT, [
        'text' => 'Looking.',
        'tool_calls_json' => [['id' => 'c1', 'name' => 'list_images', 'input' => ['folder' => 'x']]],
    ]);
    Message::record($id, Message::ROLE_TOOL, [
        'tool_results_json' => [['id' => 'c1', 'content' => '2 images']],
    ]);

    $transcript = Message::transcriptFor($id);

    // Stored dialect-neutral, so a job started against Claude replays
    // correctly if the model is switched to GPT tomorrow.
    expect($transcript)->toHaveCount(3)
        ->and($transcript[0])->toBe(['role' => 'user', 'text' => 'upscale everything'])
        ->and($transcript[1]['role'])->toBe('assistant')
        ->and($transcript[1]['tool_calls'][0]['name'])->toBe('list_images')
        ->and($transcript[2])->toBe(['role' => 'tool', 'results' => [['id' => 'c1', 'content' => '2 images']]]);
});

// ---------------------------------------------------------------------------
// The invariant that keeps a slice from hammering the gateway
// ---------------------------------------------------------------------------

test('a parked step is polled at most once per slice', function () {
    aimageUser(7, 1);
    aimageSetFileRoot('assets');
    ApiKeys::setSiteKey('site-key');
    ApiKeys::flush();

    $job = aimageJob(['user_id' => 7, 'status' => Job::STATUS_RUNNING]);
    aimageStep($job, [
        'status' => JobStep::STATUS_POLLING,
        'provider_task_id' => 'task-1',
        'provider_model' => 'gpt-image-1',
    ]);

    $task = JobQueue::enqueue($job);

    // Exactly one "still running" response is queued. Before the per-slice
    // attempt set existed, the loop re-polled the same step for the whole
    // budget and blew straight past this into an empty mock queue — a hot loop
    // of gateway calls asking a provider hundreds of times a second whether it
    // had finished.
    $handler = new class([aimageJsonResponse([])]) extends BatchHandler {
        public function __construct(private array $gateway)
        {
        }

        protected function buildClient(string $apiKey): \EvolutionCMS\aIMage\Gateway\Client
        {
            return aimageClientWithout($this->gateway);
        }
    };

    $handler->execute($task);

    expect(JobStep::query()->first()->status)->toBe(JobStep::STATUS_POLLING)
        ->and($job->fresh()->status)->toBe(Job::STATUS_RUNNING);
});
