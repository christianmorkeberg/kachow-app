<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Connections;
use App\Data\TrainingPrograms;
use App\Support\ProgramInput;

/**
 * Tool: share a training programme with a connected person so you follow it together — both see it and either can edit it, each DAY says who it is for (both, or one person),
 * and the percentages become each person's own weights. Also the way to change which days are shared, and to stop sharing.
 */
final class ShareTrainingProgram implements Tool
{
    public function __construct(private TrainingPrograms $programs, private Connections $connections)
    {
    }

    public function name(): string
    {
        return 'share_training_program';
    }

    public function description(): string
    {
        return 'Shares one of the user\'s training programmes with a CONNECTED person so they train it together: both see it and either can edit it, and a day can be for both or '
            . 'for one person only. Pass `person` (name or email) and `shared_weekdays` — the weekdays that both do together ("Wednesday and Saturday are shared") — every other day then '
            . 'becomes only the user\'s; omit it to make every day shared. Call it again to change which weekdays are shared. `stop` = true stops sharing (the programme is only the '
            . 'user\'s again). Percentages in the programme are each person\'s own % of their own training max, so the weights differ by person. Only the person who made a '
            . 'programme can share or unshare it.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'program'         => ['type' => 'string', 'description' => 'Programme name or id.'],
                'person'          => ['type' => 'string', 'description' => 'The connected person (name or email). Not needed with stop.'],
                'shared_weekdays' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Weekdays done together (names or 1–7); all other days are only the user\'s. Omit = every day shared.'],
                'stop'            => ['type' => 'boolean', 'description' => 'Stop sharing.'],
            ],
            'required' => ['program'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $p = $this->programs->find($userId, (string) ($arguments['program'] ?? ''));
        if ($p === null) {
            return ['error' => 'No programme matches "' . ($arguments['program'] ?? '') . '".'];
        }
        if ($p['user_id'] !== $userId) {
            return ['error' => 'Only the person who made this programme can change how it is shared.'];
        }
        if (filter_var($arguments['stop'] ?? false, FILTER_VALIDATE_BOOL)) {
            return $this->programs->unshare($userId, $p['id'])
                ? ['stopped' => true, 'program' => $p['name'], 'note' => 'The programme is only theirs again; the other person no longer sees it. Their logged sets are untouched.']
                : ['error' => 'Could not stop sharing — has the shared-training migration been run?'];
        }
        $person = trim((string) ($arguments['person'] ?? ''));
        $entry  = $person === '' ? null : $this->connections->resolveByOther($userId, $person);
        if ($entry === null || $entry['status'] !== 'accepted') {
            return ['error' => 'You are not connected with "' . $person . '" yet — connect first (Settings → People), then share.'];
        }
        // Both must let the other log their sets (the Train card on one phone logs for both).
        $theirs = (int) $entry['person']['id'];
        $hint   = [];
        if (!in_array('workouts', $this->connections->sharedScopes($theirs, $userId), true)) {
            $hint[] = ($entry['person']['name'] ?? 'They') . ' has not shared workouts with you, so you cannot log sets for them from the Train card — ask them to share workouts.';
        }
        if (!in_array('workouts', $this->connections->sharedScopes($userId, $theirs), true)) {
            $hint[] = 'You have not shared workouts with ' . ($entry['person']['name'] ?? 'them') . ', so they cannot log sets for you — share workouts with them (Settings → People).';
        }
        $only = null;
        if (is_array($arguments['shared_weekdays'] ?? null)) {
            $shared = [];
            foreach ($arguments['shared_weekdays'] as $w) {
                $n = ProgramInput::weekday($w);
                if ($n !== null) {
                    $shared[] = $n;
                }
            }
            $only = array_values(array_diff(range(1, 7), $shared));
        } else {
            $only = [];
        }
        if (!$this->programs->share($userId, $p['id'], (int) $entry['connection_id'], $only)) {
            return ['error' => 'Could not share it — has the shared-training migration been run?'];
        }

        return [
            'shared'          => true,
            'program'         => $p['name'],
            'with'            => (string) ($entry['person']['name'] ?? $person),
            'shared_weekdays' => isset($shared) ? $shared : 'all',
            'only_yours'      => $only === [] ? 'none' : $only,
            'to_fix'          => $hint,
            'note'            => 'Say who it is shared with and which weekdays are done together. The Train card now shows both people on shared days. ' . ($hint === [] ? '' : 'Mention what is still to fix (to_fix).'),
        ];
    }
}
