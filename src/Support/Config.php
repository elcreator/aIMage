<?php

namespace Elcreator\aIMage\Support;

/**
 * Typed access to `cms.settings.aIMage`.
 *
 * Nothing else in the package reads `config()` directly, so a site that moves
 * the gateway or renames the output folder changes one value and every caller
 * follows.
 */
final class Config
{
    public static function get(string $key, $default = null)
    {
        return config('cms.settings.aIMage.' . $key, $default);
    }

    public static function isEnabled(): bool
    {
        return (bool) static::get('enable', true);
    }

    public static function baseUrl(): string
    {
        return rtrim((string) static::get('gateway.base_url', 'https://ai.artur.work/api/v1'), '/');
    }

    /** The site-wide fallback key, or null when the operator has not set one. */
    public static function siteKey(): ?string
    {
        $key = trim((string) static::get('gateway.key', ''));

        return $key !== '' ? $key : null;
    }

    public static function timeout(): int
    {
        return max(5, (int) static::get('gateway.timeout', 120));
    }

    public static function connectTimeout(): int
    {
        return max(1, (int) static::get('gateway.connect_timeout', 10));
    }

    public static function catalogTtl(): int
    {
        return max(60, (int) static::get('gateway.catalog_ttl', 3600));
    }

    public static function defaultModel(string $kind): string
    {
        return (string) static::get('defaults.' . $kind . '_model', '');
    }

    public static function speechVoice(): string
    {
        return (string) static::get('defaults.speech_voice', 'alloy');
    }

    public static function limit(string $key, $default = 0)
    {
        return static::get('limits.' . $key, $default);
    }

    public static function outputFolder(): string
    {
        return trim((string) static::get('files.output_folder', 'aimage'), '/');
    }

    public static function allowOverwrite(): bool
    {
        return (bool) static::get('files.allow_overwrite', false);
    }

    /** @return string[] lowercase, no dot */
    public static function allowedExtensions(): array
    {
        $configured = (array) static::get('files.allowed_extensions', ['jpg', 'jpeg', 'png', 'webp', 'gif']);
        $configured = array_map(static fn ($e) => ltrim(strtolower(trim((string) $e)), '.'), $configured);

        return array_values(array_filter(array_unique($configured)));
    }

    public static function maxResultBytes(): int
    {
        return max(1024, (int) static::get('files.max_result_bytes', 32 * 1024 * 1024));
    }
}
