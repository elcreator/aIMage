<?php

use Elcreator\aIMage\Support\ImageScope;

/**
 * The permission boundary.
 *
 * Everything else in the package trusts these answers, and a worker running at
 * three in the morning has no session to fall back on, so this is the file to
 * read first when changing anything about file access.
 */

beforeEach(fn () => aimageReset());

// ---------------------------------------------------------------------------
// Root resolution
// ---------------------------------------------------------------------------

test('the file root comes from the system setting, with the base-path placeholder expanded', function () {
    aimageUser(7);
    aimageSetFileRoot('assets');

    expect(ImageScope::forUser(7)->root())->toBe(AIMAGE_TEST_ROOT . '/assets');
});

test('a per-user file root overrides the system one', function () {
    aimageUser(7);
    aimageSetFileRoot('assets');
    aimagePutImage('shared/x.png');
    aimageSetFileRoot('assets/images', 7);

    // This is how Evolution confines a manager to a folder. Honouring it is
    // the difference between a scoped workbench and a way around the
    // confinement.
    expect(ImageScope::forUser(7)->root())->toBe(AIMAGE_TEST_ROOT . '/assets/images');
});

test('one user\'s root does not leak into another\'s', function () {
    aimageUser(7);
    aimageUser(8);
    aimageSetFileRoot('assets');
    aimageSetFileRoot('assets/images', 7);

    expect(ImageScope::forUser(7)->root())->toBe(AIMAGE_TEST_ROOT . '/assets/images')
        ->and(ImageScope::forUser(8)->root())->toBe(AIMAGE_TEST_ROOT . '/assets');
});

// ---------------------------------------------------------------------------
// Who is unrestricted
// ---------------------------------------------------------------------------

test('role 1 is exempt from file groups', function () {
    aimageUser(1, 1);
    aimageSetFileRoot('assets');
    aimageRestrict('images/private', 9);

    $scope = ImageScope::forUser(1);

    expect($scope->isUnrestricted())->toBeTrue()
        ->and($scope->canRead('images/private/secret.png'))->toBeTrue();
});

test('an ordinary manager is not exempt', function () {
    aimageUser(7, 3);
    aimageSetFileRoot('assets');

    expect(ImageScope::forUser(7)->isUnrestricted())->toBeFalse();
});

test('turning off use_udperms disables the whole restriction model', function () {
    aimageUser(7, 3);
    aimageSetFileRoot('assets');
    aimageRestrict('images/private', 9);

    AIMageTestCore::$config['use_udperms'] = false;

    expect(ImageScope::forUser(7)->canRead('images/private/secret.png'))->toBeTrue();
});

test('document groups are resolved from the membership join', function () {
    aimageUser(7, 3, [4, 5]);
    aimageSetFileRoot('assets');

    expect(ImageScope::forUser(7)->groupIds())->toEqualCanonicalizing([4, 5]);
});

// ---------------------------------------------------------------------------
// Reading
// ---------------------------------------------------------------------------

test('an unrestricted path is readable by anyone', function () {
    aimageUser(7, 3);
    aimageSetFileRoot('assets');

    expect(ImageScope::forUser(7)->canRead('images/products/a.png'))->toBeTrue();
});

test('a restricted path is refused to a manager outside the group', function () {
    aimageUser(7, 3, [4]);
    aimageSetFileRoot('assets');
    aimageRestrict('images/private', 9);

    expect(ImageScope::forUser(7)->canRead('images/private/secret.png'))->toBeFalse();
});

test('a restricted path is allowed to a manager inside the group', function () {
    aimageUser(7, 3, [9]);
    aimageSetFileRoot('assets');
    aimageRestrict('images/private', 9);

    expect(ImageScope::forUser(7)->canRead('images/private/secret.png'))->toBeTrue();
});

test('a restriction is inherited by everything beneath it', function () {
    aimageUser(7, 3, [4]);
    aimageSetFileRoot('assets');
    aimageRestrict('images', 9);

    // The rule is checked against every ancestor, so restricting a folder
    // restricts the whole tree under it.
    expect(ImageScope::forUser(7)->canRead('images/products/deep/nested/a.png'))->toBeFalse();
});

