<?php

namespace Elcreator\aIMage\Console;

use Elcreator\aIMage\Agent\Executor;
use Elcreator\aIMage\Agent\Planner;
use Elcreator\aIMage\Gateway\Client;
use Elcreator\aIMage\Gateway\Estimator;
use Elcreator\aIMage\Gateway\ModelCatalog;
use Elcreator\aIMage\Models\Job;
use Elcreator\aIMage\Models\JobStep;
use Elcreator\aIMage\Support\ApiKeys;
use Elcreator\aIMage\Support\Config;
use Elcreator\aIMage\Support\ImageScope;
use Elcreator\aIMage\Support\JobQueue;
use EvolutionCMS\Interfaces\SystemTaskHandlerInterface;
use EvolutionCMS\Models\SystemCliTask;
use GuzzleHttp\Client as HttpClient;

/**
 * One slice of one job, run by the CMS's own task worker.
 *
 * This is the answer to "give the plugin a full brief and walk away for a
 * day". The handler never runs a job to completion — it works until a time
 * budget expires or nothing more can be done right now, queues its successor,
 * and returns. A batch therefore advances a little every minute, forever if
 * need be, while the worker stays free for site updates and package installs
 * between slices.
 *
 * Three properties fall out of that, and all three are the point:
 *
 *  - **Nothing is lost to a restart.** State lives in `aimage_job_steps`, so a
 *    killed worker resumes at the next step rather than the first.
 *  - **Nothing waits on a provider.** An asynchronous image parks in `polling`
 *    and is checked once per slice.
 *  - **Nothing runs unapproved.** A slice that finds the job waiting on a
 *    person stops and does not queue a successor; approving is what starts it
 *    again.
 */
class BatchHandler implements SystemTaskHandlerInterface
{
    /** The registered task type. Also the value in `system_cli_tasks.type`. */
    public const TYPE = 'aimage.batch';

    /**
     * Run for as long as this slice is allowed, then hand back.
     *
     * @param callable|null $report step/progress/message reporter from the worker
     * @return array{message: string, result: array}
     */
    public function execute(SystemCliTask $task, ?callable $report = null)
    {
        $job = $this->resolveJob($task);

        if ($job === null) {
            return [
                'message' => 'The job this task refers to no longer exists.',
                'result' => ['skipped' => true],
            ];
        }

        // A rotated key must take effect on the next slice, not on the next
        // restart of the process.
        ApiKeys::flush();

        if ($this->stopRequested($task, $job)) {
            return $this->conclude($job, 'Cancelled.', $report);
        }

        $apiKey = ApiKeys::forUser((int) $job->user_id);

        if ($apiKey === null) {
            $this->fail($job, 'NO_API_KEY', 'No API key is configured for this manager or for the site.');

            return $this->conclude($job, 'No API key configured.', $report);
        }

        $scope = ImageScope::forUser((int) $job->user_id);
        $client = $this->buildClient($apiKey);
        $catalog = new ModelCatalog($client);
        $estimator = new Estimator($catalog);

        $deadline = microtime(true) + max(5, (int) Config::limit('slice_seconds', 45));
        $advanced = 0;

        // Step ids already touched in this slice. Without it, a step that
        // parks in `polling` would be re-polled by the very next iteration and
        // then again, for the whole remaining budget — a hot loop of gateway
        // calls that asks a provider a hundred times a second whether it has
        // finished the image it was handed a moment ago. Each step gets one
        // attempt per slice; the next slice, a minute later, is when parked
        // work is looked at again.
        $attempted = [];

        while (microtime(true) < $deadline) {
            $job->refresh();

            if ($this->stopRequested($task, $job)) {
                return $this->conclude($job, 'Cancelled.', $report);
            }

            if (!$job->isRunnable()) {
                break;
            }

            if ((string) $job->status === Job::STATUS_PLANNING) {
                $outcome = (new Planner($client, $catalog, $estimator))->advance($job, $scope);
                $advanced++;

                $this->report($report, 'planning', $job, (string) $outcome['message']);

                // Planning is expensive and its result decides everything that
                // follows, so a turn ends the slice rather than rolling
                // straight into execution on stale state.
                break;
            }

            if (!$this->runOneStep($job, $scope, $client, $report, $attempted)) {
                // Nothing left that can move right now: every step is either
                // terminal, or parked, or has already had its turn this slice.
                break;
            }

            $advanced++;
        }

        $job->refresh();
        $job->refreshCounters();

        $this->settleIfComplete($job);

        if ($job->isRunnable()) {
            // More to do. Queue the successor before returning, so a crash
            // between here and the worker's bookkeeping still leaves the job
            // moving.
            JobQueue::enqueue($job);

            return [
                'message' => 'Advanced ' . $advanced . ' step(s); ' . $job->progressPercent() . '% complete.',
                'result' => [
                    'job_uuid' => (string) $job->uuid,
                    'status' => (string) $job->status,
                    'progress' => $job->progressPercent(),
                    'continued' => true,
                ],
            ];
        }

        return $this->conclude($job, $this->summaryFor($job), $report);
    }

