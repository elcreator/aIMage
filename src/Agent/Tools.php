<?php

namespace Elcreator\aIMage\Agent;

use Elcreator\aIMage\Gateway\Estimate;
use Elcreator\aIMage\Gateway\Estimator;
use Elcreator\aIMage\Gateway\ModelCatalog;
use Elcreator\aIMage\Models\Job;
use Elcreator\aIMage\Models\JobStep;
use Elcreator\aIMage\Services\Gallery;
use Elcreator\aIMage\Support\Config;
use Elcreator\aIMage\Support\ImageScope;

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
    public const PLAN_ANALYZE = 'plan_analyze';
    public const PLAN_GALLERY_ALT = 'plan_gallery_alt';

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

        $definitions = [
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
                'description' => 'List folders that already exist, to see how the file area is arranged. Results '
                    . 'do not have to go in one of them: a destination folder is created if it is not there yet, '
                    . 'so use this to understand the layout, not to decide what is a legal destination.',
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
                    . 'steps to the task, which is carried out afterwards. At most ' . $maxImages . ' images per job.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'prompt' => ['type' => 'string', 'description' => 'The image prompt. Be specific; this is what the image model sees.'],
                        'count' => ['type' => 'integer', 'description' => 'How many images to generate. Defaults to 1.'],
                        'size' => ['type' => 'string', 'description' => 'Optional. Must be one the chosen model supports.'],
                        'quality' => ['type' => 'string', 'description' => 'Optional. Must be one the chosen model supports.'],
                        'background' => ['type' => 'string', 'description' => 'Optional, e.g. transparent, for models that support it.'],
                        'basename' => ['type' => 'string', 'description' => 'Optional file name stem for the results.'],
                        'output_format' => ['type' => 'string', 'enum' => ['png', 'webp', 'jpeg'],
                            'description' => 'Optional file format of the results. Use "webp" for images going on a web page (much smaller than png).'],
                        'negative_prompt' => ['type' => 'string',
                            'description' => 'Optional: what must NOT appear (e.g. "text, watermark, blur"). Only some models honour it; the rest ignore it.'],
                        'references' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Optional image paths (from list_images) whose style or subject the result should follow. Only some models accept reference images.',
                        ],
                        'reference_role' => ['type' => 'string', 'enum' => ['style', 'subject'],
                            'description' => 'What the references are for. Defaults to "style".'],
                        'folder' => [
                            'type' => 'string',
                            'description' => 'Optional destination folder, relative to the file area of this '
                                . 'manager. It need not exist: a nested path such as "123/45" creates a folder '
                                . '"123" and a folder "45" inside it. Defaults to the output folder of the job.',
                        ],
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
                        'output_format' => ['type' => 'string', 'enum' => ['png', 'webp', 'jpeg'],
                            'description' => 'Optional file format of the results. Use "webp" for images going on a web page (much smaller than png).'],
                        'negative_prompt' => ['type' => 'string',
                            'description' => 'Optional: what must NOT appear (e.g. "text, watermark, blur"). Only some models honour it; the rest ignore it.'],
                        'size' => ['type' => 'string'],
                        'quality' => ['type' => 'string'],
                        'folder' => [
                            'type' => 'string',
                            'description' => 'Optional destination folder, relative to the file area of this '
                                . 'manager. It need not exist: a nested path such as "123/45" creates a folder '
                                . '"123" and a folder "45" inside it. Defaults to the output folder of the job.',
                        ],
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
                        'folder' => [
                            'type' => 'string',
                            'description' => 'Optional destination folder, relative to the file area of this '
                                . 'manager. It need not exist: a nested path such as "123/45" creates a folder '
                                . '"123" and a folder "45" inside it. Defaults to the output folder of the job.',
                        ],
                    ],
                    'required' => ['paths'],
                ],
            ],
            [
                'name' => self::PLAN_UPSCALE,
                'description' => 'Queue an upscale of existing images. Upscaling runs on '
                    . \Elcreator\aIMage\Support\Config::upscaleModel() . ' regardless of the chosen image model.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'paths' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'scale' => ['type' => 'integer', 'description' => 'Factor, typically 2 or 4. Defaults to 2.'],
                        'folder' => [
                            'type' => 'string',
                            'description' => 'Optional destination folder, relative to the file area of this '
                                . 'manager. It need not exist: a nested path such as "123/45" creates a folder '
                                . '"123" and a folder "45" inside it. Defaults to the output folder of the job.',
                        ],
                    ],
                    'required' => ['paths'],
                ],
            ],
            [
                'name' => self::PLAN_ANALYZE,
                'description' => 'Queue an analysis of existing images: alt text for the <img> tag written in a language of '
                    . 'your choice, the dominant colours and a focal point (for cropping). It changes no file; the answers are '
                    . 'kept on the step for the manager to copy. Use it when asked for alt text, descriptions, captions, '
                    . 'colours or a crop point.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'paths' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'lang' => ['type' => 'string', 'description' => 'Language code for the alt text, e.g. "en", "uk", "sk". Defaults to "en".'],
                        'context' => ['type' => 'string', 'description' => 'Optional: what the page is about (e.g. "product: oak chair"), so the alt text fits it.'],
                        'want' => [
                            'type' => 'array',
                            'items' => ['type' => 'string', 'enum' => ['alt', 'focal', 'colors']],
                            'description' => 'Which parts. Defaults to all three.',
                        ],
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
                        'summary' => ['type' => 'string', 'description' => 'One sentence describing what the task will do.'],
                    ],
                    'required' => ['summary'],
                ],
            ],
        ];

        return $this->withGallery($definitions);
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
            self::PLAN_ANALYZE => $this->planAnalyze($input),
            self::PLAN_GALLERY_ALT => $this->planGalleryAlt($input),
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

        $style = $this->styleParams($input, true);

        if (is_string($style)) {
            return ['content' => $style];
        }

        $gallery = $this->galleryParams($input);

        if (is_string($gallery)) {
            return ['content' => $gallery];
        }

        $this->appendStep(JobStep::TYPE_GENERATE, [
            'model' => $model,
            'prompt' => $prompt,
            'params' => $controls + $style + $gallery + [
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
            ? \Elcreator\aIMage\Support\Config::upscaleModel()
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

        $style = $type === JobStep::TYPE_EDIT ? $this->styleParams($input, false) : [];

        if (is_string($style)) {
            return ['content' => $style];
        }

        $gallery = $this->galleryParams($input);

        if (is_string($gallery)) {
            return ['content' => $gallery];
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

            $params = $controls + ['folder' => $folder] + $gallery;

            if ($type === JobStep::TYPE_EDIT) {
                $params += $style;
            }

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

    /**
     * Analysis queues one step per image like an edit does, but needs no model, no
     * destination folder and no image budget: it produces words, not files.
     */
    private function planAnalyze(array $input): array
    {
        $paths = array_values(array_filter(array_map('strval', (array) ($input['paths'] ?? []))));

        if ($paths === []) {
            return ['content' => 'At least one image path is required. Use ' . self::LIST_IMAGES . ' to find them.'];
        }

        $lang = strtolower(trim((string) ($input['lang'] ?? 'en')));
        if (!preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $lang)) {
            $lang = 'en';
        }

        $want = array_values(array_intersect(['alt', 'focal', 'colors'], array_map('strval', (array) ($input['want'] ?? []))));
        $want = $want === [] ? ['alt', 'focal', 'colors'] : $want;
        $context = mb_substr(trim((string) ($input['context'] ?? '')), 0, 500);

        $queued = 0;
        $rejected = [];

        foreach ($paths as $path) {
            $absolute = $this->scope->canRead($path) ? $this->scope->absoluteOf($path) : null;

            if ($absolute === null || !is_file($absolute)) {
                $rejected[] = $path;
                continue;
            }

            $this->appendStep(JobStep::TYPE_DESCRIBE, [
                'prompt' => $context,
                'params' => ['lang' => $lang, 'want' => $want],
                'source_path' => $path,
            ]);
            $queued++;
        }

        $message = 'Queued analysis (' . implode(', ', $want) . '; language ' . $lang . ') for ' . $queued . ' image(s).';

        if ($rejected !== []) {
            $message .= ' Skipped ' . count($rejected) . ' unusable path(s): ' . implode(', ', array_slice($rejected, 0, 10)) . '.';
        }

        return ['content' => $message];
    }

    /** How many reference images one step may carry: they are uploaded, and billed in tokens, per step. */
    private const MAX_REFERENCES = 4;

    /**
     * The look-and-feel parameters of a generation or edit: file format, negative prompt
     * and (generation only) reference images. Returns a message for the model when a
     * value is unusable, so it corrects itself instead of the worker failing at night.
     *
     * @return array<string, mixed>|string
     */
    private function styleParams(array $input, bool $references)
    {
        $params = [];

        $format = strtolower(trim((string) ($input['output_format'] ?? '')));
        if ($format === 'jpg') {
            $format = 'jpeg';
        }
        if ($format !== '') {
            if (!in_array($format, ['png', 'webp', 'jpeg'], true)) {
                return 'output_format must be png, webp or jpeg.';
            }
            $params['output_format'] = $format;
        }

        $negative = mb_substr(trim((string) ($input['negative_prompt'] ?? '')), 0, 500);
        if ($negative !== '') {
            $params['negative_prompt'] = $negative;
        }

        if ($references) {
            $paths = array_values(array_filter(array_map('strval', (array) ($input['references'] ?? []))));
            if (count($paths) > self::MAX_REFERENCES) {
                return 'At most ' . self::MAX_REFERENCES . ' reference images per step.';
            }
            $role = (string) ($input['reference_role'] ?? 'style') === 'subject' ? 'subject' : 'style';
            foreach ($paths as $path) {
                $absolute = $this->scope->canRead($path) ? $this->scope->absoluteOf($path) : null;
                if ($absolute === null || !is_file($absolute)) {
                    return 'The reference "' . $path . '" is not a readable image. Use ' . self::LIST_IMAGES . ' to find real paths.';
                }
                $params['references'][] = ['path' => $path, 'role' => $role];
            }
        }

        return $params;
    }

    /**
     * The gallery tools exist only on a site that has sGallery: a planner offered a
     * gallery it cannot use would spend a turn trying.
     */
    private function withGallery(array $definitions): array
    {
        if (!Gallery::available()) {
            return $definitions;
        }

        $target = [
            'gallery_document_id' => ['type' => 'integer', 'description' => 'Optional: id of a document whose sGallery gallery should receive each result '
                . '(the files stay where they are written; the gallery just points at them). Only for documents the manager may edit.'],
            'gallery_item_type' => ['type' => 'string', 'description' => 'Optional gallery item type, e.g. "product". Defaults to "resource".'],
            'gallery_block' => ['type' => 'string', 'description' => 'Optional gallery block name. Defaults to "1".'],
        ];

        foreach ($definitions as &$definition) {
            if (in_array($definition['name'], [self::PLAN_GENERATE, self::PLAN_EDIT, self::PLAN_VARIATE, self::PLAN_UPSCALE], true)) {
                $definition['input_schema']['properties'] += $target;
            }
        }
        unset($definition);

        $definitions[] = [
            'name' => self::PLAN_GALLERY_ALT,
            'description' => 'Queue alt text for the images already in a document\'s sGallery gallery, written in a language of your choice '
                . 'and saved into the gallery. An alt text a person already wrote is kept unless overwrite is true. '
                . 'Alt text is limited to 100 characters.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'document_id' => ['type' => 'integer', 'description' => 'The document whose gallery to describe.'],
                    'lang' => ['type' => 'string', 'description' => 'Language of the alt text, e.g. "en", "uk", "sk". Defaults to "en".'],
                    'gallery_lang' => ['type' => 'string', 'description' => 'Which gallery text language to store under. Leave empty (stores as the default "base" text) unless the site is multilingual with sLang.'],
                    'context' => ['type' => 'string', 'description' => 'Optional: what the page is about, so the alt text fits it.'],
                    'overwrite' => ['type' => 'boolean', 'description' => 'Replace alt text that already exists. Defaults to false.'],
                    'item_type' => ['type' => 'string', 'description' => 'Gallery item type, e.g. "product". Defaults to "resource".'],
                    'block' => ['type' => 'string', 'description' => 'Gallery block name. Defaults to "1".'],
                ],
                'required' => ['document_id'],
            ],
        ];

        return $definitions;
    }

    /**
     * Where results should be attached, validated now rather than at four in the morning.
     *
     * @return array{gallery?: array<string, mixed>}|string a message for the model when unusable
     */
    private function galleryParams(array $input)
    {
        $documentId = (int) ($input['gallery_document_id'] ?? 0);

        if ($documentId <= 0) {
            return [];
        }
        if (!Gallery::available()) {
            return 'This site has no sGallery, so results cannot be attached to a document gallery.';
        }
        if (!Gallery::canEdit($this->scope, $documentId)) {
            return 'Document ' . $documentId . ' does not exist or the manager may not edit it.';
        }

        return ['gallery' => [
            'document_id' => $documentId,
            'item_type' => trim((string) ($input['gallery_item_type'] ?? '')) ?: Gallery::DEFAULT_ITEM_TYPE,
            'block' => trim((string) ($input['gallery_block'] ?? '')) ?: Gallery::DEFAULT_BLOCK,
        ]];
    }

    /** One analysis step per readable image of a document's gallery, each remembering which gallery row its alt text belongs to. */
    private function planGalleryAlt(array $input): array
    {
        $documentId = (int) ($input['document_id'] ?? 0);

        if (!Gallery::available()) {
            return ['content' => 'This site has no sGallery.'];
        }
        if (!Gallery::canEdit($this->scope, $documentId)) {
            return ['content' => 'Document ' . $documentId . ' does not exist or the manager may not edit it.'];
        }

        $itemType = trim((string) ($input['item_type'] ?? '')) ?: Gallery::DEFAULT_ITEM_TYPE;
        $block = trim((string) ($input['block'] ?? '')) ?: Gallery::DEFAULT_BLOCK;
        $lang = strtolower(trim((string) ($input['lang'] ?? 'en')));
        if (!preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $lang)) {
            $lang = 'en';
        }
        $galleryLang = trim((string) ($input['gallery_lang'] ?? '')) ?: Gallery::BASE_LANG;
        $overwrite = (bool) ($input['overwrite'] ?? false);
        $context = mb_substr(trim((string) ($input['context'] ?? '')), 0, 500);

        $images = Gallery::images($documentId, $itemType, $block);
        if ($images === []) {
            return ['content' => 'The gallery of document ' . $documentId . ' has no images.'];
        }

        $queued = 0;
        $skipped = 0;

        foreach ($images as $image) {
            $path = Gallery::scopePath($this->scope, $image);

            if ($path === null) {
                $skipped++;
                continue;
            }

            $this->appendStep(JobStep::TYPE_DESCRIBE, [
                'prompt' => $context !== '' ? $context : Gallery::documentTitle($documentId),
                'params' => ['lang' => $lang, 'want' => ['alt'], 'gallery' => [
                    'document_id' => $documentId, 'item_id' => $image['id'], 'lang' => $galleryLang, 'overwrite' => $overwrite,
                ]],
                'source_path' => $path,
            ]);
            $queued++;
        }

        $message = 'Queued alt text (' . $lang . ') for ' . $queued . ' gallery image(s) of document ' . $documentId . '.';
        if ($skipped > 0) {
            $message .= ' Skipped ' . $skipped . ' the manager cannot read or that are missing on disk.';
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
     *
     * A folder that does not exist yet is not an error: "put them in 123/45"
     * is a normal instruction, and both folders are created under the write
     * root when the first result is written. What `resolveWriteFolder()`
     * refuses is a path that would leave that root, not one that has yet to be
     * made.
     */
    private function destinationFolder(array $input): ?string
    {
        $requested = trim((string) ($input['folder'] ?? ''));
        $folder = $this->scope->resolveWriteFolder(
            $requested !== ''
                ? $requested
                : ((string) $this->job->output_folder ?: $this->scope->outputFolder())
        );

        if ($folder === null) {
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
                JobStep::TYPE_DESCRIBE => $this->estimator->analyze(),
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
