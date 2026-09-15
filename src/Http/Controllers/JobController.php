<?php

namespace Elcreator\aIMage\Http\Controllers;

use Elcreator\aIMage\Services\Jobs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Creating, watching, answering and stopping batches - see Services\Jobs for
 * the rules; this only maps requests onto it.
 */
class JobController extends Controller
{
    public function index(): JsonResponse
    {
        return $this->respond(fn () => $this->jobs()->list());
    }

    /** Start a batch from the manager's first instruction. */
    public function store(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->jobs()->create(
            (string) $request->input('message'),
            $request->only(array_merge(
                ['text_model', 'image_model', 'voice_model', 'output_folder', 'audio_path'],
                Jobs::CONTROLS
            ))
        ));
    }

    /** The whole state of one job - what the page polls. */
    public function show(string $uuid): JsonResponse
    {
        return $this->respond(fn () => $this->jobs()->show($uuid));
    }

    /** Answer the planner's question and let it carry on. */
    public function reply(Request $request, string $uuid): JsonResponse
    {
        return $this->respond(fn () => $this->jobs()->reply(
            $uuid,
            (string) $request->input('message'),
            (string) $request->input('audio_path', '')
        ));
    }

    /** Approve a priced plan and start spending. */
    public function approve(string $uuid): JsonResponse
    {
        return $this->respond(fn () => $this->jobs()->approve($uuid));
    }

    /** Change the models a task in flight will carry on with. */
    public function models(Request $request, string $uuid): JsonResponse
    {
        return $this->respond(fn () => $this->jobs()->setModels(
            $uuid,
            $request->only(['text_model', 'image_model'])
        ));
    }

    public function cancel(string $uuid): JsonResponse
    {
        return $this->respond(fn () => $this->jobs()->cancel($uuid));
    }
}
