<?php

use Elcreator\aIMage\Agent\Tools;
use Elcreator\aIMage\Models\Job;
use Elcreator\aIMage\Models\JobStep;
use Elcreator\aIMage\Support\ImageScope;

/**
 * The boundary between what a language model says and what gets queued.
 *
 * Every path a tool receives is a string a model produced, so the interesting
 * cases are all the ones where the model is wrong: an invented path, a folder
 * the manager cannot write to, a size the chosen model does not accept, four
 * hundred images when the ceiling is two hundred. None of those may reach the
 * worker.
 */

beforeEach(fn () => aimageReset());

function aimageTools(Job $job, int $userId = 7): Tools
{
    return new Tools($job, ImageScope::forUser($userId), aimageCatalog(), aimageEstimator());
}

function aimageScopedJob(int $role = 1, array $groups = []): Job
{
    aimageUser(7, $role, $groups);
    aimageSetFileRoot('assets');

    return aimageJob(['user_id' => 7]);
}

// ---------------------------------------------------------------------------
// Definitions
// ---------------------------------------------------------------------------

test('every tool is declared with a name, a description and a schema', function () {
    $job = aimageScopedJob();

    foreach (aimageTools($job)->definitions() as $definition) {
        expect($definition['name'])->toBeString()->not->toBeEmpty()
            ->and($definition['description'])->toBeString()->not->toBeEmpty()
            ->and($definition['input_schema']['type'])->toBe('object');
    }
});

test('the declared tools are exactly the ones dispatch understands', function () {
    $job = aimageScopedJob();
    $declared = array_column(aimageTools($job)->definitions(), 'name');

    expect($declared)->toEqualCanonicalizing([
        Tools::LIST_IMAGES, Tools::LIST_FOLDERS,
        Tools::PLAN_GENERATE, Tools::PLAN_EDIT, Tools::PLAN_VARIATE, Tools::PLAN_UPSCALE,
        Tools::ASK_USER, Tools::FINISH,
    ]);
});

test('an unknown tool is reported back rather than throwing', function () {
    $job = aimageScopedJob();

    expect(aimageTools($job)->dispatch('no_such_tool', [])['content'])->toContain('no tool called');
});

// ---------------------------------------------------------------------------
// Reading
// ---------------------------------------------------------------------------

test('listing images shows only what the manager may see', function () {
    aimageUser(7, 3, [4]);
    aimageSetFileRoot('assets');
    aimagePutImage('images/open.png');
    aimagePutImage('images/private/secret.png');
    aimageRestrict('images/private', 9);

    $job = aimageJob(['user_id' => 7]);
    $result = aimageTools($job)->dispatch(Tools::LIST_IMAGES, ['folder' => 'images', 'recursive' => true]);

    expect($result['content'])->toContain('images/open.png')
        ->and($result['content'])->not->toContain('secret.png');
});

test('an empty folder says so instead of returning nothing useful', function () {
    $job = aimageScopedJob();

    expect(aimageTools($job)->dispatch(Tools::LIST_IMAGES, ['folder' => 'images'])['content'])
        ->toContain('No images');
});

// ---------------------------------------------------------------------------
// Generation
// ---------------------------------------------------------------------------

test('planning a generation appends a step and runs nothing', function () {
    $job = aimageScopedJob();

    $result = aimageTools($job)->dispatch(Tools::PLAN_GENERATE, ['prompt' => 'a mountain lake', 'count' => 3]);

    $step = JobStep::query()->where('job_id', $job->getKey())->first();

    // The model plans; the worker acts. Nothing here calls an image model, so
    // a plan can be priced before a cent is spent.
    expect($result['content'])->toContain('Queued generation of 3')
        ->and($step->type)->toBe(JobStep::TYPE_GENERATE)
        ->and($step->status)->toBe(JobStep::STATUS_QUEUED)
        ->and($step->prompt)->toBe('a mountain lake')
        ->and($step->param('n'))->toBe(3);
});

