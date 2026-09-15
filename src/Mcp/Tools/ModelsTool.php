<?php

namespace Elcreator\aIMage\Mcp\Tools;

use Elcreator\aIMage\Mcp\WorkbenchTool;
use Elcreator\aIMage\Services\Actor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('aimage.models')]
#[Description('List the text, image, voice and speech models the gateway offers, with prices. Pick text_model / image_model for jobs from here.')]
class ModelsTool extends WorkbenchTool
{
    protected function run(Actor $actor, Request $request): array
    {
        return $this->catalog($actor)->models((bool) $request->get('refresh', false));
    }

    /**
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'refresh' => $schema->boolean()->description('Bypass the cached catalogue'),
        ];
    }
}
