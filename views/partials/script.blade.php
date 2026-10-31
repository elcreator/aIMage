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
        recordingFor: null,
        files: null,
        filesFolder: null,
        job: null,
        applying: false,
        filesPicking: true,
        filesSelect: null,
        filesTarget: null,
        painter: null,
        painterLoad: null,
        editing: null,
        toastTimer: null,
        pendingStop: false,
        speak: false,
        spoken: null,
        speakArmed: false
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

        // The catalogue's plural key, and the singular field name the gateway
        // takes. The field name is what goes on the wire; only the label beside
        // it is translated, since the *values* — 1024x1024, auto, transparent —
        // are the gateway's own vocabulary and mean nothing translated.
        var fields = { sizes: 'size', qualities: 'quality', backgrounds: 'background', aspectRatios: 'aspect_ratio' };

        Object.keys(fields).forEach(function (key) {
            var values = model.controls[key];
            if (!values || !values.length) { return; }

            var field = fields[key];
            var wrap = document.createElement('label');
            wrap.className = 'ai-field ai-field-narrow';

            var caption = document.createElement('span');
            caption.textContent = L['control_' + field] || field;
            wrap.appendChild(caption);

            var select = document.createElement('select');
            select.dataset.control = field;
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
            $('ai-folder').value = CFG.defaults.output_folder || res.output_folder;
            state.filesFolder = res.folder;
        });
    }

    // ------------------------------------------------------------------
    // The file browser
    //
    // It keeps its own view of the tree, separate from the composer's: opening
    // it and looking around must not change where results are going. Only
    // "Put results here" does that.
    // ------------------------------------------------------------------

    /** Open the browser to choose a folder: where results go, or where new edits go. */
    function openFiles(target) {
        state.filesPicking = true;
        state.filesSelect = null;
        state.filesTarget = target || $('ai-folder');
        $('ai-files-use').hidden = false;
        $('ai-files').hidden = false;
        browse((state.filesTarget.value || '').trim() || state.filesFolder || '');
    }

    /**
     * Open the browser to show where one image ended up.
     *
     * The same dialog, without the one control that changes anything: looking
     * for a file must not quietly re-point a task's results at whatever folder
     * the manager happened to stop in. Everything else — navigating, the
     * preview, Close — behaves identically.
     */
    function inspectFile(path) {
        state.filesPicking = false;
        state.filesSelect = path;
        $('ai-files-use').hidden = true;
        $('ai-files').hidden = false;
        browse(parentOf(path));
    }

    function parentOf(path) {
        var cut = String(path || '').lastIndexOf('/');
        return cut === -1 ? '' : path.slice(0, cut);
    }

    function closeFiles() {
        $('ai-files').hidden = true;
        state.filesSelect = null;
    }

    function browse(folder) {
        say($('ai-files-msg'), '');
        clearPreview();

        return api('GET', '/files', null, { folder: folder }).then(function (res) {
            if (!res.ok) {
                say($('ai-files-msg'), res.message, 'error');
                return;
            }

            state.files = res;
            renderCrumbs(res);
            renderEntries(res);

            $('ai-files-up').disabled = res.parent === null;
            $('ai-files-use').disabled = !res.writable;

            // Only while choosing. Somebody looking for a file has not asked
            // to write anything, so a refusal to write is not their problem.
            if (state.filesPicking && !res.writable) {
                say($('ai-files-msg'), L.files_not_writable, 'error');
            }
        });
    }

    /**
     * The path, one segment at a time.
     *
     * Only the current folder and its ancestors down to the ceiling can be
     * navigated to. Anything above the ceiling is shown so the path reads
     * correctly, but it is not a link: there is nothing up there a result could
     * be written to, and offering the climb would only earn a refusal.
     */
    function renderCrumbs(res) {
        var nav = $('ai-files-crumbs');
        nav.innerHTML = '';

        var segments = (res.folder || '').split('/').filter(Boolean);

        if (!segments.length) {
            var root = document.createElement('span');
            root.className = 'ai-crumb is-current';
            root.textContent = '/';
            nav.appendChild(root);
            return;
        }

        // The server says what the parent is; everything shallower than that is
        // above the ceiling. One walk up from the parent gives the reachable
        // set without the front end having to know the rule.
        var reachable = {};
        if (res.parent !== null && res.parent !== undefined) {
            var probe = res.parent;
            for (;;) {
                reachable[probe] = true;
                if (!probe) { break; }
                var cut = probe.lastIndexOf('/');
                probe = cut === -1 ? '' : probe.slice(0, cut);
                if (probe === '' && res.parent === '') { break; }
            }
        }

        var walked = [];

        segments.forEach(function (segment, index) {
            walked.push(segment);

            var path = walked.join('/');
            var last = index === segments.length - 1;
            var node;

            if (!last && reachable[path]) {
                node = document.createElement('button');
                node.type = 'button';
                node.className = 'ai-crumb';
                node.addEventListener('click', function () { browse(path); });
            } else {
                node = document.createElement('span');
                node.className = 'ai-crumb' + (last ? ' is-current' : ' is-fixed');
            }

            node.textContent = segment;
            if (index) { nav.appendChild(document.createTextNode('/')); }
            nav.appendChild(node);
        });
    }

    function renderEntries(res) {
        var list = $('ai-files-list');
        list.innerHTML = '';

        (res.folders || []).forEach(function (folder) {
            var node = entryNode('ai-entry-folder', folder.name);
            node.insertBefore(iconNode('fa-folder'), node.firstChild);
            node.addEventListener('click', function () { browse(folder.path); });
            list.appendChild(node);
        });

        (res.images || []).forEach(function (image) {
            var node = entryNode('ai-entry-image', image.name);

            if (image.url) {
                var thumb = document.createElement('img');
                thumb.src = image.url;
                thumb.alt = '';
                thumb.loading = 'lazy';
                node.insertBefore(thumb, node.firstChild);
            } else {
                node.insertBefore(iconNode('fa-image'), node.firstChild);
            }

            node.addEventListener('click', function () {
                Array.prototype.forEach.call(list.children, function (child) {
                    child.classList.remove('is-selected');
                });
                node.classList.add('is-selected');
                showPreview(image.path);
            });

            // Opened to show where one image is: select it and open its
            // metadata, then forget it, so navigating on from here behaves
            // like any other browse.
            if (state.filesSelect === image.path) {
                state.filesSelect = null;
                node.classList.add('is-selected');
                node.scrollIntoView({ block: 'nearest' });
                showPreview(image.path);
            }

            list.appendChild(node);
        });

        if (!list.children.length) {
            var empty = document.createElement('p');
            empty.className = 'ai-hint';
            empty.textContent = L.files_empty;
            list.appendChild(empty);
        }
    }

    function entryNode(className, label) {
        var node = document.createElement('button');
        node.type = 'button';
        node.className = 'ai-entry ' + className;
        node.title = label;

        var name = document.createElement('span');
        name.className = 'ai-entry-name';
        name.textContent = label;
        node.appendChild(name);

        return node;
    }

    /** An icon from the manager's own set, which this page loads in its head. */
    function iconNode(name) {
        var icon = document.createElement('i');
        icon.className = 'ai-entry-icon fa ' + name;
        icon.setAttribute('aria-hidden', 'true');
        return icon;
    }

    /** The same icon set, at text size, for inside a button. */
    function buttonIcon(name) {
        var icon = document.createElement('i');
        icon.className = 'fa ' + name;
        icon.setAttribute('aria-hidden', 'true');
        return icon;
    }

    function clearPreview() {
        var pane = $('ai-files-preview');
        pane.hidden = true;
        pane.innerHTML = '';
    }

    /**
     * Metadata is fetched per image rather than carried in the listing:
     * measuring a file means opening it, and a folder of five hundred images is
     * not five hundred questions anybody asked.
     */
    function showPreview(path) {
        api('GET', '/files/info', null, { path: path }).then(function (res) {
            var pane = $('ai-files-preview');

            if (!res.ok) {
                say($('ai-files-msg'), res.message, 'error');
                clearPreview();
                return;
            }

            var file = res.file;

            pane.hidden = false;
            pane.innerHTML = '';

            if (file.url) {
                var figure = document.createElement('img');
                figure.className = 'ai-preview-image';
                figure.src = file.url;
                figure.alt = file.name;
                pane.appendChild(figure);
            }

            var heading = document.createElement('h3');
            heading.textContent = file.name;
            pane.appendChild(heading);

            pane.appendChild(metaRow(
                L.files_resolution,
                (file.width && file.height) ? file.width + ' × ' + file.height : L.files_unknown
            ));
            pane.appendChild(metaRow(L.files_bytes, humanBytes(file.bytes)));
            pane.appendChild(metaRow(
                L.files_modified,
                file.modified ? new Date(file.modified * 1000).toLocaleString() : L.files_unknown
            ));
            pane.appendChild(urlRow(file));

            if (CFG.editor && CFG.editor.available) {
                var edit = document.createElement('button');
                edit.type = 'button';
                edit.className = 'ai-btn ai-btn-primary ai-btn-small ai-btn-edit';
                edit.appendChild(buttonIcon('fa-paint-brush'));
                edit.appendChild(document.createTextNode(L.editor_edit));
                edit.addEventListener('click', function () {
                    closeFiles();
                    openEditor({ source: file.path, mime: file.mime });
                });
                pane.appendChild(edit);
            }
        });
    }

    function metaRow(label, value) {
        var row = document.createElement('p');
        row.className = 'ai-meta';

        var key = document.createElement('span');
        key.textContent = label;

        var val = document.createElement('strong');
        val.textContent = value;

        row.appendChild(key);
        row.appendChild(val);

        return row;
    }

    /**
     * The URL is what most people open this for — it is the thing that gets
     * pasted into a page — so it is selectable text with a copy button beside
     * it, not a truncated line of prose.
     */
    function urlRow(file) {
        var row = document.createElement('p');
        row.className = 'ai-meta ai-meta-url';

        var key = document.createElement('span');
        key.textContent = L.files_url;
        row.appendChild(key);

        var field = document.createElement('input');
        field.type = 'text';
        field.readOnly = true;
        field.value = file.url || file.path;
        field.addEventListener('focus', function () { field.select(); });
        row.appendChild(field);

        var copy = document.createElement('button');
        copy.type = 'button';
        copy.className = 'ai-btn ai-btn-small';
        copy.textContent = L.files_copy;
        copy.addEventListener('click', function () {
            var done = function () { copy.textContent = L.files_copied; };

            field.select();

            // The clipboard API needs a secure context, and a manager served
            // over plain HTTP on a local network is ordinary. execCommand is
            // deprecated but is what still works there.
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(field.value).then(done, function () {
                    if (document.execCommand('copy')) { done(); }
                });
            } else if (document.execCommand('copy')) {
                done();
            }
        });
        row.appendChild(copy);

        return row;
    }

    function humanBytes(bytes) {
        if (!bytes) { return L.files_unknown; }

        var units = ['B', 'KB', 'MB', 'GB'];
        var index = 0;

        while (bytes >= 1024 && index < units.length - 1) {
            bytes /= 1024;
            index++;
        }

        return (index ? bytes.toFixed(1) : bytes) + ' ' + units[index];
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

    // ------------------------------------------------------------------
    // Balance
    // ------------------------------------------------------------------

    function money(eur) {
        return (Math.round(eur * 100) / 100).toFixed(2) + ' €';
    }

    /** The "Top up" link: the gateway's own answer when it gave one, else the profile. */
    function topUpUrl() {
        return (state.account && state.account.top_up_url) || CFG.topUpUrl || '#';
    }

    function renderAccount(acc) {
        state.account = acc || null;
        var line = $('ai-balance');
        var alertBox = $('ai-balance-alert');
        var reason = '';
        if (acc && acc.balance_eur !== null) {
            line.textContent = L.balance + ': ' + money(acc.balance_eur);
            line.hidden = false;
            if (acc.balance_eur <= 0) { reason = L.balance_empty; }
            else if (acc.low_balance) { reason = L.balance_low.replace('%s', money(acc.balance_eur)); }
        } else {
            line.hidden = true;
        }
        if (!reason && acc && acc.cap_eur !== null && acc.spent_eur !== null && acc.spent_eur >= acc.cap_eur) {
            reason = L.cap_reached;
        }
        alertBox.hidden = !reason;
        $('ai-balance-alert-text').textContent = reason;
        var link = $('ai-topup');
        link.textContent = L.top_up;
        link.href = topUpUrl();
    }

    function loadAccount() {
        return api('GET', '/account').then(function (res) {
            if (res && res.ok) {
                if (res.top_up_url) { CFG.topUpUrl = res.top_up_url; }
                renderAccount(res.account);
            }
        }).catch(function () { /* the balance is a courtesy; never block the page on it */ });
    }

    /**
     * A failed step or job whose code says the money ran out gets a sentence in the
     * page's language and the way to fix it, instead of the gateway's English message.
     * Returns the node to show, or null for any other failure.
     */
    function paymentProblem(code) {
        var text = code === 'INSUFFICIENT_BALANCE' ? L.error_insufficient_balance
            : code === 'KEY_CAP_REACHED' ? L.error_key_cap_reached : '';
        if (!text) { return null; }
        var box = document.createElement('span');
        box.appendChild(document.createTextNode(text + ' '));
        var a = document.createElement('a');
        a.className = 'ai-btn';
        a.href = topUpUrl();
        a.target = '_blank';
        a.rel = 'noopener';
        a.textContent = L.top_up;
        box.appendChild(a);
        return box;
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
        state.speakArmed = false;
        state.spoken = null;
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

    /**
     * Point the pickers at whichever task is open.
     *
     * They are the open task's settings, not the page's. Leaving them alone
     * when the manager switches tasks shows one task's models above another
     * task's images, and the next change silently writes to the wrong one.
     *
     * `state.applying` keeps the change handlers quiet while this runs, so
     * restoring a task's own models does not save them back to it.
     */
    function applyJobSettings(job) {
        state.applying = true;

        try {
            if (job.image_model && $('ai-image-model').value !== job.image_model) {
                $('ai-image-model').value = job.image_model;
                renderControls();
            }

            if (job.text_model) { $('ai-text-model').value = job.text_model; }
            if (job.output_folder) { $('ai-folder').value = job.output_folder; }

            var controls = job.controls || {};
            Array.prototype.forEach.call(
                $('ai-controls').querySelectorAll('select[data-control]'),
                function (select) { select.value = controls[select.dataset.control] || ''; }
            );
        } finally {
            state.applying = false;
        }

        refreshEstimates();
    }

    /** Carry a picker change into the open task, so it continues on the new model. */
    function saveJobModels() {
        if (state.applying || !state.jobUuid) { return; }

        var msg = $('ai-compose-msg');

        api('POST', '/jobs/' + encodeURIComponent(state.jobUuid) + '/models', {
            text_model: $('ai-text-model').value,
            image_model: $('ai-image-model').value
        }).then(function (res) {
            if (!res.ok) {
                // Put the pickers back to what the task actually has, or they
                // would keep showing a model the task refused.
                say(msg, res.message, 'error');
                if (state.job) { applyJobSettings(state.job); }
                return;
            }

            say(msg, '');
            state.job = res.job;
        });
    }

    function renderJob(job) {
        state.job = job;
        applyJobSettings(job);

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
        maybeSpeak(job);
    }

    function renderThread(job) {
        var thread = $('ai-thread');
        thread.innerHTML = '';
        (job.messages || []).forEach(function (m) {
            var div = document.createElement('div');
            div.className = 'ai-turn ai-turn-' + m.role;
            var role = document.createElement('span');
            role.className = 'ai-turn-role';
            role.textContent = L['turn_' + m.role] || m.role;

            if (m.spoken) {
                var spoken = document.createElement('i');
                spoken.className = 'fa fa-microphone ai-turn-spoken';
                spoken.setAttribute('aria-hidden', 'true');
                role.appendChild(document.createTextNode(' '));
                role.appendChild(spoken);
            }
            var body = document.createElement('p');
            body.textContent = m.text;
            div.appendChild(role);
            div.appendChild(body);
            thread.appendChild(div);
        });

        // The job's own message repeats the last turn when the planner gave up
        // on a model that would not use its tools — the turn *is* the message.
        // Printing it twice reads as the assistant having said it twice.
        var last = (job.messages || [])[(job.messages || []).length - 1];
        var repeats = last && last.role === 'assistant' && last.text === job.message;

        if (job.message && !repeats && (job.status === 'awaiting_input' || job.status === 'failed')) {
            var note = document.createElement('div');
            note.className = 'ai-turn ai-turn-assistant';
            note.innerHTML = '<span class="ai-turn-role">' + statusLabel(job.status) + '</span>';
            var p = document.createElement('p');
            var jobProblem = job.status === 'failed' ? paymentProblem(job.error_code) : null;
            if (jobProblem) { p.appendChild(jobProblem); } else { p.textContent = job.message; }
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
    /**
     * The answer of an analysis step: alt text ready to copy, the dominant colours as
     * swatches and the focal point as percentages, with a dot on the source picture's
     * figure. A part the gateway could not measure is named, never shown blank.
     */
    function renderAnalysis(card, step) {
        var a = step.analysis;
        if (!a) { return; }
        var box = document.createElement('div');
        box.className = 'ai-analysis';

        if (typeof a.alt === 'string' && a.alt !== '') {
            var row = document.createElement('div');
            row.className = 'ai-analysis-row';
            var label = document.createElement('strong');
            label.textContent = L.analysis_alt + ': ';
            var text = document.createElement('span');
            text.className = 'ai-analysis-alt';
            text.textContent = a.alt;
            var copy = document.createElement('button');
            copy.type = 'button';
            copy.className = 'ai-btn';
            copy.textContent = L.files_copy;
            copy.addEventListener('click', function () {
                if (navigator.clipboard) { navigator.clipboard.writeText(a.alt); }
                copy.textContent = L.files_copied;
            });
            row.appendChild(label);
            row.appendChild(text);
            row.appendChild(copy);
            box.appendChild(row);
        }

        if (Array.isArray(a.colors) && a.colors.length) {
            var colors = document.createElement('div');
            colors.className = 'ai-analysis-row';
            var cl = document.createElement('strong');
            cl.textContent = L.analysis_colors + ': ';
            colors.appendChild(cl);
            a.colors.forEach(function (c) {
                if (!c || !/^#[0-9a-fA-F]{3,8}$/.test(String(c.hex || ''))) { return; }
                var sw = document.createElement('span');
                sw.className = 'ai-swatch';
                sw.style.background = c.hex;
                sw.title = c.hex + (typeof c.share === 'number' ? ' · ' + Math.round(c.share * 100) + '%' : '');
                colors.appendChild(sw);
                colors.appendChild(document.createTextNode(' '));
            });
            box.appendChild(colors);
        }

        if (a.focal && typeof a.focal.x === 'number' && typeof a.focal.y === 'number') {
            var focal = document.createElement('div');
            focal.className = 'ai-analysis-row';
            focal.textContent = L.analysis_focal + ': ' + Math.round(a.focal.x * 100) + '% × ' + Math.round(a.focal.y * 100) + '%';
            box.appendChild(focal);
            var fig = card.querySelector('.ai-step-pair figure');
            if (fig) {
                fig.style.position = 'relative';
                var dot = document.createElement('span');
                dot.className = 'ai-focal-dot';
                dot.style.left = (a.focal.x * 100) + '%';
                dot.style.top = (a.focal.y * 100) + '%';
                fig.appendChild(dot);
            }
        }

        if (Array.isArray(a.missing) && a.missing.length) {
            var miss = document.createElement('div');
            miss.className = 'ai-hint';
            miss.textContent = L.analysis_missing + ': ' + a.missing.join(', ');
            box.appendChild(miss);
        }
        card.appendChild(box);
    }

    /** What happened in the sGallery gallery, if the step was bound to one. */
    function renderGallery(card, step) {
        var g = step.gallery;
        if (!g) { return; }
        var line = document.createElement('div');
        line.className = 'ai-hint';
        if (g.error) {
            line.className = 'ai-msg is-error';
            line.textContent = L.gallery_failed + ' (' + g.error + ')';
        } else if (g.alt) {
            line.textContent = g.alt === 'saved' ? L.gallery_alt_saved : (g.alt === 'kept' ? L.gallery_alt_kept : L.gallery_alt_none);
        } else {
            line.textContent = L.gallery_added.replace('%s', g.document_id);
        }
        card.appendChild(line);
    }

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
            badge.textContent = statusLabel(step.status);
            head.appendChild(kind);
            head.appendChild(badge);
            card.appendChild(head);

            var pair = document.createElement('div');
            pair.className = 'ai-step-pair' + (step.source_url ? '' : ' is-single');

            if (step.source_url) {
                pair.appendChild(figure(step.source_url, L.before, step.source_path, false, true));
            }

            if (step.target_url) {
                // A result is always inside the write root, so the browser can
                // always open where it landed. A source may not be — it can sit
                // anywhere the manager may read — so only the result is a link.
                pair.appendChild(figure(step.target_url, L.after, step.target_path, true, true));
            } else {
                var placeholder = document.createElement('div');
                placeholder.className = 'ai-step-placeholder';
                placeholder.textContent = statusLabel(step.status);
                pair.appendChild(placeholder);
            }

            card.appendChild(pair);
            renderAnalysis(card, step);
            renderGallery(card, step);

            if (step.message && step.status === 'failed') {
                var msg = document.createElement('div');
                msg.className = 'ai-step-msg';
                var problem = paymentProblem(step.error_code);
                if (problem) { msg.appendChild(problem); } else { msg.textContent = step.message; }
                card.appendChild(msg);
            }

            host.appendChild(card);
        });
    }

    function figure(src, caption, path, locatable, editable) {
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

        if (locatable && path) {
            fig.className = 'is-locatable';
            fig.title = L.files_locate || path;
            fig.addEventListener('click', function () { inspectFile(path); });
        }

        if (editable && path && CFG.editor && CFG.editor.available) {
            var edit = document.createElement('button');
            edit.type = 'button';
            edit.className = 'ai-fig-edit';
            edit.title = L.editor_edit;
            edit.setAttribute('aria-label', L.editor_edit);
            edit.appendChild(buttonIcon('fa-paint-brush'));
            edit.addEventListener('click', function (event) {
                event.stopPropagation();
                openEditor({ source: path });
            });
            fig.appendChild(edit);
        }

        return fig;
    }

    /**
     * Enter sends, Shift+Enter is a newline.
     *
     * Applied to both boxes from one place. A brief is often several lines and
     * an answer sometimes is, so the modifier has to mean the same thing in
     * each — and the reply box was an `<input>`, which cannot hold a newline
     * at all however it is typed.
     */
    function sendOnEnter(field, send) {
        field.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                send();
            }
        });
    }

    function sendReply() {
        var field = $('ai-reply-input');
        var value = (field.value || '').trim();

        if (!value || !state.jobUuid) { return; }

        api('POST', '/jobs/' + encodeURIComponent(state.jobUuid) + '/reply', { message: value })
            .then(function (res) {
                if (!res.ok) { return; }
                field.value = '';
                state.pollDelay = 2000;
                renderJob(res.job);
                pollJob();
            });
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
    /** A microphone that is listening offers to stop, not to start again. */
    function setMicIcon(button, recording) {
        var icon = button.querySelector('i');
        if (icon) { icon.className = 'fa ' + (recording ? 'fa-stop' : 'fa-microphone'); }
    }

    /**
     * Read one answer aloud.
     *
     * Not through `api()`: that helper parses every response as JSON, and this
     * endpoint returns audio. A refusal *does* come back as JSON, which is why
     * the content type is checked rather than the status alone.
     */
    function speakText(message) {
        if (!state.speak || !message) { return; }

        fetch(url('/voice/speak'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-CSRF-TOKEN': CFG.csrf,
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ text: message, model: CFG.defaults.speech_model || '' })
        }).then(function (res) {
            var type = res.headers.get('Content-Type') || '';
            return (res.ok && type.indexOf('audio') !== -1) ? res.blob() : null;
        }).then(function (blob) {
            if (!blob) { return; }

            var audio = new Audio(URL.createObjectURL(blob));
            audio.onended = function () { URL.revokeObjectURL(audio.src); };
            // A browser may refuse to play sound the person did not ask for by
            // clicking. Refusing quietly is right: this is a convenience.
            audio.play().catch(function () {});
        }).catch(function () {});
    }

    /**
     * Speak an answer that has just arrived, and only that.
     *
     * Opening a task must not read its history back, so the first render of a
     * task records where the conversation stands without saying anything; only
     * what arrives while it is being watched is spoken.
     */
    function maybeSpeak(job) {
        var latest = null;

        (job.messages || []).forEach(function (m) {
            if (m.role === 'assistant' && m.text) { latest = m.text; }
        });

        if (!state.speakArmed) {
            state.speakArmed = true;
            state.spoken = latest;
            return;
        }

        if (latest && latest !== state.spoken) {
            state.spoken = latest;
            speakText(latest);
        }
    }

    /** How long a press has to last before it counts as holding rather than clicking. */
    var HOLD_MS = 350;

    function isRecording(button) {
        return state.recordingFor === button;
    }

    function resetMic(button) {
        button.classList.remove('is-recording');
        setMicIcon(button, false);
        button.title = L.record;
    }

    function startRecording(button, targetInput) {
        // One microphone, one recorder. Two buttons racing for it would leave
        // the loser's UI stuck in the recording state for ever.
        if (state.recordingFor) { return; }

        if (!navigator.mediaDevices || !window.MediaRecorder) { return; }

        // Claimed before the permission prompt resolves, not after: a release
        // can arrive while the browser is still asking, and there has to be
        // something for it to cancel.
        state.recordingFor = button;
        state.pendingStop = false;
        button.classList.add('is-recording');
        setMicIcon(button, true);
        button.title = L.recording;

        navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
            var chunks = [];
            var recorder = new MediaRecorder(stream);

            recorder.ondataavailable = function (e) { if (e.data.size) { chunks.push(e.data); } };
            recorder.onstop = function () {
                stream.getTracks().forEach(function (t) { t.stop(); });
                state.recorder = null;
                state.recordingFor = null;
                resetMic(button);

                var form = new FormData();
                form.append('audio', new Blob(chunks, { type: recorder.mimeType || 'audio/webm' }), 'speech.webm');
                form.append('model', CFG.defaults.voice_model || '');
                form.append('language', (navigator.language || '').slice(0, 2));

                say($('ai-compose-msg'), L.transcribing);
                api('POST', '/voice/transcribe', form).then(function (res) {
                    say($('ai-compose-msg'), res.ok ? '' : res.message, res.ok ? null : 'error');
                    if (res.ok) {
                        // The transcript lands in the box rather than being sent.
                        // Speech is an input method here: a misheard word should
                        // cost a correction, not a batch of images.
                        targetInput.value = (targetInput.value ? targetInput.value + ' ' : '') + res.text;
                        targetInput.focus();
                        refreshEstimates();
                    }
                });
            };

            recorder.start();
            state.recorder = recorder;

            // Let go before the microphone opened. Honour it now.
            if (state.pendingStop) { stopRecording(button); }
        }).catch(function () {
            state.recordingFor = null;
            resetMic(button);
            say($('ai-compose-msg'), 'Microphone unavailable.', 'error');
        });
    }

    function stopRecording(button) {
        if (!isRecording(button)) { return; }

        if (!state.recorder) {
            state.pendingStop = true;
            return;
        }

        state.recorder.stop();
    }

    function toggleRecording(button, targetInput) {
        if (isRecording(button)) {
            stopRecording(button);
        } else {
            startRecording(button, targetInput);
        }
    }

    /**
     * Click to latch, or hold to talk.
     *
     * Both, because both are what people try. A quick tap starts recording and
     * leaves it running until the next tap; pressing and holding records only
     * for as long as the button is held, which is what a microphone button
     * looks like it should do. `HOLD_MS` is what separates them, so a tap that
     * happens to be slightly slow still latches rather than recording nothing.
     *
     * `click` is kept for the keyboard: Space and Enter on a focused button fire
     * it without any pointer event, and a control that only answers to a mouse
     * is a control some people cannot use.
     */
    function bindMic(button, targetInput) {
        if (!button) { return; }

        var pressedAt = 0;
        var byPointer = false;

        button.addEventListener('pointerdown', function () {
            byPointer = true;

            if (isRecording(button)) {
                // Latched by an earlier tap; this press is the one that ends it.
                pressedAt = 0;
                stopRecording(button);
                return;
            }

            pressedAt = Date.now();
            startRecording(button, targetInput);
        });

        var release = function () {
            if (!pressedAt) { return; }

            var held = Date.now() - pressedAt;
            pressedAt = 0;

            if (held >= HOLD_MS) { stopRecording(button); }
        };

        button.addEventListener('pointerup', release);
        button.addEventListener('pointercancel', release);
        // Dragged off the button mid-hold. Treated as a release rather than a
        // cancel, because the audio up to that point is what was said.
        button.addEventListener('pointerleave', release);

        button.addEventListener('click', function () {
            if (byPointer) { byPointer = false; return; }
            toggleRecording(button, targetInput);
        });
    }

    // ------------------------------------------------------------------
    // The hand editor (Painterro)
    //
    // Needs no key and spends nothing. One Painterro instance, loaded on first
    // use and reused; `state.editing` says what is open in it, because the
    // save handler is fixed when the instance is made.
    // ------------------------------------------------------------------

    /** Formats a canvas can write; anything else (gif, svg, bmp) is saved as PNG. */
    var CANVAS_TYPES = { 'image/png': 'png', 'image/jpeg': 'jpg', 'image/webp': 'webp' };

    function mimeOfPath(path) {
        var ext = String(path || '').split('.').pop().toLowerCase();
        return { png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', webp: 'image/webp', gif: 'image/gif' }[ext] || '';
    }

    function setMode(mode, remember) {
        mode = mode === 'editor' ? 'editor' : 'ai';
        $('ai-view-ai').hidden = mode !== 'ai';
        $('ai-view-editor').hidden = mode !== 'editor';
        ['ai', 'editor'].forEach(function (m) {
            $('ai-mode-' + m).setAttribute('aria-selected', m === mode ? 'true' : 'false');
        });
        if (remember) {
            try { window.localStorage.setItem('aimage.mode', mode); } catch (e) { /* storage blocked */ }
        }
    }

    function initialMode() {
        var match = /[?&]mode=(ai|editor)\b/.exec(window.location.search);
        if (match) { return match[1]; }
        try { return window.localStorage.getItem('aimage.mode') || 'ai'; } catch (e) { return 'ai'; }
    }

    function toast(message, kind) {
        var el = $('ai-toast');
        clearTimeout(state.toastTimer);
        el.textContent = message || '';
        el.className = 'ai-toast' + (kind ? ' is-' + kind : '');
        el.hidden = !message;
        if (message) {
            state.toastTimer = setTimeout(function () { el.hidden = true; }, kind === 'error' ? 8000 : 4000);
        }
    }

    /**
     * What to make fullscreen.
     *
     * The manager's frame carries no `allowfullscreen`, so this document may
     * not go fullscreen itself. It is same-origin with the manager, though,
     * and the frame element it sits in may: that element, made fullscreen
     * from the manager's own document, is this page at the size of the screen.
     */
    function screenTarget() {
        var target = document.documentElement;
        try {
            var win = window;
            while (win.frameElement) {
                target = win.frameElement;
                win = win.parent;
            }
        } catch (e) { /* a cross-origin ancestor: stop at the last frame we may touch */ }
        return target;
    }

    function isFullscreen() {
        var doc = screenTarget().ownerDocument;
        return !!(doc.fullscreenElement || doc.webkitFullscreenElement);
    }

    function enterFullscreen() {
        var el = screenTarget();
        var request = el.requestFullscreen || el.webkitRequestFullscreen;
        if (!request || isFullscreen()) { return; }
        try {
            var result = request.call(el);
            if (result && result.catch) { result.catch(function () { /* refused: the overlay still fills the page */ }); }
        } catch (e) { /* idem */ }
    }

    function exitFullscreen() {
        var doc = screenTarget().ownerDocument;
        var exit = doc.exitFullscreen || doc.webkitExitFullscreen;
        if (!exit || !isFullscreen()) { return; }
        try {
            var result = exit.call(doc);
            if (result && result.catch) { result.catch(function () {}); }
        } catch (e) {}
    }

    function renderScreenButton() {
        var button = $('ai-editor-screen');
        var on = isFullscreen();
        var label = on ? L.editor_exit_fullscreen : L.editor_fullscreen;
        button.title = label;
        button.setAttribute('aria-label', label);
        button.querySelector('i').className = 'fa ' + (on ? 'fa-compress' : 'fa-expand');
    }

    function loadPainterro() {
        if (state.painterLoad) { return state.painterLoad; }

        state.painterLoad = new Promise(function (resolve, reject) {
            if (window.Painterro) { resolve(); return; }
            var script = document.createElement('script');
            script.src = CFG.editor.bundleUrl;
            script.onload = function () { if (window.Painterro) { resolve(); } else { reject(); } };
            script.onerror = reject;
            document.head.appendChild(script);
        }).then(function () {
            state.painter = window.Painterro({
                // Our own container. Without one Painterro dispatches its tool
                // events on `#app`, which is not on this page, and every tool
                // click throws.
                id: 'ai-painter',
                language: CFG.editor.language,
                // Every tool, including the ones Painterro hides by default. Its own
                // "open" is left out: what is open is ours to know, for saving.
                hiddenTools: ['open'],
                hideByEsc: false,
                activeColor: '#e11d48',
                colorScheme: { activeControl: '#2f6fd0', hoverControlContent: '#2f6fd0' },
                saveHandler: saveEdit,
                onBeforeClose: function (hasUnsaved, doClose) {
                    if (!hasUnsaved || window.confirm(L.editor_discard)) { doClose(); }
                },
                onHide: closedEditor,
                onImageFailedOpen: function () { toast(L.editor_open_failed, 'error'); }
            });
        });

        state.painterLoad.catch(function () {
            state.painterLoad = null;
            toast(L.editor_load_failed, 'error');
        });

        return state.painterLoad;
    }

    /**
     * Open the editor on a site image (`source`), a local file (`file`), or a blank canvas.
     *
     * Fullscreen is asked for first, while the click that got here still
     * counts as one: the bundle may take a moment to load the first time.
     */
    function openEditor(what) {
        what = what || {};
        enterFullscreen();

        loadPainterro().then(function () {
            var mime = what.mime || (what.file && what.file.type) || mimeOfPath(what.source);

            state.editing = {
                source: what.source || null,
                name: what.file ? what.file.name : '',
                mime: CANVAS_TYPES[mime] ? mime : 'image/png',
                replaceNext: false
            };

            $('ai-editor-replace').hidden = !(CFG.editor.canReplace && state.editing.source && CANVAS_TYPES[mime]);
            $('ai-editor-chrome').hidden = false;
            renderScreenButton();

            if (what.source) {
                state.painter.show(url('/editor/image', { path: what.source }), mime || undefined);
            } else if (what.file) {
                var reader = new FileReader();
                reader.onload = function () { state.painter.show(String(reader.result), what.file.type); };
                reader.onerror = function () { toast(L.editor_open_failed, 'error'); };
                reader.readAsDataURL(what.file);
            } else {
                state.painter.show();
            }
        });
    }

    function closedEditor() {
        // Painterro hides itself once while being built, before anything is
        // open; that must not undo the fullscreen the opening click asked for.
        if (!state.editing) { return; }
        $('ai-editor-chrome').hidden = true;
        state.editing = null;
        exitFullscreen();
    }

    /** Painterro's save: a copy beside the original, or over it when asked and allowed. */
    function saveEdit(image, done) {
        var editing = state.editing || {};
        var replace = !!editing.replaceNext;
        editing.replaceNext = false;

        var type = editing.mime || 'image/png';
        var form = new FormData();
        form.append('image', image.asBlob(type, 0.92), 'edited.' + CANVAS_TYPES[type]);

        if (editing.source) {
            form.append('source', editing.source);
        } else {
            form.append('folder', $('ai-editor-folder') ? $('ai-editor-folder').value : '');
            form.append('name', editing.name || '');
        }
        if (replace) { form.append('replace', '1'); }

        toast(L.editor_saving);

        api('POST', '/editor/save', form).then(function (res) {
            if (!res.ok) {
                toast(res.message, 'error');
                done(false);
                return;
            }

            done(true);
            toast(String(L.editor_saved).replace('%s', res.file.path));
            showLastSaved(res.file);

            // An open job may be showing the image that just changed.
            if (state.jobUuid) { pollJob(); }
        }, function () {
            toast(L.editor_load_failed, 'error');
            done(false);
        });
    }

    function showLastSaved(file) {
        var host = $('ai-editor-last');
        if (!host) { return; }
        host.innerHTML = '';

        var img = document.createElement('img');
        img.src = url('/editor/image', { path: file.path, t: Date.now() });
        img.alt = file.name || file.path;
        host.appendChild(img);

        var body = document.createElement('div');
        body.className = 'ai-editor-last-body';

        var path = document.createElement('code');
        path.textContent = file.path;
        body.appendChild(path);

        var row = document.createElement('div');
        row.className = 'ai-row';

        var again = document.createElement('button');
        again.type = 'button';
        again.className = 'ai-btn ai-btn-small';
        again.textContent = L.editor_edit_again;
        again.addEventListener('click', function () { openEditor({ source: file.path, mime: file.mime }); });
        row.appendChild(again);

        var locate = document.createElement('button');
        locate.type = 'button';
        locate.className = 'ai-btn ai-btn-small';
        locate.textContent = L.files_locate;
        locate.addEventListener('click', function () { inspectFile(file.path); });
        row.appendChild(locate);

        body.appendChild(row);
        host.appendChild(body);
        host.hidden = false;
    }

    function bindEditor() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-mode]'), function (tab) {
            tab.addEventListener('click', function () { setMode(tab.dataset.mode, true); });
        });
        Array.prototype.forEach.call(document.querySelectorAll('[data-mode-switch]'), function (button) {
            button.addEventListener('click', function () { setMode(button.dataset.modeSwitch, true); });
        });
        setMode(initialMode(), false);

        if (!CFG.editor || !CFG.editor.available) { return; }

        $('ai-editor-folder').value = (CFG.defaults && CFG.defaults.output_folder) || '';

        $('ai-editor-site').addEventListener('click', function () {
            // Browsing to pick an image, not a folder: nothing here changes where anything goes.
            state.filesPicking = false;
            state.filesSelect = null;
            $('ai-files-use').hidden = true;
            $('ai-files').hidden = false;
            browse(($('ai-editor-folder').value || '').trim());
        });
        $('ai-editor-browse').addEventListener('click', function () { openFiles($('ai-editor-folder')); });

        $('ai-editor-upload').addEventListener('change', function () {
            var file = this.files && this.files[0];
            this.value = '';
            if (file) { openEditor({ file: file }); }
        });
        $('ai-editor-blank').addEventListener('click', function () { openEditor({}); });

        $('ai-editor-screen').addEventListener('click', function () {
            if (isFullscreen()) { exitFullscreen(); } else { enterFullscreen(); }
        });
        $('ai-editor-replace').addEventListener('click', function () {
            if (!state.painter || !state.editing || !window.confirm(L.editor_replace_confirm)) { return; }
            state.editing.replaceNext = true;
            state.painter.save();
        });

        var doc = screenTarget().ownerDocument;
        ['fullscreenchange', 'webkitfullscreenchange'].forEach(function (name) {
            doc.addEventListener(name, function () { if (state.editing) { renderScreenButton(); } });
        });
    }

    // ------------------------------------------------------------------
    // Wiring
    // ------------------------------------------------------------------

    function boot() {
        renderKeyState(CFG.key);
        if (!CFG.key || !CFG.key.configured) { return; }
        loadAccount();
        // Back from paying on the gateway's tab: the balance is stale until asked again.
        window.addEventListener('focus', loadAccount);
        loadCatalog().then(loadFolders).then(loadJobs);
    }

    document.addEventListener('DOMContentLoaded', function () {
        $('ai-image-model').addEventListener('change', function () {
            renderControls();
            refreshEstimates();
            saveJobModels();
        });
        $('ai-text-model').addEventListener('change', function () {
            refreshEstimates();
            saveJobModels();
        });
        $('ai-instruction').addEventListener('input', refreshEstimates);

        $('ai-send').addEventListener('click', submitInstruction);
        sendOnEnter($('ai-instruction'), submitInstruction);
        sendOnEnter($('ai-reply-input'), sendReply);

        // Voice is optional. When it is off the template renders none of
        // these, so every binding here is conditional rather than assuming the
        // element exists — `$()` would return null and the listener would
        // throw on page load, taking the rest of the wiring with it.
        var speakButton = $('ai-speak');

        if (speakButton) {
            speakButton.addEventListener('click', function () {
                state.speak = !state.speak;
                this.classList.toggle('is-on', state.speak);
                this.setAttribute('aria-pressed', state.speak ? 'true' : 'false');

                var icon = this.querySelector('i');
                if (icon) { icon.className = 'fa ' + (state.speak ? 'fa-volume-up' : 'fa-volume-off'); }
            });
        }

        bindMic($('ai-mic'), $('ai-instruction'));
        bindMic($('ai-reply-mic'), $('ai-reply-input'));

        $('ai-new-job').addEventListener('click', function () {
            state.jobUuid = null;
            state.job = null;
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

        $('ai-reply-send').addEventListener('click', sendReply);

        $('ai-browse').addEventListener('click', function () { openFiles($('ai-folder')); });
        $('ai-files-close').addEventListener('click', closeFiles);

        $('ai-files-up').addEventListener('click', function () {
            if (state.files && state.files.parent !== null) { browse(state.files.parent); }
        });

        $('ai-files-use').addEventListener('click', function () {
            // Guarded as well as hidden. This is the only path from browsing to
            // changing where results go, and "looking at a file cannot move a
            // task's output" is worth asserting rather than leaving to CSS.
            if (!state.files || !state.filesPicking) { return; }
            (state.filesTarget || $('ai-folder')).value = state.files.folder;
            closeFiles();
        });

        // Clicking the backdrop, or Escape, closes it. Nothing here is being
        // edited, so there is nothing to lose by dismissing it carelessly.
        $('ai-files').addEventListener('click', function (event) {
            if (event.target === $('ai-files')) { closeFiles(); }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !$('ai-files').hidden) { closeFiles(); }
        });

        $('ai-key-save').addEventListener('click', function () { saveKey('user', $('ai-key-input').value.trim()); });
        $('ai-key-clear').addEventListener('click', function () { $('ai-key-input').value = ''; saveKey('user', ''); });

        var siteSave = $('ai-key-site-save');
        if (siteSave) {
            siteSave.addEventListener('click', function () { saveKey('site', $('ai-key-site-input').value.trim()); });
        }

        bindEditor();
        boot();
    });
})();
</script>
@endverbatim
