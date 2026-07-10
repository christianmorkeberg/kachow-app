<?php

declare(strict_types=1);

namespace App\Email;

/**
 * A provider-agnostic email message. List views populate the summary fields and
 * leave $bodyText empty; a full fetch (get) fills $bodyText in.
 */
final class EmailMessage
{
    public function __construct(
        public readonly string $id,
        public readonly string $threadId,
        public readonly string $from,
        public readonly string $to,
        public readonly string $subject,
        public readonly string $snippet,
        public readonly string $date,      // ISO-8601 (UTC) when known, else raw header
        public readonly bool $unread,
        public readonly string $bodyText = '',
    ) {
    }

    /**
     * Shape sent to the model / chat card. Never includes raw bodies unless asked
     * for (read_email); list views pass bodyText='' so cards stay compact.
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $withBody = false): array
    {
        $out = [
            'id'       => $this->id,
            'thread_id' => $this->threadId,
            'from'     => $this->from,
            'to'       => $this->to,
            'subject'  => $this->subject,
            'snippet'  => $this->snippet,
            'date'     => $this->date,
            'unread'   => $this->unread,
        ];
        if ($withBody) {
            $out['body'] = $this->bodyText;
        }

        return $out;
    }
}
