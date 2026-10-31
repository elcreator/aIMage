<?php

namespace Elcreator\aIMage\Mcp\Tools;

use Elcreator\aIMage\Mcp\WorkbenchTool;
use Elcreator\aIMage\Services\Actor;
use Elcreator\aIMage\Services\Jobs;
use EvolutionCMS\eMCP\Contracts\WritesSite;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('aimage.job.create')]
#[Description('Start an AIMage job from a plain-language instruction: generate, edit, vary or upscale one or many images. A text model plans the work (enhancing prompts, asking questions when ambiguous, estimating cost); a background worker executes it. Poll with aimage.job.show; answer questions with aimage.job.reply; confirm costly plans with aimage.job.approve. Images on the person\'s own computer are fetched into the site first with amount.fetch (see amount.computers); results can also be sent to any aMount mount, a computer included, with deliver_to.')]
class JobCreateTool extends WorkbenchTool implements WritesSite
{
    protected function run(Actor $actor, Request $request): array
    {
        return $this->jobs($actor)->create(
            $this->requireString($request, 'message'),
            $this->only($request, array_merge(['text_model', 'image_model', 'output_folder', 'deliver_to', 'deliver_folder'], Jobs::CONTROLS))
        );
    }

    /**
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'message' => $schema->string()->required()->description('What to do, e.g. "generate 3 poster-style images of a red poppy field, 1024x1024, into rainbow/red" or "upscale every image in products/ 2x"'),
            'text_model' => $schema->string()->description('Planner model (default from settings); any chat model from aimage.models'),
            'image_model' => $schema->string()->description('Image model (default from settings)'),
            'output_folder' => $schema->string()->description('Destination folder relative to the images root'),
            'deliver_to' => $schema->string()->description('Also send the results to this aMount mount: a name or uuid from amount.mounts, or a computer name from amount.computers such as "mypc"'),
            'deliver_folder' => $schema->string()->description('Folder there, e.g. "<shared folder>/photos/2025" on a computer'),
            'size' => $schema->string()->description('Image size control, e.g. 1024x1024'),
            'quality' => $schema->string()->description('Quality control'),
            'background' => $schema->string()->description('Background control, e.g. transparent'),
            'aspect_ratio' => $schema->string()->description('Aspect ratio control'),
        ];
    }
}
