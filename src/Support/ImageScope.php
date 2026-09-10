<?php

namespace Elcreator\aIMage\Support;

use EvolutionCMS\Models\MemberGroup;
use EvolutionCMS\Models\SystemSetting;
use EvolutionCMS\Models\UserAttribute;
use EvolutionCMS\Models\UserSetting;
use EvolutionCMS\Support\FileManagerAccess;

/**
 * What one manager is allowed to see and change, resolved without a session.
 *
 * Evolution CMS already answers this question — `file_groups` rows keyed by a
 * path relative to the file-manager root, checked against the user's document
 * groups, inherited down the tree — and `EvolutionCMS\Support\FileManagerAccess`
 * implements it as pure functions. What it does *not* have is a way to ask it
 * about somebody who is not the current request's logged-in user: every helper
 * in `core/functions/actions/files.php` reads `$_SESSION` (`mgrRole`,
 * `mgrDocgroups`), and a worker running a queued batch at three in the morning
 * has no session at all.
 *
 * So this class rebuilds the same facts from the database for a given user id
 * and then defers to the core's own logic for the actual verdict. It is
 * deliberately not a second permission model — every allow/deny decision below
 * ends in a `FileManagerAccess` call, so a change to the core's rules is
 * inherited rather than re-implemented here.
 */
final class ImageScope
{
    /**
     * Evolution's conventional images directory, under the image browser root.
     *
     * Not a setting — see `imageBase()`.
     */
    private const IMAGES_DIR = 'images';

    /**
     * Paths the CMS treats as its own, never as a place for content.
     *
     * The same list `manager/actions/files.dynamic.php` builds, minus the
     * permission gates. The file manager asks "may this person edit plugins?";
     * a results folder asks something narrower, and the answer is no for
     * everybody — an AI-generated image belongs in `assets/cache` or
     * `assets/plugins` under no permission at all.
     *
     * Relative to the site root, so they are compared after mapping through
     * the manager's own file root.
     */
    private const SYSTEM_PATHS = [
        'assets/backup',
        'assets/cache',
        'assets/export',
        'assets/import',
        'assets/modules',
        'assets/plugins',
        'assets/snippets',
        'assets/templates',
        // Shipped with the distribution rather than filled by anyone: they are
        // not places a manager keeps pictures, and listing them as candidate
        // destinations is the same noise as listing `core`.
        'assets/captcha',
        'assets/docs',
        'assets/fonts',
        'assets/js',
        'temp',
        'core',
        'install',
        'views',
    ];

    /** The document groups this user belongs to. Empty for an unrestricted user. */
    private array $groupIds;

    /** Cached `file_groups` restrictions, keyed by relative path. */
    private array $restrictions = [];

    private array $restrictionsLoadedFor = [];

    private function __construct(
        private readonly int $userId,
        private readonly int $role,
        private readonly string $root,
        private readonly bool $permissionsEnabled,
        array $groupIds
    ) {
        $this->groupIds = $groupIds;
    }

