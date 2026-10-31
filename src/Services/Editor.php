<?php

namespace Elcreator\aIMage\Services;

use Elcreator\aIMage\Support\Config;
use EvolutionCMS\Support\FileManagerAccess;

/**
 * The hand editor: Painterro in the page, this on the server.
 *
 * Needs no API key and spends nothing. It is the same file boundary as the AI
 * batches, though — every save goes through `ImageScope`, so a manager can only
 * open what they may read and only write where their results may go, and an
 * original is replaced only when the site allows overwriting at all.
 */
final class Editor
{
    /** What the browser bundle is and where it lives; see ci/build-painterro.sh. */
    public const BUNDLE = 'public/painterro/painterro.min.js';

    /** Raster types a canvas can produce, and the extension each is written with. */
    private const TYPES = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    public function __construct(private readonly Actor $actor)
    {
    }

    /** May an edit replace the image it was opened from? */
    public static function canReplace(): bool
    {
        return Config::allowOverwrite();
    }

    /** A manager locale as one of the languages Painterro ships; English otherwise. */
    public static function languageFor(string $locale): string
    {
        $locale = strtolower(str_replace('_', '-', trim($locale)));
        $short = substr($locale, 0, 2);

        return match (true) {
            $locale === 'pt-br' => 'pt-BR',
            $short === 'pt' => 'pt-PT',
            in_array($short, ['ca', 'de', 'es', 'fr', 'ja', 'nl', 'pl', 'ru', 'uk'], true) => $short,
            default => 'en',
        };
    }

    /** Absolute path of the built Painterro bundle, or null when it was not built. */
    public static function bundlePath(): ?string
    {
        $path = dirname(__DIR__, 2) . '/' . self::BUNDLE;

        return is_file($path) ? $path : null;
    }

    /** A cache-busting version for the bundle URL. */
    public static function bundleVersion(): string
    {
        $path = self::bundlePath();

        return $path === null ? '0' : substr((string) md5_file($path), 0, 12);
    }

    /**
     * An image to open in the editor, read through the manager's scope.
     *
     * Served by the module rather than by its public URL: `site_url` may name
     * another host than the one the manager is on, and a canvas that drew a
     * cross-origin image can no longer be saved.
     *
     * @return array{bytes: string, mime: string, name: string}
     * @throws WorkbenchException
     */
    public function open(string $path): array
    {
        $scope = $this->actor->authorize()->scope();
        $info = $scope->imageInfo($path);
        $bytes = $info === null ? null : $scope->read($info['path']);

        if ($bytes === null) {
            throw new WorkbenchException('not_found', __('aIMage::global.error_file_not_found'), 404);
        }

        $mime = (string) ($info['mime'] ?? '');

        return [
            'bytes' => $bytes,
            'mime' => str_starts_with($mime, 'image/') ? $mime : 'application/octet-stream',
            'name' => (string) $info['name'],
        ];
    }

    /**
     * Save what the editor produced.
     *
     * @param string      $bytes   the canvas, as the browser encoded it
     * @param string|null $source  the image it was opened from, if any
     * @param string|null $folder  where a new image goes when there is no source
     * @param string|null $name    a basename for a new image
     * @param bool        $replace write over `$source` (only where overwriting is allowed)
     * @throws WorkbenchException
     */
    public function save(string $bytes, ?string $source = null, ?string $folder = null, ?string $name = null, bool $replace = false): array
    {
        $scope = $this->actor->authorize()->scope();

        $extension = $this->extensionOf($bytes);

        if ($extension === null) {
            throw new WorkbenchException('not_an_image', __('aIMage::global.editor_not_an_image'));
        }

        $source = FileManagerAccess::normalizeRelativePath((string) $source);

        if ($source !== '') {
            if ($scope->imageInfo($source) === null) {
                throw new WorkbenchException('not_found', __('aIMage::global.error_file_not_found'), 404);
            }

            $folder = $this->parentOf($source);
            $basename = pathinfo($source, PATHINFO_FILENAME);
        } else {
            $folder = trim((string) $folder) !== '' ? (string) $folder : $scope->outputFolder();
            $basename = (string) $name !== '' ? pathinfo((string) $name, PATHINFO_FILENAME) : 'image';
        }

        if ($replace) {
            $target = $this->replacementFor($source, $extension);
        } else {
            // A source outside the write ceiling (readable, not writable) gets its
            // copy re-anchored inside it, exactly like an AI result would.
            $target = $scope->uniqueRelativePath(
                (string) $folder,
                $source !== '' ? $scope->sanitizeBasename($basename) . '-edited' : $basename,
                $extension
            );
        }

        if ($target === null || !$scope->write($target, $bytes)) {
            throw new WorkbenchException(
                'folder_denied',
                __('aIMage::global.error_folder_denied', ['folder' => $target ?? (string) $folder]),
                403
            );
        }

        return ['file' => $scope->imageInfo($target) ?? ['path' => $target, 'url' => $scope->publicUrl($target)]];
    }

    /** @throws WorkbenchException */
    private function replacementFor(string $source, string $extension): string
    {
        if ($source === '' || !self::canReplace()) {
            throw new WorkbenchException('overwrite_disabled', __('aIMage::global.editor_replace_disabled'), 403);
        }

        // Replacing keeps the original's name, so it keeps its format too: a PNG
        // written into photo.jpg would be a file that lies about what it is.
        $family = static fn (string $e): string => $e === 'jpeg' ? 'jpg' : $e;

        if ($family(strtolower(pathinfo($source, PATHINFO_EXTENSION))) !== $family($extension)) {
            throw new WorkbenchException('format_mismatch', __('aIMage::global.editor_replace_format'));
        }

        return $source;
    }

    /** The extension for these bytes, judged by content, or null for anything else. */
    private function extensionOf(string $bytes): ?string
    {
        if ($bytes === '') {
            return null;
        }

        $info = @getimagesizefromstring($bytes);
        $extension = self::TYPES[$info['mime'] ?? ''] ?? null;

        if ($extension === null) {
            return null;
        }

        $allowed = $this->actor->scope()->allowedExtensions();

        if (in_array($extension, $allowed, true)) {
            return $extension;
        }

        return $extension === 'jpg' && in_array('jpeg', $allowed, true) ? 'jpeg' : null;
    }

    private function parentOf(string $relative): string
    {
        return str_contains($relative, '/') ? substr($relative, 0, strrpos($relative, '/')) : '';
    }
}
