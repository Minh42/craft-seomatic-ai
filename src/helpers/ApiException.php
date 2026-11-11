<?php

namespace seomatic\craftai\helpers;

use Exception;

/**
 * An error the API answers as `{ok:false, error:{code, message}}`.
 * Codes: unauthorized, not_found, invalid, unsupported, save_failed,
 * server_error.
 */
class ApiException extends Exception
{
    public string $errorCode;
    public int $status;
    public array $extra;

    public function __construct(string $errorCode, string $message, int $status = 400, array $extra = [])
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->status = $status;
        $this->extra = $extra;
    }
}
