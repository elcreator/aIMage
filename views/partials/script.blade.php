@verbatim
<script>
/**
 * AIMage front end.
 *
 * Deliberately dependency-free and deliberately dumb: the browser holds no
 * state that matters. A batch lives in the database and is advanced by a
 * worker, so this page is a viewer that polls — closing it, reloading it, or
 * opening it on another machine tomorrow all show the same job in the same
 * place. Nothing here retries work, and nothing here decides anything a server
 * has not already decided.
 */
(function () {
    'use strict';

    var CFG = window.AIMAGE || {};
    var L = CFG.lang || {};
    var $ = function (id) { return document.getElementById(id); };

    var state = {
        catalog: null,
        jobUuid: null,
        pollTimer: null,
        pollDelay: 2000,
        recorder: null,
        recordingFor: null
    };

    // ------------------------------------------------------------------
    // Transport
    // ------------------------------------------------------------------

    function url(path, params) {
        var u = CFG.baseUrl.replace(/\/$/, '') + path;
        if (params) {
            var q = Object.keys(params)
                .filter(function (k) { return params[k] !== '' && params[k] != null; })
                .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); })
                .join('&');
            if (q) { u += '?' + q; }
        }
        return u;
    }

    function api(method, path, body, params) {
        var opts = {
            method: method,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        };

        if (method !== 'GET') {
            // The manager's CSRF middleware fails closed once a session
            // exists, so every mutating call carries the token.
            opts.headers['X-CSRF-TOKEN'] = CFG.csrf;
            if (body instanceof FormData) {
                body.append('_token', CFG.csrf);
                opts.body = body;
            } else {
                opts.headers['Content-Type'] = 'application/json';
                opts.body = JSON.stringify(body || {});
            }
        }

        return fetch(url(path, params), opts).then(function (res) {
            return res.json().catch(function () { return { ok: false, message: 'HTTP ' + res.status }; });
        });
    }

    function say(el, message, kind) {
        if (!el) { return; }
        el.textContent = message || '';
        el.className = 'ai-msg' + (kind ? ' is-' + kind : '');
    }

    function text(value) {
        return value == null ? '' : String(value);
    }

    // ------------------------------------------------------------------
    // Estimates — the two numbers the picker exists to show
    // ------------------------------------------------------------------

    function basisLabel(basis) {
        var map = {
            'tariff': L.basis_tariff, 'tariff-max': L.basis_tariff_max, 'observed': L.basis_observed,
            'rates': L.basis_rates, 'estimated': L.basis_estimated, 'unpriced': L.basis_unpriced
        };
        return map[basis] || '';
    }

    function latencyLabel(latency) {
        if (!latency) { return L.latency_none; }
        if (latency.source === 'measured') {
            return (L.latency_measured || '').replace(':n', latency.n || 0);
        }
        if (latency.source === 'coarse') { return L.latency_coarse; }
        if (latency.source === 'seeded' || latency.source === 'legacy') { return L.latency_seeded; }
        return L.latency_none;
    }

    function money(amount, currency) {
        if (amount == null) { return null; }
        var digits = amount < 1 ? 4 : 2;
        return amount.toFixed(digits) + ' ' + (currency || 'EUR');
    }

    /**
     * Render one estimate.
     *
     * An unknown price is written as "no published price", never as 0.00 —
     * `amount: null` means the gateway has no current price row, and showing
     * that as free is the one rendering mistake here that would cost somebody
     * money.
     */
    function renderEstimate(el, estimate, perImage) {
        if (!el) { return; }
        if (!estimate) { el.textContent = ''; return; }

        var parts = [];
        var priced = money(estimate.amount, estimate.currency);

        if (priced === null) {
            parts.push('<span class="ai-approx">' + text(L.price_unknown) + '</span>');
        } else {
            parts.push('<b>' + priced + '</b>' + (perImage ? ' ' + text(L.per_image) : ''));
            if (!estimate.exact) {
                parts.push('<span class="ai-approx">' + text(L.price_approx) + '</span>');
            }
        }

        var eta = estimate.eta || {};
        if (eta.p50 != null) {
            parts.push('~' + eta.p50 + 's' + (eta.p90 != null ? ' (p90 ' + eta.p90 + 's)' : ''));
        }

        el.innerHTML = parts.join(' · ');
        el.title = [basisLabel(estimate.basis), latencyLabel(eta)].filter(Boolean).join(' — ');
    }

    var estimateTimer = null;
    function refreshEstimates() {
        clearTimeout(estimateTimer);
        estimateTimer = setTimeout(function () {
            var imageModel = $('ai-image-model').value;
            if (imageModel) {
                api('GET', '/estimate', null, Object.assign(
                    { model: imageModel, count: 1, action: 'image' },
                    currentControls()
                )).then(function (res) {
                    if (res.ok) { renderEstimate($('ai-image-estimate'), res.estimate, true); }
                });
            }

            var textModel = $('ai-text-model').value;
            if (textModel) {
                api('GET', '/estimate', null, {
                    model: textModel,
                    action: 'chat',
                    prompt_chars: ($('ai-instruction').value || '').length + 1200
                }).then(function (res) {
                    if (res.ok) { renderEstimate($('ai-text-estimate'), res.estimate, false); }
                });
            }
        }, 250);
    }

    // ------------------------------------------------------------------
    // Catalogue and controls
    // ------------------------------------------------------------------

    function fillSelect(select, models, preferred) {
        select.innerHTML = '';
        models.forEach(function (m) {
            var option = document.createElement('option');
            option.value = m.model;
            option.textContent = m.title + (m.provider ? '  ·  ' + m.provider : '');
            select.appendChild(option);
        });
        if (preferred && models.some(function (m) { return m.model === preferred; })) {
            select.value = preferred;
        }
    }

    /**
     * Rebuild the model-specific controls.
     *
     * Only values the chosen model actually accepts are offered. Anything else
     * would be refused by the gateway, and finding that out mid-batch is the
     * expensive way to learn it.
     */
    function renderControls() {
        var host = $('ai-controls');
        host.innerHTML = '';

        var model = (state.catalog.groups.image || []).filter(function (m) {
            return m.model === $('ai-image-model').value;
        })[0];

        if (!model || !model.controls) { return; }

        var labels = { sizes: 'size', qualities: 'quality', backgrounds: 'background', aspectRatios: 'aspect_ratio' };

        Object.keys(labels).forEach(function (key) {
            var values = model.controls[key];
            if (!values || !values.length) { return; }

            var wrap = document.createElement('label');
            wrap.className = 'ai-field ai-field-narrow';
            wrap.innerHTML = '<span>' + labels[key] + '</span>';

            var select = document.createElement('select');
            select.dataset.control = labels[key];
            select.appendChild(new Option('—', ''));
            values.forEach(function (v) { select.appendChild(new Option(v, v)); });
            select.addEventListener('change', refreshEstimates);

            wrap.appendChild(select);
            host.appendChild(wrap);
        });
    }

    function currentControls() {
        var out = {};
        Array.prototype.forEach.call($('ai-controls').querySelectorAll('select[data-control]'), function (s) {
            if (s.value) { out[s.dataset.control] = s.value; }
        });
        return out;
    }

    function loadCatalog() {
        return api('GET', '/models').then(function (res) {
            if (!res.ok) {
                say($('ai-compose-msg'), res.message, 'error');
                return;
            }

            renderKeyState(res.key);

            if (!res.key.configured) { return; }

            state.catalog = res;

            if (res.stale) { say($('ai-compose-msg'), L.catalog_stale, 'error'); }

            fillSelect($('ai-image-model'), res.groups.image || [], CFG.defaults.image_model);
            fillSelect($('ai-text-model'), res.groups.text || [], CFG.defaults.text_model);
            renderControls();
            refreshEstimates();
        });
    }

    function loadFolders() {
        return api('GET', '/files').then(function (res) {
            if (!res.ok) { return; }
            var select = $('ai-folder');
            select.innerHTML = '';
            select.appendChild(new Option(res.output_folder, res.output_folder));
            (res.folders || []).forEach(function (f) {
                if (f.path !== res.output_folder) { select.appendChild(new Option(f.path, f.path)); }
            });
            select.value = CFG.defaults.output_folder || res.output_folder;
        });
    }

    // ------------------------------------------------------------------
    // Key
    // ------------------------------------------------------------------

    function renderKeyState(key) {
        CFG.key = key || CFG.key;
        var configured = CFG.key && CFG.key.configured;

        $('ai-key-panel').hidden = !!configured;
        $('ai-body').hidden = !configured;

        var label = '';
        if (configured) {
            label = CFG.key.source === 'user' ? L.key_using_own : L.key_using_site;
            if (CFG.key.own_key_masked) { label += ' ' + CFG.key.own_key_masked; }
        }
        $('ai-key-state').textContent = label;
    }

    function saveKey(scope, value) {
        var msg = $('ai-key-msg');
        say(msg, '…');
        api('POST', '/settings/key', { scope: scope, key: value }).then(function (res) {
            if (!res.ok) { say(msg, res.message, 'error'); return; }
            say(msg, value ? L.key_saved : L.key_cleared, 'ok');
            renderKeyState(res.key);
            if (res.key.configured) { boot(); }
        });
    }

    // ------------------------------------------------------------------
    // Jobs
    // ------------------------------------------------------------------

    function statusLabel(status) { return L['status_' + status] || status; }

    function loadJobs() {
        return api('GET', '/jobs').then(function (res) {
            if (!res.ok) { return; }
            var list = $('ai-job-list');
            list.innerHTML = '';

            if (!res.jobs.length) {
                var empty = document.createElement('li');
                empty.className = 'ai-empty';
                empty.textContent = L.no_jobs;
                list.appendChild(empty);
                return;
            }

            res.jobs.forEach(function (job) {
                var li = document.createElement('li');
                if (job.uuid === state.jobUuid) { li.className = 'is-active'; }
                var title = document.createElement('span');
                title.className = 'ai-job-line';
                title.textContent = job.title || '—';
                var meta = document.createElement('span');
                meta.className = 'ai-job-meta';
                meta.textContent = statusLabel(job.status) + ' · ' + job.progress + '%';
                li.appendChild(title);
                li.appendChild(meta);
                li.addEventListener('click', function () { openJob(job.uuid); });
                list.appendChild(li);
            });
        });
    }

    function openJob(uuid) {
        state.jobUuid = uuid;
        state.pollDelay = 2000;
        pollJob();
        loadJobs();
    }

    function pollJob() {
        clearTimeout(state.pollTimer);
        if (!state.jobUuid) { return; }

        api('GET', '/jobs/' + encodeURIComponent(state.jobUuid)).then(function (res) {
            if (!res.ok) { return; }
            renderJob(res.job);

            if (res.job.terminal || res.job.waiting_on_human) {
                loadJobs();
                return;
            }

            // Backs off to 10s. A batch advances once a worker tick, so
            // hammering the endpoint every two seconds for an hour buys
            // nothing but load.
            state.pollDelay = Math.min(10000, state.pollDelay + 1000);
            state.pollTimer = setTimeout(pollJob, state.pollDelay);
        });
    }

    function renderJob(job) {
        $('ai-job').hidden = false;
        $('ai-job-title').textContent = job.title || '—';

        var badge = $('ai-job-status');
        badge.textContent = statusLabel(job.status);
        badge.className = 'ai-badge is-' + job.status;

        var showProgress = job.steps.total > 0;
        $('ai-progress').hidden = !showProgress;
        if (showProgress) {
            $('ai-progress-fill').style.width = job.progress + '%';
            var line = (L.progress || '').replace(':done', job.steps.done).replace(':total', job.steps.total);
            if (job.steps.failed) {
                line += ' · ' + (L.failed_count || '').replace(':n', job.steps.failed);
            }
            $('ai-progress-text').textContent = line;
        }

        var approve = $('ai-approve');
        if (job.status === 'awaiting_approval') {
            approve.hidden = false;
            var cost = job.estimate && job.estimate.amount != null
                ? ' (' + money(job.estimate.amount, job.estimate.currency) + ')'
                : '';
            approve.textContent = L.approve + cost;
        } else {
            approve.hidden = true;
        }

        $('ai-cancel').hidden = job.terminal;
        $('ai-reply').hidden = job.status !== 'awaiting_input';

        renderThread(job);
        renderSteps(job);
    }

    function renderThread(job) {
        var thread = $('ai-thread');
        thread.innerHTML = '';
        (job.messages || []).forEach(function (m) {
            var div = document.createElement('div');
            div.className = 'ai-turn ai-turn-' + m.role;
            var role = document.createElement('span');
            role.className = 'ai-turn-role';
            role.textContent = m.role + (m.spoken ? ' 🎙' : '');
            var body = document.createElement('p');
            body.textContent = m.text;
            div.appendChild(role);
            div.appendChild(body);
            thread.appendChild(div);
        });

        if (job.message && (job.status === 'awaiting_input' || job.status === 'failed')) {
            var note = document.createElement('div');
            note.className = 'ai-turn ai-turn-assistant';
            note.innerHTML = '<span class="ai-turn-role">' + statusLabel(job.status) + '</span>';
            var p = document.createElement('p');
            p.textContent = job.message;
            note.appendChild(p);
            thread.appendChild(note);
        }
    }

    /**
     * The steps, as before/after pairs.
     *
     * This is the proof the plugin did its job: a finished step points at a
     * file that now exists in the manager's own folders.
     */
    function renderSteps(job) {
        var host = $('ai-steps');
        host.innerHTML = '';

        (job.steps_detail || []).forEach(function (step) {
            var card = document.createElement('div');
            card.className = 'ai-step';

            var head = document.createElement('div');
            head.className = 'ai-step-head';
            var kind = document.createElement('span');
            kind.textContent = L['step_' + step.type] || step.type;
            var badge = document.createElement('span');
            badge.className = 'ai-badge is-' + step.status;
            badge.textContent = step.status;
            head.appendChild(kind);
            head.appendChild(badge);
            card.appendChild(head);

            var pair = document.createElement('div');
            pair.className = 'ai-step-pair' + (step.source_url ? '' : ' is-single');

            if (step.source_url) {
                pair.appendChild(figure(step.source_url, L.before, step.source_path));
            }

            if (step.target_url) {
                pair.appendChild(figure(step.target_url, L.after, step.target_path));
            } else {
                var placeholder = document.createElement('div');
                placeholder.className = 'ai-step-placeholder';
                placeholder.textContent = step.status;
                pair.appendChild(placeholder);
            }

            card.appendChild(pair);

            if (step.message && step.status === 'failed') {
                var msg = document.createElement('div');
                msg.className = 'ai-step-msg';
                msg.textContent = step.message;
                card.appendChild(msg);
            }

            host.appendChild(card);
        });
    }

    function figure(src, caption, path) {
        var fig = document.createElement('figure');
        var img = document.createElement('img');
        img.src = src;
        img.alt = path || '';
        img.loading = 'lazy';
        var cap = document.createElement('figcaption');
        cap.textContent = caption;
        cap.title = path || '';
        fig.appendChild(img);
        fig.appendChild(cap);
        return fig;
    }

    function submitInstruction() {
        var instruction = ($('ai-instruction').value || '').trim();
        if (!instruction) { return; }

        var msg = $('ai-compose-msg');
        say(msg, '…');
        $('ai-send').disabled = true;

        var body = Object.assign({
            message: instruction,
            text_model: $('ai-text-model').value,
            image_model: $('ai-image-model').value,
            voice_model: CFG.defaults.voice_model,
            output_folder: $('ai-folder').value
        }, currentControls());

        api('POST', '/jobs', body).then(function (res) {
            $('ai-send').disabled = false;
            if (!res.ok) { say(msg, res.message, 'error'); return; }
            say(msg, '');
            $('ai-instruction').value = '';
            openJob(res.job.uuid);
        });
    }

    // ------------------------------------------------------------------
    // Voice
    // ------------------------------------------------------------------

    /**
     * Dictation.
     *
     * Recorded in the browser, transcribed by the gateway, and dropped into
     * the box as text the person can correct before sending. Speech is an
     * input method here, never a result.
     */
    function toggleRecording(button, targetInput) {
        if (state.recorder && state.recordingFor === button) {
            state.recorder.stop();
            return;
        }

        if (!navigator.mediaDevices || !window.MediaRecorder) { return; }

        navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
            var chunks = [];
            var recorder = new MediaRecorder(stream);

            recorder.ondataavailable = function (e) { if (e.data.size) { chunks.push(e.data); } };
            recorder.onstop = function () {
                stream.getTracks().forEach(function (t) { t.stop(); });
                state.recorder = null;
                state.recordingFor = null;
                button.classList.remove('is-recording');
                button.title = L.record;

                var form = new FormData();
                form.append('audio', new Blob(chunks, { type: recorder.mimeType || 'audio/webm' }), 'speech.webm');
                form.append('model', CFG.defaults.voice_model || '');
                form.append('language', (navigator.language || '').slice(0, 2));

                say($('ai-compose-msg'), L.transcribing);
                api('POST', '/voice/transcribe', form).then(function (res) {
                    say($('ai-compose-msg'), res.ok ? '' : res.message, res.ok ? null : 'error');
                    if (res.ok) {
                        targetInput.value = (targetInput.value ? targetInput.value + ' ' : '') + res.text;
                        targetInput.focus();
                        refreshEstimates();
                    }
                });
            };

            recorder.start();
            state.recorder = recorder;
            state.recordingFor = button;
            button.classList.add('is-recording');
            button.title = L.recording;
        }).catch(function () {
            say($('ai-compose-msg'), 'Microphone unavailable.', 'error');
        });
    }

    // ------------------------------------------------------------------
    // Wiring
    // ------------------------------------------------------------------

    function boot() {
        renderKeyState(CFG.key);
        if (!CFG.key || !CFG.key.configured) { return; }
        loadCatalog().then(loadFolders).then(loadJobs);
    }

    document.addEventListener('DOMContentLoaded', function () {
        $('ai-image-model').addEventListener('change', function () { renderControls(); refreshEstimates(); });
        $('ai-text-model').addEventListener('change', refreshEstimates);
        $('ai-instruction').addEventListener('input', refreshEstimates);

        $('ai-send').addEventListener('click', submitInstruction);
        $('ai-instruction').addEventListener('keydown', function (e) {
            // Enter sends, Shift+Enter is a newline — a batch brief is often
            // several lines, so the modifier has to mean something.
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); submitInstruction(); }
        });

        $('ai-mic').addEventListener('click', function () { toggleRecording(this, $('ai-instruction')); });
        $('ai-reply-mic').addEventListener('click', function () { toggleRecording(this, $('ai-reply-input')); });

        $('ai-new-job').addEventListener('click', function () {
            state.jobUuid = null;
            clearTimeout(state.pollTimer);
            $('ai-job').hidden = true;
            $('ai-instruction').focus();
            loadJobs();
        });

        $('ai-approve').addEventListener('click', function () {
            api('POST', '/jobs/' + encodeURIComponent(state.jobUuid) + '/approve').then(function (res) {
                if (res.ok) { state.pollDelay = 2000; renderJob(res.job); pollJob(); }
            });
        });

        $('ai-cancel').addEventListener('click', function () {
            api('POST', '/jobs/' + encodeURIComponent(state.jobUuid) + '/cancel').then(function (res) {
                if (res.ok) { renderJob(res.job); loadJobs(); }
            });
        });

        $('ai-reply-send').addEventListener('click', function () {
            var value = ($('ai-reply-input').value || '').trim();
            if (!value) { return; }
            api('POST', '/jobs/' + encodeURIComponent(state.jobUuid) + '/reply', { message: value })
                .then(function (res) {
                    if (!res.ok) { return; }
                    $('ai-reply-input').value = '';
                    state.pollDelay = 2000;
                    renderJob(res.job);
                    pollJob();
                });
        });

        $('ai-key-save').addEventListener('click', function () { saveKey('user', $('ai-key-input').value.trim()); });
        $('ai-key-clear').addEventListener('click', function () { $('ai-key-input').value = ''; saveKey('user', ''); });

        var siteSave = $('ai-key-site-save');
        if (siteSave) {
            siteSave.addEventListener('click', function () { saveKey('site', $('ai-key-site-input').value.trim()); });
        }

        boot();
    });
})();
</script>
@endverbatim
