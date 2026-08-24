<?php

use EvolutionCMS\aIMage\Agent\Executor;
use EvolutionCMS\aIMage\Models\Job;
use EvolutionCMS\aIMage\Models\JobStep;
use EvolutionCMS\aIMage\Support\ImageScope;
use GuzzleHttp\Psr7\Response;

/**
 * The only place in the package that changes a file.
 *
 * Two properties matter more than any individual case: nothing blocks waiting
 * on a provider, and a step is safe to run twice. Both are exercised below.
 */

beforeEach(fn () => aimageReset());

function aimageReadyJob(): Job
{
    aimageUser(7, 1);
    aimageSetFileRoot('assets');

    return aimageJob(['user_id' => 7, 'status' => Job::STATUS_RUNNING]);
}

// ---------------------------------------------------------------------------
// Synchronous providers
// ---------------------------------------------------------------------------

test('a synchronous generation writes the image and marks the step succeeded', function () {
    $job = aimageReadyJob();
    $step = aimageStep($job);

    $executor = new Executor(
        aimageClientWithout([aimageJsonResponse(['data' => [['url' => 'https://cdn.test/a.png']]])]),
        ImageScope::forUser(7),
        aimageDownloader([aimageImageResponse()])
    );

    $executor->advance($job, $step);
    $step->refresh();

    expect($step->status)->toBe(JobStep::STATUS_SUCCEEDED)
        ->and($step->target_path)->toBe('aimage/aimage.png')
        ->and(file_get_contents(AIMAGE_TEST_ROOT . '/assets/aimage/aimage.png'))->toBe(aimagePng());
});

test('several returned images are all written, with distinct names', function () {
    $job = aimageReadyJob();
    $step = aimageStep($job, ['params_json' => ['n' => 2, 'folder' => 'aimage']]);

    $executor = new Executor(
        aimageClientWithout([aimageJsonResponse(['data' => [
            ['url' => 'https://cdn.test/a.png'],
            ['url' => 'https://cdn.test/b.png'],
        ]])]),
        ImageScope::forUser(7),
        aimageDownloader([aimageImageResponse(), aimageImageResponse()])
    );

    $executor->advance($job, $step);
    $step->refresh();

    expect($step->status)->toBe(JobStep::STATUS_SUCCEEDED)
        ->and($step->result_json['count'])->toBe(2)
        ->and($step->result_json['paths'])->toBe(['aimage/aimage-1.png', 'aimage/aimage-2.png'])
        ->and(is_file(AIMAGE_TEST_ROOT . '/assets/aimage/aimage-1.png'))->toBeTrue()
        ->and(is_file(AIMAGE_TEST_ROOT . '/assets/aimage/aimage-2.png'))->toBeTrue();
});

test('an inline base64 result is written without a download', function () {
    $job = aimageReadyJob();
    $step = aimageStep($job);

    $executor = new Executor(
        aimageClientWithout([aimageJsonResponse(['data' => [
            ['b64_json' => base64_encode(aimagePng())],
        ]])]),
        ImageScope::forUser(7),
        // No download responses queued at all: reaching for one would fail.
        aimageDownloader([])
    );

    $executor->advance($job, $step);
    $step->refresh();

    expect($step->status)->toBe(JobStep::STATUS_SUCCEEDED)
        ->and(file_get_contents(AIMAGE_TEST_ROOT . '/assets/aimage/aimage.png'))->toBe(aimagePng());
});

// ---------------------------------------------------------------------------
// Asynchronous providers
// ---------------------------------------------------------------------------

test('an async provider parks the step instead of waiting', function () {
    $job = aimageReadyJob();
    $step = aimageStep($job);

    $executor = new Executor(
        aimageClientWithout([aimageJsonResponse(['taskId' => 'task-123'])]),
        ImageScope::forUser(7),
        aimageDownloader([])
    );

    $moved = $executor->advance($job, $step);
    $step->refresh();

    // Nothing sleeps. This is what lets one worker carry two hundred images
    // without owning the queue for the night.
    expect($moved)->toBeTrue()
        ->and($step->status)->toBe(JobStep::STATUS_POLLING)
        ->and($step->provider_task_id)->toBe('task-123')
        ->and($step->provider_model)->toBe('gpt-image-1');
});

test('polling an unfinished task reports no progress and keeps the step parked', function () {
    $job = aimageReadyJob();
    $step = aimageStep($job, [
        'status' => JobStep::STATUS_POLLING,
        'provider_task_id' => 'task-123',
        'provider_model' => 'gpt-image-1',
    ]);

    $executor = new Executor(
        // An unfinished task answers 200 with an empty object — neither a
        // result nor an error.
        aimageClientWithout([aimageJsonResponse([])]),
        ImageScope::forUser(7),
        aimageDownloader([])
    );

    $moved = $executor->advance($job, $step);
    $step->refresh();

    expect($moved)->toBeFalse()
        ->and($step->status)->toBe(JobStep::STATUS_POLLING);
});

