<?php

namespace App\Modules\Operations\Exceptions;

use Exception;
use Throwable;

class ProvisioningException extends Exception
{
    /**
     * Create a new ProvisioningException instance.
     *
     * @param string $message
     * @param int $code
     * @param Throwable|null $previous
     */
    public function __construct(
        string $message = "An error occurred during provisioning execution.",
        int $code = 422,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Render the exception as an HTTP response (if unhandled in controllers).
     */
    public function render($request)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'status'  => 'error',
                'message' => $this->getMessage(),
            ], $this->getCode() ?: 422);
        }

        return false;
    }
}
