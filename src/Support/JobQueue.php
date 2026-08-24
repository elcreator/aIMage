<?php

namespace EvolutionCMS\aIMage\Support;

use EvolutionCMS\aIMage\Console\BatchHandler;
use EvolutionCMS\aIMage\Models\Job;
use EvolutionCMS\Models\SystemCliTask;
use Illuminate\Support\Str;

/**
 * Puts a job on the CMS's own task queue.
 *
 * A row in `system_cli_tasks`, not a table of our own — so a batch appears on
 * the worker-health page beside a site update, inherits leases and the
 * cancellation flag, and is picked up by the scheduler that is already
 * running. The only reason this class exists is that `SystemTaskService`
 * keeps its `persistQueuedTask()` protected and offers no public way to queue
 * an arbitrary registered type.
 *
 * One task carries one *slice* of a job, not the whole job. When a slice ends
 * with work still to do it queues the next one. That is what lets a batch of
 * two hundred images share a worker with everything else instead of owning it
 * for the night.
 */
final class JobQueue
{
    /**
     * Queue the next slice for a job, unless one is already waiting.
     *
     * Idempotent on purpose: the UI calls it when the manager approves a plan,
     * the handler calls it at the end of every slice, and a double call must
     * not put two workers on the same job.
     */
    public static function enqueue(Job $job): ?SystemCliTask
    {
        if (!$job->isRunnable()) {
            return null;
        }

        $existing = static::pendingTaskFor($job);

        if ($existing !== null) {
            return $existing;
        }

        $now = now();

        $task = SystemCliTask::query()->create([
            'uuid' => (string) Str::uuid(),
            'type' => BatchHandler::TYPE,
            'target' => (string) $job->uuid,
            'requested_version' => '',
            'status' => 'queued',
            'step' => 'queued',
            'progress' => $job->progressPercent(),
            'message' => 'Queued',
            'payload_json' => [
                'job_id' => (int) $job->getKey(),
                'job_uuid' => (string) $job->uuid,
                // Denormalised so the task list can label the row without
                // joining our tables.
                'display_title' => mb_substr((string) $job->title, 0, 191),
                'task_type' => BatchHandler::TYPE,
            ],
            'result_json' => [],
            'created_by' => (int) $job->user_id,
            'locked_by' => '',
            'attempt_count' => 0,
            'worker_host' => '',
            'worker_pid' => null,
            'error_code' => '',
            'catalog_snapshot_hash' => '',
            'requested_by_snapshot' => ['user_id' => (int) $job->user_id],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $job->forceFill([
            'system_task_id' => (int) $task->getKey(),
            'updated_at' => $now,
        ])->save();

        return $task;
    }

    /**
     * A task for this job that has not finished yet, if there is one.
     *
     * `picked` and `running` count as well as `queued`: a slice currently in
     * flight will queue its own successor, and adding another here would put
     * two workers on one job's steps.
     */
    public static function pendingTaskFor(Job $job): ?SystemCliTask
    {
        return SystemCliTask::query()
            ->where('type', BatchHandler::TYPE)
            ->where('target', (string) $job->uuid)
            ->whereIn('status', ['queued', 'picked', 'running'])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Ask the queue to stop working on a job.
     *
     * The job's own status is the authority — the handler checks it at the top
     * of every slice — so a queued task is cancelled outright and an in-flight
     * one is left to notice on its next pass rather than being killed.
     */
    public static function cancel(Job $job): void
    {
        $now = now();

        $job->forceFill([
            'status' => Job::STATUS_CANCELLED,
            'message' => 'Cancelled.',
            'finished_at' => $now,
            'updated_at' => $now,
        ])->save();

        SystemCliTask::query()
            ->where('type', BatchHandler::TYPE)
            ->where('target', (string) $job->uuid)
            ->where('status', 'queued')
            ->update([
                'status' => 'cancelled',
                'step' => 'cancelled',
                'message' => 'Cancelled by the manager.',
                'cancellation_requested_at' => $now,
                'finished_at' => $now,
                'updated_at' => $now,
            ]);

        SystemCliTask::query()
            ->where('type', BatchHandler::TYPE)
            ->where('target', (string) $job->uuid)
            ->whereIn('status', ['picked', 'running'])
            ->update([
                'cancellation_requested_at' => $now,
                'updated_at' => $now,
            ]);
    }
}
