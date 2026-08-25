<?php

use Elcreator\aIMage\Models\Job;
use Elcreator\aIMage\Models\JobStep;
use Elcreator\aIMage\Models\Message;
use EvolutionCMS\Models\SystemCliTask;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;

/**
 * Shared setup for the database-backed suite.
 *
 * The AIMage tables are built by running this package's **real migrations**
 * rather than by a copy of their schema kept here. A duplicated schema drifts
 * silently — a column renamed in the migration keeps passing against the copy
 * — and the migration is itself something worth exercising. The CMS tables the
 * package reads are declared below, because those belong to the core and only
 * the columns this package touches matter.
 */

/** A real 1×1 PNG, so `getimagesizefromstring()` recognises fixtures as images. */
function aimagePng(): string
{
    return base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    );
}

function aimageBoot(): void
{
    static $booted = false;

    if ($booted) {
        return;
    }

    $booted = true;

    $capsule = new Capsule();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    Model::setConnectionResolver($capsule->getDatabaseManager());

    // The migrations reach for the Schema and DB facades, so give them a
    // container to resolve against.
    $container = new Container();
    $container->instance('db', $capsule->getDatabaseManager());
    $container->instance('db.schema', $capsule->schema());
    Container::setInstance($container);
    Facade::setFacadeApplication($container);

    aimageCreateCmsTables($capsule);
    aimageRunPackageMigrations();
}

/**
 * The core tables AIMage reads, reduced to the columns it actually uses.
 */
function aimageCreateCmsTables(Capsule $capsule): void
{
    $schema = $capsule->schema();

    $schema->create('user_attributes', function (Blueprint $table) {
        $table->increments('id');
        $table->unsignedInteger('internalKey');
        $table->integer('role')->default(0);
        $table->string('fullname', 100)->default('');
    });

    $schema->create('user_settings', function (Blueprint $table) {
        $table->unsignedInteger('user');
        $table->string('setting_name', 50);
        $table->text('setting_value')->nullable();
    });

    $schema->create('system_settings', function (Blueprint $table) {
        $table->string('setting_name', 50)->primary();
        $table->text('setting_value')->nullable();
    });

    $schema->create('member_groups', function (Blueprint $table) {
        $table->increments('id');
        $table->unsignedInteger('user_group')->default(0);
        $table->unsignedInteger('member')->default(0);
    });

    $schema->create('membergroup_access', function (Blueprint $table) {
        $table->increments('id');
        $table->unsignedInteger('membergroup')->default(0);
        $table->unsignedInteger('documentgroup')->default(0);
    });

    // The heart of the file permission model: a path, and the group that may
    // reach it. Restrictions are inherited by everything beneath the path.
    $schema->create('file_groups', function (Blueprint $table) {
        $table->increments('id');
        $table->unsignedInteger('document_group')->default(0);
        $table->string('file', 500)->default('');
    });

    $schema->create('system_cli_tasks', function (Blueprint $table) {
        $table->increments('id');
        $table->string('uuid', 36)->unique();
        $table->string('type', 64)->default('');
        $table->string('target', 191)->default('');
        $table->string('requested_version', 191)->default('');
        $table->string('status', 32)->default('queued');
        $table->string('step', 64)->default('');
        $table->unsignedSmallInteger('progress')->default(0);
        $table->string('message', 255)->default('');
        $table->text('payload_json')->nullable();
        $table->text('result_json')->nullable();
        $table->unsignedInteger('created_by')->nullable();
        $table->string('locked_by', 191)->default('');
        $table->unsignedInteger('attempt_count')->default(0);
        $table->dateTime('lease_expires_at')->nullable();
        $table->string('worker_host', 191)->default('');
        $table->integer('worker_pid')->nullable();
        $table->string('error_code', 64)->default('');
        $table->string('catalog_snapshot_hash', 64)->default('');
        $table->text('requested_by_snapshot')->nullable();
        $table->dateTime('started_at')->nullable();
        $table->dateTime('heartbeat_at')->nullable();
        $table->dateTime('cancellation_requested_at')->nullable();
        $table->dateTime('finished_at')->nullable();
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();
    });

    $schema->create('system_cli_task_logs', function (Blueprint $table) {
        $table->increments('id');
        $table->unsignedInteger('task_id');
        $table->unsignedInteger('seq')->default(0);
        $table->string('level', 16)->default('info');
        $table->string('step', 64)->default('');
        $table->text('message');
        $table->text('context_json')->nullable();
        $table->dateTime('created_at')->nullable();
    });
}

