<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separate the transcript from the conversation.
 *
 * Every row in `aimage_messages` has to reach the model, or a resumed planning
 * turn replays a conversation that never happened. Not every row is addressed
 * to the manager, and two kinds were being shown to them as though they were:
 *
 *  - the nudge the planner sends when a model answers in prose instead of
 *    calling a tool. It is written as a `user` row, because that is the role
 *    the model has to see it in — and so it rendered in the thread as words
 *    the manager had typed;
 *  - the prose that earned the nudge, which is not an answer but a misfire
 *    being corrected, and which routinely carries the model's own attempt at
 *    tool-call syntax in it.
 *
 * A flag rather than a role, because the role is what the dialect encodes and
 * it must stay truthful to the model.
 */
return new class extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        if (Schema::hasTable('aimage_messages') && !Schema::hasColumn('aimage_messages', 'internal')) {
            Schema::table('aimage_messages', function (Blueprint $table) {
                $table->boolean('internal')->default(false)->after('role');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('aimage_messages') && Schema::hasColumn('aimage_messages', 'internal')) {
            Schema::table('aimage_messages', function (Blueprint $table) {
                $table->dropColumn('internal');
            });
        }
    }
};
