<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The three tables AIMage owns.
 *
 * The job *queue* is not among them — that is `system_cli_tasks`, through the
 * task registry, so leases, cancellation, worker health and the scheduler are
 * inherited rather than rebuilt. What lives here is the work itself: what the
 * manager asked for, the plan a model produced, and each image operation's
 * own state, which the queue has no vocabulary for.
 */
return new class extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasTable('aimage_jobs')) {
            Schema::create('aimage_jobs', function (Blueprint $table) {
                $prefix = DB::getTablePrefix() . $table->getTable();

                $table->increments('id');
                $table->string('uuid', 36)->unique("{$prefix}_uuid");

                // Whose scope and whose API key. Every file decision and every
                // gateway call for this job is made as this user, including in
                // a worker that has no session.
                $table->unsignedInteger('user_id')->index("{$prefix}_user_id");

                $table->string('status', 32)->default('planning')->index("{$prefix}_status");
                $table->string('title', 191)->default('');

                $table->string('text_model', 191)->default('');
                $table->string('image_model', 191)->default('');
                $table->string('voice_model', 191)->default('');

                // Defaults the plan inherits: size, quality, background.
                $table->text('controls_json')->nullable();
                $table->string('output_folder', 191)->default('');

                // The cost and ETA quoted when the job was approved, kept so
                // predicted and actual can be compared later. Never
                // overwritten once the manager has agreed to it.
                $table->text('estimate_json')->nullable();

                $table->unsignedSmallInteger('planner_turns')->default(0);
                $table->unsignedInteger('steps_total')->default(0);
                $table->unsignedInteger('steps_done')->default(0);
                $table->unsignedInteger('steps_failed')->default(0);

                // The system task currently carrying this job forward. One
                // slice of work per task; a job that needs more re-queues.
                $table->unsignedInteger('system_task_id')->nullable()->index("{$prefix}_system_task_id");

                $table->string('error_code', 64)->default('');
                $table->string('message', 255)->default('');
                $table->text('result_json')->nullable();

                $table->dateTime('approved_at')->nullable();
                $table->dateTime('finished_at')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();

                $table->index(['user_id', 'status'], "{$prefix}_user_status");
            });
        }

        if (!Schema::hasTable('aimage_job_steps')) {
            Schema::create('aimage_job_steps', function (Blueprint $table) {
                $prefix = DB::getTablePrefix() . $table->getTable();

                $table->increments('id');
                $table->unsignedInteger('job_id')->index("{$prefix}_job_id");
                $table->unsignedInteger('seq')->default(0);

                // generate | edit | variate | upscale | describe
                $table->string('type', 32)->default('generate');
                $table->string('status', 32)->default('queued')->index("{$prefix}_status");

                $table->string('model', 191)->default('');
                $table->text('prompt')->nullable();
                $table->text('params_json')->nullable();

                // Both relative to the manager's file-manager root, never
                // absolute: an absolute path in a queue row is a path that
                // stops being true when the site moves.
                $table->string('source_path', 500)->default('');
                $table->string('target_path', 500)->default('');

                // Set when the provider answered {taskId} instead of a result.
                // The step then parks in `polling` and a later slice checks it,
                // which is what keeps a worker from sleeping on a provider.
                $table->string('provider_task_id', 191)->default('');
                $table->string('provider_model', 191)->default('');

                $table->unsignedSmallInteger('attempt_count')->default(0);
                $table->string('error_code', 64)->default('');
                $table->string('message', 500)->default('');
                $table->text('result_json')->nullable();

                $table->dateTime('started_at')->nullable();
                $table->dateTime('finished_at')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();

                $table->index(['job_id', 'status'], "{$prefix}_job_status");
                $table->index(['job_id', 'seq'], "{$prefix}_job_seq");
            });
        }

        if (!Schema::hasTable('aimage_messages')) {
            Schema::create('aimage_messages', function (Blueprint $table) {
                $prefix = DB::getTablePrefix() . $table->getTable();

                $table->increments('id');
                $table->unsignedInteger('job_id')->index("{$prefix}_job_id");
                $table->unsignedInteger('seq')->default(0);

                // user | assistant | tool
                $table->string('role', 16)->default('user');
                $table->longText('text')->nullable();

                // The planner's tool calls and their results, kept in the
                // canonical shape Gateway\Dialect encodes from, so a resumed
                // conversation replays identically on either dialect.
                $table->text('tool_calls_json')->nullable();
                $table->text('tool_results_json')->nullable();

                // Set when the turn arrived as speech rather than typing.
                $table->string('audio_path', 500)->default('');

                $table->dateTime('created_at')->nullable();

                $table->index(['job_id', 'seq'], "{$prefix}_job_seq");
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('aimage_messages');
        Schema::dropIfExists('aimage_job_steps');
        Schema::dropIfExists('aimage_jobs');
    }
};