/**
 * Run this package's migrations for real.
 *
 * Only the table migration: the permission one writes to `permissions` and
 * `role_permissions`, which belong to the core's ACL and are not what this
 * suite is about. It guards every write with `Schema::hasTable()`, so it is a
 * no-op here anyway.
 */
function aimageRunPackageMigrations(): void
{
    $migration = require dirname(__DIR__) . '/database/migrations/2026_08_24_000001_create_aimage_tables.php';
    $migration->up();
}

/**
 * Put the world back between tests.
 */
function aimageReset(): void
{
    foreach ([
        'aimage_messages', 'aimage_job_steps', 'aimage_jobs',
        'user_attributes', 'user_settings', 'system_settings',
        'member_groups', 'membergroup_access', 'file_groups',
        'system_cli_tasks', 'system_cli_task_logs',
    ] as $table) {
        if (Schema::hasTable($table)) {
            Capsule::table($table)->delete();
        }
    }

    AIMageTestCore::reset();

    // The file-manager root, emptied and rebuilt.
    aimageRemoveTree(AIMAGE_TEST_ROOT . '/assets');
    aimageEnsureDir(AIMAGE_TEST_ROOT . '/assets/images');

    // The catalogue is file-cached; a stale one would leak between tests.
    foreach (glob(EVO_STORAGE_PATH . '/aimage/*.json') ?: [] as $cached) {
        @unlink($cached);
    }

    \Elcreator\aIMage\Support\ApiKeys::flush();
}

/** mkdir -p that stays quiet when the directory is already there. */
function aimageEnsureDir(string $path): void
{
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
}

function aimageRemoveTree(string $path): void
{
    if (!is_dir($path)) {
        @unlink($path);
        return;
    }

    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        aimageRemoveTree($path . '/' . $entry);
    }

    @rmdir($path);
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

/**
 * A manager, with a role and any document groups they belong to.
 *
 * Role 1 is the super administrator, who is exempt from file groups — the same
 * rule the core's file manager applies.
 */
function aimageUser(int $id, int $role = 3, array $documentGroups = []): int
{
    Capsule::table('user_attributes')->insert([
        'internalKey' => $id,
        'role' => $role,
        'fullname' => 'Manager ' . $id,
    ]);

    foreach ($documentGroups as $index => $group) {
        $userGroup = 100 + $group;

        Capsule::table('member_groups')->insert([
            'user_group' => $userGroup,
            'member' => $id,
        ]);

        Capsule::table('membergroup_access')->insert([
            'membergroup' => $userGroup,
            'documentgroup' => $group,
        ]);
    }

    return $id;
}

/** Point the file manager at the fixture tree. */
function aimageSetFileRoot(string $relative = 'assets', ?int $forUser = null): void
{
    $value = '[(base_path)]' . trim($relative, '/');

    if ($forUser === null) {
        Capsule::table('system_settings')->insert([
            'setting_name' => 'filemanager_path',
            'setting_value' => $value,
        ]);

        return;
    }

    Capsule::table('user_settings')->insert([
        'user' => $forUser,
        'setting_name' => 'filemanager_path',
        'setting_value' => $value,
    ]);
}

function aimageSetting(string $name, string $value): void
{
    Capsule::table('system_settings')->insert([
        'setting_name' => $name,
        'setting_value' => $value,
    ]);
}

/** Write a real image into the fixture tree, at a path relative to the file root. */
function aimagePutImage(string $relative, string $root = 'assets'): string
{
    $absolute = AIMAGE_TEST_ROOT . '/' . trim($root, '/') . '/' . ltrim($relative, '/');
    aimageEnsureDir(dirname($absolute));
    file_put_contents($absolute, aimagePng());

    return $absolute;
}

/** Restrict a path to a document group, as the file manager's group editor does. */
function aimageRestrict(string $relative, int $documentGroup): void
{
    Capsule::table('file_groups')->insert([
        'document_group' => $documentGroup,
        'file' => trim($relative, '/'),
    ]);
}

