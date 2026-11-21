<?php

namespace App\Modules\Hr\Shared;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** İK iş kuralı hatası: { message, code, errors } biçiminde JSON'a render edilir. */
class HrException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 409,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'errors' => (object) $this->errors,
        ], $this->status);
    }
}
