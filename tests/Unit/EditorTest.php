<?php

use Elcreator\aIMage\Services\Actor;
use Elcreator\aIMage\Services\Editor;
use Elcreator\aIMage\Services\WorkbenchException;

/**
 * The hand editor (Painterro): opening an image for the canvas and saving what it made.
 *
 * No key and no gateway anywhere here - but the same file boundary as AI results.
 */

beforeEach(function () {
    aimageReset();
    config()->set('cms.settings.aIMage.files.allow_overwrite', false);
});

afterEach(function () {
    config()->set('cms.settings.aIMage.files.allow_overwrite', false);
});

function aimageEditor(int $userId = 7, bool $permitted = true): Editor
{
    return new Editor(new Actor($userId, $permitted, false));
}

/** A real JPEG, for the format checks; null where GD cannot make one. */
function aimageJpeg(): ?string
{
    if (!function_exists('imagejpeg')) {
        return null;
    }

    $image = imagecreatetruecolor(2, 2);
    ob_start();
    imagejpeg($image);

    return (string) ob_get_clean();
}

function aimageEditorRefusal(callable $call): ?string
{
    try {
        $call();
    } catch (WorkbenchException $e) {
        return $e->error;
    }

    return null;
}

test('an edit is saved as a copy beside its source, and the original is left alone', function () {
    aimageUser(7);
    aimageSetFileRoot('assets');
    $original = aimagePutImage('images/products/shoe.png');
    $before = file_get_contents($original);

    $saved = aimageEditor()->save(aimagePng(), 'images/products/shoe.png');

    expect($saved['file']['path'])->toBe('images/products/shoe-edited.png')
        ->and($saved['file']['url'])->toBe('https://example.test/assets/images/products/shoe-edited.png')
        ->and(file_get_contents(AIMAGE_TEST_ROOT . '/assets/images/products/shoe-edited.png'))->toBe(aimagePng())
        ->and(file_get_contents($original))->toBe($before);
});

test('saving again never overwrites the earlier copy', function () {
    aimageUser(7);
    aimageSetFileRoot('assets');
    aimagePutImage('images/a.png');

    $first = aimageEditor()->save(aimagePng(), 'images/a.png');
    $second = aimageEditor()->save(aimagePng(), 'images/a.png');

    expect($first['file']['path'])->toBe('images/a-edited.png')
        ->and($second['file']['path'])->toBe('images/a-edited-1.png');
});

test('what was uploaded is judged by its bytes, not by what it claims to be', function () {
    aimageUser(7);
    aimageSetFileRoot('assets');
    aimagePutImage('images/a.png');

    expect(aimageEditorRefusal(fn () => aimageEditor()->save('<html>not an image</html>', 'images/a.png')))->toBe('not_an_image')
        ->and(aimageEditorRefusal(fn () => aimageEditor()->save('', 'images/a.png')))->toBe('not_an_image')
        ->and(glob(AIMAGE_TEST_ROOT . '/assets/images/a-edited*'))->toBe([]);
});

test('a format the site does not accept is refused', function () {
    aimageUser(7);
    aimageSetFileRoot('assets');
    aimageSetting('upload_images', 'jpg,jpeg');
    aimagePutImage('images/a.png');

    expect(aimageEditorRefusal(fn () => aimageEditor()->save(aimagePng(), 'images/a.png')))->toBe('not_an_image');
});

test('a JPEG canvas keeps its format and gets a .jpg name', function () {
    $jpeg = aimageJpeg() ?? $this->markTestSkipped('GD has no JPEG support here');
    aimageUser(7);
    aimageSetFileRoot('assets');
    aimagePutImage('images/photo.jpeg');

    expect(aimageEditor()->save($jpeg, 'images/photo.jpeg')['file']['path'])->toBe('images/photo-edited.jpg');
});

test('an image the manager may not see cannot be edited or saved from', function () {
    aimageUser(7, 3, [4]);
    aimageSetFileRoot('assets');
    aimageRestrict('images/private', 9);
    aimagePutImage('images/private/secret.png');

    expect(aimageEditorRefusal(fn () => aimageEditor()->open('images/private/secret.png')))->toBe('not_found')
        ->and(aimageEditorRefusal(fn () => aimageEditor()->save(aimagePng(), 'images/private/secret.png')))->toBe('not_found')
        ->and(glob(AIMAGE_TEST_ROOT . '/assets/images/private/secret-edited*'))->toBe([]);
});

test('a path that climbs out of the file root is not opened', function () {
    aimageUser(7);
    aimageSetFileRoot('assets/images');
    aimagePutImage('outside.png');

    expect(aimageEditorRefusal(fn () => aimageEditor()->open('../outside.png')))->toBe('not_found')
        ->and(aimageEditorRefusal(fn () => aimageEditor()->open('missing.png')))->toBe('not_found');
});

