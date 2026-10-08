<?php

use Elcreator\aIMage\Agent\Executor;
use Elcreator\aIMage\Agent\Tools;
use Elcreator\aIMage\Models\Job;
use Elcreator\aIMage\Models\JobStep;
use Elcreator\aIMage\Support\ImageScope;

/**
 * Image analysis (alt text, colours, focal point) and the look options of a generation:
 * file format, negative prompt, reference images.
 */

beforeEach(fn () => aimageReset());

function aimageOptionsJob(): Job
{
    aimageUser(7, 1);
    aimageSetFileRoot('assets');

    return aimageJob(['user_id' => 7, 'status' => Job::STATUS_RUNNING]);
}

function aimageOptionsTools(Job $job): Tools
{
    return new Tools($job, ImageScope::forUser(7), aimageCatalog(), aimageEstimator());
}

// ---------------------------------------------------------------------------
// Analysis
// ---------------------------------------------------------------------------

test('planning an analysis queues one describe step per readable image, with language and context', function () {
    $job = aimageOptionsJob();
    aimagePutImage('images/chair.png');

    $result = aimageOptionsTools($job)->dispatch(Tools::PLAN_ANALYZE, [
        'paths' => ['images/chair.png', 'images/nope.png'],
        'lang' => 'SK',
        'context' => 'product: oak chair',
    ]);

    $steps = JobStep::query()->where('job_id', $job->getKey())->get();
    expect($steps)->toHaveCount(1)
        ->and($steps[0]->type)->toBe(JobStep::TYPE_DESCRIBE)
        ->and($steps[0]->source_path)->toBe('images/chair.png')
        ->and($steps[0]->prompt)->toBe('product: oak chair')
        ->and($steps[0]->param('lang'))->toBe('sk')
        ->and($steps[0]->param('want'))->toBe(['alt', 'focal', 'colors'])
        ->and($result['content'])->toContain('Queued analysis')->toContain('images/nope.png');
});

test('an analysis step uploads the image privately, asks for the parts and keeps the answer on the step', function () {
    $job = aimageOptionsJob();
    aimagePutImage('images/chair.png');
    $step = aimageStep($job, [
        'type' => JobStep::TYPE_DESCRIBE, 'model' => '', 'prompt' => 'product: oak chair',
        'source_path' => 'images/chair.png', 'params_json' => ['lang' => 'sk', 'want' => ['alt', 'focal', 'colors']],
    ]);
    $history = [];

    (new Executor(
        aimageRecordingClient([
            aimageJsonResponse(['id' => 'up-1', 'object' => 'upload'], 201),
            aimageJsonResponse([
                'object' => 'image.analysis', 'alt' => ['text' => 'Dubová stolička', 'lang' => 'sk'], 'focal' => ['x' => 0.4, 'y' => 0.6, 'source' => 'saliency'],
                'colors' => [['hex' => '#aa8855', 'share' => 0.5]], 'missing' => [], 'notes' => [], 'run_id' => 12,
            ]),
        ], $history),
        ImageScope::forUser(7),
        aimageDownloader([])
    ))->advance($job, $step);

    $step->refresh();
    expect((string) $history[0]['request']->getUri())->toContain('uploads')
        ->and((string) $history[1]['request']->getUri())->toContain('images/analyze')
        ->and(aimageSentJson($history, 1))->toBe([
            'upload' => 'up-1', 'want' => ['alt', 'focal', 'colors'], 'lang' => 'sk', 'context' => 'product: oak chair',
        ])
        ->and($history[1]['request']->getHeaderLine('Idempotency-Key'))->toBe($step->idempotencyKey())
        ->and($step->status)->toBe(JobStep::STATUS_SUCCEEDED)
        ->and($step->target_path)->toBe('')
        ->and($step->result_json['analysis']['alt'])->toBe('Dubová stolička')
        ->and($step->result_json['analysis']['focal']['x'])->toBe(0.4)
        ->and($step->result_json['analysis']['colors'][0]['hex'])->toBe('#aa8855');
});

test('a part the gateway could not measure stays listed as missing', function () {
    $job = aimageOptionsJob();
    aimagePutImage('images/a.png');
    $step = aimageStep($job, ['type' => JobStep::TYPE_DESCRIBE, 'model' => '', 'source_path' => 'images/a.png',
        'params_json' => ['lang' => 'en', 'want' => ['alt']]]);
    $history = [];

    (new Executor(
        aimageRecordingClient([
            aimageJsonResponse(['id' => 'up-2'], 201),
            aimageJsonResponse(['object' => 'image.analysis', 'alt' => null, 'missing' => ['alt'], 'notes' => ['alt: model failed']]),
        ], $history),
        ImageScope::forUser(7),
        aimageDownloader([])
    ))->advance($job, $step);

    $analysis = $step->refresh()->result_json['analysis'];
    expect($analysis)->not->toHaveKey('alt')
        ->and($analysis['missing'])->toBe(['alt']);
});

