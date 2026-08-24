<?php

use EvolutionCMS\aIMage\Http\Controllers\CatalogController;
use EvolutionCMS\aIMage\Http\Controllers\JobController;
use EvolutionCMS\aIMage\Http\Controllers\PageController;
use EvolutionCMS\aIMage\Http\Controllers\SettingsController;
use EvolutionCMS\aIMage\Http\Controllers\VoiceController;
use Illuminate\Support\Facades\Route;

/**
 * The module's routes.
 *
 * This file is included by `registerRoutingModule()` inside a group that
 * already carries the `modules/{id}` prefix and the `mgr` middleware, so
 * nothing here names a prefix and nothing re-applies the manager guard.
 *
 * Two consequences of that group worth remembering when editing this file:
 *
 *  - `mgr` includes `VerifyCsrfToken`, which fails closed once a manager
 *    session exists. Every POST below therefore needs a `_token` field or an
 *    `X-CSRF-TOKEN` header; the page supplies the latter to its own fetches.
 *  - The prefix is derived from the module name, so no URL here is stable
 *    enough to hardcode anywhere else. The page hands its base URL to the
 *    front end instead.
 */

Route::get('/', [PageController::class, 'index'])->name('aimage.index');

// The model picker, and the numbers behind it: what a chosen model costs and
// how long it is expected to take. Read-only, so GET.
Route::get('/models', [CatalogController::class, 'models'])->name('aimage.models');
Route::get('/estimate', [CatalogController::class, 'estimate'])->name('aimage.estimate');

// Scoped browsing. Everything returned here has already been filtered through
// the manager's own file-group permissions.
Route::get('/files', [CatalogController::class, 'files'])->name('aimage.files');

Route::post('/jobs', [JobController::class, 'store'])->name('aimage.jobs.store');
Route::get('/jobs', [JobController::class, 'index'])->name('aimage.jobs.index');
Route::get('/jobs/{uuid}', [JobController::class, 'show'])->name('aimage.jobs.show');
Route::post('/jobs/{uuid}/reply', [JobController::class, 'reply'])->name('aimage.jobs.reply');
Route::post('/jobs/{uuid}/approve', [JobController::class, 'approve'])->name('aimage.jobs.approve');
Route::post('/jobs/{uuid}/cancel', [JobController::class, 'cancel'])->name('aimage.jobs.cancel');

// Speech in, and optionally speech out. Both are intermediate: the terminal
// result of a job is always changed images.
Route::post('/voice/transcribe', [VoiceController::class, 'transcribe'])->name('aimage.voice.transcribe');
Route::post('/voice/speak', [VoiceController::class, 'speak'])->name('aimage.voice.speak');

Route::post('/settings/key', [SettingsController::class, 'saveKey'])->name('aimage.settings.key');