test('a generation with no prompt is refused', function () {
    $job = aimageScopedJob();

    expect(aimageTools($job)->dispatch(Tools::PLAN_GENERATE, ['prompt' => '  '])['content'])
        ->toContain('prompt is required')
        ->and(JobStep::query()->count())->toBe(0);
});

test('a model that cannot generate from text is refused with a reason', function () {
    $job = aimageScopedJob();
    $job->forceFill(['image_model' => 'whisper-1'])->save();

    expect(aimageTools($job)->dispatch(Tools::PLAN_GENERATE, ['prompt' => 'x'])['content'])
        ->toContain('cannot generate images from text')
        ->and(JobStep::query()->count())->toBe(0);
});

test('the image ceiling caps a plan instead of failing it', function () {
    $job = aimageScopedJob();

    config()->set('cms.settings.aIMage.limits.max_images_per_job', 5);

    $result = aimageTools($job)->dispatch(Tools::PLAN_GENERATE, ['prompt' => 'x', 'count' => 50]);

    expect($result['content'])->toContain('Queued generation of 5')
        ->and($result['content'])->toContain('reduced from 50')
        ->and(JobStep::query()->first()->param('n'))->toBe(5);

    config()->set('cms.settings.aIMage.limits.max_images_per_job', 200);
});

test('once the ceiling is reached nothing further is queued', function () {
    $job = aimageScopedJob();

    config()->set('cms.settings.aIMage.limits.max_images_per_job', 2);

    aimageTools($job)->dispatch(Tools::PLAN_GENERATE, ['prompt' => 'x', 'count' => 2]);
    $second = aimageTools($job)->dispatch(Tools::PLAN_GENERATE, ['prompt' => 'y', 'count' => 1]);

    expect($second['content'])->toContain('already reached its image limit')
        ->and(JobStep::query()->count())->toBe(1);

    config()->set('cms.settings.aIMage.limits.max_images_per_job', 200);
});

// ---------------------------------------------------------------------------
// Controls
// ---------------------------------------------------------------------------

test('a control the model accepts is carried onto the step', function () {
    $job = aimageScopedJob();

    aimageTools($job)->dispatch(Tools::PLAN_GENERATE, [
        'prompt' => 'x', 'size' => '1024x1024', 'quality' => 'low',
    ]);

    $params = JobStep::query()->first()->params();

    expect($params['size'])->toBe('1024x1024')
        ->and($params['quality'])->toBe('low');
});

