<?php

use Elcreator\aIMage\Agent\Executor;
use Elcreator\aIMage\Agent\Tools;
use Elcreator\aIMage\Models\Job;
use Elcreator\aIMage\Models\JobStep;
use Elcreator\aIMage\Services\Gallery;
use Elcreator\aIMage\Support\ImageScope;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bridge to sGallery. The suite has no sGallery, so these tests build its two tables
 * (as its own migrations do) and the two core document tables the bridge reads, and
 * drop them again: everything else in the suite must keep seeing a site without one.
 */

function aimageGalleryTables(): void
{
    $schema = Capsule::schema();
    $schema->create('s_galleries', function (Blueprint $table) {
        $table->increments('id');
        $table->integer('parent')->index();
        $table->string('block', 64)->default('1');
        $table->integer('position')->default(0);
        $table->string('file', 256);
        $table->string('type', 10)->default('image');
        $table->string('item_type', 64)->default('resource');
        $table->timestamps();
    });
    $schema->create('s_gallery_fields', function (Blueprint $table) {
        $table->increments('id');
        $table->integer('key')->index();
        $table->string('lang', 4)->default('base');
        $table->string('alt', 100)->default('');
        $table->string('title', 100)->default('');
        $table->timestamps();
    });
    $schema->create('site_content', function (Blueprint $table) {
        $table->increments('id');
        $table->string('pagetitle', 255)->default('');
    });
    $schema->create('document_groups', function (Blueprint $table) {
        $table->increments('id');
        $table->unsignedInteger('document_group')->default(0);
        $table->unsignedInteger('document')->default(0);
    });
    Capsule::table('site_content')->insert(['id' => 12, 'pagetitle' => 'Oak chair']);
}

function aimageGalleryJob(int $role = 1, array $groups = []): Job
{
    aimageUser(7, $role, $groups);
    aimageSetFileRoot('assets');

    return aimageJob(['user_id' => 7, 'status' => Job::STATUS_RUNNING]);
}

function aimageGalleryTools(Job $job): Tools
{
    return new Tools($job, ImageScope::forUser(7), aimageCatalog(), aimageEstimator());
}

beforeEach(function () {
    aimageReset();
    foreach (['s_galleries', 's_gallery_fields', 'site_content', 'document_groups'] as $table) {
        Schema::dropIfExists($table);
    }
});

afterEach(function () {
    foreach (['s_galleries', 's_gallery_fields', 'site_content', 'document_groups'] as $table) {
        Schema::dropIfExists($table);
    }
});

// ---------------------------------------------------------------------------
// Detection
// ---------------------------------------------------------------------------

test('a site without sGallery is detected, and the gallery tools are not offered', function () {
    $job = aimageGalleryJob();

    expect(Gallery::available())->toBeFalse()
        ->and(array_column(aimageGalleryTools($job)->definitions(), 'name'))->not->toContain(Tools::PLAN_GALLERY_ALT)
        ->and(aimageGalleryTools($job)->dispatch(Tools::PLAN_GALLERY_ALT, ['document_id' => 12])['content'])->toContain('no sGallery')
        ->and(aimageGalleryTools($job)->dispatch(Tools::PLAN_GENERATE, ['prompt' => 'x', 'gallery_document_id' => 12])['content'])->toContain('no sGallery');
});

test('with the sGallery tables present the gallery is available and the planner is offered it', function () {
    aimageGalleryTables();
    $job = aimageGalleryJob();
    $names = array_column(aimageGalleryTools($job)->definitions(), 'name');
    $generate = collect(aimageGalleryTools($job)->definitions())->firstWhere('name', Tools::PLAN_GENERATE);

    expect(Gallery::available())->toBeTrue()
        ->and($names)->toContain(Tools::PLAN_GALLERY_ALT)
        ->and($generate['input_schema']['properties'])->toHaveKey('gallery_document_id');
});

