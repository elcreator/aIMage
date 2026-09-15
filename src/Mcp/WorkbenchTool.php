<?php

namespace Elcreator\aIMage\Mcp;

use Elcreator\aIMage\Services\Actor;
use Elcreator\aIMage\Services\Catalog;
use Elcreator\aIMage\Services\Jobs;
use Elcreator\aIMage\Services\WorkbenchException;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * An MCP tool over the workbench services.
 *
 * eMCP runs an API request *as* the token owner, so Actor::current() here is the
 * same manager the page would have - same permission, key, file scope and job
 * ownership - and a tool is one service call plus the argument mapping.
 */
abstract class WorkbenchTool extends Tool
{
    /**
     * @throws WorkbenchException
     */
    abstract protected function run(Actor $actor, Request $request): array;

    public function handle(Request $request): ResponseFactory
    {
        try {
            $actor = Actor::current()->authorize();

            return Response::structured($this->run($actor, $request));
        } catch (WorkbenchException $e) {
            throw ValidationException::withMessages([$e->error => $e->getMessage()]);
        }
    }

    protected function catalog(Actor $actor): Catalog
    {
        return new Catalog($actor);
    }

    protected function jobs(Actor $actor): Jobs
    {
        return new Jobs($actor);
    }

    /**
     * The arguments present in the call, by name.
     *
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    protected function only(Request $request, array $names): array
    {
        $picked = [];
        foreach ($names as $name) {
            $value = $request->get($name);
            if ($value !== null && $value !== '') {
                $picked[$name] = $value;
            }
        }

        return $picked;
    }

    protected function requireString(Request $request, string $name): string
    {
        $value = trim((string) $request->get($name, ''));
        if ($value === '') {
            throw ValidationException::withMessages([$name => "{$name} is required."]);
        }

        return $value;
    }
}