test('a control the model rejects is refused with the legal values', function () {
    $job = aimageScopedJob();

    $result = aimageTools($job)->dispatch(Tools::PLAN_GENERATE, ['prompt' => 'x', 'size' => '999x999']);

    // Returned as a tool result rather than thrown, so the planner can correct
    // itself on the next turn instead of the job failing.
    expect($result['content'])->toContain('does not accept size="999x999"')
        ->and($result['content'])->toContain('1024x1024')
        ->and(JobStep::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Image-to-image
// ---------------------------------------------------------------------------

test('an edit queues one step per real image', function () {
    $job = aimageScopedJob();
    aimagePutImage('images/a.png');
    aimagePutImage('images/b.png');

    $result = aimageTools($job)->dispatch(Tools::PLAN_EDIT, [
        'paths' => ['images/a.png', 'images/b.png'],
        'prompt' => 'make the background transparent',
    ]);

    expect($result['content'])->toContain('for 2 image(s)')
        ->and(JobStep::query()->count())->toBe(2)
        ->and(JobStep::query()->pluck('source_path')->all())
        ->toEqualCanonicalizing(['images/a.png', 'images/b.png']);
});

test('a path the model invented is skipped and reported', function () {
    $job = aimageScopedJob();
    aimagePutImage('images/real.png');

    $result = aimageTools($job)->dispatch(Tools::PLAN_EDIT, [
        'paths' => ['images/real.png', 'images/imaginary.png'],
        'prompt' => 'x',
    ]);

    expect($result['content'])->toContain('Skipped 1 unusable path')
        ->and($result['content'])->toContain('images/imaginary.png')
        ->and(JobStep::query()->count())->toBe(1);
});

test('a path outside the manager\'s groups is skipped even though the file exists', function () {
    aimageUser(7, 3, [4]);
    aimageSetFileRoot('assets');
    aimagePutImage('images/private/secret.png');
    aimageRestrict('images/private', 9);

    $job = aimageJob(['user_id' => 7]);

    $result = aimageTools($job)->dispatch(Tools::PLAN_EDIT, [
        'paths' => ['images/private/secret.png'],
        'prompt' => 'x',
    ]);

    expect($result['content'])->toContain('Skipped 1')
        ->and(JobStep::query()->count())->toBe(0);
});

test('a traversal path is skipped', function () {
    $job = aimageScopedJob();

    $result = aimageTools($job)->dispatch(Tools::PLAN_EDIT, [
        'paths' => ['../../etc/passwd'],
        'prompt' => 'x',
    ]);

    expect($result['content'])->toContain('Skipped 1')
        ->and(JobStep::query()->count())->toBe(0);
});

test('an edit with no prompt is refused', function () {
    $job = aimageScopedJob();
    aimagePutImage('images/a.png');

    expect(aimageTools($job)->dispatch(Tools::PLAN_EDIT, ['paths' => ['images/a.png']])['content'])
        ->toContain('needs a prompt');
});

test('an empty path list is refused with a pointer to the listing tool', function () {
    $job = aimageScopedJob();

    expect(aimageTools($job)->dispatch(Tools::PLAN_EDIT, ['paths' => [], 'prompt' => 'x'])['content'])
        ->toContain(Tools::LIST_IMAGES);
});

test('an upscale is pinned to the gateway\'s fixed model, not the chosen one', function () {
    $job = aimageScopedJob();
    aimagePutImage('images/a.png');

    aimageTools($job)->dispatch(Tools::PLAN_UPSCALE, ['paths' => ['images/a.png'], 'scale' => 4]);

    $step = JobStep::query()->first();

    expect($step->model)->toBe(\Elcreator\aIMage\Gateway\ModelCatalog::UPSCALE_MODEL)
        ->and($step->param('scale'))->toBe(4);
});

test('an out-of-range upscale factor is clamped rather than refused', function () {
    $job = aimageScopedJob();
    aimagePutImage('images/a.png');

    aimageTools($job)->dispatch(Tools::PLAN_UPSCALE, ['paths' => ['images/a.png'], 'scale' => 99]);

    expect(JobStep::query()->first()->param('scale'))->toBe(4);
});

test('a variation carries its count', function () {
    $job = aimageScopedJob();
    // gpt-image-1 cannot variate, so a model that can is selected here. The
    // refusal for one that cannot is covered by the next test.
    $job->forceFill(['image_model' => 'flux1-fill-pro'])->save();
    aimagePutImage('images/a.png');

    aimageTools($job)->dispatch(Tools::PLAN_VARIATE, ['paths' => ['images/a.png'], 'count' => 3]);

    $step = JobStep::query()->first();

    expect($step->type)->toBe(JobStep::TYPE_VARIATE)
        ->and($step->expectedImages())->toBe(3);
});

test('a model that cannot variate is refused, and queues nothing', function () {
    $job = aimageScopedJob();
    aimagePutImage('images/a.png');

    // gpt-image-1 advertises textToImage and imagesAndTextToImage only. The
    // pairing is rejected at planning time rather than failing hours into a
    // batch.
    $result = aimageTools($job)->dispatch(Tools::PLAN_VARIATE, ['paths' => ['images/a.png']]);

    expect($result['content'])->toContain('does not support variate')
        ->and(JobStep::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Destinations
// ---------------------------------------------------------------------------

test('results default to the job\'s output folder', function () {
    $job = aimageScopedJob();

    aimageTools($job)->dispatch(Tools::PLAN_GENERATE, ['prompt' => 'x']);

    expect(JobStep::query()->first()->param('folder'))->toBe('aimage');
});

test('a folder the manager may not write to is refused', function () {
    aimageUser(7, 3, [4]);
    aimageSetFileRoot('assets');
    aimageRestrict('images/private', 9);

    $job = aimageJob(['user_id' => 7]);

    $result = aimageTools($job)->dispatch(Tools::PLAN_GENERATE, [
        'prompt' => 'x', 'folder' => 'images/private',
    ]);

    expect($result['content'])->toContain('may not write')
        ->and(JobStep::query()->count())->toBe(0);
});

test('a traversal folder is refused', function () {
    $job = aimageScopedJob();

    $result = aimageTools($job)->dispatch(Tools::PLAN_GENERATE, ['prompt' => 'x', 'folder' => '../escape']);

    expect($result['content'])->toContain('may not write')
        ->and(JobStep::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Control flow
// ---------------------------------------------------------------------------

test('asking the manager returns a control signal and the question', function () {
    $job = aimageScopedJob();

    $result = aimageTools($job)->dispatch(Tools::ASK_USER, [
        'question' => 'Which folder?',
        'options' => ['products', 'banners'],
    ]);

    expect($result['control'])->toBe(Tools::ASK_USER)
        ->and($result['question'])->toBe('Which folder?')
        ->and($result['options'])->toBe(['products', 'banners']);
});

test('finishing with nothing queued is refused, so a job cannot end as a conversation', function () {
    $job = aimageScopedJob();

    $result = aimageTools($job)->dispatch(Tools::FINISH, ['summary' => 'all done']);

    // This plugin only produces images. A plan with no steps has not done its
    // job however confidently the model says it has.
    expect($result)->not->toHaveKey('control')
        ->and($result['content'])->toContain('Nothing has been queued');
});

test('finishing with queued steps signals completion', function () {
    $job = aimageScopedJob();
    aimageTools($job)->dispatch(Tools::PLAN_GENERATE, ['prompt' => 'x']);

    $result = aimageTools($job)->dispatch(Tools::FINISH, ['summary' => 'generate one image']);

    expect($result['control'])->toBe(Tools::FINISH)
        ->and($result['summary'])->toBe('generate one image');
});

// ---------------------------------------------------------------------------
// Pricing the plan
// ---------------------------------------------------------------------------

test('a plan is priced from its real steps', function () {
    $job = aimageScopedJob();
    $tools = aimageTools($job);

    $tools->dispatch(Tools::PLAN_GENERATE, [
        'prompt' => 'x', 'count' => 4, 'quality' => 'low', 'size' => '1024x1024',
    ]);

    // 4 × the low/1024 tier, resolved by variant rather than by the model's
    // headline price.
    expect($tools->estimatePlan()->amount)->toBeGreaterThan(0.039)
        ->and($tools->estimatePlan()->amount)->toBeLessThan(0.041);
});

test('a mixed plan sums generation and upscaling', function () {
    $job = aimageScopedJob();
    aimagePutImage('images/a.png');

    $tools = aimageTools($job);
    $tools->dispatch(Tools::PLAN_GENERATE, ['prompt' => 'x', 'quality' => 'low', 'size' => '1024x1024']);
    $tools->dispatch(Tools::PLAN_UPSCALE, ['paths' => ['images/a.png']]);

    $estimate = $tools->estimatePlan();

    expect($estimate->amount)->toBeGreaterThan(0.03)
        ->and($estimate->etaP50)->toBeGreaterThan(0);
});

test('an empty plan prices as nothing rather than as unknown', function () {
    $job = aimageScopedJob();

    expect(aimageTools($job)->estimatePlan()->amount)->toBeNull();
});