test('an older sGallery missing a column the bridge writes counts as absent', function () {
    aimageGalleryTables();
    Capsule::schema()->table('s_galleries', fn (Blueprint $t) => $t->dropColumn('block'));

    expect(Gallery::available())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Results into a document's gallery
// ---------------------------------------------------------------------------

test('a generated result is attached to the document gallery by path, with an empty text row', function () {
    aimageGalleryTables();
    $job = aimageGalleryJob();
    $tools = aimageGalleryTools($job);
    $tools->dispatch(Tools::PLAN_GENERATE, ['prompt' => 'a chair', 'gallery_document_id' => 12, 'gallery_item_type' => 'product']);
    $step = JobStep::query()->where('job_id', $job->getKey())->first();

    expect($step->param('gallery'))->toBe(['document_id' => 12, 'item_type' => 'product', 'block' => '1']);

    (new Executor(
        aimageClientWithout([aimageJsonResponse(['data' => [['url' => 'https://cdn.test/a.png']]])]),
        ImageScope::forUser(7),
        aimageDownloader([aimageImageResponse()])
    ))->advance($job, $step);

    $row = Capsule::table('s_galleries')->first();
    expect($step->refresh()->status)->toBe(JobStep::STATUS_SUCCEEDED)
        ->and($row->parent)->toBe(12)
        ->and($row->item_type)->toBe('product')
        ->and($row->file)->toBe('assets/aimage/aimage.png')
        ->and($row->type)->toBe('image')
        ->and($row->position)->toBe(0)
        ->and(Capsule::table('s_gallery_fields')->where('key', $row->id)->value('alt'))->toBe('')
        ->and($step->result_json['gallery'])->toBe(['document_id' => 12, 'item_ids' => [(int) $row->id]]);
});

test('attaching the same file twice leaves one row; the next file goes after it', function () {
    aimageGalleryTables();

    $first = Gallery::attach(12, 'assets/a.png');
    $again = Gallery::attach(12, 'assets/a.png');
    $second = Gallery::attach(12, 'assets/b.png');

    expect($again)->toBe(['id' => $first['id'], 'added' => false])
        ->and(Capsule::table('s_galleries')->count())->toBe(2)
        ->and((int) Capsule::table('s_galleries')->where('id', $second['id'])->value('position'))->toBe(1);
});

test('a gallery that cannot be written never fails a step that already produced the image', function () {
    aimageGalleryTables();
    $job = aimageGalleryJob();
    $step = aimageStep($job, ['params_json' => ['n' => 1, 'folder' => 'aimage', 'gallery' => ['document_id' => 999]]]);

    (new Executor(
        aimageClientWithout([aimageJsonResponse(['data' => [['url' => 'https://cdn.test/a.png']]])]),
        ImageScope::forUser(7),
        aimageDownloader([aimageImageResponse()])
    ))->advance($job, $step);

    expect($step->refresh()->status)->toBe(JobStep::STATUS_SUCCEEDED)
        ->and($step->result_json['gallery'])->toBe(['document_id' => 999, 'error' => 'gallery_forbidden'])
        ->and(Capsule::table('s_galleries')->count())->toBe(0);
});

test('a manager outside a document\'s groups may not plan into its gallery', function () {
    aimageGalleryTables();
    Capsule::table('document_groups')->insert(['document_group' => 5, 'document' => 12]);
    $job = aimageGalleryJob(3, [6]);

    expect(Gallery::canEdit(ImageScope::forUser(7), 12))->toBeFalse()
        ->and(aimageGalleryTools($job)->dispatch(Tools::PLAN_GENERATE, ['prompt' => 'x', 'gallery_document_id' => 12])['content'])->toContain('may not edit')
        ->and(JobStep::query()->count())->toBe(0);

    Capsule::table('membergroup_access')->insert(['membergroup' => 1, 'documentgroup' => 5]);
    Capsule::table('member_groups')->insert(['user_group' => 1, 'member' => 7]);
    expect(Gallery::canEdit(ImageScope::forUser(7), 12))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Alt text into the gallery
// ---------------------------------------------------------------------------

test('alt text planned for a gallery covers each readable image and skips the missing ones', function () {
    aimageGalleryTables();
    aimagePutImage('sgallery/resource/12/a.png');
    $job = aimageGalleryJob();
    Capsule::table('s_galleries')->insert([
        ['parent' => 12, 'file' => 'a.png', 'position' => 0, 'created_at' => now(), 'updated_at' => now()],
        ['parent' => 12, 'file' => 'gone.png', 'position' => 1, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $result = aimageGalleryTools($job)->dispatch(Tools::PLAN_GALLERY_ALT, ['document_id' => 12, 'lang' => 'sk']);

    $steps = JobStep::query()->where('job_id', $job->getKey())->get();
    expect($steps)->toHaveCount(1)
        ->and($steps[0]->type)->toBe(JobStep::TYPE_DESCRIBE)
        ->and($steps[0]->source_path)->toBe('sgallery/resource/12/a.png')
        ->and($steps[0]->prompt)->toBe('Oak chair')
        ->and($steps[0]->param('want'))->toBe(['alt'])
        ->and($steps[0]->param('lang'))->toBe('sk')
        ->and($steps[0]->param('gallery'))->toMatchArray(['document_id' => 12, 'lang' => 'base', 'overwrite' => false])
        ->and($result['content'])->toContain('Skipped 1');
});

function aimageRunGalleryAlt(Job $job, JobStep $step, string $alt): void
{
    (new Executor(
        aimageClientWithout([
            aimageJsonResponse(['id' => 'up-1'], 201),
            aimageJsonResponse(['object' => 'image.analysis', 'alt' => ['text' => $alt, 'lang' => 'en'], 'missing' => [], 'notes' => []]),
        ]),
        ImageScope::forUser(7),
        aimageDownloader([])
    ))->advance($job, $step);
}

test('the analysed alt text lands in the gallery row\'s text, within 100 characters', function () {
    aimageGalleryTables();
    aimagePutImage('sgallery/resource/12/a.png');
    $job = aimageGalleryJob();
    $item = Gallery::attach(12, 'a.png')['id'];
    $step = aimageStep($job, ['type' => JobStep::TYPE_DESCRIBE, 'model' => '', 'source_path' => 'sgallery/resource/12/a.png',
        'params_json' => ['lang' => 'en', 'want' => ['alt'], 'gallery' => ['document_id' => 12, 'item_id' => $item, 'lang' => 'base', 'overwrite' => false]]]);

    aimageRunGalleryAlt($job, $step, str_repeat('An oak dining chair with a woven seat ', 5));

    $alt = (string) Capsule::table('s_gallery_fields')->where('key', $item)->where('lang', 'base')->value('alt');
    expect($step->refresh()->status)->toBe(JobStep::STATUS_SUCCEEDED)
        ->and(mb_strlen($alt))->toBeLessThanOrEqual(100)
        ->and($alt)->toStartWith('An oak dining chair')
        ->and($alt)->not->toEndWith(' ')
        ->and($step->result_json['gallery'])->toBe(['item_id' => $item, 'alt' => 'saved']);
});

test('an alt text a person already wrote is kept unless the plan says to overwrite', function () {
    aimageGalleryTables();
    aimagePutImage('sgallery/resource/12/a.png');
    $job = aimageGalleryJob();
    $item = Gallery::attach(12, 'a.png', 'resource', '1', 'My own wording')['id'];
    $params = fn (bool $overwrite) => ['lang' => 'en', 'want' => ['alt'],
        'gallery' => ['document_id' => 12, 'item_id' => $item, 'lang' => 'base', 'overwrite' => $overwrite]];
    $base = ['type' => JobStep::TYPE_DESCRIBE, 'model' => '', 'source_path' => 'sgallery/resource/12/a.png'];

    $keep = aimageStep($job, $base + ['params_json' => $params(false)]);
    aimageRunGalleryAlt($job, $keep, 'Machine wording');
    expect(Capsule::table('s_gallery_fields')->where('key', $item)->value('alt'))->toBe('My own wording')
        ->and($keep->refresh()->result_json['gallery']['alt'])->toBe('kept');

    $replace = aimageStep($job, $base + ['params_json' => $params(true)]);
    aimageRunGalleryAlt($job, $replace, 'Machine wording');
    expect(Capsule::table('s_gallery_fields')->where('key', $item)->value('alt'))->toBe('Machine wording');
});

test('alt text is stored under the requested gallery language without touching the base text', function () {
    aimageGalleryTables();
    $item = Gallery::attach(12, 'a.png', 'resource', '1', 'Base text')['id'];

    expect(Gallery::setAlt($item, 'uk', 'Дубовий стілець'))->toBe('saved')
        ->and(Capsule::table('s_gallery_fields')->where('key', $item)->where('lang', 'base')->value('alt'))->toBe('Base text')
        ->and(Capsule::table('s_gallery_fields')->where('key', $item)->where('lang', 'uk')->value('alt'))->toBe('Дубовий стілець')
        ->and(Gallery::setAlt(99999, 'base', 'x'))->toBe('missing');
});
