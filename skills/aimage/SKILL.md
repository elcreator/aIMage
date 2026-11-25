---
name: aimage
description: Use aIMage eMCP tools to generate, edit, analyze or upscale images on Evolution CMS sites, estimate costs, change models, check progress or cancel jobs.
license: MIT
compatibility: Evolution CMS 3.5.2+ with evolution-cms/emcp and elcreator/aimage installed; an MCP client connected to the site's eMCP server
metadata:
  version: "1.0.0"
  author: Artur Kyryliuk <mail@artur.work>
  tags: evolution-cms, mcp, image-generation
  package: elcreator/aimage
---

# aIMage via eMCP

## Purpose

Drive the aIMage workbench of an Evolution CMS site through its `aimage.*` MCP
tools. A text model plans each job (enhances prompts, asks back, estimates the
cost) and a background worker generates, edits, varies, analyzes or upscales
images. Every call uses the permissions, API key, file groups and job ownership
of the manager who owns the eMCP token.

## When to Use

Use when the user wants images created or changed on an Evo site: hero images,
product shots, variations, upscales of existing images, or images for many
documents at once, image analysis such as alt text, and when they ask what a
job will cost or want to change or stop a job.

Do NOT use for sites without aIMage, for editing image files on the local disk,
or for document and element changes (use the `evolution-cms-mcp` skill).

## Prerequisites

- The site is connected as described in the `evolution-cms-mcp` skill, and
  `tools/list` contains `aimage.*` tools. If not, aIMage is not installed
  (`php artisan package:installrequire elcreator/aimage "*"`), the role lacks the
  `aimage` permission, or `AIMAGE_MCP=false`.
- Listing tools requires `mcp:read` and calling tools requires `mcp:call`.
  Creating, replying to, approving, switching models or cancelling a job also
  requires `mcp:write` and write tools enabled in **Tools → MCP settings**.
- The manager or the site has an ai.artur.work key (connect it on the aIMage page).
- The Laravel scheduler runs (`* * * * * php artisan schedule:run`); without it
  jobs stay queued.

## Instructions

1. **Pick models.** `aimage.models` lists model IDs, supported actions and prices
   in `groups.text` and `groups.image`. Omit model fields from
   `aimage.job.create` to use site defaults.
2. **Quote the cost** for anything beyond a single image: `aimage.estimate`
   `{"model": "<image model>", "count": 12, "size": "1024x1024"}`. Tell the user
   the estimate and its currency before starting. A null amount means the
   cost is unknown.
3. **Start one job per request.** `aimage.job.create` with a plain-language
   `message` that names count, subject, style, size and target; set
   `output_folder` (relative to the images root, e.g. `news/spring`).
4. **Poll** `aimage.job.show` with the UUID from `job.create`. Use the same
   UUID until the job reaches `succeeded`, `failed` or `cancelled`. While the
   job is `awaiting_input` or `awaiting_approval`, address that request before
   resuming polling.
5. **Answer questions.** When `job.show` holds a planner question, relay it to the
   user and send their answer with `aimage.job.reply`.
6. **Approve only with consent.** A plan with an unknown price or a price above
   the approval threshold (5 EUR by default) waits. Show the estimate before
   calling `aimage.job.approve` for an `awaiting_approval` job.
7. **Switch models for future work.** Call `aimage.job.models` on an unfinished
   job with `uuid` and the field or fields the user wants to change. Copy model
   IDs from `aimage.models`. The new image model must support the queued image
   actions. In-flight, completed, upscale and describe steps keep their model.
   If the change is for price, estimate the remaining work with the new model.
   The saved plan estimate reflects the original model choices.
8. **Cancel when asked to stop.** Call `aimage.job.cancel` with the job UUID.
   It stops queued work and keeps saved images. Provider work already submitted
   may still finish and incur charges. Cancelling a terminal job returns its
   existing details.
9. **Report results** with paths and URLs from `job.steps_detail` in
   `aimage.job.show`, including any saved results from a cancelled job.

## Tools

