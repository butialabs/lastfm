<?php

declare(strict_types=1);

namespace App\Services\Social;

/**
 * A login/authorization failure with a reason the login screen can explain.
 */
final class SocialAuthException extends \RuntimeException
{
    /** The server could not be reached (DNS, TLS, timeout). */
    public const UNREACHABLE = 'unreachable';

    /** The server answered, but not like the expected network's API. */
    public const NOT_A_SERVER = 'not_a_server';

    /** The server rejected the identifier/password. */
    public const REJECTED = 'rejected';

    public function __construct(public readonly string $reason, string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message !== '' ? $message : $reason, 0, $previous);
    }
}
