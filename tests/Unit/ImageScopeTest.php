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

// ---------------------------------------------------------------------------
// Where results are written
// ---------------------------------------------------------------------------

test('the output folder lands inside the image browser root when that is below the file root', function () {
    aimageUser(1, 1);
    aimageSetFileRoot('');
    AIMageTestCore::$config['rb_base_dir'] = '[(base_path)]assets/';

    // The default install: the Files page is rooted at the site root, KCFinder
    // — the browser TinyMCE's Insert image dialog opens — at assets/. A bare
    // `aimage` would be a real folder no manager could insert an image from.
    expect(ImageScope::forUser(1)->outputFolder())->toBe('assets/images/aimage');
});

test('a manager confined inside the browser root writes straight to the configured folder', function () {
    aimageUser(1, 1);
    aimageSetFileRoot('assets/images');
    AIMageTestCore::$config['rb_base_dir'] = '[(base_path)]assets/';

    // Everything this manager can write is already inside the dialog's tree,
    // so prefixing would only push results into assets/images/assets.
    expect(ImageScope::forUser(1)->outputFolder())->toBe('aimage');
});

test('coinciding roots are not prefixed', function () {
    aimageUser(1, 1);
    aimageSetFileRoot('assets');
    AIMageTestCore::$config['rb_base_dir'] = '[(base_path)]assets/';

    expect(ImageScope::forUser(1)->outputFolder())->toBe('aimage');
});

test('an unconfigured browser root leaves the folder alone', function () {
    aimageUser(1, 1);
    aimageSetFileRoot('assets');

    expect(ImageScope::forUser(1)->outputFolder())->toBe('aimage');
});

test('a custom upload dir is resolved against the browser root, as KCFinder resolves it', function () {
    aimageUser(1, 1);
    aimageSetFileRoot('');
    AIMageTestCore::$config['rb_base_dir'] = '[(base_path)]assets/';
    // Relative, so relative to rb_base_dir rather than to the site root —
    // mirroring manager/media/browser/mcpuk/config.php.
    AIMageTestCore::$config['image_base_upload_dir'] = 'images';

    expect(ImageScope::forUser(1)->outputFolder())->toBe('assets/images/aimage');
});

test('a folder already inside the browser root is not prefixed twice', function () {
    aimageUser(1, 1);
    aimageSetFileRoot('');
    AIMageTestCore::$config['rb_base_dir'] = '[(base_path)]assets/';

    // The repository is built once per run, so this has to be put back or the
    // next test inherits it.
    $key = 'cms.settings.aIMage.files.output_folder';
    $previous = config($key);
    config()->set($key, 'assets/generated');

    try {
        expect(ImageScope::forUser(1)->outputFolder())->toBe('assets/generated');
    } finally {
        config()->set($key, $previous);
    }
});

// ---------------------------------------------------------------------------
// The write root
// ---------------------------------------------------------------------------

/** The default install: file root is the whole site, image browser root is assets/. */
function aimageSiteWideRoots(): void
{
    aimageSetFileRoot('');
    AIMageTestCore::$config['rb_base_dir'] = '[(base_path)]assets/';
}

test('a manager confined by filemanager_path writes inside their own root', function () {
    aimageUser(7);
    aimageSetFileRoot('assets');
    aimageSetFileRoot('assets/images', 7);
    AIMageTestCore::$config['rb_base_dir'] = '[(base_path)]assets/';

    // Their root is already below the browser's, so there is nothing to
    // prefix — and prefixing would send results to the site-wide folder they
    // are confined out of.
    $scope = ImageScope::forUser(7);

    expect($scope->writeRoot())->toBe(AIMAGE_TEST_ROOT . '/assets/images')
        ->and($scope->outputFolder())->toBe('aimage')
        ->and($scope->resolveWriteFolder('123/45'))->toBe('123/45');
});

test('an unconfined manager is still held inside the common images folder', function () {
    aimageUser(1, 1);
    aimageSiteWideRoots();

    $scope = ImageScope::forUser(1);

    // Role 1 is exempt from file groups, and their file root is the whole
    // site. The browser root is what stops results landing in core/.
    expect($scope->writeRoot())->toBe(AIMAGE_TEST_ROOT . '/assets')
        ->and($scope->resolveWriteFolder('core/config'))->toBe('assets/images/core/config');
});

test('a nested destination folder is taken at face value, not refused for not existing', function () {
    aimageUser(1, 1);
    aimageSiteWideRoots();

    // "put them in 123/45" — neither folder is there, and that is not an
    // error: both are created when the first result is written.
    expect(is_dir(AIMAGE_TEST_ROOT . '/assets/images/123'))->toBeFalse()
        ->and(ImageScope::forUser(1)->resolveWriteFolder('123/45'))->toBe('assets/images/123/45');
});