test('a manager holding any one of several required groups is allowed', function () {
    aimageUser(7, 3, [5]);
    aimageSetFileRoot('assets');
    aimageRestrict('images/private', 9);
    aimageRestrict('images/private', 5);

    expect(ImageScope::forUser(7)->canRead('images/private/secret.png'))->toBeTrue();
});

test('a manager with no groups at all is refused every restricted path', function () {
    aimageUser(7, 3, []);
    aimageSetFileRoot('assets');
    aimageRestrict('images', 9);

    expect(ImageScope::forUser(7)->canRead('images/a.png'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Writing
// ---------------------------------------------------------------------------

test('a restricted manager may not write a top-level entry', function () {
    aimageUser(7, 3, [4]);
    aimageSetFileRoot('assets');

    // The core's own rule, inherited from canModifyExistingPath(): results
    // land inside a folder, never loose in the file root.
    expect(ImageScope::forUser(7)->canWrite('loose.png'))->toBeFalse()
        ->and(ImageScope::forUser(7)->canWrite('aimage/result.png'))->toBeTrue();
});

test('an administrator may write anywhere, including the top level', function () {
    aimageUser(1, 1);
    aimageSetFileRoot('assets');

    expect(ImageScope::forUser(1)->canWrite('loose.png'))->toBeTrue();
});

test('writing into a restricted folder follows the same group rule as reading', function () {
    aimageUser(7, 3, [4]);
    aimageSetFileRoot('assets');
    aimageRestrict('images/private', 9);

    expect(ImageScope::forUser(7)->canWrite('images/private/new.png'))->toBeFalse();
});

test('an empty path is never writable', function () {
    aimageUser(1, 1);
    aimageSetFileRoot('assets');

    expect(ImageScope::forUser(1)->canWrite(''))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Path safety
// ---------------------------------------------------------------------------

test('traversal segments are refused outright', function () {
    aimageUser(1, 1);
    aimageSetFileRoot('assets');

    $scope = ImageScope::forUser(1);

    foreach (['../secret.png', 'images/../../etc/passwd', './x.png', 'images/./a.png', 'a//b.png'] as $path) {
        expect($scope->absoluteOf($path))->toBeNull("expected {$path} to be refused");
    }
});

test('a NUL byte in a path is refused', function () {
    aimageUser(1, 1);
    aimageSetFileRoot('assets');

    expect(ImageScope::forUser(1)->absoluteOf("images/a.png\0.txt"))->toBeNull();
});

test('an absolute path dressed as a relative one cannot escape', function () {
    aimageUser(1, 1);
    aimageSetFileRoot('assets/images');
    aimagePutImage('outside.png', 'assets');

    // Normalisation strips the leading slash, so this resolves inside the root
    // and simply does not exist — it must never reach ../outside.png.
    $resolved = ImageScope::forUser(1)->absoluteOf('/outside.png');

    expect($resolved)->toBe(AIMAGE_TEST_ROOT . '/assets/images/outside.png')
        ->and(is_file((string) $resolved))->toBeFalse();
});

test('a resolvable path inside the root comes back absolute', function () {
    aimageUser(1, 1);
    aimageSetFileRoot('assets');
    aimagePutImage('images/a.png');

    expect(ImageScope::forUser(1)->absoluteOf('images/a.png'))
        ->toBe(AIMAGE_TEST_ROOT . '/assets/images/a.png');
});

test('relativeOf is the inverse of absoluteOf inside the root, and null outside it', function () {
    aimageUser(1, 1);
    aimageSetFileRoot('assets');
    aimagePutImage('images/a.png');

    $scope = ImageScope::forUser(1);

    expect($scope->relativeOf(AIMAGE_TEST_ROOT . '/assets/images/a.png'))->toBe('images/a.png')
        ->and($scope->relativeOf(AIMAGE_TEST_ROOT . '/elsewhere/a.png'))->toBeNull();
});

// ---------------------------------------------------------------------------
// Listing
// ---------------------------------------------------------------------------

test('listing returns only images, and only ones the manager may see', function () {
    aimageUser(7, 3, [4]);
    aimageSetFileRoot('assets');
    aimagePutImage('images/a.png');
    aimagePutImage('images/b.png');
    aimagePutImage('images/notes.txt');
    aimagePutImage('images/private/secret.png');
    aimageRestrict('images/private', 9);

    $paths = array_column(ImageScope::forUser(7)->listImages('images', true), 'path');

    expect($paths)->toEqualCanonicalizing(['images/a.png', 'images/b.png']);
});

test('listing is not recursive unless asked', function () {
    aimageUser(1, 1);
    aimageSetFileRoot('assets');
    aimagePutImage('images/a.png');
    aimagePutImage('images/deep/b.png');

    expect(array_column(ImageScope::forUser(1)->listImages('images'), 'path'))->toBe(['images/a.png'])
        ->and(array_column(ImageScope::forUser(1)->listImages('images', true), 'path'))
        ->toEqualCanonicalizing(['images/a.png', 'images/deep/b.png']);
});

test('listing honours its limit so a huge tree cannot exhaust memory', function () {
    aimageUser(1, 1);
    aimageSetFileRoot('assets');

    for ($i = 0; $i < 12; $i++) {
        aimagePutImage('images/img-' . $i . '.png');
    }

    expect(ImageScope::forUser(1)->listImages('images', false, 5))->toHaveCount(5);
});

test('listing a folder the manager may not read returns nothing', function () {
    aimageUser(7, 3, [4]);
    aimageSetFileRoot('assets');
    aimagePutImage('images/private/secret.png');
    aimageRestrict('images/private', 9);

    expect(ImageScope::forUser(7)->listImages('images/private'))->toBe([]);
});

test('hidden entries are skipped', function () {
    aimageUser(1, 1);
    aimageSetFileRoot('assets');
    aimagePutImage('images/.hidden.png');
    aimagePutImage('images/visible.png');

    expect(array_column(ImageScope::forUser(1)->listImages('images'), 'path'))->toBe(['images/visible.png']);
});

test('folder listing filters by the same rules as image listing', function () {
    aimageUser(7, 3, [4]);
    aimageSetFileRoot('assets');
    aimagePutImage('images/open/a.png');
    aimagePutImage('images/private/secret.png');
    aimageRestrict('images/private', 9);

    expect(array_column(ImageScope::forUser(7)->listFolders('images'), 'path'))->toBe(['images/open']);
});

// ---------------------------------------------------------------------------
// Extensions
// ---------------------------------------------------------------------------

test('allowed extensions are the intersection of the package list and upload_images', function () {
    aimageUser(1, 1);
    aimageSetFileRoot('assets');
    aimageSetting('upload_images', 'png,gif,bmp');

    // bmp is not in the package list and png/gif are, so the intersection is
    // the answer — a site that narrowed uploads has narrowed this too.
    expect(ImageScope::forUser(1)->allowedExtensions())->toEqualCanonicalizing(['png', 'gif']);
});

test('an empty upload_images falls back to the package list rather than to nothing', function () {
    aimageUser(1, 1);
    aimageSetFileRoot('assets');
    aimageSetting('upload_images', '');

    expect(ImageScope::forUser(1)->allowedExtensions())->toContain('png')->toContain('jpg');
});

test('a disjoint upload_images falls back rather than leaving no writable extension', function () {
    aimageUser(1, 1);
    aimageSetFileRoot('assets');
    aimageSetting('upload_images', 'bmp,tiff');

    expect(ImageScope::forUser(1)->allowedExtensions())->toContain('png');
});

// ---------------------------------------------------------------------------
// Reading and writing bytes
// ---------------------------------------------------------------------------

test('reading returns the file, and refuses one outside the scope', function () {
    aimageUser(7, 3, [4]);
    aimageSetFileRoot('assets');
    aimagePutImage('images/a.png');
    aimagePutImage('images/private/secret.png');
    aimageRestrict('images/private', 9);

    $scope = ImageScope::forUser(7);

    expect($scope->read('images/a.png'))->toBe(aimagePng())
        ->and($scope->read('images/private/secret.png'))->toBeNull()
        ->and($scope->read('images/missing.png'))->toBeNull();
});

test('writing creates the folder and lands the bytes', function () {
    aimageUser(1, 1);
    aimageSetFileRoot('assets');

    $scope = ImageScope::forUser(1);

    expect($scope->write('aimage/new/result.png', aimagePng()))->toBeTrue()
        ->and(file_get_contents(AIMAGE_TEST_ROOT . '/assets/aimage/new/result.png'))->toBe(aimagePng());
});

test('writing leaves no temporary file behind', function () {
    aimageUser(1, 1);
    aimageSetFileRoot('assets');

    ImageScope::forUser(1)->write('aimage/result.png', aimagePng());

    expect(glob(AIMAGE_TEST_ROOT . '/assets/aimage/.aimage-*'))->toBe([]);
});

test('a write the manager may not make is refused, not attempted', function () {
    aimageUser(7, 3, [4]);
    aimageSetFileRoot('assets');
    aimageRestrict('images/private', 9);

    expect(ImageScope::forUser(7)->write('images/private/x.png', aimagePng()))->toBeFalse()
        ->and(is_file(AIMAGE_TEST_ROOT . '/assets/images/private/x.png'))->toBeFalse();
});

test('an oversized result is refused rather than written', function () {
    aimageUser(1, 1);
    aimageSetFileRoot('assets');

    $huge = str_repeat('x', 33 * 1024 * 1024);

    expect(ImageScope::forUser(1)->write('aimage/huge.png', $huge))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Naming
// ---------------------------------------------------------------------------

test('a unique path avoids overwriting an existing file', function () {
    aimageUser(1, 1);
    aimageSetFileRoot('assets');
    aimagePutImage('aimage/hero.png');

    expect(ImageScope::forUser(1)->uniqueRelativePath('aimage', 'hero', 'png'))->toBe('aimage/hero-1.png');
});

test('a free name is used as-is', function () {
    aimageUser(1, 1);
    aimageSetFileRoot('assets');

    expect(ImageScope::forUser(1)->uniqueRelativePath('aimage', 'hero', 'png'))->toBe('aimage/hero.png');
});

test('an extension the site does not allow yields no path at all', function () {
    aimageUser(1, 1);
    aimageSetFileRoot('assets');

    expect(ImageScope::forUser(1)->uniqueRelativePath('aimage', 'hero', 'svg'))->toBeNull();
});

test('names from a language model are sanitised into something safe', function () {
    aimageUser(1, 1);
    aimageSetFileRoot('assets');

    $scope = ImageScope::forUser(1);

    expect($scope->sanitizeBasename('../../etc/passwd'))->not->toContain('/')
        ->and($scope->sanitizeBasename('../../etc/passwd'))->not->toContain('.')
        ->and($scope->sanitizeBasename('a "quoted" <name>'))->toBe('a-quoted-name')
        ->and($scope->sanitizeBasename('   '))->toBe('')
        ->and($scope->sanitizeBasename(str_repeat('a', 200)))->toHaveLength(80);
});

// ---------------------------------------------------------------------------
// Public URLs
// ---------------------------------------------------------------------------

test('a file under the web root gets a public URL', function () {
    aimageUser(1, 1);
    aimageSetFileRoot('assets');
    aimagePutImage('images/a.png');

    expect(ImageScope::forUser(1)->publicUrl('images/a.png'))
        ->toBe('https://example.test/assets/images/a.png');
});

test('a URL is percent-encoded segment by segment', function () {
    aimageUser(1, 1);
    aimageSetFileRoot('assets');
    aimagePutImage('images/a b.png');

    expect(ImageScope::forUser(1)->publicUrl('images/a b.png'))
        ->toBe('https://example.test/assets/images/a%20b.png');
});

test('a path that will not resolve has no URL', function () {
    aimageUser(1, 1);
    aimageSetFileRoot('assets');

    // Null is a real answer here: the upscale endpoint takes a URL rather than
    // an upload and has to know when there is not one.
    expect(ImageScope::forUser(1)->publicUrl('../outside.png'))->toBeNull();
});
