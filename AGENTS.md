# AGENTS.md — AIMage

Context file for AI coding agents. Feature-complete and tested — see *Build status* at the bottom.

---

## What this is

An AI image workbench for the **Evolution CMS 3.5+** manager. A manager describes a batch of
image work in text or by voice; a text model clarifies what is ambiguous and plans the work;
a cross-server worker carries it out against the **ai.artur.work** gateway while nobody is
watching. The terminal result is always changed images — text, voice and previews are
intermediate only.

- Package `elcreator/aimage` · type `evolutioncms-plugin`
- Namespace `Elcreator\aIMage\` → `src/`
- PHP 8.3+ · MIT

---

## The gateway

Base `https://ai.artur.work/api/v1`, described by `https://ai.artur.work/openapi.json`.
Auth is the user's own key as **`X-Api-Key`** (also accepted as a bearer token, but that
header is shared with mesh worker tokens which resolve first, so we send `X-Api-Key`).

| Route | Shape | Notes |
|---|---|---|
| `GET /models` | — | Public. Price + latency per model. Cached 1h upstream, ETag'd. |
| `POST /chat/completions` | JSON | OpenAI dialect. **Claude tool_use is lost here.** |
| `POST /messages` | JSON | Anthropic dialect. **Non-Claude tool_calls are lost here.** |
| `POST /images/generations` | **JSON** | Sync → `{data:[{url}]}`; async → `{taskId}`. |
| `POST /images/edits` | multipart | Every upload forwarded as an `image` part. |
| `POST /images/variations` | multipart | OpenAI drops the form fields (no prompt upstream). |
| `POST /images/upscale` | multipart | Model **fixed** to `Qubico/image-toolkit`. Takes `imageUrl`, so the source must be publicly reachable. Always async. |
| `GET /images/status/{model}/{taskId}` | — | Use the literal `upscale` as `{model}` for upscale tasks. **Unfinished = 200 with an empty body**, not an error. |
| `POST /audio/transcriptions` | multipart | webm/ogg transcoded upstream. |
| `POST /audio/speech` | JSON | Answers **raw audio**, not JSON. |

### Three traps already encoded in the source

1. **The dialect is chosen by model family, never by preference.** `Gateway\Dialect` routes
   `claude-*` to `/messages` and everything else to `/chat/completions`, because the
   gateway's translation drops tool calls in both directions and the planner is a
   tool-calling loop.
2. **A model with `variants` must be priced per variant.** Quoting `price.amount` for
   `gpt-image-1` quotes the dearest tier — between `low`/1024 and `high`/1536 that is a
   **22.7× error** (0.227010 vs 0.009988 EUR, measured against the live catalogue).
   `Gateway\Estimator::resolveVariant()` matches the request's own controls and
   refuses to assume a default for a control the request does not set.
