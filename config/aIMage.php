<?php

/**
 * AIMage configuration.
 *
 * Merged into `cms.settings.aIMage`. Everything here is overridable from
 * `core/custom/config/cms/settings.php`; secrets belong in the environment or
 * in the per-user key store (see Support\ApiKeys), never in this file.
 */
return [
    /** Master switch. Off means the module is not registered and the routes do not exist. */
    'enable' => true,

    'gateway' => [
        /** Canonical prefix of the ai.artur.work gateway. No trailing slash. */
        'base_url' => env('AIMAGE_BASE_URL', 'https://ai.artur.work/api/v1'),

        /**
         * The site-wide fallback key, used when a manager has not set their own.
         * Prefer the environment over a settings row: a database dump is a routine
         * artefact and an API key must not travel with it.
         */
        'key' => env('AIMAGE_API_KEY'),

        /** Seconds. A single gateway call, not a whole job. */
        'timeout' => 120,
        'connect_timeout' => 10,

        /** How long GET /models is cached. The endpoint itself is hourly-cached upstream. */
        'catalog_ttl' => 3600,
    ],

    'defaults' => [
        /** The planner. Must be a model whose actions include respondChat. */
        'text_model' => env('AIMAGE_TEXT_MODEL', 'claude-sonnet-5'),
        /** The generator. Must be a model whose actions include textToImage. */
        'image_model' => env('AIMAGE_IMAGE_MODEL', 'gpt-image-1'),
        /** Speech to text, for the microphone button. */
        'voice_model' => env('AIMAGE_VOICE_MODEL', 'whisper-1'),
        /** Text to speech, for reading answers back. Empty disables the feature. */
        'speech_model' => env('AIMAGE_SPEECH_MODEL', 'gpt-4o-mini-tts'),
        'speech_voice' => env('AIMAGE_SPEECH_VOICE', 'alloy'),
    ],

    'limits' => [
        /**
         * How long one worker slice may spend on a job before handing back.
         *
         * The CMS worker runs one task per invocation and the scheduler fires
         * it every minute, so this is effectively how much of each minute a
         * batch may own. Keep it comfortably under 60 so the worker is free
         * again before the next tick, and far under the task lease so a slice
         * can never outlive its own claim.
         */
        'slice_seconds' => 45,

        /** How many batches may be in flight at once, across every manager. */
        'parallelism' => 3,

        /** Hard ceiling on images one job may produce, whatever the plan says. */
        'max_images_per_job' => 200,
        /** Hard ceiling on steps one job may hold. */
        'max_steps_per_job' => 400,
        /** A step is retried this many times before it is marked failed. */
        'max_attempts' => 4,
        /** Planner turns one job may spend before it must either act or ask. */
        'max_planner_turns' => 12,
        /** Estimated euro cost above which a job waits for explicit approval. */
        'approval_threshold_eur' => 5.0,
    ],

    'files' => [
        /** Written results land here, relative to the manager's own file-manager root. */
        'output_folder' => 'aimage',
        /**
         * Whether a result may overwrite the file it was derived from.
         * Off — the default — writes a sibling and leaves the original alone.
         */
        'allow_overwrite' => false,
        /** Extensions a result may be written with. Intersected with the CMS `upload_images` list. */
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
        /** Bytes. A result larger than this is refused rather than written. */
        'max_result_bytes' => 32 * 1024 * 1024,
    ],
];
