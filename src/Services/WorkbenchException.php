<?php

namespace Elcreator\aIMage\Services;

use RuntimeException;

/**
 * A refusal from the workbench, in the shape every surface reports it.
 *
 * `error` is the stable machine code the page switches on (`no_key`,
 * `folder_denied`, ...), `status` the HTTP status the controllers answer with,
 * and the message is already translated for the manager.
 */
class WorkbenchException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $message,
        public readonly int $status = 422
    ) {
        parent::__construct($message);
    }

    public static function forbidden(): self
    {
        return new self('forbidden', __('aIMage::global.error_forbidden'), 403);
    }

    public static function noKey(): self
    {
        return new self('no_key', __('aIMage::global.error_no_key'), 409);
    }

    public static function jobNotFound(): self
    {
        return new self('not_found', __('aIMage::global.error_job_not_found'), 404);
    }
}
