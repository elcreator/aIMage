<?php

namespace Elcreator\aIMage\Mcp\Tools;

use Elcreator\aIMage\Mcp\WorkbenchTool;
use Elcreator\aIMage\Services\Actor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('aimage.file_info')]
#[Description('Details (size, dimensions, public URL) of one image the acting manager may see.')]
class FileInfoTool extends WorkbenchTool
{
    protected function run(Actor $actor, Request $request): array
    {
        return $this->catalog($actor)->fileInfo($this->requireString($request, 'path'));
    }

    /**
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->required()->description('Path relative to the file-manager root, as returned by aimage.files'),
        ];
    }
}
