<?php

namespace Elcreator\aIMage\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where a manager puts their key, and where an administrator puts the
 * fallback - see Services\Keys; this only maps the request onto it.
 */
class SettingsController extends Controller
{
    public function saveKey(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->keys()->save(
            (string) $request->input('scope', 'user'),
            (string) $request->input('key', '')
        ));
    }
}
