<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Income;
use App\Data\Receipts;
use App\Email\EmailService;
use App\Receipts\ReceiptStorage;
use Throwable;

/**
 * Tool: save one attachment of an email (a PDF invoice or a receipt photo) as the document of an expense or an income
 * entry — so the SKAT-ready file rides along with the entry. The attachment ids come from read_email.
 */
final class AttachEmailFile implements Tool
{
    private const MAX_BYTES = 12 * 1024 * 1024;

    public function __construct(
        private EmailService $email,
        private Receipts $receipts,
        private Income $income,
        private ReceiptStorage $storage,
    ) {
    }

    public function name(): string
    {
        return 'attach_email_file';
    }

    public function description(): string
    {
        return 'Saves ONE attachment of an email (a PDF or an image) as the receipt/invoice file of an existing expense (kind "expense", '
            . 'the entry_id add_expense returned) or income entry (kind "income"). Get the email id and the attachment id from read_email '
            . '("attachments"), pass the email\'s account if known. Replaces a file already on the entry. Use it after add_expense / add_income '
            . 'when the entry came from an email that has a PDF or photo attached; if the email has no attachment (an HTML-only receipt), '
            . 'say so — there is nothing to attach.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'email_id'      => ['type' => 'string', 'description' => 'The email id (from get_emails / read_email).'],
                'attachment_id' => ['type' => 'string', 'description' => 'The attachment id from read_email\'s "attachments".'],
                'kind'          => ['type' => 'string', 'enum' => ['expense', 'income'], 'description' => 'Which kind of entry gets the file.'],
                'entry_id'      => ['type' => 'integer', 'description' => 'The expense or income entry id.'],
                'account'       => ['type' => 'string', 'description' => 'Which mailbox (email or provider), if known.'],
            ],
            'required' => ['email_id', 'attachment_id', 'kind', 'entry_id'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $emailId = trim((string) ($arguments['email_id'] ?? ''));
        $attId   = trim((string) ($arguments['attachment_id'] ?? ''));
        $kind    = (string) ($arguments['kind'] ?? '');
        $entryId = (int) ($arguments['entry_id'] ?? 0);
        if ($emailId === '' || $attId === '' || $entryId <= 0 || !in_array($kind, ['expense', 'income'], true)) {
            return ['error' => 'An email id, an attachment id, a kind (expense or income) and the entry id are all required.'];
        }
        $exists = $kind === 'expense' ? $this->receipts->get($userId, $entryId) : $this->income->get($userId, $entryId);
        if ($exists === null) {
            return ['error' => 'I could not find that ' . $kind . ' entry.'];
        }
        if (!$this->email->hasAccounts($userId)) {
            return ['error' => 'No email account is connected yet.'];
        }

        try {
            $accountId = $this->email->matchAccount($userId, isset($arguments['account']) ? (string) $arguments['account'] : null);
            $file      = $this->email->attachment($userId, $accountId, $emailId, $attId);
        } catch (Throwable $e) {
            error_log('attach_email_file: ' . $e->getMessage());

            return ['error' => 'I could not download that attachment just now.'];
        }
        if ($file === null || $file['data'] === '') {
            return ['error' => 'I could not find that attachment (read the email again to get its attachment ids).'];
        }
        if (strlen($file['data']) > self::MAX_BYTES) {
            return ['error' => 'That attachment is too large (max 12 MB).'];
        }

        $tmp = tempnam(sys_get_temp_dir(), 'kachow-att-');
        if ($tmp === false || file_put_contents($tmp, $file['data']) === false) {
            return ['error' => 'Could not handle the attachment file.'];
        }
        try {
            $stored = $this->storage->store($userId, $tmp, strlen($file['data']));
        } catch (Throwable $e) {
            return ['error' => $e->getMessage() . ' (' . $file['filename'] . ')'];
        } finally {
            @unlink($tmp);
        }

        if ($kind === 'expense') {
            $old = $this->receipts->attachFile($userId, $entryId, $stored['file_ref'], $stored['mime']);
            $row = $this->receipts->get($userId, $entryId);
            $card = $row !== null ? $this->receipts->cardWithChecks($userId, $row) : null;
        } else {
            $this->income->attachFile($userId, $entryId, $stored['file_ref'], $stored['mime']);
            $old = isset($exists['file_ref']) && $exists['file_ref'] !== null ? (string) $exists['file_ref'] : null;
            $row = $this->income->get($userId, $entryId);
            $card = $row !== null ? $this->income->card($row) : null;
        }
        if (is_string($old) && $old !== '' && $old !== $stored['file_ref']) {
            $this->storage->delete($userId, $old);
        }

        return ['attached' => true, 'kind' => $kind, 'entry_id' => $entryId, 'filename' => $file['filename'], '_render' => $card];
    }
}