    // ------------------------------------------------------------------
    // Steps
    // ------------------------------------------------------------------

    /**
     * Advance the next actionable step.
     *
     * Queued steps come first, then parked ones. Preferring fresh work means a
     * slice spends its budget starting images rather than re-asking a slow
     * provider whether it is done yet — the parked ones are cheap to check and
     * are swept up once there is nothing new to submit.
     *
     * @param int[] $attempted step ids already touched this slice, added to here
     * @return bool whether anything moved
     */
    private function runOneStep(
        Job $job,
        ImageScope $scope,
        Client $client,
        ?callable $report,
        array &$attempted
    ): bool {
        $step = JobStep::query()
            ->where('job_id', $job->getKey())
            ->where('status', JobStep::STATUS_QUEUED)
            ->whereNotIn('id', $attempted ?: [0])
            ->orderBy('seq')
            ->first();

        if ($step === null) {
            $step = JobStep::query()
                ->where('job_id', $job->getKey())
                ->whereIn('status', [JobStep::STATUS_POLLING, JobStep::STATUS_RUNNING])
                ->whereNotIn('id', $attempted ?: [0])
                ->orderBy('seq')
                ->first();
        }

        if ($step === null) {
            return false;
        }

        $attempted[] = (int) $step->getKey();

        // A step left `running` by a killed worker is put back in the queue:
        // it never reached a provider task id, so nothing is in flight and
        // re-submitting is the only way it will ever finish.
        if ((string) $step->status === JobStep::STATUS_RUNNING) {
            $step->requeue('Resumed after an interrupted slice.');
        }

        $moved = (new Executor($client, $scope, $this->buildDownloader()))->advance($job, $step);

        if ($moved) {
            $job->refreshCounters();

            $this->report(
                $report,
                'executing',
                $job,
                'Step ' . $step->seq . ' (' . $step->type . '): ' . $step->status
            );
        }

        return $moved;
    }

    /**
     * Mark the job finished once no step can move again.
     *
     * A job whose steps all failed is a failed job, not a successful one with
     * nothing to show — the manager asked for images and got none.
     */
    private function settleIfComplete(Job $job): void
    {
        if (!$job->isRunnable()) {
            return;
        }

        $unfinished = JobStep::query()
            ->where('job_id', $job->getKey())
            ->whereNotIn('status', JobStep::TERMINAL)
            ->exists();

        if ($unfinished) {
            return;
        }

        $total = (int) $job->steps_total;

        if ($total === 0) {
            // Running with no steps at all means the plan produced nothing.
            $this->fail($job, 'EMPTY_PLAN', 'The plan contained no image work.');

            return;
        }

        $succeeded = (int) $job->steps_done;

        if ($succeeded === 0) {
            $this->fail($job, 'ALL_STEPS_FAILED', 'Every step failed. See the step list for the reasons.');

            return;
        }

        $message = $succeeded === $total
            ? 'All ' . $total . ' step(s) completed.'
            : $succeeded . ' of ' . $total . ' step(s) completed; the rest failed.';

        $job->forceFill([
            'status' => Job::STATUS_SUCCEEDED,
            'message' => mb_substr($message, 0, 255),
            'error_code' => '',
            'finished_at' => now(),
            'updated_at' => now(),
        ])->save();
    }

