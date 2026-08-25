<?php

namespace Elcreator\aIMage\Support;

use EvolutionCMS\Models\SystemSetting;
use EvolutionCMS\Models\UserSetting;

/**
 * Which gateway key a given manager spends.
 *
 * Three tiers, in order: the manager's own key, then the site-wide fallback,
 * then nothing — and "nothing" is a first-class answer, because the UI's job
 * in that case is to ask for a key rather than to fail a request halfway
 * through a batch.
 *
 * The tier matters beyond which string is sent. A manager on their own key is
 * spending their own budget and may be trusted with a larger job; a manager on
 * the site key is spending the operator's, which is the case where an approval
 * threshold earns its keep. Callers ask `sourceFor()` rather than inferring it.
 */
final class ApiKeys
{
    public const SETTING = 'aimage_api_key';

    public const SOURCE_USER = 'user';
    public const SOURCE_SITE = 'site';
    public const SOURCE_NONE = 'none';

    /** In-process memo. A worker resolves the same key once per job, not once per step. */
    private static array $memo = [];

    /**
     * The key this manager's work should be billed to, or null when neither
     * they nor the operator has configured one.
     */
    public static function forUser(int $userId): ?string
    {
        return static::resolve($userId)['key'];
    }

    /** @return self::SOURCE_* */
    public static function sourceFor(int $userId): string
    {
        return static::resolve($userId)['source'];
    }

    public static function hasKey(int $userId): bool
    {
        return static::forUser($userId) !== null;
    }

    /**
     * @return array{key: ?string, source: string}
     */
    private static function resolve(int $userId): array
    {
        if (isset(self::$memo[$userId])) {
            return self::$memo[$userId];
        }

        $own = static::userKey($userId);

        if ($own !== null) {
            return self::$memo[$userId] = ['key' => $own, 'source' => self::SOURCE_USER];
        }

        $site = static::siteKey();

        if ($site !== null) {
            return self::$memo[$userId] = ['key' => $site, 'source' => self::SOURCE_SITE];
        }

        return self::$memo[$userId] = ['key' => null, 'source' => self::SOURCE_NONE];
    }

    // ------------------------------------------------------------------
    // The manager's own key
    // ------------------------------------------------------------------

    public static function userKey(int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }

        $stored = UserSetting::query()
            ->where('user', $userId)
            ->where('setting_name', self::SETTING)
            ->value('setting_value');

        return Crypt::decrypt(is_string($stored) ? $stored : null);
    }

    /**
     * Store, or with null clear, one manager's key.
     *
     * Encrypted before it reaches the row — see Crypt for why a key in a
     * database dump is a key someone else can spend.
     */
    public static function setUserKey(int $userId, ?string $key): void
    {
        if ($userId <= 0) {
            return;
        }

        unset(self::$memo[$userId]);

        $key = $key === null ? '' : trim($key);

        if ($key === '') {
            UserSetting::query()
                ->where('user', $userId)
                ->where('setting_name', self::SETTING)
                ->delete();

            return;
        }

        UserSetting::query()->updateOrInsert(
            ['user' => $userId, 'setting_name' => self::SETTING],
            ['setting_value' => Crypt::encrypt($key)]
        );
    }

    // ------------------------------------------------------------------
    // The site-wide fallback
    // ------------------------------------------------------------------

    /**
     * The operator's key.
     *
     * Configuration wins over the settings row, deliberately: the environment
     * is the right place for a shared secret, and an operator who has set
     * AIMAGE_API_KEY should not have it silently overridden by a row somebody
     * pasted into the manager months ago.
     */
    public static function siteKey(): ?string
    {
        $configured = Config::siteKey();

        if ($configured !== null) {
            return $configured;
        }

        $stored = SystemSetting::query()
            ->where('setting_name', self::SETTING)
            ->value('setting_value');

        return Crypt::decrypt(is_string($stored) ? $stored : null);
    }

    /** True when the fallback comes from config and the settings row is therefore inert. */
    public static function siteKeyIsFromConfig(): bool
    {
        return Config::siteKey() !== null;
    }

    public static function setSiteKey(?string $key): void
    {
        self::$memo = [];

        $key = $key === null ? '' : trim($key);

        if ($key === '') {
            SystemSetting::query()->where('setting_name', self::SETTING)->delete();

            return;
        }

        SystemSetting::query()->updateOrInsert(
            ['setting_name' => self::SETTING],
            ['setting_value' => Crypt::encrypt($key)]
        );
    }

    /**
     * A key rendered for display: enough to recognise, not enough to use.
     *
     * Never send a stored key back to the browser in full. The manager who
     * typed it does not need to read it back, and an XSS or an over-shared
     * screenshot should not be able to leak one.
     */
    public static function mask(?string $key): string
    {
        $key = (string) $key;

        if ($key === '') {
            return '';
        }

        if (mb_strlen($key) <= 8) {
            return str_repeat('•', mb_strlen($key));
        }

        return mb_substr($key, 0, 4) . str_repeat('•', 8) . mb_substr($key, -4);
    }

    /** Drop the memo. The worker calls this between jobs so a rotated key takes effect. */
    public static function flush(): void
    {
        self::$memo = [];
    }
}
