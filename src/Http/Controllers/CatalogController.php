<?php

namespace Elcreator\aIMage\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The model picker, the numbers behind it, and the file browser - see
 * Services\Catalog; this only maps requests onto it.
 */
class CatalogController extends Controller
{
    public function models(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->catalogService()->models((bool) $request->query('refresh')));
    }

    /** Price and time one prospective operation. */
    public function estimate(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->catalogService()->estimate($request->query()));
    }

    /** Folders and images this manager may work with. */
    public function files(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->catalogService()->files(
            (string) $request->query('folder', ''),
            (bool) $request->query('recursive')
        ));
    }

    /** Everything the preview pane shows about one image. */
    public function fileInfo(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->catalogService()->fileInfo((string) $request->query('path', '')));
    }
}