    public static function forUser(int $userId): self
    {
        $role = (int) UserAttribute::query()->where('internalKey', $userId)->value('role');

        // The same join UserLogin uses to fill $_SESSION['mgrDocgroups'].
        // Reproduced rather than reused because the core exposes it only as a
        // side effect of logging somebody in.
        $groupIds = MemberGroup::query()
            ->join('membergroup_access', 'membergroup_access.membergroup', '=', 'member_groups.user_group')
            ->where('member_groups.member', $userId)
            ->pluck('documentgroup')
            ->map(static fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        return new self(
            $userId,
            $role,
            static::resolveRoot($userId),
            static::permissionsEnabled(),
            $groupIds
        );
    }

    public function userId(): int
    {
        return $this->userId;
    }

    /** Absolute file-manager root, forward slashes, no trailing slash. */
    public function root(): string
    {
        return $this->root;
    }

    /**
     * Role 1 is the super administrator, who is exempt from file groups.
     *
     * This mirrors `fileManagerUserGroupIds()` and every core call site: the
     * check is on the role id, not on a permission, so it must stay that way
     * here or an administrator would find folders missing that the file
     * manager shows them.
     */
    public function isUnrestricted(): bool
    {
        return !$this->permissionsEnabled || $this->role === 1;
    }

    /** @return int[] */
    public function groupIds(): array
    {
        return $this->groupIds;
    }

    // ------------------------------------------------------------------
    // Access decisions
    // ------------------------------------------------------------------

    /**
     * May this manager see this path?
     *
     * A path with no `file_groups` row anywhere along its ancestry is visible
     * to everyone — that is the core's rule, and it is why an unconfigured
     * site behaves as it always did.
     */
    public function canRead(?string $relative): bool
    {
        if ($this->isUnrestricted()) {
            return true;
        }

        return FileManagerAccess::isAccessible(
            $relative,
            $this->groupIds,
            $this->restrictionsFor($relative)
        );
    }

    /**
     * May this manager write to this path?
     *
     * Stricter than reading in one specific way, inherited from
     * `canModifyExistingPath()`: a top-level entry — something sitting
     * directly in the file-manager root — may never be modified by a
     * restricted user, however the groups fall. Results therefore land inside
     * a sub-folder, never loose in the root.
     */
    public function canWrite(?string $relative): bool
    {
        $relative = FileManagerAccess::normalizeRelativePath($relative);

        if ($relative === '') {
            return false;
        }

        if ($this->isUnrestricted()) {
            return true;
        }

        return FileManagerAccess::canModifyExistingPath(
            $relative,
            $this->groupIds,
            $this->restrictionsFor($relative)
        );
    }

    /**
     * Restrictions covering one path, loaded once per ancestry.
     *
     * A batch touches hundreds of files under a handful of folders, so this
     * memo is the difference between one query per job and one per image.
     */
    private function restrictionsFor(?string $relative): array
    {
        $relative = FileManagerAccess::normalizeRelativePath($relative);

        if (!isset($this->restrictionsLoadedFor[$relative])) {
            $this->restrictions += FileManagerAccess::loadRestrictions([$relative]);
            $this->restrictionsLoadedFor[$relative] = true;
        }

        return $this->restrictions;
    }

    /** Warm the memo for a whole listing in one query. */
    public function preloadRestrictions(array $relativePaths): void
    {
        $relativePaths = array_values(array_filter(array_map(
            static fn ($path) => FileManagerAccess::normalizeRelativePath($path),
            $relativePaths
        )));

        if ($relativePaths === [] || $this->isUnrestricted()) {
            return;
        }

        $this->restrictions += FileManagerAccess::loadRestrictions($relativePaths);

        foreach ($relativePaths as $path) {
            $this->restrictionsLoadedFor[$path] = true;
        }
    }

    // ------------------------------------------------------------------
    // Paths
    // ------------------------------------------------------------------

    /**
     * Turn a relative path into an absolute one, or null if it escapes.
     *
     * Traversal is refused by rejecting `.` and `..` segments outright rather
     * than by normalising them away, because a normaliser is a thing that can
     * be wrong once and wrong forever. For paths that already exist, realpath
     * containment is checked as well — that is the half that catches a symlink
     * pointing out of the tree, which segment inspection alone cannot see.
     */
    public function absoluteOf(?string $relative): ?string
    {
        $relative = FileManagerAccess::normalizeRelativePath($relative);

        if ($relative === '') {
            return $this->root;
        }

        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }

            // A NUL byte truncates the path inside any C-level filesystem call.
            if (str_contains($segment, "\0")) {
                return null;
            }
        }

        $candidate = $this->root . '/' . $relative;

        if (file_exists($candidate)) {
            $real = realpath($candidate);

            if ($real === false || !$this->isInsideRoot($real)) {
                return null;
            }

            return str_replace('\\', '/', $real);
        }

