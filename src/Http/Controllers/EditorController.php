<?php

namespace Elcreator\aIMage\Http\Controllers;

use Elcreator\aIMage\Services\Editor;
use Elcreator\aIMage\Services\WorkbenchException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The hand editor (Painterro): its script, and saving what it made.
 *
 * Neither needs an API key - editing by hand is a workbench feature of its own,
 * not something gated behind the AI.
 */
class EditorController extends Controller
{
    /**
     * The built Painterro bundle, served from the package rather than published,
     * like the rest of the page. The URL carries a content hash, so it is cached for good.
     */
    public function bundle(): Response
    {
        if (!$this->authorized()) {
            return $this->denied();
        }

        $path = Editor::bundlePath();

        if ($path === null) {
            return response('/* Painterro is not built: run ci/build-painterro.sh */', 404, [
                'Content-Type' => 'application/javascript; charset=utf-8',
            ]);
        }

        // The manager session's cache limiter has already queued `no-store` headers, and
        // Symfony adds rather than replaces them, so they would win over ours.
        if (!headers_sent()) {
            header_remove('Cache-Control');
            header_remove('Pragma');
            header_remove('Expires');
        }

        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
        ], false);

        return $response->setPrivate()->setMaxAge(31536000)->setImmutable();
    }

    /** One image's bytes for the canvas, same-origin, after the scope has allowed it. */
    public function image(Request $request): Response
    {
        if (!$this->authorized()) {
            return $this->denied();
        }

        try {
            $image = (new Editor($this->actor()))->open((string) $request->query('path', ''));
        } catch (WorkbenchException $e) {
            return $this->fail($e->error, $e->getMessage(), $e->status);
        }

        return response($image['bytes'], 200, [
            'Content-Type' => $image['mime'],
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            // An SVG opened here must not run as a document of the manager's origin.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    /** Write an edited image: beside its source as a copy, or over it where allowed. */
    public function save(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            $upload = $request->file('image');
            $bytes = $upload !== null && $upload->isValid() ? (string) file_get_contents($upload->getRealPath()) : '';

            return (new Editor($this->actor()))->save(
                $bytes,
                (string) $request->input('source', ''),
                (string) $request->input('folder', ''),
                (string) $request->input('name', ''),
                $request->boolean('replace')
            );
        });
    }
}