test('writing into a folder that does not exist creates the whole chain', function () {
    aimageUser(1, 1);
    aimageSiteWideRoots();

    $scope = ImageScope::forUser(1);
    $target = $scope->uniqueRelativePath($scope->resolveWriteFolder('123/45'), 'hero', 'png');

    expect($target)->toBe('assets/images/123/45/hero.png')
        ->and($scope->write($target, aimagePng()))->toBeTrue()
        ->and(is_file(AIMAGE_TEST_ROOT . '/assets/images/123/45/hero.png'))->toBeTrue();
});

test('a folder already inside the write root is used as given', function () {
    aimageUser(1, 1);
    aimageSiteWideRoots();

    expect(ImageScope::forUser(1)->resolveWriteFolder('assets/products/hero'))
        ->toBe('assets/products/hero');
});

test('a destination folder cannot climb out of the write root', function () {
    aimageUser(1, 1);
    aimageSiteWideRoots();

    $scope = ImageScope::forUser(1);

    // Traversal is refused outright rather than clamped: a folder that tries
    // to climb out is a mistake worth reporting, not a path to quietly
    // rewrite into something the manager did not ask for.
    expect($scope->resolveWriteFolder('../../etc'))->toBeNull()
        ->and($scope->resolveWriteFolder('assets/../core'))->toBeNull()
        ->and($scope->resolveWriteFolder("assets/\0/x"))->toBeNull();
});

test('writing outside the write root is refused even when the file root allows it', function () {
    aimageUser(1, 1);
    aimageSiteWideRoots();
    aimageEnsureDir(AIMAGE_TEST_ROOT . '/core/config');

    // `core/x.png` is inside the file-manager root and an unrestricted role
    // may write there, so nothing before this last gate refuses it. A step
    // queued under an older rule arrives looking exactly like this.
    expect(ImageScope::forUser(1)->write('core/config/x.png', aimagePng()))->toBeFalse()
        ->and(is_file(AIMAGE_TEST_ROOT . '/core/config/x.png'))->toBeFalse();
});

test('a step carrying a folder from before the write root existed is re-anchored', function () {
    aimageUser(1, 1);
    aimageSiteWideRoots();

    // What an upgraded site has sitting on its queue: a folder resolved when
    // `aimage` meant the file root. It has to land where the rule says now.
    expect(ImageScope::forUser(1)->uniqueRelativePath('aimage', 'hero', 'png'))
        ->toBe('assets/images/aimage/hero.png');
});

// ---------------------------------------------------------------------------
// The image base, and what the picker offers
// ---------------------------------------------------------------------------

test('the default base is the CMS images directory, not the whole assets folder', function () {
    aimageUser(1, 1);
    aimageSiteWideRoots();

    $scope = ImageScope::forUser(1);

    // `assets/images` is Evolution's own images directory — the installer
    // refuses to finish without it. The ceiling stays `assets` so an explicit
    // `assets/products` still works; only the default moves.
    expect($scope->imageBase())->toBe('assets/images')
        ->and($scope->writeRoot())->toBe(AIMAGE_TEST_ROOT . '/assets')
        ->and($scope->outputFolder())->toBe('assets/images/aimage');
});

test('a folder named inside the ceiling is honoured, not pushed under the base', function () {
    aimageUser(1, 1);
    aimageSiteWideRoots();

    // What the picker returns, and what a manager who names a real folder
    // means. Anchoring this under the base would give assets/images/assets/…
    expect(ImageScope::forUser(1)->resolveWriteFolder('assets/products'))->toBe('assets/products');
});

test('the images directory is not assumed into existence', function () {
    aimageUser(1, 1);
    aimageSetFileRoot('');
    AIMageTestCore::$config['rb_base_dir'] = '[(base_path)]assets/';
    aimageRemoveTree(AIMAGE_TEST_ROOT . '/assets/images');

    // A convention, not a setting: a site without the folder falls back to the
    // browser root rather than writing into a path that is not there.
    expect(ImageScope::forUser(1)->imageBase())->toBe('assets');
});

test('a confined manager gets no images suffix', function () {
    aimageUser(7);
    aimageSetFileRoot('assets');
    aimageSetFileRoot('assets/images', 7);
    AIMageTestCore::$config['rb_base_dir'] = '[(base_path)]assets/';
    aimageEnsureDir(AIMAGE_TEST_ROOT . '/assets/images/images');

    // Their root is already their image area. A folder that happens to be
    // called "images" inside it is a coincidence, not an instruction.
    expect(ImageScope::forUser(7)->imageBase())->toBe('')
        ->and(ImageScope::forUser(7)->outputFolder())->toBe('aimage');
});

test('the folder picker starts at the image base, not the file root', function () {
    aimageUser(1, 1);
    aimageSiteWideRoots();
    aimageEnsureDir(AIMAGE_TEST_ROOT . '/assets/images/products');

    $paths = array_column(ImageScope::forUser(1)->listFolders(), 'path');

    // These are candidate destinations. Rooted at the file root it offered
    // `core`, `manager` and `views`, which are the CMS's own directories.
    expect($paths)->toContain('assets/images/products')
        ->and($paths)->not->toContain('core')
        ->and($paths)->not->toContain('assets');
});

