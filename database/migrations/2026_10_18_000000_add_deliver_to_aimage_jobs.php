<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a finished job's images go besides the site (`Services\ResultDelivery`): an aMount
 * mount (its uuid) - a cloud drive, or one of the person's own computers - and a folder there.
 */
return new class extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        if (Schema::hasTable('aimage_jobs') && !Schema::hasColumn('aimage_jobs', 'deliver_to')) {
            Schema::table('aimage_jobs', function (Blueprint $table) {
                $table->string('deliver_to', 64)->default('');
                $table->string('deliver_folder', 191)->default('');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('aimage_jobs') && Schema::hasColumn('aimage_jobs', 'deliver_to')) {
            Schema::table('aimage_jobs', function (Blueprint $table) {
                $table->dropColumn(['deliver_to', 'deliver_folder']);
            });
        }
    }
};
