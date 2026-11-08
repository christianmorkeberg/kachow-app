<?php

declare(strict_types=1);

namespace App\Recruiter;

use App\Data\Users;
use App\Notify\NotificationTypes;
use App\Notify\Notifier;
use Throwable;

/**
 * Gets a recruiter's message — and "someone opened your link" — to the owner. A message is SAVED FIRST (RecruiterLinks::addLead); this then pushes and emails it
 * and records which of the two worked, so a lead can never vanish silently: the recruiter is only told "delivered" once it is saved, and anything that did not go out
 * is retried by bin/recruiter-cron.php and shows as undelivered in the list.
 */
final class LeadDelivery
{
    /** @var callable(int, string, string): bool */
    private $push;
    /** @var callable(string, string, string): bool */
    private $mail;
    /** @var callable(int): string */
    private $ownerEmail;

    /**
     * Push, mail and the owner's address are injected (the tests use fakes); fromEnv() wires the real ones.
     *
     * @param callable(int):string $ownerEmail
     * @param callable(int, string, string):bool $push (owner id, title, body) → whether a device was reached
     * @param callable(string, string, string):bool $mail (to, subject, html)
     */
    public function __construct(private RecruiterLinks $links, callable $ownerEmail, callable $push, callable $mail)
    {
        $this->ownerEmail = $ownerEmail;
        $this->push       = $push;
        $this->mail       = $mail;
    }

    public static function fromEnv(RecruiterLinks $links): self
    {
        $users = new Users();

        return new self(
            $links,
            static fn (int $owner): string => (string) ($users->findById($owner)['email'] ?? ''),
            static fn (int $owner, string $title, string $body): bool => Notifier::fromEnv()->notify($owner, NotificationTypes::RECRUITER, $title, $body, '/') > 0,
            static fn (string $to, string $subject, string $html): bool => \App\Mail\NativeMailer::fromEnv()->send($to, $subject, $html)
        );
    }

    /**
     * @param array<string, mixed> $link
     * @return array{push:bool, email:bool}
     */
    public function deliver(array $link, int $leadId, string $name, string $contact, string $message): array
    {
        $owner = (int) $link['user_id'];
        $where = trim(($link['company'] ?? '') . ' ' . ($link['role_title'] ?? '')) ?: (string) $link['label'];
        $title = 'Message from ' . ($name !== '' ? $name : 'a recruiter') . ' — ' . $where;
        $push  = false;
        $email = false;
        try {
            $push = (bool) ($this->push)($owner, $title, mb_substr($message, 0, 160));
        } catch (Throwable $e) {
            error_log('recruiter push: ' . $e->getMessage());
        }
        try {
            $to = ($this->ownerEmail)($owner);
            if ($to !== '') {
                $body = '<p><strong>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong> (' . htmlspecialchars($contact, ENT_QUOTES, 'UTF-8') . ') left you a message via the link “'
                    . htmlspecialchars((string) $link['label'], ENT_QUOTES, 'UTF-8') . '”:</p><blockquote>' . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . '</blockquote>';
                $email = (bool) ($this->mail)($to, $title, $body);
            }
        } catch (Throwable $e) {
            error_log('recruiter email: ' . $e->getMessage());
        }
        $this->links->markLeadDelivery($leadId, $push, $email);

        return ['push' => $push, 'email' => $email];
    }

    /** Tries again for every lead nothing reached the owner with (bin/recruiter-cron.php). @return int how many were delivered this time */
    public function retryUndelivered(): int
    {
        $ok = 0;
        foreach ($this->links->undelivered() as $lead) {
            $link = $this->links->byId((int) $lead['link_id']);
            if ($link === null) {
                continue;
            }
            $r = $this->deliver($link, (int) $lead['id'], (string) $lead['name'], (string) $lead['contact'], (string) $lead['message']);
            $ok += ($r['push'] || $r['email']) ? 1 : 0;
        }

        return $ok;
    }

    /** "Someone opened your link for the first time." Best effort. @param array<string, mixed> $link */
    public function opened(array $link): void
    {
        try {
            $where = trim(($link['company'] ?? '') . ' ' . ($link['role_title'] ?? '')) ?: (string) $link['label'];
            ($this->push)((int) $link['user_id'], 'Your recruiter link was opened', $where . ' opened the link for the first time.');
        } catch (Throwable $e) {
            error_log('recruiter opened push: ' . $e->getMessage());
        }
    }
}
