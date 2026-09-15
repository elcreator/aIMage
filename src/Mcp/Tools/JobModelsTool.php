<?php

namespace Elcreator\aIMage\Mcp\Tools;

use Elcreator\aIMage\Mcp\WorkbenchTool;
use Elcreator\aIMage\Services\Actor;
use EvolutionCMS\eMCP\Contracts\WritesSite;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('aimage.job.models')]
#[Description('Switch the text and/or image model of a job before it executes.')]
class JobModelsTool extends WorkbenchTool implements WritesSite
{
    protected function run(Actor $actor, Request $request): array
    {
        return $this->jobs($actor)->setModels($this->requireString($request, 'uuid'), $this->only($request, ['text_model', 'image_model']));
    }

    /**
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->required()->description('Job uuid'),
            'text_model' => $schema->string()->description('New planner model'),
            'image_model' => $schema->string()->description('New image model'),
        ];
    }
}
