<?php

declare(strict_types=1);

namespace App\Email;

/**
 * A message to be drafted (and, once unlocked, sent). Plain-text body only for
 * v1 — no attachments. When replying, $inReplyToId / $threadId thread it.
 */
final class EmailDraft
{
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $bodyText,
        public readonly string $cc = '',
        public readonly ?string $inReplyToId = null,
        public readonly ?string $threadId = null,
    ) {
    }
}
