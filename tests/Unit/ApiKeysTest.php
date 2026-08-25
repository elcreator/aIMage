<?php

use Elcreator\aIMage\Support\ApiKeys;
use Elcreator\aIMage\Support\Crypt;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Which key a manager spends, and what the database is allowed to hold.
 */

beforeEach(fn () => aimageReset());

test('a manager with no key of their own falls back to the site key', function () {
    ApiKeys::setSiteKey('site-key');

    expect(ApiKeys::forUser(7))->toBe('site-key')
        ->and(ApiKeys::sourceFor(7))->toBe(ApiKeys::SOURCE_SITE);
});

test('a manager\'s own key wins over the site key', function () {
    ApiKeys::setSiteKey('site-key');
    ApiKeys::setUserKey(7, 'own-key');
    ApiKeys::flush();

    expect(ApiKeys::forUser(7))->toBe('own-key')
        ->and(ApiKeys::sourceFor(7))->toBe(ApiKeys::SOURCE_USER);
});

test('no key anywhere is a state, not an error', function () {
    expect(ApiKeys::forUser(7))->toBeNull()
        ->and(ApiKeys::sourceFor(7))->toBe(ApiKeys::SOURCE_NONE)
        ->and(ApiKeys::hasKey(7))->toBeFalse();
});

test('one manager\'s key is not visible to another', function () {
    ApiKeys::setUserKey(7, 'seven');
    ApiKeys::setUserKey(8, 'eight');
    ApiKeys::flush();

    expect(ApiKeys::forUser(7))->toBe('seven')
        ->and(ApiKeys::forUser(8))->toBe('eight');
});

test('clearing a key falls back rather than leaving an empty one behind', function () {
    ApiKeys::setSiteKey('site-key');
    ApiKeys::setUserKey(7, 'own-key');
    ApiKeys::setUserKey(7, null);
    ApiKeys::flush();

    expect(ApiKeys::forUser(7))->toBe('site-key')
        ->and(Capsule::table('user_settings')->where('user', 7)->count())->toBe(0);
});

test('setting a key twice updates rather than duplicating the row', function () {
    ApiKeys::setUserKey(7, 'first');
    ApiKeys::setUserKey(7, 'second');
    ApiKeys::flush();

    expect(Capsule::table('user_settings')->where('setting_name', ApiKeys::SETTING)->count())->toBe(1)
        ->and(ApiKeys::forUser(7))->toBe('second');
});

// ---------------------------------------------------------------------------
// At rest
// ---------------------------------------------------------------------------

test('a stored key is never readable from the row', function () {
    ApiKeys::setUserKey(7, 'sk-super-secret');

    $stored = Capsule::table('user_settings')
        ->where('user', 7)
        ->where('setting_name', ApiKeys::SETTING)
        ->value('setting_value');

    // A database dump is a routine artefact; a key that travels with one is a
    // key somebody else can spend.
    expect($stored)->not->toContain('sk-super-secret')
        ->and(Crypt::isEncrypted($stored))->toBeTrue();
});

test('the site key is encrypted in its settings row too', function () {
    ApiKeys::setSiteKey('sk-site-secret');

    $stored = Capsule::table('system_settings')
        ->where('setting_name', ApiKeys::SETTING)
        ->value('setting_value');

    expect($stored)->not->toContain('sk-site-secret')
        ->and(Crypt::isEncrypted($stored))->toBeTrue();
});

test('a key written in the clear by hand still works', function () {
    // Someone pasting a key straight into the settings table must not find it
    // read back as gibberish.
    Capsule::table('system_settings')->insert([
        'setting_name' => ApiKeys::SETTING,
        'setting_value' => 'plain-text-key',
    ]);

    expect(ApiKeys::siteKey())->toBe('plain-text-key');
});

test('a key that will not decrypt reads as absent rather than as garbage', function () {
    Capsule::table('user_settings')->insert([
        'user' => 7,
        'setting_name' => ApiKeys::SETTING,
        'setting_value' => 'aimg1:' . base64_encode(random_bytes(40)),
    ]);

    // A rotated secret must present as "no key configured" and prompt for a
    // new one, not as a mysterious 401 later.
    expect(ApiKeys::userKey(7))->toBeNull();
});

test('encryption is not deterministic, so two identical keys look different', function () {
    $a = Crypt::encrypt('same-key');
    $b = Crypt::encrypt('same-key');

    expect($a)->not->toBe($b)
        ->and(Crypt::decrypt($a))->toBe('same-key')
        ->and(Crypt::decrypt($b))->toBe('same-key');
});

test('an empty stored value decrypts to null, not to an empty string', function () {
    expect(Crypt::decrypt(''))->toBeNull()
        ->and(Crypt::decrypt(null))->toBeNull();
});

// ---------------------------------------------------------------------------
// Config precedence and display
// ---------------------------------------------------------------------------

test('a configured site key wins over the settings row', function () {
    ApiKeys::setSiteKey('row-key');

    config()->set('cms.settings.aIMage.gateway.key', 'env-key');
    ApiKeys::flush();

    // The environment is the right place for a shared secret; a row somebody
    // pasted months ago must not silently override it.
    expect(ApiKeys::siteKey())->toBe('env-key')
        ->and(ApiKeys::siteKeyIsFromConfig())->toBeTrue();

    config()->set('cms.settings.aIMage.gateway.key', null);
    ApiKeys::flush();
});

test('masking shows enough to recognise and not enough to use', function () {
    expect(ApiKeys::mask('sk-abcdefghijklmnop'))->toBe('sk-a' . str_repeat('•', 8) . 'mnop')
        ->and(ApiKeys::mask('short'))->toBe(str_repeat('•', 5))
        ->and(ApiKeys::mask(null))->toBe('')
        ->and(ApiKeys::mask('sk-abcdefghijklmnop'))->not->toContain('efghij');
});

test('the memo is dropped on flush so a rotated key takes effect', function () {
    ApiKeys::setUserKey(7, 'first');
    expect(ApiKeys::forUser(7))->toBe('first');

    // Written behind the memo's back, as another process would.
    Capsule::table('user_settings')
        ->where('user', 7)
        ->where('setting_name', ApiKeys::SETTING)
        ->update(['setting_value' => Crypt::encrypt('rotated')]);

    expect(ApiKeys::forUser(7))->toBe('first');

    ApiKeys::flush();

    expect(ApiKeys::forUser(7))->toBe('rotated');
});