    // ------------------------------------------------------------------
    // Seams
    // ------------------------------------------------------------------

    /**
     * The gateway client this slice talks through.
     *
     * A method rather than an inline `new` so a test can substitute a
     * transport without a network, and so a site that needs a proxy or a
     * different base URL has one place to change.
     */
    protected function buildClient(string $apiKey): Client
    {
        return new Client($apiKey);
    }

    /**
     * The HTTP client used to fetch finished images from a CDN.
     *
     * Null means "let the Executor build its own", which is what production
     * does. It is deliberately not the gateway client: that one carries the
     * API key, and the key has no business being sent to a third-party host.
     */
    protected function buildDownloader(): ?HttpClient
    {
        return null;
    }

    // ------------------------------------------------------------------
    // Plumbing
    // ------------------------------------------------------------------

    private function resolveJob(SystemCliTask $task): ?Job
    {
        $payload = is_array($task->payload_json) ? $task->payload_json : [];
        $jobId = (int) ($payload['job_id'] ?? 0);

        if ($jobId > 0) {
            $job = Job::query()->find($jobId);

            if ($job !== null) {
                return $job;
            }
        }

        // `target` carries the uuid, which survives a database copied between
        // environments where an auto-increment id may not.
        $uuid = trim((string) $task->target);

        return $uuid === '' ? null : Job::query()->where('uuid', $uuid)->first();
    }

    /**
     * Has anyone asked this to stop?
     *
     * Two authorities, deliberately: the CMS's own cancellation flag on the
     * task, and the job's own status, which is what our UI sets. Either is
     * enough.
     */
    private function stopRequested(SystemCliTask $task, Job $job): bool
    {
        if ((string) $job->status === Job::STATUS_CANCELLED) {
            return true;
        }

        $task->refresh();

        if ($task->cancellation_requested_at !== null) {
            if (!$job->isTerminal()) {
                $job->forceFill([
                    'status' => Job::STATUS_CANCELLED,
                    'message' => 'Cancelled.',
                    'finished_at' => now(),
                    'updated_at' => now(),
                ])->save();
            }

            return true;
        }

        return false;
    }

    private function fail(Job $job, string $code, string $message): void
    {
        $job->forceFill([
            'status' => Job::STATUS_FAILED,
            'error_code' => mb_substr($code, 0, 64),
            'message' => mb_substr($message, 0, 255),
            'finished_at' => now(),
            'updated_at' => now(),
        ])->save();
    }

    private function summaryFor(Job $job): string
    {
        $message = trim((string) $job->message);

        if ($message !== '') {
            return $message;
        }

        return match ((string) $job->status) {
            Job::STATUS_AWAITING_INPUT => 'Waiting for an answer from the manager.',
            Job::STATUS_AWAITING_APPROVAL => 'Waiting for approval.',
            Job::STATUS_CANCELLED => 'Cancelled.',
            default => 'Finished.',
        };
    }

    private function conclude(Job $job, string $message, ?callable $report): array
    {
        $this->report($report, 'finished', $job, $message);

        return [
            'message' => $message,
            'result' => [
                'job_uuid' => (string) $job->uuid,
                'status' => (string) $job->status,
                'progress' => $job->progressPercent(),
                'steps_done' => (int) $job->steps_done,
                'steps_failed' => (int) $job->steps_failed,
                'steps_total' => (int) $job->steps_total,
            ],
        ];
    }

    private function report(?callable $report, string $step, Job $job, string $message): void
    {
        if ($report === null) {
            return;
        }

        $report($step, $job->progressPercent(), mb_substr($message, 0, 255), 'info', [
            'job_uuid' => (string) $job->uuid,
            'job_status' => (string) $job->status,
        ]);
    }
}
