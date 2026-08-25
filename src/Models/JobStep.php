<?php

namespace Elcreator\aIMage\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One image operation: the unit that either changes a file or does not.
 *
 * `polling` is the status that makes long batches possible. An asynchronous
 * provider answers with a task id instead of an image, and the obvious thing —
 * sleeping until it finishes — would hold a worker for the length of the
 * slowest provider, times the number of images. Instead the step records the
 * id, parks, and a later slice asks once whether it is done. A job of two
 * hundred images therefore costs the worker a few seconds a minute rather than
 * a night.
 *
 * @property int $id
 * @property int $job_id
 * @property array|null $params_json
 * @property array|null $result_json
 */
class JobStep extends Model
{
    public const TYPE_GENERATE = 'generate';
    public const TYPE_EDIT = 'edit';
    public const TYPE_VARIATE = 'variate';
    public const TYPE_UPSCALE = 'upscale';
    public const TYPE_DESCRIBE = 'describe';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_POLLING = 'polling';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    /** Deliberately not attempted — a source that vanished, or a job cancelled mid-flight. */
    public const STATUS_SKIPPED = 'skipped';

    public const TERMINAL = [
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
        self::STATUS_SKIPPED,
    ];

    protected $table = 'aimage_job_steps';

    protected $fillable = [
        'job_id',
        'seq',
        'type',
        'status',
        'model',
        'prompt',
        'params_json',
        'source_path',
        'target_path',
        'provider_task_id',
        'provider_model',
        'attempt_count',
        'error_code',
        'message',
        'result_json',
        'started_at',
        'finished_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'job_id' => 'integer',
        'seq' => 'integer',
        'attempt_count' => 'integer',
        'params_json' => 'array',
        'result_json' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function isTerminal(): bool
    {
        return in_array((string) $this->status, self::TERMINAL, true);
    }

    public function params(): array
    {
        return (array) ($this->params_json ?? []);
    }

    public function param(string $key, $default = null)
    {
        return $this->params()[$key] ?? $default;
    }

    /**
     * How many images this step is expected to produce.
     *
     * Generation and variation both take an `n`; an edit and an upscale
     * produce exactly one result from exactly one source. Getting this wrong
     * is not cosmetic — it feeds both the job's image budget and the cost
     * estimate, so treating a five-way variation as one image undercounts the
     * bill by a factor of five.
     */
    public function expectedImages(): int
    {
        if (!in_array((string) $this->type, [self::TYPE_GENERATE, self::TYPE_VARIATE], true)) {
            return 1;
        }

        return max(1, (int) $this->param('n', 1));
    }

    public function markRunning(): void
    {
        $this->forceFill([
            'status' => self::STATUS_RUNNING,
            'attempt_count' => (int) $this->attempt_count + 1,
            'started_at' => $this->started_at ?: now(),
            'updated_at' => now(),
        ])->save();
    }

    public function markPolling(string $providerTaskId, string $providerModel): void
    {
        $this->forceFill([
            'status' => self::STATUS_POLLING,
            'provider_task_id' => $providerTaskId,
            'provider_model' => $providerModel,
            'updated_at' => now(),
        ])->save();
    }

    public function markSucceeded(string $targetPath, array $result = []): void
    {
        $this->forceFill([
            'status' => self::STATUS_SUCCEEDED,
            'target_path' => $targetPath,
            'result_json' => $result,
            'error_code' => '',
            'message' => '',
            'finished_at' => now(),
            'updated_at' => now(),
        ])->save();
    }

    public function markFailed(string $errorCode, string $message): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'error_code' => mb_substr($errorCode, 0, 64),
            'message' => mb_substr($message, 0, 500),
            'finished_at' => now(),
            'updated_at' => now(),
        ])->save();
    }

    public function markSkipped(string $message): void
    {
        $this->forceFill([
            'status' => self::STATUS_SKIPPED,
            'message' => mb_substr($message, 0, 500),
            'finished_at' => now(),
            'updated_at' => now(),
        ])->save();
    }

    /**
     * Put a failed attempt back in the queue.
     *
     * Only transient failures come back here — see GatewayException::retryable.
     * A 400 will fail identically every time, and burning four attempts on it
     * only delays the message the manager needs to read.
     */
    public function requeue(string $message): void
    {
        $this->forceFill([
            'status' => self::STATUS_QUEUED,
            'provider_task_id' => '',
            'message' => mb_substr($message, 0, 500),
            'updated_at' => now(),
        ])->save();
    }
}
