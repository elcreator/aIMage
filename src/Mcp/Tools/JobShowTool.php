<?php

namespace Elcreator\aIMage\Mcp\Tools;

use Elcreator\aIMage\Mcp\WorkbenchTool;
use Elcreator\aIMage\Services\Actor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('aimage.job.show')]
#[Description('Full state of one job: status, planner conversation (questions to answer), planned steps, estimate, results with paths and URLs.')]
class JobShowTool extends WorkbenchTool
{
    protected function run(Actor $actor, Request $request): array
    {
        return $this->jobs($actor)->show($this->requireString($request, 'uuid'));
    }

    /**
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->required()->description('Job uuid from aimage.job.create / aimage.jobs'),
        ];
    }
}