test('the CMS own directories are never offered or accepted', function () {
    aimageUser(1, 1);
    aimageSiteWideRoots();
    foreach (['assets/plugins', 'assets/cache', 'assets/images/fine'] as $dir) {
        aimageEnsureDir(AIMAGE_TEST_ROOT . '/' . $dir);
    }

    $scope = ImageScope::forUser(1);
    $paths = array_column($scope->listFolders('assets'), 'path');

    expect($paths)->not->toContain('assets/plugins')
        ->and($paths)->not->toContain('assets/cache')
        // Refused as a destination too: passing through the ceiling check is
        // not the same as being somewhere an image belongs.
        ->and($scope->resolveWriteFolder('assets/plugins'))->toBeNull()
        ->and($scope->resolveWriteFolder('assets/cache/x'))->toBeNull()
        ->and($scope->resolveWriteFolder('assets/images/fine'))->toBe('assets/images/fine');
});

// ---------------------------------------------------------------------------
// Browsing
// ---------------------------------------------------------------------------

test('browsing climbs to the ceiling and stops there', function () {
    aimageUser(1, 1);
    aimageSiteWideRoots();
    aimageEnsureDir(AIMAGE_TEST_ROOT . '/assets/images/products/hero');

    $scope = ImageScope::forUser(1);

    // Up from a nested folder, up again, and then nothing: above `assets`
    // there is nothing a result could be written to, so offering the climb
    // would only earn a refusal on arrival.
    expect($scope->parentFolder('assets/images/products/hero'))->toBe('assets/images/products')
        ->and($scope->parentFolder('assets/images'))->toBe('assets')
        ->and($scope->parentFolder('assets'))->toBeNull();
});

test('a confined manager cannot browse above their own root', function () {
    aimageUser(7);
    aimageSetFileRoot('assets');
    aimageSetFileRoot('assets/images', 7);
    AIMageTestCore::$config['rb_base_dir'] = '[(base_path)]assets/';
    aimagePutImage('deep/nested/a.png', 'assets/images');

    $scope = ImageScope::forUser(7);

    expect($scope->parentFolder('deep/nested'))->toBe('deep')
        ->and($scope->parentFolder('deep'))->toBe('')
        ->and($scope->parentFolder(''))->toBeNull();
});

test('the sibling of the image base is reachable by browsing up', function () {
    aimageUser(1, 1);
    aimageSiteWideRoots();
    aimageEnsureDir(AIMAGE_TEST_ROOT . '/assets/products');

    $scope = ImageScope::forUser(1);
    $paths = array_column($scope->listFolders($scope->parentFolder($scope->imageBase())), 'path');

    // The complaint this answers: the picker started at assets/images and
    // listed only its children, so assets/products — writable, and offered by
    // nothing — could be reached by naming it and no other way.
    expect($paths)->toContain('assets/products')
        ->and($paths)->toContain('assets/images')
        ->and($scope->resolveWriteFolder('assets/products'))->toBe('assets/products');
});

test('image metadata carries what a preview shows', function () {
    aimageUser(1, 1);
    aimageSiteWideRoots();
    aimagePutImage('images/a.png');

    $info = ImageScope::forUser(1)->imageInfo('assets/images/a.png');

    expect($info['name'])->toBe('a.png')
        ->and($info['folder'])->toBe('assets/images')
        ->and($info['url'])->toBe('https://example.test/assets/images/a.png')
        ->and($info['width'])->toBeGreaterThan(0)
        ->and($info['height'])->toBeGreaterThan(0)
        ->and($info['bytes'])->toBeGreaterThan(0);
});

test('metadata is refused for anything the manager may not read', function () {
    aimageUser(7, 3, [4]);
    aimageSetFileRoot('assets');
    aimagePutImage('images/private/secret.png');
    aimageRestrict('images/private', 9);

    $scope = ImageScope::forUser(7);

    expect($scope->imageInfo('images/private/secret.png'))->toBeNull()
        ->and($scope->imageInfo('../outside.png'))->toBeNull()
        ->and($scope->imageInfo('images/nothing-here.png'))->toBeNull();
});

// ---------------------------------------------------------------------------
// The page's own furniture
// ---------------------------------------------------------------------------

test('the hidden attribute outranks any class that sets display', function () {
    $styles = file_get_contents(dirname(__DIR__, 2) . '/views/partials/styles.blade.php');
    $script = file_get_contents(dirname(__DIR__, 2) . '/views/partials/script.blade.php');

    // Everything on the page is shown and hidden through the `hidden`
    // attribute, which the browser implements at UA-stylesheet strength — so
    // one class rule setting `display` pins a panel open with the attribute
    // set correctly and nothing in the console to say why. The dialog did
    // exactly that. Nothing catches it but this.
    expect($styles)->toContain('[hidden] { display: none !important; }')
        ->and($script)->toContain('.hidden = true');
});
