<?php

namespace Elcreator\aIMage\Mcp;

use Elcreator\aIMage\Mcp\Tools\EstimateTool;
use Elcreator\aIMage\Mcp\Tools\FileInfoTool;
use Elcreator\aIMage\Mcp\Tools\FilesTool;
use Elcreator\aIMage\Mcp\Tools\JobApproveTool;
use Elcreator\aIMage\Mcp\Tools\JobCancelTool;
use Elcreator\aIMage\Mcp\Tools\JobCreateTool;
use Elcreator\aIMage\Mcp\Tools\JobModelsTool;
use Elcreator\aIMage\Mcp\Tools\JobReplyTool;
use Elcreator\aIMage\Mcp\Tools\JobShowTool;
use Elcreator\aIMage\Mcp\Tools\JobsTool;
use Elcreator\aIMage\Mcp\Tools\ModelsTool;
use EvolutionCMS\eMCP\Contracts\ToolProvider as Contract;

/**
 * Exposes the workbench to AI agents through eMCP (evolution-cms/emcp).
 *
 * Every tool is a proxy to an existing controller, so an agent connected with a
 * manager's token gets exactly what that manager gets on the page: the same
 * permission, the same key, the same file scope, the same job ownership. Prompt
 * enhancement, questions, cost estimates and batching all happen in the job the
 * agent creates — nothing is re-implemented for the API.
 */
final class ToolProvider implements Contract
{
    public function server(): string
    {
        return (string) config('cms.settings.aIMage.mcp.server', 'content');
    }

    public function tools(): array
    {
        return [
            ModelsTool::class,
            EstimateTool::class,
            FilesTool::class,
            FileInfoTool::class,
            JobsTool::class,
            JobCreateTool::class,
            JobShowTool::class,
            JobReplyTool::class,
            JobApproveTool::class,
            JobModelsTool::class,
            JobCancelTool::class,
        ];
    }
}
