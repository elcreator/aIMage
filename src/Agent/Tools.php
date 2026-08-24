<?php

namespace EvolutionCMS\aIMage\Agent;

use EvolutionCMS\aIMage\Gateway\Estimate;
use EvolutionCMS\aIMage\Gateway\Estimator;
use EvolutionCMS\aIMage\Gateway\ModelCatalog;
use EvolutionCMS\aIMage\Models\Job;
use EvolutionCMS\aIMage\Models\JobStep;
use EvolutionCMS\aIMage\Support\Config;
use EvolutionCMS\aIMage\Support\ImageScope;

/**
 * What the planner is allowed to do, and what happens when it does it.
 *
 * The division of labour here is the point of the whole design: **the model
 * plans, the worker acts**. Every tool below either reads something or appends
 * rows to `aimage_job_steps` — none of them calls an image model. That has
 * three consequences worth stating, because each one is a thing that goes
 * wrong in agent designs that skip it:
 *
 *  - A plan can be priced before a cent is spent, so `awaiting_approval` is a
 *    real gate rather than a formality after the fact.
 *  - A planner that loops, hallucinates a folder, or asks for four hundred
 *    images fails at planning time and costs nothing.
 *  - Execution survives the planner. The conversation can be finished, the
 *    browser closed and the process killed, and the steps are still sitting in
 *    a table waiting for the next slice.
 *
 * Every path a tool receives is a string a language model produced, so it is
 * treated as hostile input: nothing is touched that `ImageScope` has not
 * agreed the manager may touch.
 */
class Tools
{
    /** Reading is free and instant, so the planner may look before it plans. */
    public const LIST_IMAGES = 'list_images';
    public const LIST_FOLDERS = 'list_folders';

    /** These append steps. */
    public const PLAN_GENERATE = 'plan_generate';
    public const PLAN_EDIT = 'plan_edit';
    public const PLAN_VARIATE = 'plan_variate';
    public const PLAN_UPSCALE = 'plan_upscale';

    /** Control flow. */
    public const ASK_USER = 'ask_user';
    public const FINISH = 'finish';

    public function __construct(
        private readonly Job $job,
        private readonly ImageScope $scope,
        private readonly ModelCatalog $catalog,
        private readonly Estimator $estimator
    ) {
    }

