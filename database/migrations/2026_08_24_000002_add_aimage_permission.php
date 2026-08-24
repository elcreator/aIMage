<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The `aimage` permission, and its assignment to the administrator role.
 *
 * This gates the module page and every route. It is deliberately separate from
 * the file-group machinery: holding `aimage` says a manager may use the
 * workbench at all, while which images they may touch inside it is still
 * decided by `file_groups`, exactly as it is in the file manager.
 */
return new class extends Migration {
    public $withinTransaction = false;

    private const PERMISSION = 'aimage';
    private const GROUP = 'AIMage';

    public function up(): void
    {
        if (!Schema::hasTable('permissions_groups') || !Schema::hasTable('permissions')) {
            return;
        }

        $groupId = $this->getOrCreateGroup();
        $this->upsertPermission($groupId);
        $this->assignToAdmin();
    }

    public function down(): void
    {
        if (Schema::hasTable('role_permissions')) {
            DB::table('role_permissions')
                ->where('permission', self::PERMISSION)
                ->delete();
        }

        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->where('key', self::PERMISSION)->delete();
        }

        if (Schema::hasTable('permissions_groups')) {
            $group = DB::table('permissions_groups')->where('name', self::GROUP)->first();

            if ($group) {
                $stillUsed = Schema::hasTable('permissions')
                    && DB::table('permissions')->where('group_id', $group->id)->exists();

                if (!$stillUsed) {
                    DB::table('permissions_groups')->where('id', $group->id)->delete();
                }
            }
        }
    }

    private function getOrCreateGroup(): int
    {
        $group = DB::table('permissions_groups')->where('name', self::GROUP)->first();

        if ($group) {
            return (int) $group->id;
        }

        try {
            return (int) DB::table('permissions_groups')->insertGetId([
                'name' => self::GROUP,
                'lang_key' => 'aIMage::global.permissions_group',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            // A concurrent migration, or a PostgreSQL sequence out of step.
            $group = DB::table('permissions_groups')->where('name', self::GROUP)->first();

            if ($group) {
                return (int) $group->id;
            }

            throw $e;
        }
    }

    private function upsertPermission(int $groupId): void
    {
        $attributes = [
            'name' => 'Use AIMage',
            'lang_key' => 'aIMage::global.permission_access',
            'group_id' => $groupId,
            'disabled' => 0,
            'updated_at' => now(),
        ];

        if (DB::table('permissions')->where('key', self::PERMISSION)->exists()) {
            DB::table('permissions')->where('key', self::PERMISSION)->update($attributes);

            return;
        }

        try {
            DB::table('permissions')->insert($attributes + [
                'key' => self::PERMISSION,
                'created_at' => now(),
            ]);
        } catch (QueryException $e) {
            DB::table('permissions')->where('key', self::PERMISSION)->update($attributes);
        }
    }

    private function assignToAdmin(): void
    {
        if (!Schema::hasTable('role_permissions')) {
            return;
        }

        $exists = DB::table('role_permissions')
            ->where('role_id', 1)
            ->where('permission', self::PERMISSION)
            ->exists();

        if ($exists) {
            return;
        }

        try {
            DB::table('role_permissions')->insert([
                'role_id' => 1,
                'permission' => self::PERMISSION,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            // Already there — a concurrent run won the race.
        }
    }
};
