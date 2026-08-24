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
php artisan package:installrequire evolution-cms/a-image "*"
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

## Configuration

`config/aIMage.php`, overridable from `core/custom/config/cms/settings.php`. The values worth
knowing:

| Key | Default | Meaning |
|---|---|---|
| `limits.slice_seconds` | `45` | How much of each worker minute a batch may own |
| `limits.parallelism` | `3` | Batches in flight at once, across all managers |
| `limits.max_images_per_job` | `200` | Hard ceiling, whatever the plan says |
| `limits.approval_threshold_eur` | `5.0` | Above this, a plan waits for a signature |
| `files.output_folder` | `aimage` | Where results land, relative to the file root |
| `files.allow_overwrite` | `false` | Off means results are written beside originals |

Secrets belong in the environment, never in this file.

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