    /**
     * The tool definitions, in the canonical shape `Dialect` encodes from.
     *
     * Descriptions carry the constraints rather than leaving them to be
     * discovered through errors: a model told the ceiling up front plans
     * within it, while a model that finds out by being refused spends a turn
     * apologising and another guessing again.
     */
    public function definitions(): array
    {
        $maxImages = (int) Config::limit('max_images_per_job', 200);
        $extensions = implode(', ', $this->scope->allowedExtensions());

        return [
            [
                'name' => self::LIST_IMAGES,
                'description' => 'List the image files the manager may work with. Use this before planning work on '
                    . '"the existing images" so you operate on real paths instead of guessing. Returns paths relative '
                    . 'to the manager\'s file area. Allowed extensions: ' . $extensions . '.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'folder' => [
                            'type' => 'string',
                            'description' => 'Folder to list, relative to the manager\'s file root. Empty string means the root.',
                        ],
                        'recursive' => [
                            'type' => 'boolean',
                            'description' => 'Include sub-folders. Defaults to false.',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => self::LIST_FOLDERS,
                'description' => 'List folders the manager may use, to choose where results should be written.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'folder' => ['type' => 'string', 'description' => 'Parent folder. Empty string means the root.'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => self::PLAN_GENERATE,
                'description' => 'Queue generation of new images from a text prompt. This does not run now — it adds '
                    . 'steps to the batch, which is carried out afterwards. At most ' . $maxImages . ' images per job.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'prompt' => ['type' => 'string', 'description' => 'The image prompt. Be specific; this is what the image model sees.'],
                        'count' => ['type' => 'integer', 'description' => 'How many images to generate. Defaults to 1.'],
                        'size' => ['type' => 'string', 'description' => 'Optional. Must be one the chosen model supports.'],
                        'quality' => ['type' => 'string', 'description' => 'Optional. Must be one the chosen model supports.'],
                        'background' => ['type' => 'string', 'description' => 'Optional, e.g. transparent, for models that support it.'],
                        'basename' => ['type' => 'string', 'description' => 'Optional file name stem for the results.'],
                        'folder' => ['type' => 'string', 'description' => 'Optional destination folder. Defaults to the job\'s output folder.'],
                    ],
                    'required' => ['prompt'],
                ],
            ],
            [
                'name' => self::PLAN_EDIT,
                'description' => 'Queue an edit of existing images with a prompt, one step per image. Use paths from '
                    . self::LIST_IMAGES . '. Originals are never overwritten; each result is written as a new file.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'paths' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Image paths relative to the manager\'s file root.',
                        ],
                        'prompt' => ['type' => 'string', 'description' => 'What to change about each image.'],
                        'size' => ['type' => 'string'],
                        'quality' => ['type' => 'string'],
                        'folder' => ['type' => 'string', 'description' => 'Optional destination folder.'],
                    ],
                    'required' => ['paths', 'prompt'],
                ],
            ],
            [
                'name' => self::PLAN_VARIATE,
                'description' => 'Queue variations of existing images.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'paths' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'count' => ['type' => 'integer', 'description' => 'Variations per image. Defaults to 1.'],
                        'prompt' => ['type' => 'string', 'description' => 'Optional steer; ignored by providers whose variation endpoint takes no prompt.'],
                        'folder' => ['type' => 'string'],
                    ],
                    'required' => ['paths'],
                ],
            ],
            [
                'name' => self::PLAN_UPSCALE,
                'description' => 'Queue an upscale of existing images. Upscaling always runs on '
                    . ModelCatalog::UPSCALE_MODEL . ' regardless of the chosen image model.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'paths' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'scale' => ['type' => 'integer', 'description' => 'Factor, typically 2 or 4. Defaults to 2.'],
                        'folder' => ['type' => 'string'],
                    ],
                    'required' => ['paths'],
                ],
            ],
            [
                'name' => self::ASK_USER,
                'description' => 'Ask the manager a question and stop. Use this whenever the request is ambiguous in a '
                    . 'way that would change the images — subject, style, count, which files, where results go. Do not '
                    . 'guess and do not ask about things you can find out with ' . self::LIST_IMAGES . '.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'question' => ['type' => 'string'],
                        'options' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Optional suggested answers, offered as buttons.',
                        ],
                    ],
                    'required' => ['question'],
                ],
            ],
            [
                'name' => self::FINISH,
                'description' => 'Declare the plan complete. Call this once every step has been queued. Do not call it '
                    . 'if nothing has been queued — ask a question instead.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'summary' => ['type' => 'string', 'description' => 'One sentence describing what the batch will do.'],
                    ],
                    'required' => ['summary'],
                ],
            ],
        ];
    }

    /**
     * Run one tool call.
     *
     * @return array{content: string, control?: string, question?: string, options?: array, summary?: string}
     *         `content` goes back to the model as the tool result; `control`
     *         tells the planner to stop the turn.
     */
    public function dispatch(string $name, array $input): array
    {
        return match ($name) {
            self::LIST_IMAGES => $this->listImages($input),
            self::LIST_FOLDERS => $this->listFolders($input),
            self::PLAN_GENERATE => $this->planGenerate($input),
            self::PLAN_EDIT => $this->planImageToImage($input, JobStep::TYPE_EDIT),
            self::PLAN_VARIATE => $this->planImageToImage($input, JobStep::TYPE_VARIATE),
            self::PLAN_UPSCALE => $this->planImageToImage($input, JobStep::TYPE_UPSCALE),
            self::ASK_USER => $this->askUser($input),
            self::FINISH => $this->finish($input),
            default => ['content' => 'There is no tool called "' . $name . '".'],
        };
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    private function listImages(array $input): array
    {
        $folder = (string) ($input['folder'] ?? '');
        $recursive = (bool) ($input['recursive'] ?? false);

        $images = $this->scope->listImages($folder, $recursive);

        if ($images === []) {
            return ['content' => 'No images the manager may access were found in "' . ($folder ?: '(root)') . '".'];
        }

        $lines = [];

        foreach ($images as $image) {
            $lines[] = $image['path'] . ' (' . $this->humanBytes((int) $image['size']) . ')';
        }

        return ['content' => count($images) . " image(s):\n" . implode("\n", $lines)];
    }

    private function listFolders(array $input): array
    {
        $folders = $this->scope->listFolders((string) ($input['folder'] ?? ''));

        if ($folders === []) {
            return ['content' => 'No sub-folders the manager may access.'];
        }

        return ['content' => implode("\n", array_column($folders, 'path'))];
    }

    // ------------------------------------------------------------------
    // Planning
    // ------------------------------------------------------------------

    private function planGenerate(array $input): array
    {
        $prompt = trim((string) ($input['prompt'] ?? ''));

        if ($prompt === '') {
            return ['content' => 'A prompt is required.'];
        }

        $count = max(1, (int) ($input['count'] ?? 1));
        $model = (string) $this->job->image_model;

        if (!$this->catalog->supports($model, ModelCatalog::ACTION_TEXT_TO_IMAGE)) {
            return ['content' => 'The selected image model "' . $model . '" cannot generate images from text. '
                . 'Ask the manager to pick a different model.'];
        }

        $remaining = $this->remainingImageBudget();

        if ($remaining <= 0) {
            return ['content' => 'This job has already reached its image limit. Queue nothing further.'];
        }

        $capped = min($count, $remaining);
        $controls = $this->controlsFrom($input, $model);

        if (is_string($controls)) {
            return ['content' => $controls];
        }

        $folder = $this->destinationFolder($input);

        if ($folder === null) {
            return ['content' => 'The manager may not write to the folder "' . (string) ($input['folder'] ?? '') . '".'];
        }

        $this->appendStep(JobStep::TYPE_GENERATE, [
            'model' => $model,
            'prompt' => $prompt,
            'params' => $controls + [
                'n' => $capped,
                'folder' => $folder,
                'basename' => $this->scope->sanitizeBasename((string) ($input['basename'] ?? 'aimage')) ?: 'aimage',
            ],
        ]);

        $note = $capped < $count
            ? ' (reduced from ' . $count . ' to stay within this job\'s limit)'
            : '';

        return ['content' => 'Queued generation of ' . $capped . ' image(s) into "' . $folder . '"' . $note . '.'];
    }

    /**
     * Edit, variate and upscale differ only in which action they need and
     * which parameters they carry, so they share one planner.
     */
    private function planImageToImage(array $input, string $type): array
    {
        $paths = array_values(array_filter(array_map('strval', (array) ($input['paths'] ?? []))));

        if ($paths === []) {
            return ['content' => 'At least one image path is required. Use ' . self::LIST_IMAGES . ' to find them.'];
        }

        $model = $type === JobStep::TYPE_UPSCALE
            ? ModelCatalog::UPSCALE_MODEL
            : (string) $this->job->image_model;

        if ($type !== JobStep::TYPE_UPSCALE) {
            $action = $type === JobStep::TYPE_EDIT
                ? ModelCatalog::ACTION_IMAGES_AND_TEXT_TO_IMAGE
                : ModelCatalog::ACTION_VARIATE_IMAGE;

            if (!$this->catalog->supports($model, $action)) {
                return ['content' => 'The selected image model "' . $model . '" does not support ' . $type
                    . '. Ask the manager to pick a different model.'];
            }
        }

        $prompt = trim((string) ($input['prompt'] ?? ''));

        if ($type === JobStep::TYPE_EDIT && $prompt === '') {
            return ['content' => 'An edit needs a prompt describing the change.'];
        }

        $folder = $this->destinationFolder($input);

        if ($folder === null) {
            return ['content' => 'The manager may not write to that folder.'];
        }

        $controls = $this->controlsFrom($input, $model);

        if (is_string($controls)) {
            return ['content' => $controls];
        }

        $perImage = $type === JobStep::TYPE_VARIATE ? max(1, (int) ($input['count'] ?? 1)) : 1;
        $queued = 0;
        $rejected = [];

        foreach ($paths as $path) {
            // The planner may have invented this path, or named one the
            // manager cannot see. Either way it is refused here rather than
            // failing hours later inside the batch.
            if (!$this->scope->canRead($path) || $this->scope->absoluteOf($path) === null) {
                $rejected[] = $path;
                continue;
            }

            $absolute = $this->scope->absoluteOf($path);

            if ($absolute === null || !is_file($absolute)) {
                $rejected[] = $path;
                continue;
            }

            if ($this->remainingImageBudget() < $perImage) {
                $rejected[] = $path . ' (job image limit reached)';
                continue;
            }

            $params = $controls + ['folder' => $folder];

            if ($type === JobStep::TYPE_UPSCALE) {
                $params['scale'] = max(2, min(4, (int) ($input['scale'] ?? 2)));
            }

            if ($type === JobStep::TYPE_VARIATE) {
                $params['n'] = $perImage;
            }

            $this->appendStep($type, [
                'model' => $model,
                'prompt' => $prompt,
                'params' => $params,
                'source_path' => $path,
            ]);

            $queued++;
        }

        $message = 'Queued ' . $type . ' for ' . $queued . ' image(s) into "' . $folder . '".';

        if ($rejected !== []) {
            $message .= ' Skipped ' . count($rejected) . ' unusable path(s): ' . implode(', ', array_slice($rejected, 0, 10)) . '.';
        }

        return ['content' => $message];
    }

    private function askUser(array $input): array
    {
        $question = trim((string) ($input['question'] ?? ''));

        if ($question === '') {
            return ['content' => 'A question is required.'];
        }

        $options = array_values(array_filter(array_map(
            static fn ($option) => trim((string) $option),
            (array) ($input['options'] ?? [])
        )));

        return [
            'content' => 'Asked the manager: ' . $question,
            'control' => self::ASK_USER,
            'question' => $question,
            'options' => array_slice($options, 0, 6),
        ];
    }

    private function finish(array $input): array
    {
        $queued = JobStep::query()->where('job_id', $this->job->getKey())->count();

        if ($queued === 0) {
            return ['content' => 'Nothing has been queued, so there is nothing to finish. Either queue steps or ask '
                . 'the manager a question.'];
        }

        return [
            'content' => 'Plan complete: ' . $queued . ' step(s).',
            'control' => self::FINISH,
            'summary' => trim((string) ($input['summary'] ?? '')),
        ];
    }

    // ------------------------------------------------------------------
    // Shared plumbing
    // ------------------------------------------------------------------

    /**
     * Validate the controls a tool call carries against what the model offers.
     *
     * Returns an error string rather than throwing, so an invalid value comes
     * back to the planner as a tool result it can correct on the next turn —
     * which is far cheaper than failing the job.
     *
     * @return array|string
     */
    private function controlsFrom(array $input, string $model)
    {
        $available = $this->catalog->controls($model);
        $controls = $this->job->controls();

        $map = [
            'size' => 'sizes',
            'quality' => 'qualities',
            'background' => 'backgrounds',
            'aspect_ratio' => 'aspectRatios',
        ];

        foreach ($map as $key => $catalogKey) {
            $requested = trim((string) ($input[$key] ?? ''));

            if ($requested === '') {
                continue;
            }

            $allowed = array_map('strval', (array) ($available[$catalogKey] ?? []));

            if ($allowed !== [] && !in_array($requested, $allowed, true)) {
                return 'The model "' . $model . '" does not accept ' . $key . '="' . $requested . '". '
                    . 'Allowed values: ' . implode(', ', $allowed) . '.';
            }

            $controls[$key] = $requested;
        }

        return $controls;
    }

    /**
     * Where results go, or null when the manager may not write there.
     *
     * Defaults to the job's own output folder, which itself defaults to the
     * configured one — so a plan that never mentions a destination still lands
     * somewhere predictable rather than in the file root.
     */
    private function destinationFolder(array $input): ?string
    {
        $requested = trim((string) ($input['folder'] ?? ''));
        $folder = $requested !== ''
            ? $requested
            : ((string) $this->job->output_folder ?: $this->scope->outputFolder());

        $folder = trim(str_replace('\\', '/', $folder), '/');

        if ($folder === '') {
            return null;
        }

        if ($this->scope->absoluteOf($folder) === null) {
            return null;
        }

        // Probed with a file name rather than the bare folder: writing is
        // judged on the file that will be created, and canWrite() refuses
        // top-level entries, which a bare folder name always is.
        if (!$this->scope->canWrite($folder . '/probe.png')) {
            return null;
        }

        return $folder;
    }

    /** How many more images this job may still queue. */
    private function remainingImageBudget(): int
    {
        $ceiling = (int) Config::limit('max_images_per_job', 200);

        $planned = JobStep::query()
            ->where('job_id', $this->job->getKey())
            ->get()
            ->sum(static fn (JobStep $step) => $step->expectedImages());

        return max(0, $ceiling - (int) $planned);
    }

    private function appendStep(string $type, array $attributes): void
    {
        $seq = (int) JobStep::query()->where('job_id', $this->job->getKey())->max('seq');

        JobStep::query()->create([
            'job_id' => $this->job->getKey(),
            'seq' => $seq + 1,
            'type' => $type,
            'status' => JobStep::STATUS_QUEUED,
            'model' => (string) ($attributes['model'] ?? ''),
            'prompt' => (string) ($attributes['prompt'] ?? ''),
            'params_json' => (array) ($attributes['params'] ?? []),
            'source_path' => (string) ($attributes['source_path'] ?? ''),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Price the plan as it currently stands.
     *
     * Used for the approval gate and for what the UI shows, so it has to walk
     * the real steps rather than a summary of them — a job that mixes a dear
     * generation with cheap upscales has no single per-image price.
     */
    public function estimatePlan(): Estimate
    {
        $estimates = [];

        foreach (JobStep::query()->where('job_id', $this->job->getKey())->get() as $step) {
            $estimates[] = match ((string) $step->type) {
                JobStep::TYPE_UPSCALE => $this->estimator->upscale($step->expectedImages()),
                default => $this->estimator->image(
                    (string) $step->model,
                    $step->expectedImages(),
                    $step->params()
                ),
            };
        }

        return Estimate::sum($estimates);
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        return max(1, (int) round($bytes / 1024)) . ' KB';
    }
}
