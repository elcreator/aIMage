<?php

namespace Elcreator\aIMage\Services;

use Closure;
use Elcreator\aIMage\Models\Job;
use Elcreator\aIMage\Models\JobStep;
use Elcreator\aIMage\Support\ImageScope;
use Throwable;

/**
 * "...and put the results on my computer": a finished job's images handed to aMount, which
 * sends them to any of its mounts - a cloud drive, or one of the person's own computers
 * through ai.artur.work (aMount's `ai-computer` driver; a computer's name such as "mypc"
 * works as the mount). aMount is a soft dependency: without it the option is refused when
 * the job is created, not discovered missing at the end.
 *
 * Each image is its own aMount delivery, so the computer decides per file (its folder,
 * quota, conflicts) and a refusal is reported per file - kept in the job's `result_json`
 * under `deliveries`, never dropped. An existing file at the other end is kept (`skip`):
 * replacing a person's file is not something an image job decides on its own.
 */
final class ResultDelivery
{
    /** Tests replace aMount (fn (int $userId): object with resolveMount()/deliver()). */
    public static ?Closure $factory = null;

    private const DELIVERY = '\\Elcreator\\aMount\\Services\\Delivery';

    public static function available(): bool
    {
        return self::$factory !== null || class_exists(self::DELIVERY);
    }

    private static function for(int $userId): object
    {
        if (self::$factory !== null) {
            return (self::$factory)($userId);
        }
        $class = self::DELIVERY;

        return $class::for($userId);
    }

    /**
     * Check, when the job is created, that the destination exists for this manager.
     *
     * @return string the mount as aMount knows it (its uuid)
     * @throws WorkbenchException
     */
    public static function check(int $userId, string $mount): string
    {
        if (!self::available()) {
            throw new WorkbenchException('deliver_unavailable', self::text('error_deliver_unavailable', [], 'Sending results elsewhere needs the aMount package.'));
        }
        try {
            $found = self::for($userId)->resolveMount($mount);
        } catch (Throwable $e) {
            throw new WorkbenchException('deliver_mount', self::text('error_deliver_mount', ['mount' => $mount, 'reason' => $e->getMessage()],
                'Cannot send the results to "' . $mount . '": ' . $e->getMessage()));
        }

        return (string) ($found['uuid'] ?? $mount);
    }

    /**
     * Send every image the job produced. Returns what was sent and what was refused, by file.
     *
     * @return array{sent: int, failed: int, files: array<int, array<string, mixed>>}
     */
    public static function deliver(Job $job): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'files' => []];
        $mount = trim((string) $job->deliver_to);
        if ($mount === '' || !self::available()) {
            return $out;
        }
        $scope = ImageScope::forUser((int) $job->user_id);
        $delivery = self::for((int) $job->user_id);
        $steps = JobStep::query()->where('job_id', $job->getKey())->where('status', JobStep::STATUS_SUCCEEDED)->get();
        foreach ($steps as $step) {
            foreach ((array) ($step->result_json['paths'] ?? []) as $relative) {
                $sitePath = self::sitePath($scope, (string) $relative);
                $row = ['file' => (string) $relative];
                try {
                    if ($sitePath === null) {
                        throw new \RuntimeException('The image is no longer where the job wrote it.');
                    }
                    $result = $delivery->deliver($sitePath, $mount, trim((string) $job->deliver_folder) ?: null,
                        ['origin' => 'aimage', 'on_conflict' => 'skip']);
                    $row += ['ok' => true, 'run' => (string) ($result['run'] ?? '')];
                    $out['sent']++;
                } catch (Throwable $e) {
                    $row += ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 300)];
                    $out['failed']++;
                }
                $out['files'][] = $row;
            }
        }

        return $out;
    }

    /** A translated message, or the English one where there is no translator (a worker, a test). */
    private static function text(string $key, array $replace, string $english): string
    {
        try {
            $text = __('aIMage::global.' . $key, $replace);

            return is_string($text) && $text !== 'aIMage::global.' . $key ? $text : $english;
        } catch (Throwable) {
            return $english;
        }
    }

    /** The image's path from the site root (aMount's frame), from the scope's own. */
    private static function sitePath(ImageScope $scope, string $relative): ?string
    {
        $absolute = $scope->absoluteOf($relative);
        if ($absolute === null || !defined('EVO_BASE_PATH')) {
            return null;
        }
        $base = rtrim(str_replace('\\', '/', (string) EVO_BASE_PATH), '/') . '/';
        $absolute = str_replace('\\', '/', $absolute);

        return str_starts_with($absolute, $base) ? substr($absolute, strlen($base)) : null;
    }
}
