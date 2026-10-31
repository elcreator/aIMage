<?php

use Elcreator\aIMage\Models\JobStep;
use Elcreator\aIMage\Services\ResultDelivery;
use Elcreator\aIMage\Services\WorkbenchException;

/**
 * "...and put the results on my computer": a finished job's images handed to aMount, file by
 * file, with every refusal kept - never dropped.
 */

/** aMount as ResultDelivery sees it: resolves a mount, takes deliveries, can refuse one. */
final class FakeAmountDelivery
{
    public array $calls = [];

    public function resolveMount(?string $hint): array
    {
        if ($hint === 'nowhere') {
            throw new RuntimeException('No such mount.');
        }

        return ['uuid' => 'uuid-' . $hint, 'name' => $hint];
    }

    public function deliver(string $path, ?string $mount, ?string $folder, array $options): array
    {
        $this->calls[] = compact('path', 'mount', 'folder', 'options');
        if (str_contains($path, 'CON')) {
            throw new RuntimeException('the name "CON.png" is reserved on Windows');
        }

        return ['run' => 'run-' . count($this->calls)];
    }
}

beforeEach(function () {
    aimageReset();
    ResultDelivery::$factory = null;
});

afterEach(function () {
    ResultDelivery::$factory = null;
});

test('without aMount the option is refused when the job is made, not at the end', function () {
    if (class_exists('Elcreator\\aMount\\Services\\Delivery')) {
        $this->markTestSkipped('aMount is installed here');
    }
    expect(fn () => ResultDelivery::check(7, 'mypc'))->toThrow(WorkbenchException::class);
});

test('the destination is resolved up front, and a wrong one says why', function () {
    $fake = new FakeAmountDelivery();
    ResultDelivery::$factory = static fn () => $fake;

    expect(ResultDelivery::check(7, 'mypc'))->toBe('uuid-mypc');
    expect(fn () => ResultDelivery::check(7, 'nowhere'))->toThrow(WorkbenchException::class);
});

test('every image of the job is sent, keeping what is there, and a refusal is reported per file', function () {
    aimageUser(7, 1);
    aimageSetFileRoot('assets');
    aimagePutImage('aimage/lake.png');
    aimagePutImage('aimage/CON.png');
    $fake = new FakeAmountDelivery();
    ResultDelivery::$factory = static fn () => $fake;
    $job = aimageJob(['deliver_to' => 'uuid-mypc', 'deliver_folder' => 'photos/2025']);
    aimageStep($job, ['status' => JobStep::STATUS_SUCCEEDED, 'result_json' => ['paths' => ['aimage/lake.png', 'aimage/CON.png']]]);
    aimageStep($job, ['status' => JobStep::STATUS_FAILED, 'result_json' => ['paths' => ['aimage/never.png']]]);

    $result = ResultDelivery::deliver($job);

    expect($result['sent'])->toBe(1)->and($result['failed'])->toBe(1)
        ->and($fake->calls)->toHaveCount(2)
        ->and($fake->calls[0]['path'])->toBe('assets/aimage/lake.png')
        ->and($fake->calls[0]['mount'])->toBe('uuid-mypc')
        ->and($fake->calls[0]['folder'])->toBe('photos/2025')
        ->and($fake->calls[0]['options']['on_conflict'])->toBe('skip')
        ->and($result['files'][1]['error'])->toContain('reserved on Windows');
});

test('a job without a destination sends nothing', function () {
    $fake = new FakeAmountDelivery();
    ResultDelivery::$factory = static fn () => $fake;

    expect(ResultDelivery::deliver(aimageJob())['sent'])->toBe(0)->and($fake->calls)->toBe([]);
});
