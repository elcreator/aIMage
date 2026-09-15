<?php

namespace Elcreator\aIMage\Mcp\Tools;

use Elcreator\aIMage\Mcp\WorkbenchTool;
use Elcreator\aIMage\Services\Actor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('aimage.files')]
#[Description('List images and folders the acting manager may see, under their file-manager root.')]
class FilesTool extends WorkbenchTool
{
    protected function run(Actor $actor, Request $request): array
    {
        return $this->catalog($actor)->files((string) $request->get('folder', ''), (bool) $request->get('recursive', false));
    }

    /**
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'folder' => $schema->string()->description('Folder relative to the images root (default: the images root)'),
            'recursive' => $schema->boolean()->description('Include subfolders'),
        ];
    }
}