test('an analysis of a missing file fails the step without calling the gateway', function () {
    $job = aimageOptionsJob();
    $step = aimageStep($job, ['type' => JobStep::TYPE_DESCRIBE, 'model' => '', 'source_path' => 'images/gone.png', 'params_json' => []]);
    $history = [];

    (new Executor(aimageRecordingClient([], $history), ImageScope::forUser(7), aimageDownloader([])))->advance($job, $step);

    expect($step->refresh()->status)->toBe(JobStep::STATUS_FAILED)
        ->and($step->error_code)->toBe('SOURCE_UNAVAILABLE')
        ->and($history)->toBe([]);
});

// ---------------------------------------------------------------------------
// Format, negative prompt, references
// ---------------------------------------------------------------------------

test('a generation plan keeps the format, the negative prompt and validated references', function () {
    $job = aimageOptionsJob();
    aimagePutImage('images/style.png');

    $tools = aimageOptionsTools($job);
    $tools->dispatch(Tools::PLAN_GENERATE, [
        'prompt' => 'a lake', 'output_format' => 'JPG', 'negative_prompt' => ' text, watermark ',
        'references' => ['images/style.png'], 'reference_role' => 'subject',
    ]);

    $step = JobStep::query()->where('job_id', $job->getKey())->first();
    expect($step->param('output_format'))->toBe('jpeg')
        ->and($step->param('negative_prompt'))->toBe('text, watermark')
        ->and($step->param('references'))->toBe([['path' => 'images/style.png', 'role' => 'subject']]);
});

test('an unusable format or reference is refused at planning time, not hours later', function () {
    $job = aimageOptionsJob();
    $tools = aimageOptionsTools($job);

    expect($tools->dispatch(Tools::PLAN_GENERATE, ['prompt' => 'x', 'output_format' => 'bmp'])['content'])->toContain('output_format')
        ->and($tools->dispatch(Tools::PLAN_GENERATE, ['prompt' => 'x', 'references' => ['images/ghost.png']])['content'])->toContain('not a readable image');

    foreach (['a', 'b', 'c', 'd', 'e'] as $n) {
        aimagePutImage("images/$n.png");
    }
    $tooMany = $tools->dispatch(Tools::PLAN_GENERATE, ['prompt' => 'x', 'references' => array_map(fn ($n) => "images/$n.png", ['a', 'b', 'c', 'd', 'e'])]);
    expect($tooMany['content'])->toContain('At most 4')
        ->and(JobStep::query()->where('job_id', $job->getKey())->count())->toBe(0);
});

test('a generation sends webp, the negative prompt and each reference as a private upload', function () {
    $job = aimageOptionsJob();
    aimagePutImage('images/style.png');
    $step = aimageStep($job, ['params_json' => [
        'n' => 1, 'folder' => 'aimage', 'output_format' => 'webp', 'negative_prompt' => 'text',
        'references' => [['path' => 'images/style.png', 'role' => 'style']],
    ]]);
    $history = [];

    (new Executor(
        aimageRecordingClient([aimageJsonResponse(['id' => 'ref-1'], 201), aimageJsonResponse(['taskId' => 't-1'])], $history),
        ImageScope::forUser(7),
        aimageDownloader([])
    ))->advance($job, $step);

    expect((string) $history[0]['request']->getUri())->toContain('uploads');
    $sent = aimageSentJson($history, 1);
    expect($sent['options'])->toMatchArray([
        'outputFormat' => 'webp', 'negativePrompt' => 'text', 'strict' => false,
        'references' => [['upload' => 'ref-1', 'role' => 'style']],
    ]);
});

test('an edit carries the format and negative prompt as options too', function () {
    $job = aimageOptionsJob();
    aimagePutImage('images/a.png');
    $step = aimageStep($job, ['type' => JobStep::TYPE_EDIT, 'source_path' => 'images/a.png', 'prompt' => 'brighter',
        'params_json' => ['folder' => 'aimage', 'output_format' => 'webp', 'negative_prompt' => 'blur']]);
    $history = [];

    (new Executor(
        aimageRecordingClient([aimageJsonResponse(['taskId' => 't-2'])], $history),
        ImageScope::forUser(7),
        aimageDownloader([])
    ))->advance($job, $step);

    $body = (string) $history[0]['request']->getBody();
    expect($body)->toContain('"outputFormat":"webp"')->toContain('"negativePrompt":"blur"');
});
