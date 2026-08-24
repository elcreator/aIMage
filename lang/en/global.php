<?php

return [
    // Permissions, referenced by lang_key from the migration.
    'permissions_group' => 'AIMage',
    'permission_access' => 'Use AIMage',

    // Page furniture
    'title' => 'AIMage',
    'tagline' => 'Describe a batch of image work; it runs in the background.',
    'denied' => 'You do not have permission to use AIMage.',

    'new_job' => 'New batch',
    'jobs' => 'Batches',
    'no_jobs' => 'Nothing yet. Describe what you want done.',
    'instruction_placeholder' => 'e.g. upscale every image in products/, or generate 10 hero images of a mountain lake at dawn',
    'send' => 'Send',
    'record' => 'Dictate',
    'recording' => 'Recording — click to stop',
    'transcribing' => 'Transcribing…',
    'speak_answer' => 'Read answers aloud',

    // Models
    'text_model' => 'Planning model',
    'image_model' => 'Image model',
    'voice_model' => 'Dictation model',
    'output_folder' => 'Results folder',
    'model_provider' => 'via :provider',

    // The two numbers the picker exists to show.
    'per_image' => 'per image',
    'est_cost' => 'Estimated cost',
    'est_time' => 'Estimated wait',
    // The unit is kept clear of the placeholder. ':p50s' would still resolve —
    // Laravel replaces ':p50' inside it — but it reads as a placeholder named
    // 'p50s', and the day someone adds one, this line breaks silently.
    'eta_range' => '~:p50 s typical, up to :p90 s',
    'price_exact' => 'Fixed price',
    'price_approx' => 'Approximate',
    'price_unknown' => 'No published price',
    'basis_tariff' => 'a fixed per-image tariff',
    'basis_tariff_max' => 'the highest-priced resolution tier, so an upper bound',
    'basis_observed' => 'the median of comparable past runs',
    'basis_rates' => 'token rates — the final amount depends on length',
    'basis_estimated' => 'an assumption; there is no billing history for it',
    'basis_unpriced' => 'not currently priced',
    'latency_measured' => 'measured from :n real runs',
    'latency_coarse' => 'pooled across this model\'s variants',
    'latency_seeded' => 'an estimate, not a measurement',
    'latency_none' => 'unknown',
    'catalog_stale' => 'Showing a cached model list — the gateway could not be reached.',

    // Job states
    'status_planning' => 'Planning',
    'status_awaiting_input' => 'Needs your answer',
    'status_awaiting_approval' => 'Needs your approval',
    'status_running' => 'Running',
    'status_succeeded' => 'Done',
    'status_failed' => 'Failed',
    'status_cancelled' => 'Cancelled',

    'approve' => 'Approve and run',
    'cancel_job' => 'Cancel',
    'plan_summary' => ':steps step(s), about :images image(s)',
    'progress' => ':done of :total done',
    'failed_count' => ':n failed',
    'reply_placeholder' => 'Answer…',

    // Steps
    'step_generate' => 'Generate',
    'step_edit' => 'Edit',
    'step_variate' => 'Variation',
    'step_upscale' => 'Upscale',
    'step_describe' => 'Describe',
    'before' => 'Before',
    'after' => 'After',

    // Keys
    'key_needed_title' => 'An API key is needed',
    'key_needed_body' => 'AIMage works through the ai.artur.work gateway. Enter your own key, or ask an administrator '
        . 'to set a site-wide one.',
    'key_your_own' => 'Your key',
    'key_site' => 'Site-wide key',
    'key_using_own' => 'Using your own key.',
    'key_using_site' => 'Using the site-wide key.',
    'key_placeholder' => 'Paste your key',
    'key_save' => 'Save key',
    'key_clear' => 'Remove',
    'key_saved' => 'Key saved and verified.',
    'key_cleared' => 'Key removed.',

    // Batching health
    'batching_unavailable' => 'Background batching is unavailable, so work will not run on its own.',
    'batching_not_registered' => 'The AIMage task type is not registered with this Evolution CMS installation.',
    'batching_scheduler_down' => 'The scheduler is not running. Start it with "php core/artisan schedule:work", or '
        . 'have cron call "schedule:run" every minute.',

    // Errors returned by the endpoints
    'error_forbidden' => 'You do not have permission to do that.',
    'error_no_key' => 'No API key is configured for you or for this site.',
    'error_empty_instruction' => 'Say what you want done.',
    'error_unknown_model' => 'The gateway does not offer a :kind model called ":model".',
    'error_folder_denied' => 'You may not write to ":folder".',
    'error_job_not_found' => 'That batch does not exist, or is not yours.',
    'error_job_finished' => 'That batch has already finished.',
    'error_not_awaiting_approval' => 'That batch is not waiting for approval.',
    'error_key_from_config' => 'The site key is set in configuration and cannot be changed here.',
    'error_key_rejected' => 'The gateway rejected that key.',
    'error_no_audio' => 'No audio was received.',
    'error_audio_too_large' => 'That recording is too large.',
    'error_audio_unsupported' => 'That audio format is not supported.',
    'error_empty_transcript' => 'Nothing could be transcribed from that recording.',
    'error_empty_text' => 'There is nothing to read aloud.',
    'error_speech_disabled' => 'Reading answers aloud is not configured.',
];
