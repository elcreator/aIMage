{{-- Inlined so the module renders correctly before any publish step. --}}
<style>
    :root {
        --ai-bg: #f6f7f9;
        --ai-panel: #fff;
        --ai-border: #dfe3e8;
        --ai-text: #23282d;
        --ai-muted: #6b7280;
        --ai-accent: #2f6fd0;
        --ai-accent-soft: #eaf1fb;
        --ai-warn: #b45309;
        --ai-warn-soft: #fef6e7;
        --ai-danger: #c0392b;
        --ai-ok: #1e7d47;
    }

    /* The manager frame has no theme signal to read, so this commits to one
       light palette rather than guessing at a dark one. */
    * { box-sizing: border-box; }

    /* The `hidden` attribute is how everything on this page is shown and
       hidden, and the browser implements it as `[hidden] { display: none }` in
       the UA stylesheet — which any class selector that sets `display`
       outranks. A rule as ordinary as `.ai-modal { display: flex }` therefore
       pins the dialog open, with the attribute set correctly and no error
       anywhere to say so. This puts the attribute back in charge. */
    [hidden] { display: none !important; }

    body {
        margin: 0;
        background: var(--ai-bg);
        color: var(--ai-text);
        font: 14px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    }

    .ai-shell { padding: 1.25rem 1.5rem 3rem; max-width: 1400px; margin: 0 auto; }

    .ai-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: 1rem; }
    .ai-head h1 { margin: 0; font-size: 1.35rem; letter-spacing: -0.01em; }
    .ai-sub { margin: 0.15rem 0 0; color: var(--ai-muted); }
    .ai-head-state { color: var(--ai-muted); font-size: 0.85rem; text-align: right; }

    .ai-alert {
        padding: 0.7rem 0.9rem; border-radius: 6px; margin-bottom: 1rem;
        display: flex; flex-direction: column; gap: 0.15rem;
    }
    .ai-alert-warn { background: var(--ai-warn-soft); border-left: 3px solid var(--ai-warn); color: var(--ai-warn); }

    .ai-panel {
        background: var(--ai-panel); border: 1px solid var(--ai-border);
        border-radius: 8px; padding: 1rem; margin-bottom: 1rem;
    }

    .ai-body { display: grid; grid-template-columns: minmax(200px, 260px) 1fr; gap: 1rem; align-items: start; }
    @media (max-width: 900px) { .ai-body { grid-template-columns: 1fr; } }

    .ai-side { background: var(--ai-panel); border: 1px solid var(--ai-border); border-radius: 8px; padding: 0.75rem; }
    .ai-side-head { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; }
    .ai-side-head h2 { margin: 0; font-size: 0.95rem; }
    .ai-job-list { list-style: none; margin: 0.6rem 0 0; padding: 0; display: flex; flex-direction: column; gap: 0.3rem; }
    .ai-job-list li { padding: 0.45rem 0.55rem; border-radius: 5px; cursor: pointer; border: 1px solid transparent; }
    .ai-job-list li:hover { background: var(--ai-bg); }
    .ai-job-list li.is-active { background: var(--ai-accent-soft); border-color: #c4d8f5; }
    .ai-job-list .ai-job-line { display: block; font-size: 0.85rem; }
    .ai-job-list .ai-job-meta { color: var(--ai-muted); font-size: 0.75rem; }
    .ai-empty { color: var(--ai-muted); font-size: 0.85rem; padding: 0.4rem 0.55rem; }

    .ai-pickers { display: flex; flex-wrap: wrap; gap: 0.9rem; }
    .ai-field { display: flex; flex-direction: column; gap: 0.25rem; flex: 1 1 260px; min-width: 0; }
    .ai-field-narrow { flex: 0 1 220px; }
    .ai-field > span { font-size: 0.78rem; color: var(--ai-muted); text-transform: uppercase; letter-spacing: 0.04em; }
    select, input[type="text"], input[type="password"], textarea {
        font: inherit; padding: 0.45rem 0.55rem; border: 1px solid var(--ai-border);
        border-radius: 5px; background: #fff; color: inherit; width: 100%;
    }
    textarea { resize: vertical; }

    /* The two numbers the picker exists to show. Provenance is rendered as a
       title attribute rather than dropped, so a seeded estimate never reads as
       a measurement. */
    .ai-estimate { font-style: normal; font-size: 0.8rem; color: var(--ai-muted); min-height: 1.2em; }
    .ai-estimate b { color: var(--ai-text); font-weight: 600; }
    .ai-estimate .ai-approx { color: var(--ai-warn); }

    .ai-controls { display: flex; flex-wrap: wrap; gap: 0.9rem; margin-top: 0.9rem; }
    .ai-controls:empty { margin-top: 0; }

    .ai-compose-row { display: flex; gap: 0.75rem; margin-top: 0.9rem; align-items: flex-start; }
    .ai-compose-row textarea { flex: 1; }
    /* Send on top; dictate and read-aloud share the line beneath it. The
       column is as wide as Send, and the two icons stretch to fill that, so the
       pair lines up with the button above however long the label translates. */
    .ai-compose-actions { display: flex; flex-direction: column; gap: 0.4rem; }
    .ai-compose-icons { display: flex; gap: 0.4rem; }
    .ai-compose-icons .ai-btn-icon { flex: 1 1 auto; width: auto; }

    .ai-btn {
        font: inherit; padding: 0.45rem 0.8rem; border-radius: 5px; cursor: pointer;
        border: 1px solid var(--ai-border); background: #fff; color: inherit;
    }
    .ai-btn:hover:not(:disabled) { background: var(--ai-bg); }
    .ai-btn:disabled { opacity: 0.5; cursor: not-allowed; }
    .ai-btn-primary { background: var(--ai-accent); border-color: var(--ai-accent); color: #fff; }
    .ai-btn-primary:hover:not(:disabled) { background: #2760b8; }
    .ai-btn-danger { color: var(--ai-danger); border-color: #e9c4c0; }
    .ai-btn-small { padding: 0.25rem 0.55rem; font-size: 0.8rem; }
    .ai-btn-icon { width: 2.4rem; text-align: center; flex: 0 0 auto; }
    /* touch-action so holding the button to talk does not scroll the page. */
    .ai-btn-mic { touch-action: none; user-select: none; }
    .ai-btn-toggle.is-on { background: var(--ai-accent); border-color: var(--ai-accent); color: #fff; }
    .ai-btn-toggle.is-on:hover:not(:disabled) { background: var(--ai-accent); opacity: 0.85; }
    .ai-btn-mic.is-recording { background: var(--ai-danger); border-color: var(--ai-danger); color: #fff; }
    /* The generic hover is a class-plus-two-pseudos and outranks the rule above,
       so without this the button turns near-white the moment the pointer rests
       on it — while it is recording, which is when it most needs to look live.
       Hovering a button that is already doing something should acknowledge the
       pointer, not repaint the state. */
    .ai-btn-mic.is-recording:hover:not(:disabled) { background: var(--ai-danger); opacity: 0.85; }

    .ai-row { display: flex; gap: 0.5rem; align-items: center; }
    .ai-row input { flex: 1; }
    .ai-key-form { margin-top: 0.75rem; }
    .ai-key-form > label { display: block; font-size: 0.78rem; color: var(--ai-muted); margin-bottom: 0.25rem; }
    .ai-key-site { padding-top: 0.75rem; border-top: 1px dashed var(--ai-border); }
    .ai-hint, .ai-msg { color: var(--ai-muted); font-size: 0.82rem; margin: 0.4rem 0 0; min-height: 1.1em; }
    .ai-msg.is-error { color: var(--ai-danger); }
    .ai-msg.is-ok { color: var(--ai-ok); }

    .ai-job-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; }
    .ai-job-head h2 { margin: 0.25rem 0 0; font-size: 1.05rem; font-weight: 600; }
    .ai-job-actions { display: flex; gap: 0.5rem; flex-shrink: 0; }

    .ai-badge {
        display: inline-block; padding: 0.12rem 0.5rem; border-radius: 999px;
        font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em;
        background: var(--ai-bg); color: var(--ai-muted); border: 1px solid var(--ai-border);
    }
    .ai-badge.is-running, .ai-badge.is-planning { background: var(--ai-accent-soft); color: var(--ai-accent); border-color: #c4d8f5; }
    .ai-badge.is-succeeded { background: #e8f6ee; color: var(--ai-ok); border-color: #bfe3cd; }
    .ai-badge.is-failed { background: #fdf3f2; color: var(--ai-danger); border-color: #efc9c4; }
    .ai-badge.is-awaiting_input, .ai-badge.is-awaiting_approval { background: var(--ai-warn-soft); color: var(--ai-warn); border-color: #f0d9ae; }

    .ai-progress { display: flex; align-items: center; gap: 0.7rem; margin-top: 0.8rem; font-size: 0.82rem; color: var(--ai-muted); }
    .ai-progress-bar { flex: 1; height: 6px; background: var(--ai-bg); border-radius: 999px; overflow: hidden; }
    .ai-progress-bar i { display: block; height: 100%; background: var(--ai-accent); width: 0; transition: width 0.4s ease; }

    .ai-thread { margin-top: 1rem; display: flex; flex-direction: column; gap: 0.6rem; }
    .ai-turn { padding: 0.55rem 0.75rem; border-radius: 8px; max-width: 46rem; }
    .ai-turn-user { background: var(--ai-accent-soft); align-self: flex-end; }
    .ai-turn-assistant { background: var(--ai-bg); }
    .ai-turn .ai-turn-role { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--ai-muted); }
    .ai-turn p { margin: 0.2rem 0 0; white-space: pre-wrap; }

    .ai-reply { display: flex; gap: 0.5rem; margin-top: 0.9rem; align-items: flex-start; }
    .ai-reply textarea { flex: 1; min-height: 2.6rem; }

    .ai-steps { margin-top: 1.1rem; display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 0.7rem; }
    .ai-step { border: 1px solid var(--ai-border); border-radius: 7px; overflow: hidden; background: #fff; }
    .ai-step-head { display: flex; justify-content: space-between; align-items: center; gap: 0.4rem; padding: 0.4rem 0.55rem; font-size: 0.75rem; }
    .ai-step-pair { display: grid; grid-template-columns: 1fr 1fr; gap: 1px; background: var(--ai-border); }
    .ai-step-pair.is-single { grid-template-columns: 1fr; }
    .ai-step figure { margin: 0; background: #fff; }
    .ai-step figure img { display: block; width: 100%; height: 118px; object-fit: cover; background: var(--ai-bg); }
    .ai-step figcaption { font-size: 0.68rem; color: var(--ai-muted); padding: 0.2rem 0.4rem; }
    .ai-step-msg { padding: 0.35rem 0.55rem; font-size: 0.75rem; color: var(--ai-danger); }
    .ai-step-placeholder {
        height: 118px; display: flex; align-items: center; justify-content: center;
        color: var(--ai-muted); font-size: 0.75rem; background: var(--ai-bg);
    }

    /* A result opens the browser where it landed. */
    .ai-step figure.is-locatable { cursor: zoom-in; }
    .ai-step figure.is-locatable:hover img { outline: 2px solid var(--ai-accent); outline-offset: -2px; }
    .ai-step figure.is-locatable:hover figcaption { color: var(--ai-accent); }

    /* The file browser. */
    .ai-folder-row { display: flex; gap: 0.35rem; }
    .ai-folder-row input { flex: 1 1 auto; min-width: 0; }

    .ai-modal {
        position: fixed; inset: 0; z-index: 40; display: flex;
        align-items: center; justify-content: center; padding: 1.5rem;
        background: rgba(35, 40, 45, 0.45);
    }
    .ai-modal-box {
        background: var(--ai-panel); border: 1px solid var(--ai-border); border-radius: 8px;
        width: min(1000px, 100%); max-height: 100%; display: flex; flex-direction: column;
        box-shadow: 0 12px 40px rgba(0, 0, 0, 0.25);
    }
    .ai-modal-head {
        display: flex; justify-content: space-between; align-items: center; gap: 1rem;
        padding: 0.75rem 1rem; border-bottom: 1px solid var(--ai-border);
    }
    .ai-modal-head h2 { margin: 0; font-size: 0.95rem; }

    .ai-files-bar {
        display: flex; align-items: center; gap: 0.6rem;
        padding: 0.6rem 1rem; border-bottom: 1px solid var(--ai-border);
    }
    .ai-crumbs {
        flex: 1 1 auto; min-width: 0; display: flex; flex-wrap: wrap; align-items: center;
        gap: 0.15rem; font-size: 0.82rem; color: var(--ai-muted);
    }
    .ai-crumb {
        background: none; border: 0; padding: 0.1rem 0.2rem; font: inherit;
        color: var(--ai-accent); cursor: pointer; border-radius: 3px;
    }
    .ai-crumb:hover { background: var(--ai-accent-soft); }
    /* Above the ceiling: shown so the path reads correctly, but not a link. */
    .ai-crumb.is-fixed { color: var(--ai-muted); cursor: default; }
    .ai-crumb.is-current { color: var(--ai-text); font-weight: 600; cursor: default; }

    .ai-files-body { display: flex; min-height: 0; flex: 1 1 auto; }
    .ai-files-list {
        flex: 1 1 auto; overflow: auto; padding: 0.75rem;
        display: grid; gap: 0.5rem; align-content: start;
        grid-template-columns: repeat(auto-fill, minmax(104px, 1fr));
    }
    .ai-entry {
        display: flex; flex-direction: column; align-items: center; gap: 0.3rem;
        padding: 0.5rem 0.35rem; background: none; font: inherit; color: inherit;
        border: 1px solid transparent; border-radius: 6px; cursor: pointer; overflow: hidden;
    }
    .ai-entry:hover { background: var(--ai-bg); border-color: var(--ai-border); }
    .ai-entry.is-selected { background: var(--ai-accent-soft); border-color: var(--ai-accent); }
    .ai-entry-icon { font-size: 1.9rem; line-height: 1; color: var(--ai-muted); }
    .ai-entry-folder .ai-entry-icon { color: var(--ai-accent); }
    .ai-turn-spoken { font-size: 0.72rem; opacity: 0.75; }
    .ai-entry img { width: 100%; height: 68px; object-fit: cover; border-radius: 4px; background: var(--ai-bg); }
    .ai-entry-name {
        font-size: 0.74rem; width: 100%; text-align: center;
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }

    .ai-files-preview {
        width: 270px; flex: 0 0 270px; overflow: auto;
        padding: 0.75rem; border-left: 1px solid var(--ai-border);
    }
    .ai-files-preview h3 { margin: 0.5rem 0 0.6rem; font-size: 0.85rem; word-break: break-all; }
    .ai-preview-image {
        width: 100%; max-height: 200px; object-fit: contain;
        background: var(--ai-bg); border-radius: 4px;
    }
    .ai-meta { display: flex; justify-content: space-between; gap: 0.5rem; margin: 0.3rem 0; font-size: 0.76rem; }
    .ai-meta span { color: var(--ai-muted); }
    .ai-meta-url { flex-direction: column; gap: 0.25rem; }
    .ai-meta-url input { width: 100%; font-size: 0.72rem; }

    @media (max-width: 760px) {
        .ai-files-body { flex-direction: column; }
        .ai-files-preview { width: auto; flex: 0 0 auto; border-left: 0; border-top: 1px solid var(--ai-border); }
    }
</style>
