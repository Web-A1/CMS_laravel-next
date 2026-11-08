<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

final class AdminReviewException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $httpStatus = 409)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['error' => ['code' => $this->errorCode, 'message' => $this->getMessage()]], $this->httpStatus)
            ->header('Cache-Control', 'private, no-store');
    }
}