        // The path does not exist yet — a result about to be written, or a
        // folder about to be created. Its nearest existing ancestor still has
        // to be inside the root, and finding it means climbing until something
        // resolves: `123/45/x.png` under a manager who has neither folder yet
        // has no existing parent at all, and stopping at `dirname()` would
        // check nothing and let a symlinked `123` lead the write out of the
        // tree.
        $parent = dirname($candidate);

        while (true) {
            $realParent = realpath($parent);

            if ($realParent !== false) {
                return $this->isInsideRoot($realParent) ? $candidate : null;
            }

            $next = dirname($parent);

            // Climbed past the filesystem root without resolving anything.
            // Not reachable from a path built on our own root, which exists,
            // but a loop with no floor is worse than a redundant guard.
            if ($next === $parent) {
                return null;
            }

            $parent = $next;
        }
    }

    /** The relative path of an absolute one, or null when it is outside the root. */
    public function relativeOf(string $absolute): ?string
    {
        $relative = FileManagerAccess::getRelativePath($this->root, $absolute);

        return $relative === '' ? null : $relative;
    }

    private function isInsideRoot(string $realPath): bool
    {
        return static::isInside($realPath, $this->root);
    }

    /** Inside the ceiling results may be written under — never merely the file root. */
    private function isInsideWriteRoot(string $path): bool
    {
        return static::isInside($path, $this->writeRoot());
    }

    private static function isInside(string $path, string $root): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');

        return $root !== '' && ($path === $root || str_starts_with($path, $root . '/'));
    }

    /**
     * A public URL for a path, or null when the file is not web-reachable.
     *
     * Null is a real answer, not a failure: a file manager rooted outside the
     * document root has no URL for its contents, and the upscale endpoint —
     * which takes a URL rather than an upload — has to know that instead of
     * being handed a broken link.
     */
    public function publicUrl(?string $relative): ?string
    {
        $absolute = $this->absoluteOf($relative);

        if ($absolute === null || !defined('EVO_BASE_PATH')) {
            return null;
        }

        $basePath = rtrim(str_replace('\\', '/', EVO_BASE_PATH), '/');

        if (!str_starts_with($absolute, $basePath . '/')) {
            return null;
        }

        $webPath = substr($absolute, strlen($basePath) + 1);
        $baseUrl = rtrim((string) evo()->getConfig('site_url', '/'), '/');

        return $baseUrl . '/' . implode('/', array_map('rawurlencode', explode('/', $webPath)));
    }

    // ------------------------------------------------------------------
    // Listing
    // ------------------------------------------------------------------

    /**
     * Image files this manager may see under a folder.
     *
     * @param string $relativeDir folder relative to the root; '' is the root
     * @param bool $recursive walk sub-folders too
     * @param int $limit hard cap, so a job cannot be pointed at a tree with
     *                   fifty thousand files and blow memory building a plan
     * @return array<int, array{path:string,name:string,size:int,modified:int,url:?string}>
     */
    public function listImages(string $relativeDir = '', bool $recursive = false, int $limit = 500): array
    {
        $absolute = $this->absoluteOf($relativeDir);

        if ($absolute === null || !is_dir($absolute) || !$this->canRead($relativeDir)) {
            return [];
        }

        $extensions = $this->allowedExtensions();
        $found = [];

        $this->walk($absolute, $relativeDir, $recursive, $extensions, $found, $limit);

        // One query for the whole listing rather than one per file.
        $this->preloadRestrictions(array_column($found, 'path'));

        $visible = [];

        foreach ($found as $file) {
            if (!$this->canRead($file['path'])) {
                continue;
            }

            $file['url'] = $this->publicUrl($file['path']);
            $visible[] = $file;
        }

        return $visible;
    }

    private function walk(
        string $absoluteDir,
        string $relativeDir,
        bool $recursive,
        array $extensions,
        array &$found,
        int $limit
    ): void {
        if (count($found) >= $limit) {
            return;
        }

        $entries = @scandir($absoluteDir);

        if ($entries === false) {
            return;
        }

        sort($entries);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            if (count($found) >= $limit) {
                return;
            }

            $childAbsolute = $absoluteDir . '/' . $entry;
            $childRelative = $relativeDir === '' ? $entry : $relativeDir . '/' . $entry;

            if (is_dir($childAbsolute)) {
                if ($recursive) {
                    $this->walk($childAbsolute, $childRelative, true, $extensions, $found, $limit);
                }
                continue;
            }

            $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));

            if (!in_array($extension, $extensions, true)) {
                continue;
            }

            $found[] = [
                'path' => $childRelative,
                'name' => $entry,
                'size' => (int) @filesize($childAbsolute),
                'modified' => (int) @filemtime($childAbsolute),
                'url' => null,
            ];
        }
    }

    /**
     * Folders this manager may see under a folder, for the picker.
     *
     * @return array<int, array{path:string,name:string}>
     */
    public function listFolders(string $relativeDir = ''): array
    {
        // These are candidate destinations, so the listing starts where
        // results go, not at the file-manager root. Rooted at the root, an
        // unconfined manager is offered `core`, `manager` and `views` — the
        // CMS's own directories, which are not somewhere anybody puts a
        // generated image.
        if (FileManagerAccess::normalizeRelativePath($relativeDir) === '') {
            $relativeDir = $this->imageBase();
        }

        $absolute = $this->absoluteOf($relativeDir);

        if ($absolute === null || !is_dir($absolute) || !$this->canRead($relativeDir)) {
            return [];
        }

        $entries = @scandir($absolute) ?: [];
        sort($entries);

        $candidates = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            if (!is_dir($absolute . '/' . $entry)) {
                continue;
            }

            $candidates[] = $relativeDir === '' ? $entry : $relativeDir . '/' . $entry;
        }

        $this->preloadRestrictions($candidates);

        $folders = [];

        foreach ($candidates as $path) {
            if (!$this->canRead($path) || $this->isSystemPath($path)) {
                continue;
            }

            $folders[] = ['path' => $path, 'name' => basename($path)];
        }

        return $folders;
    }

    /**
     * Is this one of the CMS's own directories?
     *
     * Resolved through the manager's file root rather than compared as a
     * string, because the root moves: `plugins` means `assets/plugins` to an
     * unconfined manager and something entirely innocent to one confined to
     * `assets/clients/456`.
     */
    public function isSystemPath(?string $relative): bool
    {
        $absolute = $this->absoluteOf($relative);

        if ($absolute === null) {
            return true;
        }

        foreach (static::systemPaths() as $system) {
            if (static::isInside($absolute, $system)) {
                return true;
            }
        }

        return false;
    }

    /** @return string[] absolute, forward slashes, no trailing slash */
    private static function systemPaths(): array
    {
        $base = defined('EVO_BASE_PATH') ? rtrim(str_replace('\\', '/', EVO_BASE_PATH), '/') : '';

        if ($base === '') {
            return [];
        }

        $paths = array_map(static fn (string $path): string => $base . '/' . $path, static::SYSTEM_PATHS);

        // The manager directory is renameable, and a site that has renamed it
        // for obscurity would otherwise find the new name listed as a folder
        // results may be written to.
        if (defined('EVO_MANAGER_PATH')) {
            $paths[] = rtrim(str_replace('\\', '/', EVO_MANAGER_PATH), '/');
        }

        return $paths;
    }

    // ------------------------------------------------------------------
    // Writing
    // ------------------------------------------------------------------

    /**
     * Extensions a result may be written with.
     *
     * The intersection of this package's list and the CMS's own
     * `upload_images`, so a site that has narrowed what may be uploaded has
     * also narrowed what this may create. A CMS setting that is empty or
     * unreadable falls back to the package list rather than to "everything".
     */
    public function allowedExtensions(): array
    {
        $ours = Config::allowedExtensions();
        $cms = trim((string) $this->setting('upload_images', ''));

        if ($cms === '') {
            return $ours;
        }

        $cmsList = array_values(array_filter(array_map(
            static fn ($e) => ltrim(strtolower(trim((string) $e)), '.'),
            explode(',', $cms)
        )));

        if ($cmsList === []) {
            return $ours;
        }

        $intersection = array_values(array_intersect($ours, $cmsList));

        return $intersection === [] ? $ours : $intersection;
    }

    /** The folder results are written to by default, relative to the root. */
    public function outputFolder(): string
    {
        return $this->resolveWriteFolder(Config::outputFolder()) ?? '';
    }

    /**
     * A destination folder for results, as a path relative to the file-manager
     * root — or null when it cannot be made into one.
     *
     * Everything this job writes is confined to the **write root**, which is
     * the narrower of two things: the manager's own file-manager root, and the
     * image browser's root. A folder that is not already inside it is placed
     * inside it rather than refused, so a manager who asks for "123/45" gets
     * `123` and `45` created under the write root and the result written
     * there, whether or not either folder existed a moment ago.
     *
     * Both halves of that ceiling matter and neither substitutes for the other:
     *
     *  - The **file-manager root** is the permission boundary. A manager
     *    confined by `filemanager_path` is already below the browser's root,
     *    so their write root is their own folder and results land there, never
     *    in the site-wide images folder they cannot see.
     *  - The **image browser root** (`rb_base_dir`, `assets/` by default) is
     *    what the *Insert image* dialog can actually see. Without it, an
     *    unconfined manager — whose file root is the whole site — could have
     *    results written to `core/` or `manager/`, where nothing can use them.
     *
     * Traversal is still refused outright rather than clamped: `absoluteOf()`
     * rejects `..` instead of normalising it, so a folder that tries to climb
     * out is an error, not a silently rewritten path.
     */
    public function resolveWriteFolder(?string $folder): ?string
    {
        $folder = FileManagerAccess::normalizeRelativePath(str_replace('\\', '/', (string) $folder));
        $ceiling = $this->writeRootRelative();
        $base = $this->imageBase();

        if ($folder === '') {
            $folder = $base;
        } elseif (!static::isUnder($folder, $ceiling)) {
            // Not a path within the ceiling, so it is a name to create rather
            // than a place that already means something: "123/45" from a
            // manager becomes `<image base>/123/45`. A folder that *is* within
            // the ceiling — `assets/products`, or anything the picker returned
            // — is a real destination and is left exactly as given.
            $folder = ($base === '' ? '' : $base . '/') . $folder;
        }

        if ($folder === '') {
            return null;
        }

        // The final word on containment. Anything with a `..` segment, a NUL
        // byte, or a symlinked ancestor pointing out of the tree dies here.
        $absolute = $this->absoluteOf($folder);

        if ($absolute === null || !$this->isInsideWriteRoot($absolute)) {
            return null;
        }

        // A path within the ceiling is passed through untouched, which is what
        // makes `assets/products` work — and would equally make
        // `assets/plugins` work. The CMS's own directories are not somewhere a
        // generated image goes, whatever the manager's permissions say.
        return $this->isSystemPath($folder) ? null : $folder;
    }

    /** Is a relative path at or below a relative prefix? An empty prefix is the root, so everything is. */
    private static function isUnder(string $path, string $prefix): bool
    {
        return $prefix === '' || $path === $prefix || str_starts_with($path, $prefix . '/');
    }

    /**
     * The folder above this one, or null when it is the ceiling.
     *
     * Browsing stops at the write root rather than at the file-manager root.
     * Above the ceiling there is nothing a result could be written to, so
     * offering the climb would only produce folders that are refused on
     * arrival.
     */
    public function parentFolder(?string $relative): ?string
    {
        $relative = FileManagerAccess::normalizeRelativePath($relative);
        $ceiling = $this->writeRootRelative();

        if ($relative === '' || $relative === $ceiling) {
            return null;
        }

        $parent = str_contains($relative, '/') ? substr($relative, 0, strrpos($relative, '/')) : '';

        return static::isUnder($parent, $ceiling) ? $parent : null;
    }

    /**
     * Everything a preview needs about one image.
     *
     * Dimensions are read here rather than in `listImages()` because
     * `getimagesize()` opens the file: once, for the image somebody clicked,
     * is a different proposition from five hundred times for a folder nobody
     * has looked at yet.
     */
    public function imageInfo(string $relative): ?array
    {
        $relative = FileManagerAccess::normalizeRelativePath($relative);

        if (!$this->canRead($relative)) {
            return null;
        }

        $absolute = $this->absoluteOf($relative);

        if ($absolute === null || !is_file($absolute)) {
            return null;
        }

        $extension = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));

        if (!in_array($extension, $this->allowedExtensions(), true)) {
            return null;
        }

        $size = @getimagesize($absolute) ?: [];

        return [
            'path' => $relative,
            'name' => basename($relative),
            'folder' => $this->parentOf($relative),
            'url' => $this->publicUrl($relative),
            'bytes' => (int) @filesize($absolute),
            'modified' => (int) @filemtime($absolute),
            // Null rather than 0 for anything GD cannot measure — an SVG, or a
            // file whose extension lies about what it is. The panel says
            // "unknown" instead of claiming a resolution of nothing.
            'width' => isset($size[0]) ? (int) $size[0] : null,
            'height' => isset($size[1]) ? (int) $size[1] : null,
            'mime' => isset($size['mime']) ? (string) $size['mime'] : null,
        ];
    }

    private function parentOf(string $relative): string
    {
        return str_contains($relative, '/') ? substr($relative, 0, strrpos($relative, '/')) : '';
    }

    /** Absolute path of the write root — the ceiling every result stays under. */
    public function writeRoot(): string
    {
        $prefix = $this->writeRootRelative();

        return $prefix === '' ? $this->root : $this->root . '/' . $prefix;
    }

    /**
     * Where images live by default, relative to the file-manager root.
     *
     * The ceiling and the base are different questions. The ceiling is
     * security — `assets/`, because that is what the *Insert image* dialog can
     * see, and a manager who explicitly asks for `assets/products` should get
     * it. The base is convention: Evolution's own images directory is
     * `assets/images`. The installer refuses to finish without it, and
     * `HelperProcessor` and `LegacyDeleteService` both special-case it, but it
     * is a convention rather than a setting — there is no `rb_images_dir` to
     * read — so it is checked for rather than assumed.
     *
     * Only the site-wide root gets the `images` suffix. A manager confined to
     * `assets/clients/456` is already in their own image area; sending their
     * results to `assets/clients/456/images` because a folder of that name
     * happens to exist would be reading a coincidence as an instruction.
     */
    public function imageBase(): string
    {
        $writeRoot = $this->writeRoot();

        if ($writeRoot !== static::resolveBrowserRoot()) {
            return $this->writeRootRelative();
        }

        $candidate = $writeRoot . '/' . static::IMAGES_DIR;

        if (!is_dir($candidate)) {
            return $this->writeRootRelative();
        }

        return FileManagerAccess::getRelativePath($this->root, $candidate);
    }

    /**
     * The write root as a path relative to this manager's file-manager root.
     * `''` when the two coincide, which is every confined manager.
     *
     * The manager has two file roots and they routinely disagree. The Files
     * page uses `filemanager_path`, falling back to the site root; KCFinder —
     * the browser TinyMCE's *Insert image* dialog opens — uses `rb_base_dir`,
     * which ships as `[(base_path)]assets/`. On a default install, where
     * `filemanager_path` is empty, a result written to `<root>/aimage` is a
     * real file the Files page lists and the dialog a manager actually inserts
     * images from cannot see at all.
     *
     * Confining writes to the browser's root fixes that without moving the
     * permission boundary, which stays the file-manager root exactly as
     * `FileManagerAccess` expects: the prefix is a path *within* that root, so
     * every write still passes the same checks as before, plus this one.
     */
    public function writeRootRelative(): string
    {
        $browserRoot = static::resolveBrowserRoot();

        if ($browserRoot === '') {
            return '';
        }

        // `getRelativePath()` answers '' both when the browser root is this
        // root and when it lies outside it — a manager confined to a folder
        // inside `assets/`, or two unrelated trees. Neither narrows anything:
        // in the first case everything this manager can write is already
        // inside the dialog's tree, and in the second nothing they could write
        // would be, so the file-manager root is the only ceiling there is.
        return FileManagerAccess::getRelativePath($this->root, $browserRoot);
    }

    /**
     * The image browser's root, resolved the way KCFinder resolves it.
     *
     * Mirrors `manager/media/browser/mcpuk/config.php`: `rb_base_dir` is the
     * default, `image_base_upload_dir` overrides it, and a relative override
     * is taken as relative to the default rather than to the site root.
     */
    private static function resolveBrowserRoot(): string
    {
        $default = static::expandBasePath((string) evo()->getConfig('rb_base_dir', ''));
        $custom = static::expandBasePath((string) evo()->getConfig('image_base_upload_dir', ''));

        if ($custom === '') {
            return $default;
        }

        if (!preg_match('#^(?:[A-Za-z]:/|/)#', $custom)) {
            $custom = $default === '' ? '' : $default . '/' . ltrim($custom, '/');
        }

        return $custom;
    }

    /** A configured path with the CMS's own placeholder expanded, forward slashes, no trailing slash. */
    private static function expandBasePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '';
        }

        if (defined('EVO_BASE_PATH')) {
            $path = str_replace('[(base_path)]', EVO_BASE_PATH, $path);
        }

        return rtrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * Pick a free path for a new result.
     *
     * Never overwrites unless the site has explicitly allowed it: the source
     * images belong to the manager, and a batch that silently replaced twenty
     * originals with AI output would be unrecoverable.
     */
    public function uniqueRelativePath(string $relativeDir, string $basename, string $extension): ?string
    {
        $basename = $this->sanitizeBasename($basename);
        $extension = ltrim(strtolower($extension), '.');

        if ($basename === '' || !in_array($extension, $this->allowedExtensions(), true)) {
            return null;
        }

        // Anchored rather than trusted. The folder reaches here from a step
        // queued during planning, which may predate the current write root —
        // an upgraded site has jobs on its queue whose folder was resolved
        // under the old rule, and they must land where the new one says.
        $relativeDir = $this->resolveWriteFolder($relativeDir);

        if ($relativeDir === null) {
            return null;
        }

        $candidate = $relativeDir . '/' . $basename . '.' . $extension;

        if ($this->absoluteOf($candidate) === null) {
            return null;
        }

        if (Config::allowOverwrite() && $this->canWrite($candidate)) {
            return $candidate;
        }

        for ($suffix = 0; $suffix < 1000; $suffix++) {
            $name = $suffix === 0 ? $basename : $basename . '-' . $suffix;
            $candidate = $relativeDir . '/' . $name . '.' . $extension;
            $absolute = $this->absoluteOf($candidate);

            if ($absolute === null) {
                return null;
            }

            if (!file_exists($absolute)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Strip a name down to something safe to put on a filesystem.
     *
     * The name often comes from a model's prose, so it may contain anything at
     * all. Directory separators, NUL, leading dots and Windows-reserved
     * characters all go.
     */
    public function sanitizeBasename(string $name): string
    {
        $name = str_replace(['\\', '/'], '-', $name);
        $name = preg_replace('/[\x00-\x1F\x7F<>:"|?*]+/u', '', $name) ?? '';
        $name = preg_replace('/\s+/u', '-', trim($name)) ?? '';
        $name = trim($name, '.-');

        if ($name === '') {
            return '';
        }

        return mb_substr($name, 0, 80);
    }

    /**
     * Write a result, creating the folder if need be.
     *
     * Returns false rather than throwing on a permission refusal, because the
     * caller is a batch step that must record the refusal against that one
     * image and carry on with the rest.
     */
    public function write(string $relative, string $bytes): bool
    {
        if (!$this->canWrite($relative)) {
            return false;
        }

        if (strlen($bytes) > Config::maxResultBytes()) {
            return false;
        }

        $absolute = $this->absoluteOf($relative);

        if ($absolute === null) {
            return false;
        }

        // The last gate before bytes reach the disk, and the only one every
        // future caller is guaranteed to pass through. A step queued under an
        // older rule, or a folder that became a symlink between planning and
        // execution, is refused here rather than written outside the ceiling.
        if (!$this->isInsideWriteRoot($absolute)) {
            return false;
        }

        $directory = dirname($absolute);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return false;
        }

        // Re-checked now that the folder exists: `mkdir` resolves symlinks the
        // containment check above could only reason about as strings.
        $realDirectory = realpath($directory);

        if ($realDirectory === false || !$this->isInsideWriteRoot($realDirectory)) {
            return false;
        }

        // Written through a temp file in the same directory and moved into
        // place, so a reader — the manager refreshing the page — never sees a
        // half-written image, and a crash leaves no truncated result behind.
        $temp = $directory . '/.aimage-' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($temp, $bytes) === false) {
            @unlink($temp);

            return false;
        }

        if (!@rename($temp, $absolute)) {
            @unlink($temp);

            return false;
        }

        @chmod($absolute, 0664);

        return true;
    }

    /** Read a source image, or null when it is missing or out of scope. */
    public function read(string $relative): ?string
    {
        if (!$this->canRead($relative)) {
            return null;
        }

        $absolute = $this->absoluteOf($relative);

        if ($absolute === null || !is_file($absolute)) {
            return null;
        }

        if (filesize($absolute) > Config::maxResultBytes()) {
            return null;
        }

        $bytes = @file_get_contents($absolute);

        return $bytes === false ? null : $bytes;
    }

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    private static function permissionsEnabled(): bool
    {
        return (bool) evo()->getConfig('use_udperms', true);
    }

    /**
     * The file-manager root for this user.
     *
     * Per-user settings override the system one — that is how Evolution CMS
     * confines a manager to a folder, and honouring it is the difference
     * between a scoped workbench and a way around the confinement.
     */
    private static function resolveRoot(int $userId): string
    {
        $path = trim((string) static::settingFor($userId, 'filemanager_path', ''));

        if ($path === '') {
            $path = defined('EVO_BASE_PATH') ? EVO_BASE_PATH : '';
        }

        // The stored value carries the CMS's own placeholder, expanded here
        // exactly as Core::getSettings() expands it.
        if (defined('EVO_BASE_PATH')) {
            $path = str_replace('[(base_path)]', EVO_BASE_PATH, $path);
        }

        $path = rtrim(str_replace('\\', '/', $path), '/');
        $real = realpath($path);

        return $real === false ? $path : rtrim(str_replace('\\', '/', $real), '/');
    }

    private function setting(string $name, string $default = ''): string
    {
        return static::settingFor($this->userId, $name, $default);
    }

    /**
     * One setting for one user: their own value, else the system value.
     *
     * `evo()->getConfig()` is not used for the per-user half because in the
     * manager it already reflects whoever is logged in — which is the wrong
     * person when a worker is acting on somebody else's behalf.
     */
    private static function settingFor(int $userId, string $name, string $default = ''): string
    {
        if ($userId > 0) {
            $own = UserSetting::query()
                ->where('user', $userId)
                ->where('setting_name', $name)
                ->value('setting_value');

            if (is_string($own) && trim($own) !== '' && trim($own) !== 'default') {
                return $own;
            }
        }

        $system = SystemSetting::query()
            ->where('setting_name', $name)
            ->value('setting_value');

        if (is_string($system) && trim($system) !== '') {
            return $system;
        }

        return $default;
    }
}
