<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Routines;
use App\Support\BackgroundRun;

/**
 * Tool: run one of the user's routines NOW — "I'm leaving", "kør min morgenrutine". The user asked, so its conditions do not
 * apply. The run itself is done by the same unattended runner as scheduled tasks (bin/scheduled-tasks-cron.php --requested-only,
 * started in the background here, otherwise by the per-minute cron) and ends with a notification saying what it did.
 */
final class RunRoutine implements Tool
{
    public function __construct(private Routines $routines, private ?string $runnerScript = null)
    {
    }

    public function name(): string
    {
        return 'run_routine';
    }

    public function description(): string
    {
        return 'Runs one of the user\'s routines now. Call it when the user\'s words match a routine\'s PHRASE or name (the routines are listed in '
            . 'your context): "I\'m leaving", "jeg smutter", "run my leaving routine". Do NOT do the routine\'s steps yourself — run it. It starts '
            . 'in the background and ends with a notification saying what it did; tell the user in a few words that it is running (e.g. "On it — '
            . 'lights off and heating down, I\'ll tell you when it\'s done"). Pass `routine` (its name, a phrase, or its id) and `said` (the user\'s '
            . 'words, for the log).';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'routine' => ['type' => 'string', 'description' => 'Name, phrase or id of the routine.'],
                'said'    => ['type' => 'string', 'description' => 'What the user said, e.g. "I\'m leaving".'],
            ],
            'required' => ['routine'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $q = trim((string) ($arguments['routine'] ?? ''));
        $r = $this->routines->find($userId, $q);
        if ($r === null) {
            return ['error' => 'No active routine matches "' . $q . '" (a draft or paused routine does not run — update_routine can save or resume it).'];
        }
        $said = trim((string) ($arguments['said'] ?? $q));
        if (!$this->routines->requestRun($userId, $r['id'], 'you said: ' . mb_substr($said, 0, 120))) {
            return ['started' => false, 'routine' => $r['label'], 'note' => 'It is already waiting to run — it will start within a minute. Say so.'];
        }
        $script = $this->runnerScript ?? dirname(__DIR__, 2) . '/bin/scheduled-tasks-cron.php';
        $kicked = BackgroundRun::kick($script, ['--requested-only']);

        return [
            'started' => true,
            'routine' => $r['label'],
            'does'    => $r['instruction'],
            'note'    => ($kicked ? 'It is running now' : 'It will start within a minute') . ' and ends with a notification saying what it did. Tell the user so in a few words.',
        ];
    }
}
