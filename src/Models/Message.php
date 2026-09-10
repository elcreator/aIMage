<?php

namespace Elcreator\aIMage\Models;

use Elcreator\aIMage\Agent\Tools;
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
        'internal',
        'text',
        'tool_calls_json',
        'tool_results_json',
        'audio_path',
        'created_at',
    ];

    protected $casts = [
        'job_id' => 'integer',
        'seq' => 'integer',
        'internal' => 'boolean',
        'tool_calls_json' => 'array',
        'tool_results_json' => 'array',
        'created_at' => 'datetime',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    /**
     * Is this turn worth showing a person?
     *
     * The transcript and the conversation are not the same thing. The planner
     * needs every row to replay correctly — tool calls and their results
     * included — while the manager needs the turns that were addressed to
     * them. Two kinds of row are machinery:
     *
     *  - `tool` rows, which are the plumbing of a tool-calling loop;
     *  - `assistant` rows with no text, which is what a turn looks like when
     *    the model queued work instead of saying something. Rendering those
     *    gives a blank speech bubble per planning turn, which reads as the
     *    assistant having replied with nothing. What it actually did is in the
     *    step list.
     */
    public function isConversational(): bool
    {
        if ((string) $this->role === self::ROLE_TOOL || (bool) $this->internal) {
            return false;
        }

        return (string) $this->role === self::ROLE_USER || trim((string) $this->text) !== '';
    }

    /**
     * The text as a person should read it.
     *
     * Models sometimes write a tool call out as prose — `[ask_user(question=
     * "...")]` on the end of a sentence — instead of emitting it through the
     * tool-calling channel. The transcript keeps that verbatim, because the
     * model has to see its own turn to correct it, but the manager has no use
     * for our internal function names and should never be shown them.
     *
     * Only our own tool names are stripped, and only where they appear as a
     * bracketed call. Anything else the model wrote is left exactly alone —
     * a sanitiser that guesses is worse than the artefact it removes.
     */
    public function displayText(): string
    {
        return static::stripToolSyntax((string) $this->text);
    }

    /**
     * Remove tool-call syntax a model wrote out as prose.
     *
     * Static because the same text reaches a manager by two routes — as a turn
     * in the thread, and as the job's own message when the planner gives up on
     * a model that will not use its tools — and both have to be clean.
     */
    public static function stripToolSyntax(string $text): string
    {
        $names = implode('|', array_map('preg_quote', [
            Tools::LIST_IMAGES, Tools::LIST_FOLDERS,
            Tools::PLAN_GENERATE, Tools::PLAN_EDIT, Tools::PLAN_VARIATE, Tools::PLAN_UPSCALE,
            Tools::ASK_USER, Tools::FINISH,
        ]));

        // Bracketed and bare forms both appear in the wild: `[ask_user(...)]`
        // and a bare `ask_user(...)` on its own.
        $text = preg_replace('/\[\s*(?:' . $names . ')\s*\((?:[^\[\]]*)\)\s*\]/u', '', $text);
        $text = preg_replace('/(?:' . $names . ')\s*\([^()]*\)/u', '', (string) $text);

        // Collapse the trailing whitespace a removed call leaves behind.
        return trim(preg_replace('/[ \\t]+(\\r?\\n)/u', '$1', (string) $text));
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