/** A job in a given state, with sane defaults for everything else. */
function aimageJob(array $attributes = []): Job
{
    return Job::query()->create($attributes + [
        'uuid' => (string) Illuminate\Support\Str::uuid(),
        'user_id' => 7,
        'status' => Job::STATUS_PLANNING,
        'title' => 'Test batch',
        'text_model' => 'claude-sonnet-5',
        'image_model' => 'gpt-image-1',
        'voice_model' => 'whisper-1',
        'controls_json' => [],
        'output_folder' => 'aimage',
        'planner_turns' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function aimageStep(Job $job, array $attributes = []): JobStep
{
    $seq = (int) JobStep::query()->where('job_id', $job->getKey())->max('seq');

    return JobStep::query()->create($attributes + [
        'job_id' => $job->getKey(),
        'seq' => $seq + 1,
        'type' => JobStep::TYPE_GENERATE,
        'status' => JobStep::STATUS_QUEUED,
        'model' => 'gpt-image-1',
        'prompt' => 'a lake',
        'params_json' => ['n' => 1, 'folder' => 'aimage'],
        'source_path' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** The `system_cli_tasks` row a queued job is carried by. */
function aimageTaskFor(Job $job): SystemCliTask
{
    return SystemCliTask::query()->where('target', $job->uuid)->orderByDesc('id')->firstOrFail();
}

// ---------------------------------------------------------------------------
// Gateway doubles
// ---------------------------------------------------------------------------

/**
 * A `GET /models` snapshot captured from the live gateway.
 *
 * Real data rather than a hand-written stub, because the thing most worth
 * testing about the catalogue — variant pricing — only has teeth against the
 * shapes the gateway actually emits.
 */
function aimageModelsJson(): string
{
    return file_get_contents(__DIR__ . '/fixtures/models.json');
}

/**
 * A gateway client backed by canned responses.
 *
 * @param array<int, \GuzzleHttp\Psr7\Response|\Throwable> $responses served in order
 */
function aimageClient(array $responses = []): \Elcreator\aIMage\Gateway\Client
{
    $queue = array_merge(
        // Every catalogue-using path asks for the model list first.
        [new \GuzzleHttp\Psr7\Response(200, [], aimageModelsJson())],
        $responses
    );

    $stack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler($queue));

    return new \Elcreator\aIMage\Gateway\Client(
        'test-key',
        'https://ai.artur.work/api/v1',
        new \GuzzleHttp\Client(['handler' => $stack, 'http_errors' => false])
    );
}

/**
 * A gateway client whose queue is exactly what the caller passed.
 *
 * Unlike aimageClient(), nothing is prepended: callers that never touch the
 * catalogue — the Executor, for one — would otherwise consume a models.json
 * response as though it were their result.
 *
 * @param array<int, \GuzzleHttp\Psr7\Response|\Throwable> $responses
 */
function aimageClientWithout(array $responses): \Elcreator\aIMage\Gateway\Client
{
    $stack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler($responses));

    return new \Elcreator\aIMage\Gateway\Client(
        "test-key",
        "https://ai.artur.work/api/v1",
        new \GuzzleHttp\Client(["handler" => $stack, "http_errors" => false])
    );
}
/** A catalogue over the fixture snapshot, with no live network behind it. */
function aimageCatalog(): \Elcreator\aIMage\Gateway\ModelCatalog
{
    $catalog = new \Elcreator\aIMage\Gateway\ModelCatalog(aimageClient());
    $catalog->snapshot(true);

    return $catalog;
}

function aimageEstimator(): \Elcreator\aIMage\Gateway\Estimator
{
    return new \Elcreator\aIMage\Gateway\Estimator(aimageCatalog());
}

/** A JSON gateway response. */
function aimageJsonResponse(array $body, int $status = 200): \GuzzleHttp\Psr7\Response
{
    return new \GuzzleHttp\Psr7\Response($status, ['Content-Type' => 'application/json'], json_encode($body));
}

/** A response carrying image bytes, as a CDN download would. */
function aimageImageResponse(): \GuzzleHttp\Psr7\Response
{
    return new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'image/png'], aimagePng());
}

/**
 * An HTTP client for the Executor's *downloads*, separate from the gateway.
 *
 * @param array<int, \GuzzleHttp\Psr7\Response|\Throwable> $responses
 */
function aimageDownloader(array $responses): \GuzzleHttp\Client
{
    $stack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler($responses));

    return new \GuzzleHttp\Client(['handler' => $stack, 'http_errors' => false]);
}

// ---------------------------------------------------------------------------

aimageBoot();
