<?php

namespace Elcreator\aIMage\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One thing a manager asked for, from the first sentence to the last image.
 *
 * The status values are the whole design in miniature, so they are worth
 * reading as a sequence rather than a list:
 *
 *   planning           a text model is turning the conversation into steps
 *   awaiting_input     it asked a question and cannot proceed until answered
 *   awaiting_approval  the plan is priced and costs more than the threshold
 *   running            steps are being carried out, a slice at a time
 *   succeeded/failed/cancelled
 *
 * `awaiting_input` and `awaiting_approval` are what make "give the full task
 * and walk away" honest: the job parks in the database rather than guessing,
 * and picks up exactly where it stopped whenever the manager comes back.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $status
 * @property array|null $controls_json
 * @property array|null $estimate_json
 * @property array|null $result_json
 */
class Job extends Model
{
    public const STATUS_PLANNING = 'planning';
    public const STATUS_AWAITING_INPUT = 'awaiting_input';
    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    /** States from which nothing more will happen without a new instruction. */
    public const TERMINAL = [
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    /** States where the job is waiting on the manager, not on the machine. */
    public const WAITING_ON_HUMAN = [
        self::STATUS_AWAITING_INPUT,
        self::STATUS_AWAITING_APPROVAL,
    ];

    protected $table = 'aimage_jobs';

    protected $fillable = [
        'uuid',
        'user_id',
        'status',
        'title',
        'text_model',
        'image_model',
        'voice_model',
        'controls_json',
        'output_folder',
        'estimate_json',
        'planner_turns',
        'steps_total',
        'steps_done',
        'steps_failed',
        'system_task_id',
        'error_code',
        'message',
        'result_json',
        'approved_at',
        'finished_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'planner_turns' => 'integer',
        'steps_total' => 'integer',
        'steps_done' => 'integer',
        'steps_failed' => 'integer',
        'system_task_id' => 'integer',
        'controls_json' => 'array',
        'estimate_json' => 'array',
        'result_json' => 'array',
        'approved_at' => 'datetime',
        'finished_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function steps(): HasMany
    {
        return $this->hasMany(JobStep::class, 'job_id')->orderBy('seq');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'job_id')->orderBy('seq');
    }

    public function isTerminal(): bool
    {
        return in_array((string) $this->status, self::TERMINAL, true);
    }

    public function isWaitingOnHuman(): bool
    {
        return in_array((string) $this->status, self::WAITING_ON_HUMAN, true);
    }

    /**
     * Should the worker be carrying this job forward right now?
     *
     * A job waiting on a person is deliberately not runnable: re-queueing it
     * every minute would burn planner turns re-asking a question nobody has
     * answered yet.
     */
    public function isRunnable(): bool
    {
        return !$this->isTerminal() && !$this->isWaitingOnHuman();
    }

    /** The controls a step inherits when the plan does not name its own. */
    public function controls(): array
    {
        return (array) ($this->controls_json ?? []);
    }

    public function progressPercent(): int
    {
        $total = max(0, (int) $this->steps_total);

        if ($total === 0) {
            return $this->isTerminal() ? 100 : 0;
        }

        $settled = min($total, (int) $this->steps_done + (int) $this->steps_failed);

        return (int) floor($settled / $total * 100);
    }

    /**
     * Recount progress from the steps themselves.
     *
     * The counters on the job are a denormalised cache for the listing page;
     * the steps are the truth. Recomputing rather than incrementing means a
     * step retried after a worker was killed cannot double-count.
     */
    public function refreshCounters(): void
    {
        $counts = JobStep::query()
            ->where('job_id', $this->getKey())
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $done = (int) ($counts[JobStep::STATUS_SUCCEEDED] ?? 0);
        $failed = (int) ($counts[JobStep::STATUS_FAILED] ?? 0)
            + (int) ($counts[JobStep::STATUS_SKIPPED] ?? 0);

        $this->forceFill([
            'steps_total' => array_sum($counts),
            'steps_done' => $done,
            'steps_failed' => $failed,
            'updated_at' => now(),
        ])->save();
    }
}
