{{--
    The AIMage workbench.

    Rendered into the manager's main frame by a routing module, so it owns the
    whole document. Assets are inlined rather than linked: the module works the
    moment the package is installed, with no `vendor:publish` step to forget,
    and a manager page that half-loads is worse than one file more to read.

    Everything the front end needs that it cannot derive — the route prefix,
    which is a hash of the module name, the CSRF token, and the current key and
    scheduler state — is handed over as one JSON island via @js().
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('aIMage::global.title') }}</title>
    {{-- The manager's own icon set. This page is a document of its own
         inside the frame, so nothing the manager loads reaches it. --}}
    <link rel="stylesheet" href="{{ $iconsUrl }}">
    @include('aIMage::partials.styles')
</head>
<body>
<div class="ai-shell" id="ai-shell">

    <header class="ai-head">
        <div>
            <h1>{{ __('aIMage::global.title') }}</h1>
            <p class="ai-sub">{{ __('aIMage::global.tagline') }}</p>
        </div>
        <div class="ai-head-state" id="ai-key-state"></div>
    </header>

    {{-- Batching health. Shown only when something is actually wrong, because
         a permanent green banner is a banner nobody reads. --}}
    @if (!$batching['available'])
        <div class="ai-alert ai-alert-warn">
            <strong>{{ __('aIMage::global.batching_unavailable') }}</strong>
            <span>
                @if (!$batching['registered'])
                    {{ __('aIMage::global.batching_not_registered') }}
                @elseif ($batching['scheduler_healthy'] === false)
                    {{ __('aIMage::global.batching_scheduler_down') }}
                @endif
            </span>
        </div>
    @endif

    {{-- The key gate. When no key is configured this is the only thing that
         matters on the page, so it renders first and the composer stays
         disabled behind it. --}}
    <section class="ai-panel ai-key-panel" id="ai-key-panel" hidden>
        <h2>{{ __('aIMage::global.key_needed_title') }}</h2>
        {{-- The gateway name in the copy doubles as a link to the profile page
             where a key can be created; the text itself is escaped first. --}}
        <p>{!! str_replace(
            'ai.artur.work',
            '<a href="https://ai.artur.work/profile" target="_blank">ai.artur.work</a>',
            e(__('aIMage::global.key_needed_body'))
        ) !!}</p>

        <div class="ai-key-form">
            <label for="ai-key-input">{{ __('aIMage::global.key_your_own') }}</label>
            <div class="ai-row">
                <input type="password" id="ai-key-input" autocomplete="off" spellcheck="false"
                       placeholder="{{ __('aIMage::global.key_placeholder') }}">
                <button type="button" class="ai-btn ai-btn-primary" id="ai-key-save">
                    {{ __('aIMage::global.key_save') }}
                </button>
                <button type="button" class="ai-btn" id="ai-key-clear">
                    {{ __('aIMage::global.key_clear') }}
                </button>
            </div>
        </div>

        @if ($canManageSiteKey)
            <div class="ai-key-form ai-key-site">
                <label for="ai-key-site-input">{{ __('aIMage::global.key_site') }}</label>
                <div class="ai-row">
                    <input type="password" id="ai-key-site-input" autocomplete="off" spellcheck="false"
                           placeholder="{{ __('aIMage::global.key_placeholder') }}"
                           @if ($keyState['site_key_from_config']) disabled @endif>
                    <button type="button" class="ai-btn" id="ai-key-site-save"
                            @if ($keyState['site_key_from_config']) disabled @endif>
                        {{ __('aIMage::global.key_save') }}
                    </button>
                </div>
                @if ($keyState['site_key_from_config'])
                    <p class="ai-hint">{{ __('aIMage::global.error_key_from_config') }}</p>
                @endif
            </div>
        @endif

        <p class="ai-msg" id="ai-key-msg" role="status"></p>
    </section>

    <div class="ai-body" id="ai-body">

        {{-- Left: the batch list. --}}
        <aside class="ai-side">
            <div class="ai-side-head">
                <h2>{{ __('aIMage::global.jobs') }}</h2>
                <button type="button" class="ai-btn ai-btn-small" id="ai-new-job">
                    {{ __('aIMage::global.new_job') }}
                </button>
            </div>
            <ul class="ai-job-list" id="ai-job-list"></ul>
        </aside>

        {{-- Right: the composer and the running batch. --}}
        <main class="ai-main">

            <section class="ai-panel ai-composer" id="ai-composer">
                <div class="ai-pickers">
                    <label class="ai-field">
                        <span>{{ __('aIMage::global.image_model') }}</span>
                        <select id="ai-image-model"></select>
                        <em class="ai-estimate" id="ai-image-estimate"></em>
                    </label>

                    <label class="ai-field">
                        <span>{{ __('aIMage::global.text_model') }}</span>
                        <select id="ai-text-model"></select>
                        <em class="ai-estimate" id="ai-text-estimate"></em>
                    </label>

                    {{-- The results folder is a path, not a list: a manager may
                         name a folder that does not exist yet, and the ones that
                         do are better browsed than enumerated into a dropdown. --}}
                    <label class="ai-field ai-field-narrow">
                        <span>{{ __('aIMage::global.output_folder') }}</span>
                        <span class="ai-folder-row">
                            <input type="text" id="ai-folder" spellcheck="false" autocomplete="off">
                            <button type="button" class="ai-btn ai-btn-small" id="ai-browse">
                                {{ __('aIMage::global.files_browse') }}
                            </button>
                        </span>
                    </label>
                </div>

                {{-- Model-specific controls. Rebuilt from the catalogue when a
                     model changes, because which sizes and qualities are legal
                     is per-model and offering anything else invites a refusal
                     halfway through a batch. --}}
                <div class="ai-controls" id="ai-controls"></div>

                <div class="ai-compose-row">
                    <textarea id="ai-instruction" rows="3"
                              placeholder="{{ __('aIMage::global.instruction_placeholder') }}"></textarea>
                    <div class="ai-compose-actions">
                        <button type="button" class="ai-btn ai-btn-primary" id="ai-send">
                            {{ __('aIMage::global.send') }}
                        </button>
                        {{-- Voice is optional and off by default. The buttons
                             are not rendered at all when it is off, and the
                             endpoints behind them refuse too — see
                             `features.voice` in config/aIMage.php.

                             The two share a line of their own under Send, and
                             split its width between them so the block stays
                             square whatever the translated label is. --}}
                        @if ($features['voice'])
                        <div class="ai-compose-icons">
                            <button type="button" class="ai-btn ai-btn-icon ai-btn-mic" id="ai-mic"
                                    title="{{ __('aIMage::global.record') }}"
                                    aria-label="{{ __('aIMage::global.record') }}">
                                <i class="fa fa-microphone" aria-hidden="true"></i>
                            </button>
                            {{-- A pressed button rather than a checkbox: it fits
                                 the row, and `aria-pressed` says what a
                                 checkbox's label used to. --}}
                            <button type="button" class="ai-btn ai-btn-icon ai-btn-toggle" id="ai-speak"
                                    aria-pressed="false"
                                    title="{{ __('aIMage::global.speak_answer') }}"
                                    aria-label="{{ __('aIMage::global.speak_answer') }}">
                                <i class="fa fa-volume-off" aria-hidden="true"></i>
                            </button>
                        </div>
                        @endif
                    </div>
                </div>
                <p class="ai-msg" id="ai-compose-msg" role="status"></p>
            </section>

            <section class="ai-panel ai-job" id="ai-job" hidden>
                <header class="ai-job-head">
                    <div>
                        <span class="ai-badge" id="ai-job-status"></span>
                        <h2 id="ai-job-title"></h2>
                    </div>
                    <div class="ai-job-actions">
                        <button type="button" class="ai-btn ai-btn-primary" id="ai-approve" hidden></button>
                        <button type="button" class="ai-btn ai-btn-danger" id="ai-cancel" hidden>
                            {{ __('aIMage::global.cancel_job') }}
                        </button>
                    </div>
                </header>

                <div class="ai-progress" id="ai-progress" hidden>
                    <div class="ai-progress-bar"><i id="ai-progress-fill"></i></div>
                    <span id="ai-progress-text"></span>
                </div>

                <div class="ai-thread" id="ai-thread"></div>

                {{-- Answering a clarifying question. The planner parks the job
                     in awaiting_input and this is how it gets going again. --}}
                <div class="ai-reply" id="ai-reply" hidden>
                    <textarea id="ai-reply-input" rows="2"
                              placeholder="{{ __('aIMage::global.reply_placeholder') }}"></textarea>
                    <button type="button" class="ai-btn ai-btn-primary" id="ai-reply-send">
                        {{ __('aIMage::global.send') }}
                    </button>
                    @if ($features['voice'])
                    <button type="button" class="ai-btn ai-btn-icon ai-btn-mic" id="ai-reply-mic"
                            title="{{ __('aIMage::global.record') }}" aria-label="{{ __('aIMage::global.record') }}">
                        <i class="fa fa-microphone" aria-hidden="true"></i>
                    </button>
                    @endif
                </div>

                <div class="ai-steps" id="ai-steps"></div>
            </section>

        </main>
    </div>
</div>

<script>
    window.AIMAGE = @js([
        'baseUrl' => $baseUrl,
        'csrf' => $csrfToken,
        'key' => $keyState,
        'defaults' => $defaults,
        'limits' => $limits,
        'scope' => $scope,
        'batching' => $batching,
        'canManageSiteKey' => $canManageSiteKey,
        'features' => $features,
        'lang' => [
            'est_cost' => __('aIMage::global.est_cost'),
            'est_time' => __('aIMage::global.est_time'),
            'per_image' => __('aIMage::global.per_image'),
            'price_exact' => __('aIMage::global.price_exact'),
            'price_approx' => __('aIMage::global.price_approx'),
            'price_unknown' => __('aIMage::global.price_unknown'),
            'basis_tariff' => __('aIMage::global.basis_tariff'),
            'basis_tariff_max' => __('aIMage::global.basis_tariff_max'),
            'basis_observed' => __('aIMage::global.basis_observed'),
            'basis_rates' => __('aIMage::global.basis_rates'),
            'basis_estimated' => __('aIMage::global.basis_estimated'),
            'basis_unpriced' => __('aIMage::global.basis_unpriced'),
            'latency_measured' => __('aIMage::global.latency_measured'),
            'latency_coarse' => __('aIMage::global.latency_coarse'),
            'latency_seeded' => __('aIMage::global.latency_seeded'),
            'latency_none' => __('aIMage::global.latency_none'),
            'catalog_stale' => __('aIMage::global.catalog_stale'),
            'status_planning' => __('aIMage::global.status_planning'),
            'status_awaiting_input' => __('aIMage::global.status_awaiting_input'),
            'status_awaiting_approval' => __('aIMage::global.status_awaiting_approval'),
            'status_running' => __('aIMage::global.status_running'),
            'status_succeeded' => __('aIMage::global.status_succeeded'),
            'status_failed' => __('aIMage::global.status_failed'),
            'status_cancelled' => __('aIMage::global.status_cancelled'),
            'status_queued' => __('aIMage::global.status_queued'),
            'status_polling' => __('aIMage::global.status_polling'),
            'status_skipped' => __('aIMage::global.status_skipped'),
            'control_size' => __('aIMage::global.control_size'),
            'control_quality' => __('aIMage::global.control_quality'),
            'control_background' => __('aIMage::global.control_background'),
            'control_aspect_ratio' => __('aIMage::global.control_aspect_ratio'),
            'approve' => __('aIMage::global.approve'),
            'progress' => __('aIMage::global.progress'),
            'failed_count' => __('aIMage::global.failed_count'),
            'no_jobs' => __('aIMage::global.no_jobs'),
            'key_using_own' => __('aIMage::global.key_using_own'),
            'key_using_site' => __('aIMage::global.key_using_site'),
            'key_saved' => __('aIMage::global.key_saved'),
            'key_cleared' => __('aIMage::global.key_cleared'),
            'recording' => __('aIMage::global.recording'),
            'transcribing' => __('aIMage::global.transcribing'),
            'record' => __('aIMage::global.record'),
            'before' => __('aIMage::global.before'),
            'after' => __('aIMage::global.after'),
            'step_generate' => __('aIMage::global.step_generate'),
            'step_edit' => __('aIMage::global.step_edit'),
            'step_variate' => __('aIMage::global.step_variate'),
            'step_upscale' => __('aIMage::global.step_upscale'),
            'step_describe' => __('aIMage::global.step_describe'),
            'model_provider' => __('aIMage::global.model_provider'),
            'files_title' => __('aIMage::global.files_title'),
            'files_up' => __('aIMage::global.files_up'),
            'files_use_folder' => __('aIMage::global.files_use_folder'),
            'files_here' => __('aIMage::global.files_here'),
            'files_empty' => __('aIMage::global.files_empty'),
            'files_resolution' => __('aIMage::global.files_resolution'),
            'files_bytes' => __('aIMage::global.files_bytes'),
            'files_modified' => __('aIMage::global.files_modified'),
            'files_url' => __('aIMage::global.files_url'),
            'files_copy' => __('aIMage::global.files_copy'),
            'files_copied' => __('aIMage::global.files_copied'),
            'files_unknown' => __('aIMage::global.files_unknown'),
            'files_not_writable' => __('aIMage::global.files_not_writable'),
            'files_locate' => __('aIMage::global.files_locate'),
            'turn_user' => __('aIMage::global.turn_user'),
            'turn_assistant' => __('aIMage::global.turn_assistant'),
        ],
    ]);
</script>
{{-- The file browser. A dialog rather than a panel: it is opened to answer one
     question — which folder, or what is that image — and the page behind it is
     the thing being answered for. --}}
<div class="ai-modal" id="ai-files" hidden>
    <div class="ai-modal-box" role="dialog" aria-modal="true" aria-labelledby="ai-files-title">
        <div class="ai-modal-head">
            <h2 id="ai-files-title">{{ __('aIMage::global.files_title') }}</h2>
            <button type="button" class="ai-btn ai-btn-small" id="ai-files-close">
                {{ __('aIMage::global.files_close') }}
            </button>
        </div>

        <div class="ai-files-bar">
            <button type="button" class="ai-btn ai-btn-small" id="ai-files-up">
                <i class="fa fa-arrow-up" aria-hidden="true"></i> {{ __('aIMage::global.files_up') }}
            </button>
            <nav class="ai-crumbs" id="ai-files-crumbs"></nav>
            <button type="button" class="ai-btn ai-btn-primary ai-btn-small" id="ai-files-use">
                {{ __('aIMage::global.files_use_folder') }}
            </button>
        </div>

        <div class="ai-files-body">
            <div class="ai-files-list" id="ai-files-list"></div>
            <aside class="ai-files-preview" id="ai-files-preview" hidden></aside>
        </div>

        <p class="ai-msg" id="ai-files-msg" role="status"></p>
    </div>
</div>

@include('aIMage::partials.script')
</body>
</html>
