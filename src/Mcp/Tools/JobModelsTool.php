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
#[Description(
    'Change the models for future work on an existing job you own. For a new job, set its models in aimage.job.create. '
    . 'Get current model IDs from aimage.models. Pass one or both model fields. An omitted or blank field keeps its current value. '
    . 'Requires a configured gateway key. Works on planning, waiting and running jobs. Finished jobs and unknown model IDs are rejected. '
    . 'The text choice applies to future planner turns. The image choice must support the queued image actions and updates those steps. '
    . 'In-flight and completed steps keep their model, as do upscale and describe steps. Returns the updated job.'
)]
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
            'uuid' => $schema->string()->required()->description('UUID of your unfinished job from aimage.jobs or aimage.job.create.'),
            'text_model' => $schema->string()->description('Copy the model ID from an entry in groups.text returned by aimage.models. Omit to keep the current planner model.'),
            'image_model' => $schema->string()->description('Copy the model ID from an entry in groups.image returned by aimage.models. Its actions must cover the queued image steps. Omit to keep the current image model.'),
        ];
    }
}
