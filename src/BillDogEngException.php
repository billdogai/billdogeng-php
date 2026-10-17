<?php

declare(strict_types=1);

namespace BillDogEng;

/** Exception thrown when an API request ultimately fails (after retries). */
class BillDogEngException extends \RuntimeException
{
    /** HTTP status code, or 0 for network/timeout failures. */
    public readonly int $status;

    /** Machine-readable error code from the API envelope, if any. */
    public readonly ?string $errorCode;

    /** Raw response body (parsed if JSON, else text), if any. */
    public readonly mixed $body;

    public function __construct(string $message, int $status, ?string $errorCode = null, mixed $body = null)
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->body = $body;
    }
}
