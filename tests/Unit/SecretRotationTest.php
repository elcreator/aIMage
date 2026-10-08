<?php

use Elcreator\aIMage\Services\Actor;
use Elcreator\aIMage\Support\ApiKeys;
use Elcreator\aIMage\Support\Crypt;
use EvolutionCMS\Models\UserSetting;

/**
 * The encryption secret is generated for the person, who never sees it. So it has to be
 * possible to move it, rotate it and even lose it without anyone knowing what it was.
 */

function aimageSecretFile(): string
{
    return EVO_CORE_PATH . 'custom/aimage.secret';
}

function aimageDropSecretFile(): void
{
    if (is_file(aimageSecretFile())) {
        unlink(aimageSecretFile());
    }
}

function aimageUseSecret(?string $secret, ?string $previous = null): void
{
    $secret === null ? putenv('AIMAGE_SECRET') : putenv('AIMAGE_SECRET=' . $secret);
    $previous === null ? putenv('AIMAGE_SECRET_PREVIOUS') : putenv('AIMAGE_SECRET_PREVIOUS=' . $previous);
    Crypt::flush();
    ApiKeys::flush();
}

function aimageStoredKey(int $userId): ?string
{
    $value = UserSetting::query()->where('user', $userId)->where('setting_name', ApiKeys::SETTING)->value('setting_value');

    return is_string($value) ? $value : null;
}

beforeEach(function () {
    aimageReset();
    aimageUseSecret(null);
    aimageDropSecretFile();
});

afterEach(function () {
    aimageUseSecret(null);
    aimageDropSecretFile();
});

test('a deliberate rotation: the old secret in AIMAGE_SECRET_PREVIOUS keeps the key readable and rewrites it', function () {
    aimageUseSecret('first-secret');
    ApiKeys::setUserKey(7, 'sk-user-key');
    $before = aimageStoredKey(7);

    aimageUseSecret('second-secret', 'first-secret');
    expect(Crypt::needsRotation($before))->toBeTrue()
        ->and(ApiKeys::forUser(7))->toBe('sk-user-key');

    $after = aimageStoredKey(7);
    expect($after)->not->toBe($before)
        ->and(Crypt::needsRotation($after))->toBeFalse();

    // The old value can now be dropped for good.
    aimageUseSecret('second-secret');
    expect(ApiKeys::forUser(7))->toBe('sk-user-key');
});

test('moving from the generated file to AIMAGE_SECRET needs no manual step', function () {
    ApiKeys::setUserKey(7, 'sk-user-key');       // written under the generated file secret
    expect(is_file(aimageSecretFile()))->toBeTrue();

    aimageUseSecret('chosen-later');             // the owner sets the variable, never having seen the file
    expect(ApiKeys::forUser(7))->toBe('sk-user-key')
        ->and(Crypt::needsRotation(aimageStoredKey(7)))->toBeFalse();

    aimageDropSecretFile();                 // and the file may now go
    Crypt::flush();
    ApiKeys::flush();
    expect(ApiKeys::forUser(7))->toBe('sk-user-key');
});

test('a lost secret is reported as unreadable, not as "no key", and connecting again clears it', function () {
    aimageUseSecret('gone-for-good');
    ApiKeys::setUserKey(7, 'sk-user-key');

    aimageUseSecret('a-different-one');          // restored database, secret not carried over

    expect(ApiKeys::forUser(7))->toBeNull()
        ->and(ApiKeys::unreadable(7))->toBe(['own' => true, 'site' => false]);

    aimageUser(7, 1);
    $state = (new Actor(7, true, true))->keyState();
    expect($state['configured'])->toBeFalse()
        ->and($state['unreadable']['own'])->toBeTrue();

    ApiKeys::setUserKey(7, 'sk-new-key');        // Connect again / paste: replaces the unreadable one
    expect(ApiKeys::forUser(7))->toBe('sk-new-key')
        ->and(ApiKeys::unreadable(7))->toBe(['own' => false, 'site' => false]);
});

test('a manager with no saved key is not reported as unreadable', function () {
    expect(ApiKeys::unreadable(7))->toBe(['own' => false, 'site' => false]);
});