| Tool | Kind | Key arguments |
|---|---|---|
| `aimage.models` | read | `refresh` |
| `aimage.estimate` | read | `model`*, `count`, `action` (`image`, `upscale`, `chat`, `transcribe`, `speak`), `size`, `quality`, `background`, `aspect_ratio` |
| `aimage.files` | read | `folder`, `recursive` |
| `aimage.file_info` | read | `path`* (as returned by `aimage.files`) |
| `aimage.jobs` | read | none: the manager's jobs with progress |
| `aimage.job.show` | read | `uuid`* |
| `aimage.job.create` | write | `message`*, `text_model`, `image_model`, `output_folder`, `deliver_to`, `deliver_folder`, `size`, `quality`, `background`, `aspect_ratio` |
| `aimage.job.reply` | write | `uuid`*, `message`* |
| `aimage.job.approve` | write | `uuid`* |
| `aimage.job.models` | write | `uuid`*, `text_model`, `image_model`. Omit either model to keep it. |
| `aimage.job.cancel` | write | `uuid`*. Stops queued work and keeps saved images. |

`*` required.

## Examples

### Generate images and attach one to a document

```json
{"name": "aimage.job.create", "arguments": {
  "message": "3 poster-style hero images of a red poppy field at sunrise, 16:9",
  "output_folder": "news/spring-sale"}}
{"name": "aimage.job.show", "arguments": {"uuid": "<uuid from create>"}}
```

When finished, write the chosen result into the document's image TV with the
eMCP write tool, then clear the cache:

```json
{"name": "evo.write.content.update", "arguments": {"id": 88, "tvs": {"image": "<result path>"}}}
{"name": "evo.write.cache.clear", "arguments": {}}
```

### Upscale a folder

1. `aimage.files` `{"folder": "products", "recursive": false}` to confirm what is there.
2. Call `aimage.estimate` with `action: "upscale"` and the count. The input
   schema requires `model`. Pass a model ID from the catalogue. The site uses
   its configured upscaler for both the estimate and the job.
3. On approval: `aimage.job.create` `{"message": "upscale every image in products/ 2x", "output_folder": "products/2x"}`.

### Illustrate articles that have no image

1. `evo.content.search` with `with_tvs: ["image"]` and
   `tv_filters: [{"tv": "image", "op": "null"}]` lists the articles.
2. Estimate, confirm, then one `aimage.job.create` naming each article title and
   an output folder per alias.
3. Write each result path into that article's `image` TV.

### Work with files on the user's computer (with aMount)

- Fetch images from the user's computer with `amount.fetch` (see the `amount`
  skill). Wait for `amount.status` to confirm the transfer before referencing
  the site paths in the job message.
- Results can also go to any aMount mount or computer: `deliver_to` (mount name or
  uuid, or a computer name such as `mypc`) and `deliver_folder`. Each image is its
  own delivery; refusals are listed per file in the job (`deliveries`).

## Limitations

- Jobs spend money from the ai.artur.work key; the gateway enforces its spending cap.
- Only images inside the manager's file-manager root and allowed file groups are visible.
- Other managers' jobs are answered as not found.
- No tool deletes generated images; remove them in the file manager.
- Generation is asynchronous; a large batch can take minutes.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| No `aimage.*` tools in `tools/list` | aIMage not installed, `aimage` permission missing, or `AIMAGE_MCP=false` | Install the package, grant the permission, or enable MCP in aIMage settings |
| HTTP `403` `tool_denied` / `scope_denied` on `job.create` | Write tools disabled or token without `mcp:write` | Enable write tools in MCP settings; recreate the token with `mcp:write` |
| Job is `planning` or `running` without progress | Scheduler or task worker may be stopped | Check the worker and the `schedule:run` cron |
| `awaiting_approval` or `awaiting_input` | Approval or an answer is required | Read `aimage.job.show` and follow the matching step above |
| JSON-RPC `-32602` `no_key` / `key_rejected` | The ai.artur.work key is absent or rejected | Check the connection on the aIMage page |
| `-32602` `catalog_unavailable` | The catalogue request failed | Retry later and check the site's outbound network |
| `-32602` `unknown_model` | Model ID is unknown or lacks a queued image action | Pick a model from `aimage.models` that supports the required actions |
| `-32602` `folder_denied` | Folder outside the manager's file root or file groups | Use a folder from `aimage.files` |
| `-32602` `not_awaiting_approval` / `job_finished` | The job already moved on | Re-read `aimage.job.show` |
| `-32602` `deliver_mount` / `deliver_unavailable` | `deliver_to` unknown, or aMount missing | Check `amount.mounts` / `amount.computers` |
