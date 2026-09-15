<?php

namespace Elcreator\aIMage\Mcp\Tools;

use Elcreator\aIMage\Mcp\WorkbenchTool;
use Elcreator\aIMage\Services\Actor;
use EvolutionCMS\eMCP\Contracts\WritesSite;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('aimage.job.cancel')]
#[Description('Cancel a job; queued steps are dropped, finished images stay.')]
class JobCancelTool extends WorkbenchTool implements WritesSite
{
    protected function run(Actor $actor, Request $request): array
    {
        return $this->jobs($actor)->cancel($this->requireString($request, 'uuid'));
    }

    /**
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->required()->description('Job uuid'),
        ];
    }
}
