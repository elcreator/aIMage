# AIMage

An AI image workbench for the **Evolution CMS 3.5+** manager.

Describe a batch of image work; it runs in the background. A text model asks about
anything genuinely ambiguous, plans the work, and a background worker carries it out against
the [ai.artur.work](https://ai.artur.work/profile) gateway, a slice at a time, for as long as it takes.

The result of a job is always **changed image files**. Text, voice and previews are
intermediate; a job that ends in a nice paragraph has failed at its purpose.

---

## What it does

- **"Upscale everything in `products/`"** — lists what you may actually see, queues one step
  per image, runs them.
- **"Generate 10 hero images of a mountain lake at dawn"** — queues the generation, writes the
  results into a folder you may write to.
- **"Make the backgrounds transparent"** — queues an edit per image; originals are never
  overwritten.
- Anything ambiguous enough to change the output — which files, what style, how many — gets a
  question instead of a guess.

Before you commit, the model picker shows **what it will cost and how long it will take**, with
the provenance of both: a fixed tariff reads differently from a median of past runs, and a
seeded latency prior is labelled an estimate rather than dressed up as a measurement.

## Requirements

- Evolution CMS **3.5.2+** with the system task registry
  (`EvolutionCMS\Services\SystemTasks\SystemTaskRegistry`)
- PHP 8.3+
- A running scheduler — `php core/artisan schedule:work`, or cron calling `schedule:run` every
  minute. Without it batches queue and never start; the page says so rather than pretending.
- An ai.artur.work API key, per manager or site-wide

## Install

```bash
cd core
php artisan package:installrequire elcreator/aimage "*"
php artisan migrate
```

Then open **AIMage** in the manager's Modules menu.

## Keys

Three tiers, in order:

1. **The manager's own key** — set on the page, stored encrypted in `user_settings`.
2. **The site-wide key** — `AIMAGE_API_KEY` in the environment, or a settings row an
   administrator sets. Configuration wins over the row.
3. **Nothing** — the page asks for a key instead of failing.

Keys are encrypted at rest with AES-256-GCM (`Support\Crypt`), because Evolution CMS registers
no Laravel encrypter and a database dump is a routine artefact. The secret comes from
`AIMAGE_SECRET`, or from a generated `core/custom/aimage.secret` — never from the database
beside the ciphertext. A stored key is never sent back to the browser in full.

## Permissions

Two separate questions, and neither substitutes for the other:

- **`aimage`** — may this manager use the workbench at all.
- **File groups** — which images they may touch, decided by the CMS's own `file_groups` table
  exactly as in the file manager, inherited down the folder tree.

A manager confined to a folder by `filemanager_path` stays confined here. The worker has no
session, so `Support\ImageScope` rebuilds the same facts from the database for a given user id
and then defers to the core's own `FileManagerAccess` for every verdict — it is not a second
permission model.

## Where results are written

Every result lands under the **write root**, which is the narrower of two things:

- the manager's own file-manager root (`filemanager_path`, else the site root), and
- the image browser's root (`rb_base_dir`, `assets/` by default) — what the *Insert image*
  dialog can actually see.

So a manager confined to `assets/clients/acme` gets their results there, not in the site-wide
folder they cannot open; and an unconfined manager, whose file root is the whole site, cannot
have images written to `core/` or `manager/` where nothing could use them.

Inside that ceiling, the **base** is `assets/images` — Evolution's own images directory, the one
the installer refuses to finish without. That is where results go by default and what the folder
picker lists, so nobody is offered `core`, `views` or `assets/plugins` as a place to put a
picture. Naming a real folder elsewhere under the ceiling still works: ask for `assets/products`
and you get `assets/products`. The CMS's own directories — `assets/plugins`, `assets/cache`,
`assets/modules`, `assets/backup`, `import`, `export`, `templates`, `snippets`, the manager
directory — are refused outright, whatever the manager's permissions say.

The results folder is a path you can type and a tree you can browse. **Browse…** opens a file
browser over the same scoped listing everything else uses: folders, image thumbnails, click an
image for its URL, resolution, size and date. It climbs to the ceiling and stops — so
`assets/products`, a sibling of the base, is one step up rather than unreachable — and it never
shows the CMS's own directories.

Clicking a result in a task opens the same browser where that file landed, with it selected and
its metadata showing. That view has no *Put results here* button: looking for a file must not
quietly re-point a task's output at whatever folder you stopped in. Everything else — navigating,
the preview, Close — behaves identically.

It is deliberately *not* the manager's KCFinder browser. KCFinder is rooted at `rb_base_dir`
alone and ignores `filemanager_path`, so it shows a manager confined to `assets/clients/456` the
whole `assets/` tree — everyone's files. Reusing it here would undo the confinement this package
exists to respect.

A destination folder need not exist. "Put them in `123/45`" means a folder `45` inside a folder
`123`, both created under the base when the first result is written — the same for edits,
variations and upscales. A name that is not a path under the ceiling is placed under the base
rather than refused; one that tries to climb out with `..` is refused outright, not quietly
rewritten.

## Configuration

`config/aIMage.php`, overridable from `core/custom/config/cms/settings.php`. The values worth
knowing:

| Key | Default | Meaning |
|---|---|---|
| `limits.slice_seconds` | `45` | How much of each worker minute a batch may own |
| `limits.parallelism` | `3` | Batches in flight at once, across all managers |
| `limits.max_images_per_job` | `200` | Hard ceiling, whatever the plan says |
| `limits.approval_threshold_eur` | `5.0` | Above this, a plan waits for a signature |
| `features.voice` | `false` | The microphone and read-aloud buttons. Off unless a site opts in |
| `files.output_folder` | `aimage` | Where results land, under `assets/images` (see above) |
| `files.allow_overwrite` | `false` | Off means results are written beside originals |

Secrets belong in the environment, never in this file.

**Voice is opt-in.** Dictation and reading answers back both send audio to the gateway and both
cost money per use, so neither is inherited by installing the package. `AIMAGE_VOICE=1` in the
environment turns them on, as does `features.voice` in the site's settings. Off, the buttons are
not rendered and `/voice/transcribe` and `/voice/speak` refuse — a flag that only hides a button
while its endpoint still answers is a decoration, not a switch.

## Design notes

See [`AGENTS.md`](AGENTS.md) for the full picture. The three things most likely to be
misunderstood:

1. **The dialect is chosen by model family, never by preference.** The gateway serves every
   model on both `/chat/completions` and `/messages`, but its translation drops tool calls in
   both directions. The planner is a tool-calling loop, so `claude-*` goes to `/messages` and
   everything else to `/chat/completions`.
2. **A model with `variants` must be priced per variant.** Quoting `price.amount` for
   `gpt-image-1` quotes the dearest tier — a 22.7× error against the cheapest.
3. **The model plans; the worker acts.** No tool the planner can call touches an image model.
   That is what makes a plan priceable before anything is spent, and what lets execution
   survive the conversation ending.

## Known limits

- **Upscaling needs a publicly reachable URL.** `/images/upscale` takes `imageUrl`, not an
  upload, so a site on localhost or behind HTTP auth cannot upscale. The step fails with
  `NOT_PUBLICLY_REACHABLE` rather than timing out.
- **Translations are machine-produced.** Every language Evolution CMS core carries is
  present — but none has been reviewed by a native speaker.

## Licence

GPL-3.0-or-later.
