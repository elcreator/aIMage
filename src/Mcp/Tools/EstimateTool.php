<?php

namespace Elcreator\aIMage\Mcp\Tools;

use Elcreator\aIMage\Mcp\WorkbenchTool;
use Elcreator\aIMage\Services\Actor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('aimage.estimate')]
#[Description('Estimate the cost of a number of images (or chat/transcription/speech) with a given model and controls before queuing a job.')]
class EstimateTool extends WorkbenchTool
{
    protected function run(Actor $actor, Request $request): array
    {
        return $this->catalog($actor)->estimate($this->only($request, ['model', 'count', 'action', 'size', 'quality', 'background', 'aspect_ratio', 'prompt_chars', 'seconds', 'chars']));
    }

    /**
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'model' => $schema->string()->required()->description('Model id from aimage.models'),
            'count' => $schema->integer()->description('Number of images (default 1)'),
            'action' => $schema->string()->description('image (default), upscale, chat, transcribe or speak'),
            'size' => $schema->string()->description('e.g. 1024x1024'),
            'quality' => $schema->string()->description('e.g. low, medium, high'),
            'background' => $schema->string()->description('e.g. transparent'),
            'aspect_ratio' => $schema->string()->description('e.g. 16:9'),
        ];
    }
}