3. **`price.amount` is null for token-metered models by design** (the length of an answer is
   the caller's choice) and `price.unit` says which kind an entry is. Null is *unknown*, not
   *free*, and `Estimate` keeps it null all the way to the UI.

Never pass `?wait=1`. A job may hold hundreds of images; holding an HTTP request open per
image is exactly what the worker exists to avoid.

---

## Evolution CMS facts this package depends on

- **Manager file access** is `EvolutionCMS\Support\FileManagerAccess` plus the `file_groups`
  table (`file` = path relative to the file-manager root, `document_group` = the group that
  may see it). Restrictions are **inherited down the tree** — every ancestor path is checked.
- The core helpers in `core/functions/actions/files.php` (`fileManagerUserGroupIds()` and
  friends) all read **`$_SESSION`**, so they are unusable in the worker. `Support\ImageScope`
  resolves the same facts from the database for a given user id:
  - role from `user_attributes.role`; **role 1 is unrestricted**
  - groups from `member_groups` ⨝ `membergroup_access` (the same query `UserLogin` uses to
    fill `$_SESSION['mgrDocgroups']`)
  - per-user `filemanager_path` / `upload_images` from `user_settings`, falling back to the
    system setting
  - then `FileManagerAccess::isAccessible()`, which is pure and takes the groups explicitly.
- **A manager module with its own routes** is `evo()->registerRoutingModule($name, $routesFile)`:
  it adds the menu entry *and* registers the file as a route group under
  `modules/{md5(name)}` with the `mgr` middleware. Menu link building is in
  `Controllers\Frame::menuModules()`.
- The `mgr` middleware group includes `VerifyCsrfToken`, which **fails closed** once a manager
  session exists. Every non-GET route here must carry `_token` or `X-CSRF-TOKEN`.
- **Do not use `$modx`** — use `evo()` / `EvolutionCMS()`.

### The core change this package depends on

`system_cli_tasks` used to be closed to packages: `TaskWorkerCommand` dispatched on a
hardcoded `switch` over three types, and `SystemTaskService` treated **any** queued or
running task as a global mutual-exclusion lock. Both are fixed on the Evolution CMS branch
`feat-system-task-registry`:

- `EvolutionCMS\Services\SystemTasks\SystemTaskRegistry` maps `type => handler`, and
  `EvolutionCMS\Interfaces\SystemTaskHandlerInterface` is the contract the worker calls.
- A registration declares a **mode**: `exclusive` (the old behaviour — alone on the queue)
  or `concurrent` with a `parallelism` cap. The global lock now applies only between
  exclusive types, so a day-long image batch no longer holds the site updater hostage.
- A registration also declares its own `permissions` and `super_admin` gate, so AIMage
  gates its work with the `aimage` permission rather than borrowing a CMS one.

AIMage therefore registers `aimage.batch` as a concurrent type and inherits the queue, the
lease, the progress log, the cancellation flag and the worker-health page — no second
scheduler, no private tables for the job queue itself.

```php
SystemTaskRegistry::register('aimage.batch', ImageBatchHandler::class, [
    'mode' => SystemTaskRegistry::MODE_CONCURRENT,
    'parallelism' => 3,
    'permissions' => ['aimage'],
    'creator' => [ImageBatchHandler::class, 'queue'],
]);
```

---

## Layout

```
config/aIMage.php              all configuration; secrets via env or the key store only
database/migrations/           jobs, steps, messages, the `aimage` permission
lang/<locale>/global.php      every user-visible string; 21 locales
src/
  aIMageServiceProvider.php    registers the routing module, views, migrations, commands
  Gateway/
    Client.php                 the gateway, one instance per API key
    Dialect.php                canonical transcript ⇄ the two chat dialects
    ModelCatalog.php           GET /models, indexed and file-cached (stale-on-failure)
    Estimator.php              catalogue entry → cost and ETA
    Estimate.php               the value object; sum() folds a plan
    GatewayException.php       carries `retryable`, which is what the worker branches on
  Support/
    Config.php                 typed access to cms.settings.aIMage
    Crypt.php                  AES-256-GCM for stored keys (Evo has no app.key)
    ApiKeys.php                manager key → site key → none
    ImageScope.php             session-free file access for one user
  Agent/                       planner (tool-calling loop) and step executor
  Models/                      Job, JobStep, Message
  Console/BatchHandler.php     one worker slice of one job
  Http/                        routes.php + controllers
views/                         the module page (assets inlined; no publish step)
tests/                         database-backed Pest suite; see *Running the tests*
```

---

## How a batch actually runs

Worth reading once, because the slicing is the part that is easy to "simplify" into
something that breaks.

1. The manager sends an instruction. `JobController::store()` writes a `Job` in `planning`,
   appends the first `Message`, and calls `JobQueue::enqueue()`. **No model is called.** The
   browser can close here and nothing is lost.
2. `JobQueue::enqueue()` writes one `system_cli_tasks` row of type `aimage.batch`.
3. The CMS worker picks it up and calls `Console\BatchHandler::execute()`, which runs for at
   most `limits.slice_seconds` (45) and then **returns**, queueing its own successor if there
   is more to do. It never runs a job to completion.
4. A planning slice runs exactly one `Planner` turn, then stops — planning decides everything
   downstream, so it does not roll straight into execution on stale state.
5. `Tools` only ever *appends rows*. The model plans; the worker acts. That is what makes the
   approval gate real: a plan is fully priced before a cent is spent.
6. An execution slice advances one step at a time. An async provider returns a `taskId`, the
   step parks in `polling`, and a **later slice** checks it once. Nothing ever sleeps.
7. `awaiting_input` / `awaiting_approval` stop the loop and queue no successor. Answering or
   approving is what starts it again.

Invariants to keep:

- **Never `?wait=1`.** Holding an HTTP request per image is the thing slicing exists to avoid.
- **A step must be safe to run twice.** A worker can die between calling a provider and
  recording the result. Results get a freshly-unique filename and the step is marked succeeded
  only after bytes are on disk — so a duplicate costs an extra image, never a corrupt one.
- **`amount: null` is "unknown", never "free".** It survives all the way to the UI, and an
  unpriceable plan always requires approval regardless of the threshold.
- **Originals are never overwritten** unless `files.allow_overwrite` is explicitly on.

## Running the tests

**137 tests, 318 assertions, all passing.** They are database-backed: a real SQLite schema
built by running this package's own migration, with the CMS tables it reads declared
alongside.

The package has no vendor directory of its own, so the suite borrows an installed Evolution
CMS core's autoloader and Pest binary:

```bash
cd /path/to/aIMage
php /path/to/evolution/core/vendor/pestphp/pest/bin/pest --no-coverage
```

Point `EVO_CORE_PATH_TEST` at a different core if yours is not the one hardcoded in
`tests/bootstrap.php`. That core needs its dev dependencies installed:
`composer install --no-scripts --no-plugins` inside `core/` (the committed autoloader is
`--no-dev --classmap-authoritative`, so Pest will not load until you do).

Layout, and why it is shaped this way:

| File | Notes |
|---|---|
| `tests/bootstrap.php` | Defines `EVO_CLASS` as a stub Core so `evo()` and `config()` resolve without booting the CMS; creates a temp file tree as `EVO_BASE_PATH` and removes it at shutdown |
| `tests/helpers.php` | Schema, fixtures, gateway doubles. Loaded from the bootstrap, **not** as `Pest.php` — Pest's root is the borrowed core, so it would never discover a `Pest.php` here |
| `tests/fixtures/models.json` | A real `GET /models` capture. Variant pricing only has teeth against shapes the gateway actually emits |

Each test file calls `beforeEach(fn () => aimageReset())` for the same reason.

Gateway doubles: `aimageClientWithout([...])` gives a client whose queue is exactly what you
pass; `aimageClient([...])` prepends a catalogue response for callers that need one.
`BatchHandler::buildClient()` / `buildDownloader()` exist as seams so a slice can be run with
no network at all.

## Build status

**Feature-complete and tested.** 37 source files plus a 6-file suite.

Covered: `ImageScope` (44 tests — the permission boundary, path safety, listing, writing),
`ApiKeys` (15 — tiers, encryption at rest, masking), `Tools` (31 — planning, budget caps,
control validation, hostile paths), `Executor` (19 — sync/async, retries, what gets written),
`BatchHandler` + `JobQueue` (17 — slicing, resumption, cancellation), the models (11).
Separately, a standalone harness covers `Dialect` and `Estimator` against the live catalogue.

Three real bugs the suite caught, all now fixed and pinned by a test:

1. **`Message::append()` could not exist.** Eloquent's `Model` has a non-static `append()`, and
   PHP refuses to redeclare an inherited instance method as static — the class failed to load
   at all, so the planner would have died on its first turn. Renamed to `record()`.
2. **A parked step was re-polled in a hot loop.** After an async submit the slice loop
   immediately polled the same step again, and kept going for the rest of the 45-second
   budget — hundreds of gateway calls asking whether an image just requested was ready.
   `BatchHandler` now tracks attempted step ids per slice.
3. **Non-images were written to a public folder.** `Executor::extensionFor()` fell back to the
   URL's extension when the magic-byte check failed, so an HTML error page from a CDN URL
   ending in `.png` was written as a PNG. The fallback is gone.

Plus a quieter one: `JobStep::expectedImages()` returned 1 for variations, undercounting both
the image budget and the cost estimate by the variation factor.

Remaining honest gaps:

- **Nothing has run against the real gateway.** No image has been generated by this code; the
  client is written to the OpenAPI spec and exercised only against mocks.
- **The HTTP controllers are untested.** They need a booted manager session, which is
  integration territory rather than unit.
- **Upscale needs a publicly reachable URL.** `/images/upscale` takes `imageUrl`, not an
  upload, so a site on localhost or behind auth cannot upscale. `Executor::submitUpscale()`
  fails the step with `NOT_PUBLICLY_REACHABLE` rather than letting it time out — a real
  functional limit, not a bug to fix here.
- **Translations are machine-produced.** `lang/` carries every locale Evolution CMS core
  ships, each with the same 87 keys and the same placeholders,
  verified mechanically. They have not been reviewed by native speakers.
