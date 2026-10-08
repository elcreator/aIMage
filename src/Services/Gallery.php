<?php

namespace Elcreator\aIMage\Services;

use Elcreator\aIMage\Support\ImageScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The bridge to sGallery (seiger/sgallery), the gallery that hangs images on a document.
 *
 * sGallery is optional and knows nothing about this package, so the contract is its
 * two tables and nothing else: `s_galleries` (one row per file, `parent` = document id)
 * and `s_gallery_fields` (texts per language, `key` = the file's row id). A file may sit
 * anywhere under the site root - sGallery reads `file` relative to it - so a result
 * written by the workbench is attached by path, never copied.
 *
 * Everything here answers "is it there?" first. A site without sGallery, or with an old
 * schema, gets `available() === false` and the workbench behaves exactly as before.
 */
final class Gallery
{
    /** sGallery's `alt` column is VARCHAR(100). */
    public const ALT_MAX = 100;

    public const DEFAULT_ITEM_TYPE = 'resource';
    public const DEFAULT_BLOCK = '1';
    public const BASE_LANG = 'base';

    /** Columns the bridge writes; an older sGallery lacking one is treated as absent. */
    private const GALLERY_COLUMNS = ['id', 'parent', 'block', 'item_type', 'position', 'file', 'type'];
    private const FIELD_COLUMNS = ['key', 'lang', 'alt'];

    public static function available(): bool
    {
        try {
            return Schema::hasTable('s_galleries') && Schema::hasTable('s_gallery_fields')
                && Schema::hasColumns('s_galleries', self::GALLERY_COLUMNS)
                && Schema::hasColumns('s_gallery_fields', self::FIELD_COLUMNS);
        } catch (\Throwable) {
            return false;
        }
    }

    // ------------------------------------------------------------------
    // Documents
    // ------------------------------------------------------------------

    /**
     * May this manager change the gallery of this document?
     *
     * The same rule the manager applies to documents: super administrators always; a
     * document in no document group is open to everyone; otherwise one of the
     * manager's groups has to be among the document's.
     */
    public static function canEdit(ImageScope $scope, int $documentId): bool
    {
        if ($documentId <= 0 || !Schema::hasTable('site_content')
            || !DB::table('site_content')->where('id', $documentId)->exists()) {
            return false;
        }
        if ($scope->isUnrestricted() || !Schema::hasTable('document_groups')) {
            return true;
        }

        $groups = DB::table('document_groups')->where('document', $documentId)->pluck('document_group')
            ->map(static fn ($id) => (int) $id)->all();

        return $groups === [] || array_intersect($groups, $scope->groupIds()) !== [];
    }

    public static function documentTitle(int $documentId): string
    {
        return (string) (Schema::hasTable('site_content')
            ? DB::table('site_content')->where('id', $documentId)->value('pagetitle') : '');
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    /**
     * The images of a document's gallery, in gallery order.
     *
     * @return array<int, array{id:int, file:string, item_type:string, parent:int}>
     */
    public static function images(int $documentId, string $itemType = self::DEFAULT_ITEM_TYPE, string $block = self::DEFAULT_BLOCK): array
    {
        return DB::table('s_galleries')
            ->where('parent', $documentId)->where('block', $block)->where('item_type', $itemType)->where('type', 'image')
            ->orderBy('position')->orderBy('id')
            ->get(['id', 'file', 'item_type', 'parent'])
            ->map(static fn ($row) => ['id' => (int) $row->id, 'file' => (string) $row->file,
                'item_type' => (string) $row->item_type, 'parent' => (int) $row->parent])
            ->all();
    }

    /**
     * Where one gallery image is, as a path the workbench may read - relative to the
     * manager's file root - or null when the file is missing or outside what they can see.
     * sGallery keeps uploads in `assets/sgallery/<item_type>/<document>/<file>` and
     * library picks as a path from the site root; both are tried, like sGallery does.
     */
    public static function scopePath(ImageScope $scope, array $image): ?string
    {
        $base = defined('EVO_BASE_PATH') ? (string) EVO_BASE_PATH : '';
        $file = ltrim((string) $image['file'], '/');
        if ($file === '' || str_contains($file, '..')) {
            return null;
        }

        foreach ([
            $base . 'assets/sgallery/' . $image['item_type'] . '/' . $image['parent'] . '/' . $file,
            $base . $file,
        ] as $absolute) {
            if (is_file($absolute) && ($relative = $scope->relativeOf($absolute)) !== null && $scope->canRead($relative)) {
                return $relative;
            }
        }

        return null;
    }

    /** A path in the manager's file root as the site root sees it - what sGallery stores - or null when outside the site. */
    public static function siteRelative(ImageScope $scope, string $scopePath): ?string
    {
        $absolute = $scope->absoluteOf($scopePath);
        $base = rtrim(str_replace('\\', '/', defined('EVO_BASE_PATH') ? (string) EVO_BASE_PATH : ''), '/') . '/';
        $absolute = $absolute === null ? null : str_replace('\\', '/', $absolute);

        return $absolute !== null && str_starts_with($absolute, $base) ? substr($absolute, strlen($base)) : null;
    }

    // ------------------------------------------------------------------
    // Writing
    // ------------------------------------------------------------------

    /**
     * Put a file in a document's gallery. Idempotent: the same file in the same
     * gallery is found, not added twice (a step that runs twice must not double it).
     *
     * @return array{id:int, added:bool}
     */
    public static function attach(
        int $documentId,
        string $siteRelativeFile,
        string $itemType = self::DEFAULT_ITEM_TYPE,
        string $block = self::DEFAULT_BLOCK,
        ?string $alt = null,
        string $lang = self::BASE_LANG
    ): array {
        $existing = DB::table('s_galleries')
            ->where('parent', $documentId)->where('block', $block)->where('item_type', $itemType)->where('file', $siteRelativeFile)
            ->value('id');
        if ($existing !== null) {
            return ['id' => (int) $existing, 'added' => false];
        }

        $position = (int) DB::table('s_galleries')
            ->where('parent', $documentId)->where('block', $block)->where('item_type', $itemType)->max('position');
        $hasAny = DB::table('s_galleries')
            ->where('parent', $documentId)->where('block', $block)->where('item_type', $itemType)->exists();

        $id = (int) DB::table('s_galleries')->insertGetId([
            'parent' => $documentId, 'block' => $block, 'item_type' => $itemType,
            'position' => $hasAny ? $position + 1 : 0, 'file' => $siteRelativeFile, 'type' => 'image',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // sGallery creates an empty text row for every file; the manager's form expects one.
        self::writeField($id, $lang, $alt === null ? '' : self::fitAlt($alt), true);

        return ['id' => $id, 'added' => true];
    }

    /**
     * Save alt text for a gallery image. An alt the manager (or an earlier run) wrote is
     * kept unless `$overwrite` - this must never quietly replace someone's own wording.
     *
     * @return string `saved`, `kept` (a text was already there) or `missing` (no such image)
     */
    public static function setAlt(int $imageId, string $lang, string $alt, bool $overwrite = false): string
    {
        if (!DB::table('s_galleries')->where('id', $imageId)->exists()) {
            return 'missing';
        }
        $alt = self::fitAlt($alt);
        if ($alt === '') {
            return 'kept';
        }
        $current = DB::table('s_gallery_fields')->where('key', $imageId)->where('lang', $lang)->value('alt');
        if (!$overwrite && trim((string) $current) !== '') {
            return 'kept';
        }
        self::writeField($imageId, $lang, $alt, false);

        return 'saved';
    }

    /** The alt text as sGallery can store it: at most 100 characters, cut at a word where possible. */
    public static function fitAlt(string $alt): string
    {
        $alt = trim(preg_replace('/\s+/u', ' ', $alt) ?? '');
        if (mb_strlen($alt) <= self::ALT_MAX) {
            return $alt;
        }
        $cut = mb_substr($alt, 0, self::ALT_MAX);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space >= 40 ? mb_substr($cut, 0, $space) : $cut, " ,;:-");
    }

    private static function writeField(int $imageId, string $lang, string $alt, bool $onlyIfMissing): void
    {
        $row = DB::table('s_gallery_fields')->where('key', $imageId)->where('lang', $lang);
        if ($row->exists()) {
            if (!$onlyIfMissing) {
                DB::table('s_gallery_fields')->where('key', $imageId)->where('lang', $lang)
                    ->update(['alt' => $alt, 'updated_at' => now()]);
            }

            return;
        }
        DB::table('s_gallery_fields')->insert([
            'key' => $imageId, 'lang' => $lang, 'alt' => $alt, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
