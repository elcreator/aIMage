<?php

use Elcreator\aIMage\Models\Job;
use Elcreator\aIMage\Models\JobStep;
use Elcreator\aIMage\Models\Message;

/**
 * The language files, checked against each other.
 *
 * Every string on the page is looked up by key, and a key a language is missing
 * renders in English — quietly, with nothing in a log to say so. That is how
 * half a dialog ends up in the wrong language while every test passes: the code
 * was right, one of twenty-one files was simply never updated.
 *
 * English is the reference because it is the fallback the service provider
 * installs when the CMS has set none.
 */

function aimageLangFile(string $language): array
{
    return require dirname(__DIR__, 2) . '/lang/' . $language . '/global.php';
}

/** @return string[] */
function aimageLanguages(): array
{
    return array_map(
        static fn (string $path): string => basename(dirname($path)),
        glob(dirname(__DIR__, 2) . '/lang/*/global.php') ?: []
    );
}

test('every language the package ships carries every key', function () {
    $reference = aimageLangFile('en');
    $languages = aimageLanguages();

    expect($languages)->toContain('en')
        ->and(count($languages))->toBeGreaterThan(1);

    foreach ($languages as $language) {
        $rows = aimageLangFile($language);

        expect(array_diff(array_keys($reference), array_keys($rows)))
            ->toBe([], "lang/{$language}/global.php is missing keys");

        // An extra key is dead weight at best and a rename half-done at worst.
        expect(array_diff(array_keys($rows), array_keys($reference)))
            ->toBe([], "lang/{$language}/global.php has keys English does not");
    }
});

test('no translated string is empty', function () {
    foreach (aimageLanguages() as $language) {
        foreach (aimageLangFile($language) as $key => $value) {
            expect(trim((string) $value))->not->toBe('', "lang/{$language} has an empty {$key}");
        }
    }
});

test('placeholders survive translation', function () {
    $reference = aimageLangFile('en');

    foreach (aimageLanguages() as $language) {
        if ($language === 'en') {
            continue;
        }

        $rows = aimageLangFile($language);

        foreach ($reference as $key => $english) {
            preg_match_all('/:[a-z_0-9]+/', (string) $english, $expected);

            if ($expected[0] === []) {
                continue;
            }

            preg_match_all('/:[a-z_0-9]+/', (string) ($rows[$key] ?? ''), $actual);

            // A dropped `:provider` renders as a sentence with a hole in it,
            // and a renamed one renders as the literal placeholder.
            sort($expected[0]);
            sort($actual[0]);

            expect($actual[0])->toBe($expected[0], "lang/{$language} broke the placeholders in {$key}");
        }
    }
});

test('every state a job or a step can be in has a label', function () {
    $reference = aimageLangFile('en');

    $states = array_unique(array_merge(
        [Job::STATUS_PLANNING, Job::STATUS_AWAITING_INPUT, Job::STATUS_AWAITING_APPROVAL,
         Job::STATUS_RUNNING, Job::STATUS_SUCCEEDED, Job::STATUS_FAILED, Job::STATUS_CANCELLED],
        [JobStep::STATUS_QUEUED, JobStep::STATUS_RUNNING, JobStep::STATUS_POLLING,
         JobStep::STATUS_SUCCEEDED, JobStep::STATUS_FAILED, JobStep::STATUS_SKIPPED]
    ));

    // The page looks these up as `L['status_' + status]`, so a state without a
    // key does not fail — it renders its own raw value, in English, in the
    // middle of a translated page. `queued`, `polling` and `skipped` did
    // exactly that.
    foreach ($states as $state) {
        expect($reference)->toHaveKey('status_' . $state);
    }
});

test('every step type and model control has a label', function () {
    $reference = aimageLangFile('en');

    foreach ([JobStep::TYPE_GENERATE, JobStep::TYPE_EDIT, JobStep::TYPE_VARIATE,
              JobStep::TYPE_UPSCALE, JobStep::TYPE_DESCRIBE] as $type) {
        expect($reference)->toHaveKey('step_' . $type);
    }

    // Looked up as `L['control_' + field]`. The values these carry — 1024x1024,
    // auto, transparent — are the gateway's vocabulary and stay untranslated.
    foreach (['size', 'quality', 'background', 'aspect_ratio'] as $control) {
        expect($reference)->toHaveKey('control_' . $control);
    }

    // `L['turn_' + role]`, above each bubble in the thread. `tool` never
    // reaches a person, so it needs no label.
    foreach ([Message::ROLE_USER, Message::ROLE_ASSISTANT] as $role) {
        expect($reference)->toHaveKey('turn_' . $role);
    }
});

test('the page hands the front end every key it looks up by name', function () {
    $island = file_get_contents(dirname(__DIR__, 2) . '/views/page.blade.php');
    $script = file_get_contents(dirname(__DIR__, 2) . '/views/partials/script.blade.php');

    preg_match_all("/'([a-z_0-9]+)' => __\('aIMage::global\./", $island, $passed);
    preg_match_all('/\bL\.([a-z_0-9]+)/', $script, $used);

    // A key the script reads but the page never passes is `undefined` in the
    // browser, which renders as nothing at all rather than as an error.
    expect(array_diff(array_unique($used[1]), $passed[1]))->toBe([]);
});
