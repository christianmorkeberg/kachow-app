<?php

declare(strict_types=1);

namespace App\Email;

use RuntimeException;

/**
 * Thrown by EmailService::send() when the global send lock is closed
 * (EMAIL_SEND_ENABLED not set). Distinct type so tools can respond calmly
 * ("saved as a draft") rather than surfacing an error.
 */
final class SendLockedException extends RuntimeException
{
}
