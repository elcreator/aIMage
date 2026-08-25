<?php

namespace Elcreator\aIMage\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One turn of the conversation that produced a plan.
 *
 * Stored in the canonical shape `Gateway\Dialect` encodes from, not in either
 * vendor's wire format. That is what lets a manager start a job against Claude,
 * come back tomorrow, switch to GPT because Claude is having an outage, and
 * have the transcript replay correctly — the tool calls survive the change of
 * dialect because they were never written in one.
 *
 * @property int $id
 * @property int $job_id
 * @property array|null $tool_calls_json
 * @property array|null $tool_results_json
 */
class Message extends Model
{
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';
    public const ROLE_TOOL = 'tool';

    protected $table = 'aimage_messages';

    public $timestamps = false;

    protected $fillable = [
        'job_id',
        'seq',
        'role',
        'text',
        'tool_calls_json',
        'tool_results_json',
        'audio_path',
        'created_at',
    ];

    protected $casts = [
        'job_id' => 'integer',
        'seq' => 'integer',
        'tool_calls_json' => 'array',
        'tool_results_json' => 'array',
        'created_at' => 'datetime',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    /**
     * This row as one entry of a canonical transcript.
     *
     * @see \Elcreator\aIMage\Gateway\Dialect
     */
    public function toTranscriptEntry(): array
    {
        $role = (string) $this->role;

        if ($role === self::ROLE_TOOL) {
            return [
                'role' => 'tool',
                'results' => (array) ($this->tool_results_json ?? []),
            ];
        }

        if ($role === self::ROLE_ASSISTANT) {
            return [
                'role' => 'assistant',
                'text' => (string) $this->text,
                'tool_calls' => (array) ($this->tool_calls_json ?? []),
            ];
        }

        return ['role' => 'user', 'text' => (string) $this->text];
    }

    /**
     * Append a turn, numbering it from what is already stored.
     *
     * The sequence comes from a MAX() rather than a counter on the job so that
     * two writers — a manager typing while a worker records a planner turn —
     * cannot both claim the same number and silently reorder the transcript.
     *
     * **Not named `append()`.** Eloquent's Model already has a non-static
     * `append()` for adding accessors to a model's array form, and PHP refuses
     * to redeclare an inherited instance method as static — the class does not
     * merely misbehave, it fails to load at all. Renaming this back would take
     * the planner down with it.
     */
    public static function record(int $jobId, string $role, array $attributes = []): self
    {
        $seq = (int) static::query()->where('job_id', $jobId)->max('seq');

        return static::query()->create($attributes + [
            'job_id' => $jobId,
            'seq' => $seq + 1,
            'role' => $role,
            'created_at' => now(),
        ]);
    }

    /**
     * The whole conversation for a job, ready to hand to the planner.
     *
     * @return array<int, array>
     */
    public static function transcriptFor(int $jobId): array
    {
        return static::query()
            ->where('job_id', $jobId)
            ->orderBy('seq')
            ->get()
            ->map(static fn (self $message) => $message->toTranscriptEntry())
            ->all();
    }
}