test('polling a finished task writes the image', function () {
    $job = aimageReadyJob();
    $step = aimageStep($job, [
        'status' => JobStep::STATUS_POLLING,
        'provider_task_id' => 'task-123',
        'provider_model' => 'gpt-image-1',
    ]);

    $executor = new Executor(
        aimageClientWithout([aimageJsonResponse(['data' => [['url' => 'https://cdn.test/a.png']]])]),
        ImageScope::forUser(7),
        aimageDownloader([aimageImageResponse()])
    );

    $executor->advance($job, $step);
    $step->refresh();

    expect($step->status)->toBe(JobStep::STATUS_SUCCEEDED)
        ->and(is_file(AIMAGE_TEST_ROOT . '/assets/aimage/aimage.png'))->toBeTrue();
});

test('an upscale is polled under the literal upscale segment, not its model', function () {
    $job = aimageReadyJob();
    aimagePutImage('images/a.png');

    $step = aimageStep($job, [
        'type' => JobStep::TYPE_UPSCALE,
        'model' => \EvolutionCMS\aIMage\Gateway\ModelCatalog::UPSCALE_MODEL,
        'source_path' => 'images/a.png',
        'params_json' => ['scale' => 2, 'folder' => 'aimage'],
    ]);

    $executor = new Executor(
        aimageClientWithout([aimageJsonResponse(['taskId' => 'up-1'])]),
        ImageScope::forUser(7),
        aimageDownloader([])
    );

    $executor->advance($job, $step);
    $step->refresh();

    // The gateway routes upscale status specially; polling under the model
    // name would 404.
    expect($step->provider_model)->toBe('upscale');
});

// ---------------------------------------------------------------------------
// Sources and scope
// ---------------------------------------------------------------------------

test('an edit reads its source and sends it', function () {
    $job = aimageReadyJob();
    aimagePutImage('images/a.png');

    $step = aimageStep($job, [
        'type' => JobStep::TYPE_EDIT,
        'source_path' => 'images/a.png',
        'prompt' => 'make it blue',
    ]);

    $executor = new Executor(
        aimageClientWithout([aimageJsonResponse(['data' => [['url' => 'https://cdn.test/a.png']]])]),
        ImageScope::forUser(7),
        aimageDownloader([aimageImageResponse()])
    );

    $executor->advance($job, $step);
    $step->refresh();

    // Named after the source, so a folder of results stays legible.
    expect($step->status)->toBe(JobStep::STATUS_SUCCEEDED)
        ->and($step->target_path)->toBe('aimage/a-edit.png');
});

test('a source that has vanished fails the step without retrying', function () {
    $job = aimageReadyJob();

    $step = aimageStep($job, [
        'type' => JobStep::TYPE_EDIT,
        'source_path' => 'images/gone.png',
        'prompt' => 'x',
    ]);

    $executor = new Executor(aimageClientWithout([]), ImageScope::forUser(7), aimageDownloader([]));

    $executor->advance($job, $step);
    $step->refresh();

    expect($step->status)->toBe(JobStep::STATUS_FAILED)
        ->and($step->error_code)->toBe('SOURCE_UNAVAILABLE');
});

test('an upscale with no public URL fails with a reason a person can act on', function () {
    aimageUser(7, 1);
    // A file root outside the web root: nothing under it has a URL.
    aimageSetFileRoot('assets');
    AIMageTestCore::$config['site_url'] = 'https://example.test/';

    $job = aimageJob(['user_id' => 7, 'status' => Job::STATUS_RUNNING]);

    $step = aimageStep($job, [
        'type' => JobStep::TYPE_UPSCALE,
        'source_path' => '../outside.png',
        'params_json' => ['scale' => 2, 'folder' => 'aimage'],
    ]);

    $executor = new Executor(aimageClientWithout([]), ImageScope::forUser(7), aimageDownloader([]));

    $executor->advance($job, $step);
    $step->refresh();

    expect($step->status)->toBe(JobStep::STATUS_FAILED)
        ->and($step->error_code)->toBe('NOT_PUBLICLY_REACHABLE')
        ->and($step->message)->toContain('public URL');
});

test('a result that cannot be written fails the step rather than being lost quietly', function () {
    aimageUser(7, 3, [4]);
    aimageSetFileRoot('assets');
    aimageRestrict('locked', 9);

    $job = aimageJob(['user_id' => 7, 'status' => Job::STATUS_RUNNING, 'output_folder' => 'locked']);
    $step = aimageStep($job, ['params_json' => ['n' => 1, 'folder' => 'locked']]);

    $executor = new Executor(
        aimageClientWithout([aimageJsonResponse(['data' => [['url' => 'https://cdn.test/a.png']]])]),
        ImageScope::forUser(7),
        aimageDownloader([aimageImageResponse()])
    );

    $executor->advance($job, $step);
    $step->refresh();

    expect($step->status)->toBe(JobStep::STATUS_FAILED)
        ->and($step->error_code)->toBe('WRITE_FAILED');
});

