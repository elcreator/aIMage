<?php

namespace EvolutionCMS\aIMage\Support;

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

        // The path does not exist yet — a result about to be written. Its
        // nearest existing ancestor still has to be inside the root.
        $parent = dirname($candidate);
        $realParent = realpath($parent);

        if ($realParent !== false && !$this->isInsideRoot($realParent)) {
            return null;
        }

        return $candidate;
    }

    /** The relative path of an absolute one, or null when it is outside the root. */
    public function relativeOf(string $absolute): ?string
    {
        $relative = FileManagerAccess::getRelativePath($this->root, $absolute);

        return $relative === '' ? null : $relative;
    }

    private function isInsideRoot(string $realPath): bool
    {
        $real = rtrim(str_replace('\\', '/', $realPath), '/');
        $root = rtrim($this->root, '/');

        return $real === $root || str_starts_with($real, $root . '/');
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
            if (!$this->canRead($path)) {
                continue;
            }

            $folders[] = ['path' => $path, 'name' => basename($path)];
        }

        return $folders;
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

    /** The folder results are written to, relative to the root. */
    public function outputFolder(): string
    {
        return Config::outputFolder();
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

        $relativeDir = FileManagerAccess::normalizeRelativePath($relativeDir);
        $candidate = ($relativeDir === '' ? '' : $relativeDir . '/') . $basename . '.' . $extension;

        if ($this->absoluteOf($candidate) === null) {
            return null;
        }

        if (Config::allowOverwrite() && $this->canWrite($candidate)) {
            return $candidate;
        }

        for ($suffix = 0; $suffix < 1000; $suffix++) {
            $name = $suffix === 0 ? $basename : $basename . '-' . $suffix;
            $candidate = ($relativeDir === '' ? '' : $relativeDir . '/') . $name . '.' . $extension;
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

        $directory = dirname($absolute);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
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