test('opening hands back the bytes and the type for the canvas', function () {
    aimageUser(7);
    aimageSetFileRoot('assets');
    aimagePutImage('images/a.png');

    $image = aimageEditor()->open('images/a.png');

    expect($image)->toBe(['bytes' => aimagePng(), 'mime' => 'image/png', 'name' => 'a.png']);
});

test('a new or uploaded image goes to the chosen folder under a safe name', function () {
    aimageUser(7);
    aimageSetFileRoot('assets');

    $saved = aimageEditor()->save(aimagePng(), null, 'images/drawings', '../My scan?.jpg');

    expect($saved['file']['path'])->toBe('images/drawings/My-scan.png')
        ->and(is_file(AIMAGE_TEST_ROOT . '/assets/images/drawings/My-scan.png'))->toBeTrue();
});

test('without a folder a new image goes to the results folder', function () {
    aimageUser(7);
    aimageSetFileRoot('assets');

    expect(aimageEditor()->save(aimagePng())['file']['path'])->toBe('aimage/image.png');
});

test('replacing the original is refused unless the site allows overwriting', function () {
    aimageUser(7);
    aimageSetFileRoot('assets');
    $original = aimagePutImage('images/a.png');
    $kept = file_get_contents($original);

    expect(aimageEditorRefusal(fn () => aimageEditor()->save(aimagePng(), 'images/a.png', null, null, true)))
        ->toBe('overwrite_disabled')
        ->and(file_get_contents($original))->toBe($kept)
        ->and(Editor::canReplace())->toBeFalse();
});

test('where overwriting is allowed, the original is replaced in place', function () {
    config()->set('cms.settings.aIMage.files.allow_overwrite', true);
    aimageUser(7);
    aimageSetFileRoot('assets');
    $original = aimagePutImage('images/a.png');
    $edited = aimagePng() . "\0";

    $saved = aimageEditor()->save($edited, 'images/a.png', null, null, true);

    expect(Editor::canReplace())->toBeTrue()
        ->and($saved['file']['path'])->toBe('images/a.png')
        ->and(file_get_contents($original))->toBe($edited)
        ->and(glob(AIMAGE_TEST_ROOT . '/assets/images/a-edited*'))->toBe([]);
});

test('an original is only replaced with its own format', function () {
    $jpeg = aimageJpeg() ?? $this->markTestSkipped('GD has no JPEG support here');
    config()->set('cms.settings.aIMage.files.allow_overwrite', true);
    aimageUser(7);
    aimageSetFileRoot('assets');
    aimagePutImage('images/a.png');
    aimagePutImage('images/b.jpeg');

    expect(aimageEditorRefusal(fn () => aimageEditor()->save($jpeg, 'images/a.png', null, null, true)))->toBe('format_mismatch')
        ->and(aimageEditor()->save($jpeg, 'images/b.jpeg', null, null, true)['file']['path'])->toBe('images/b.jpeg')
        ->and(aimageEditorRefusal(fn () => aimageEditor()->save(aimagePng(), null, 'images', 'x', true)))->toBe('overwrite_disabled');
});

test('a manager without the permission can neither open nor save', function () {
    aimageUser(7);
    aimageSetFileRoot('assets');
    aimagePutImage('images/a.png');

    expect(aimageEditorRefusal(fn () => aimageEditor(7, false)->open('images/a.png')))->toBe('forbidden')
        ->and(aimageEditorRefusal(fn () => aimageEditor(7, false)->save(aimagePng(), 'images/a.png')))->toBe('forbidden');
});

test('the manager language is mapped onto the languages Painterro ships', function (string $locale, string $expected) {
    expect(Editor::languageFor($locale))->toBe($expected);
})->with([
    ['en', 'en'],
    ['uk', 'uk'],
    ['de_DE', 'de'],
    ['pt', 'pt-PT'],
    ['pt-BR', 'pt-BR'],
    ['pt_br', 'pt-BR'],
    ['ja', 'ja'],
    ['zh', 'en'],
    ['', 'en'],
]);

test('the built Painterro bundle ships with its MIT licence and the source it came from', function () {
    $bundle = (string) Editor::bundlePath();
    $dir = dirname($bundle);

    expect(str_replace('\\', '/', $bundle))->toEndWith('/' . Editor::BUNDLE)
        ->and(file_get_contents($bundle))->toContain('Painterro')
        ->and(file_get_contents($dir . '/LICENSE'))->toContain('The MIT License')
        ->and(trim(file_get_contents($dir . '/VERSION')))->toMatch('/^v\d+\.\d+\.\d+ [0-9a-f]{40}$/')
        ->and(Editor::bundleVersion())->toMatch('/^[0-9a-f]{12}$/');
});