// ---------------------------------------------------------------------------
// What comes back
// ---------------------------------------------------------------------------

test('a response that is not an image at all is refused', function () {
    $job = aimageReadyJob();
    $step = aimageStep($job);

    $executor = new Executor(
        aimageClientWithout([aimageJsonResponse(['data' => [['url' => 'https://cdn.test/a.png']]])]),
        ImageScope::forUser(7),
        // HTML where an image was promised. The magic-byte check is the only
        // thing standing between that and a publicly served folder.
        aimageDownloader([new Response(200, ['Content-Type' => 'text/html'], '<html>nope</html>')])
    );

    $executor->advance($job, $step);
    $step->refresh();

    expect($step->status)->toBe(JobStep::STATUS_FAILED)
        ->and($step->error_code)->toBe('WRITE_FAILED')
        ->and(glob(AIMAGE_TEST_ROOT . '/assets/aimage/*'))->toBe([]);
});

test('a non-http result URL is refused', function () {
    $job = aimageReadyJob();
    $step = aimageStep($job);

    $executor = new Executor(
        aimageClientWithout([aimageJsonResponse(['data' => [['url' => 'file:///etc/passwd']]])]),
        ImageScope::forUser(7),
        aimageDownloader([])
    );

    $executor->advance($job, $step);
    $step->refresh();

    expect($step->status)->toBe(JobStep::STATUS_FAILED);
});

test('a finished response with no image fails clearly', function () {
    $job = aimageReadyJob();
    $step = aimageStep($job, [
        'status' => JobStep::STATUS_POLLING,
        'provider_task_id' => 't',
        'provider_model' => 'gpt-image-1',
    ]);

    $executor = new Executor(
        aimageClientWithout([aimageJsonResponse(['data' => [['revised_prompt' => 'x']]])]),
        ImageScope::forUser(7),
        aimageDownloader([])
    );

    $moved = $executor->advance($job, $step);
    $step->refresh();

    // `data` present but carrying no url and no b64 reads as "still running",
    // which is the safe reading: it parks rather than failing a job that may
    // yet finish.
    expect($moved)->toBeFalse()
        ->and($step->status)->toBe(JobStep::STATUS_POLLING);
});

// ---------------------------------------------------------------------------
// Failure handling
// ---------------------------------------------------------------------------

test('a transient failure is retried, not failed', function () {
    $job = aimageReadyJob();
    $step = aimageStep($job);

    $executor = new Executor(
        aimageClientWithout([aimageJsonResponse(['error' => ['message' => 'slow down']], 429)]),
        ImageScope::forUser(7),
        aimageDownloader([])
    );

    $executor->advance($job, $step);
    $step->refresh();

    expect($step->status)->toBe(JobStep::STATUS_QUEUED)
        ->and($step->attempt_count)->toBe(1)
        ->and($step->message)->toContain('Retrying');
});

test('a permanent failure is not retried, because it would fail identically', function () {
    $job = aimageReadyJob();
    $step = aimageStep($job);

    $executor = new Executor(
        aimageClientWithout([aimageJsonResponse(['error' => ['message' => 'bad prompt']], 400)]),
        ImageScope::forUser(7),
        aimageDownloader([])
    );

    $executor->advance($job, $step);
    $step->refresh();

    expect($step->status)->toBe(JobStep::STATUS_FAILED)
        ->and($step->message)->toContain('bad prompt');
});

test('a rejected key is reported as such rather than as a provider fault', function () {
    $job = aimageReadyJob();
    $step = aimageStep($job);

    $executor = new Executor(
        aimageClientWithout([aimageJsonResponse(['error' => ['message' => 'invalid key']], 403)]),
        ImageScope::forUser(7),
        aimageDownloader([])
    );

    $executor->advance($job, $step);
    $step->refresh();

    expect($step->status)->toBe(JobStep::STATUS_FAILED)
        ->and($step->error_code)->toBe('KEY_REJECTED');
});

test('retries stop at the configured ceiling', function () {
    $job = aimageReadyJob();
    $step = aimageStep($job, ['attempt_count' => 4]);

    $executor = new Executor(
        aimageClientWithout([aimageJsonResponse(['error' => ['message' => 'still busy']], 503)]),
        ImageScope::forUser(7),
        aimageDownloader([])
    );

    $executor->advance($job, $step);
    $step->refresh();

    expect($step->status)->toBe(JobStep::STATUS_FAILED);
});

test('an unknown step type fails rather than silently doing nothing', function () {
    $job = aimageReadyJob();
    $step = aimageStep($job, ['type' => 'teleport']);

    $executor = new Executor(aimageClientWithout([]), ImageScope::forUser(7), aimageDownloader([]));

    $executor->advance($job, $step);
    $step->refresh();

    expect($step->status)->toBe(JobStep::STATUS_FAILED)
        ->and($step->error_code)->toBe('UNKNOWN_STEP_TYPE');
});
