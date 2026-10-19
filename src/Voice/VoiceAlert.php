<?php

declare(strict_types=1);

namespace App\Voice;

use App\Data\FeedbackReports;
use App\Data\Users;
use App\Notify\NotificationTypes;
use App\Notify\Notifier;

/**
 * A failed voice tool call becomes a feedback report for the developer (the same snapshot the "report to developer"
 * button makes, plus the admin push) — so voice failures show up in the reports card without anyone reporting them.
 */
final class VoiceAlert
{
    /** Per session, so one bad session cannot flood the developer. */
    public const MAX_PER_SESSION = 3;

    /**
     * @param array<string, mixed> $call ['name', 'args', 'error', 'ms']
     * @param list<array{role:string, content:string}> $transcript the messages leading up to it
     * @return int the report id
     */
    public static function report(FeedbackReports $reports, ?Notifier $notifier, Users $users, int $userId, ?int $conversationId, array $call, array $transcript): int
    {
        $name  = (string) ($call['name'] ?? '?');
        $error = (string) ($call['error'] ?? 'failed');
        $snap  = [
            'reported' => [
                'role'        => 'assistant',
                'content'     => 'Voice tool call failed: ' . $name . ' — ' . $error,
                'diagnostics' => [
                    'routing' => ['voice'],
                    'model'   => 'gemini-live',
                    'calls'   => [['name' => $name, 'ok' => false, 'error' => $error, 'args' => (string) ($call['args'] ?? '')]],
                ],
                'created_at'  => gmdate('Y-m-d H:i:s'),
            ],
            'context'     => array_map(static fn (array $m): array => ['role' => $m['role'], 'tool_name' => null, 'content' => mb_substr($m['content'], 0, 800)], array_slice($transcript, -10)),
            'reported_at' => date('c'),
            'source'      => 'voice',
        ];
        $id = $reports->create($userId, $conversationId, null, 'Voice: ' . $name . ' failed — ' . mb_substr($error, 0, 200), $snap);

        if ($notifier !== null) {
            try {
                foreach ($users->adminIds() as $adminId) {
                    $notifier->notify($adminId, NotificationTypes::FEEDBACK_REPORT, 'Feedback #' . $id . ' (voice)', 'Voice: ' . $name . ' failed — ' . mb_substr($error, 0, 120));
                }
            } catch (\Throwable $e) {
                error_log('VoiceAlert notify: ' . $e->getMessage());
            }
        }

        return $id;
    }
}
