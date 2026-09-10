<?php

use Elcreator\aIMage\Console\BatchHandler;
use Elcreator\aIMage\Models\Job;
use Elcreator\aIMage\Models\JobStep;
use Elcreator\aIMage\Models\Message;
use Elcreator\aIMage\Support\ApiKeys;
use Elcreator\aIMage\Support\JobQueue;

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

        protected function buildClient(string $apiKey): \Elcreator\aIMage\Gateway\Client
        {
            return aimageClientWithout($this->gateway);
        }
    };

    $handler->execute($task);

    expect(JobStep::query()->first()->status)->toBe(JobStep::STATUS_POLLING)
        ->and($job->fresh()->status)->toBe(Job::STATUS_RUNNING);
});

// ---------------------------------------------------------------------------
// What the manager is shown of the conversation
// ---------------------------------------------------------------------------

test('a planner turn that only called tools is not shown as an empty reply', function () {
    $job = aimageJob();
    $id = (int) $job->getKey();

    Message::record($id, Message::ROLE_USER, ['text' => 'Generate 1 image of a blue butterfly']);
    // What a planning turn looks like: no prose, one tool call.
    Message::record($id, Message::ROLE_ASSISTANT, [
        'text' => '',
        'tool_calls_json' => [['id' => 'c1', 'name' => 'plan_generate', 'input' => ['prompt' => 'x']]],
    ]);
    Message::record($id, Message::ROLE_TOOL, [
        'tool_results_json' => [['id' => 'c1', 'content' => 'Queued generation of 1 image(s).']],
    ]);
    Message::record($id, Message::ROLE_ASSISTANT, ['text' => '   ']);
    Message::record($id, Message::ROLE_ASSISTANT, ['text' => 'Which of the two logos did you mean?']);

    $shown = Message::query()->where('job_id', $id)->orderBy('seq')->get()
        ->filter(static fn (Message $m) => $m->isConversational())
        ->map(static fn (Message $m) => $m->role . ':' . trim((string) $m->text))
        ->values()
        ->all();

    // One blank bubble per planning turn read as the assistant having replied
    // with nothing. What it did is in the step list instead.
    expect($shown)->toBe([
        'user:Generate 1 image of a blue butterfly',
        'assistant:Which of the two logos did you mean?',
    ]);
});

test('the whole transcript still reaches the planner', function () {
    $job = aimageJob();
    $id = (int) $job->getKey();

    Message::record($id, Message::ROLE_USER, ['text' => 'Upscale the logo']);
    Message::record($id, Message::ROLE_ASSISTANT, [
        'text' => '',
        'tool_calls_json' => [['id' => 'c1', 'name' => 'list_images', 'input' => []]],
    ]);
    Message::record($id, Message::ROLE_TOOL, [
        'tool_results_json' => [['id' => 'c1', 'content' => '1 image(s): logo.png']],
    ]);

    // The two audiences are different. Hiding a turn from the manager must
    // never hide it from the model, or the next turn replays a conversation
    // that never happened.
    expect(Message::transcriptFor($id))->toHaveCount(3);
});

test('the planner nudge is not shown as something the manager typed', function () {
    $job = aimageJob();
    $id = (int) $job->getKey();

    Message::record($id, Message::ROLE_USER, ['text' => 'привет']);
    Message::record($id, Message::ROLE_ASSISTANT, ['text' => 'I am ready to help.', 'internal' => true]);
    Message::record($id, Message::ROLE_USER, [
        'internal' => true,
        'text' => 'Do not reply with prose. This plugin only produces images.',
    ]);

    $shown = Message::query()->where('job_id', $id)->orderBy('seq')->get()
        ->filter(static fn (Message $m) => $m->isConversational())
        ->map(static fn (Message $m) => $m->role . ':' . $m->displayText())
        ->values()
        ->all();

    // The nudge has to be a `user` turn for the model to obey it, which is
    // exactly why it appeared in the thread as words the manager had typed.
    expect($shown)->toBe(['user:привет']);
});

test('tool-call syntax a model wrote as prose is not shown to the manager', function () {
    $job = aimageJob();
    $id = (int) $job->getKey();

    $raw = 'I am ready to help. What would you like? [ask_user(question="What can I help you with today?")]';
    $message = Message::record($id, Message::ROLE_ASSISTANT, ['text' => $raw]);

    expect($message->displayText())->toBe('I am ready to help. What would you like?')
        // The transcript keeps it verbatim: the model has to see its own turn
        // to correct it on the next one.
        ->and($message->text)->toBe($raw)
        ->and(Message::stripToolSyntax('Done. plan_generate(prompt="a lake")'))->toBe('Done.');
});

test('the sanitiser leaves anything that is not one of our tools alone', function () {
    // A prompt is ordinary prose and routinely has brackets in it. A sanitiser
    // that guesses is worse than the artefact it removes.
    expect(Message::stripToolSyntax('Use the [blue] one, and call verify(now).'))
        ->toBe('Use the [blue] one, and call verify(now).')
        ->and(Message::stripToolSyntax('Which logo — [the round one] or the square?'))
        ->toBe('Which logo — [the round one] or the square?');
});

// ---------------------------------------------------------------------------
// Changing models on a task in flight
// ---------------------------------------------------------------------------

test('re-pointing a task at another image model carries its queued steps with it', function () {
    $job = aimageJob(['status' => Job::STATUS_RUNNING, 'image_model' => 'gpt-image-1']);

    $queued = aimageStep($job, ['model' => 'gpt-image-1']);
    $done = aimageStep($job, ['model' => 'gpt-image-1', 'status' => JobStep::STATUS_SUCCEEDED]);
    $upscale = aimageStep($job, ['model' => 'Qubico/image-toolkit', 'type' => JobStep::TYPE_UPSCALE]);

    // What JobController::models() does once the catalogue has agreed.
    $job->forceFill(['image_model' => 'flux1-schnell'])->save();
    JobStep::query()
        ->where('job_id', $job->getKey())
        ->where('status', JobStep::STATUS_QUEUED)
        ->where('type', '!=', JobStep::TYPE_UPSCALE)
        ->update(['model' => 'flux1-schnell']);

    expect($queued->fresh()->model)->toBe('flux1-schnell')
        // Already run: rewriting it would misreport what made the image on disk.
        ->and($done->fresh()->model)->toBe('gpt-image-1')
        // Upscaling is pinned to the gateway's own upscaler either way.
        ->and($upscale->fresh()->model)->toBe('Qubico/image-toolkit');
});

test('a task carries its own models, not the page defaults', function () {
    $a = aimageJob(['text_model' => 'claude-sonnet-5', 'image_model' => 'gpt-image-1']);
    $b = aimageJob(['text_model' => 'gpt-5', 'image_model' => 'flux1-schnell']);

    // The pickers read these when a task is opened. Two tasks side by side
    // must not answer with the same pair.
    expect([$a->fresh()->text_model, $a->fresh()->image_model])->toBe(['claude-sonnet-5', 'gpt-image-1'])
        ->and([$b->fresh()->text_model, $b->fresh()->image_model])->toBe(['gpt-5', 'flux1-schnell']);
});
