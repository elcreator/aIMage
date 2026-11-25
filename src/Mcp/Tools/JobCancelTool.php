<?php

namespace Elcreator\aIMage\Mcp\Tools;

use Elcreator\aIMage\Mcp\WorkbenchTool;
use Elcreator\aIMage\Services\Actor;
use EvolutionCMS\eMCP\Contracts\WritesSite;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('aimage.job.cancel')]
#[IsDestructive]
#[Description(
    'Use when the user wants to stop the remaining work on a job they own. Use aimage.job.show to check progress or retrieve images. '
    . 'Marks the job cancelled and stops queued work. A worker stops when it next checks for cancellation, and provider work already submitted may still finish and incur charges. '
    . 'Saved images are kept. Returns the job details. Calling it on a finished or already cancelled job returns its existing details.'
)]
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
            'uuid' => $schema->string()->required()->description('UUID of your job from aimage.jobs or aimage.job.create.'),
        ];
    }
}
