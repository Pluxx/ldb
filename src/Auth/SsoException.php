<?php

declare(strict_types=1);

namespace Ldb\Auth;

/**
 * An SSO token or request was rejected. The message is the detailed reason for the server
 * log only; users always see the same generic error page.
 */
final class SsoException extends \RuntimeException
{
}
