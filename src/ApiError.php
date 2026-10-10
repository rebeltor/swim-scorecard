<?php
declare(strict_types=1);

/**
 * An error that is safe to show to the person using the app.
 */
final class ApiError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
