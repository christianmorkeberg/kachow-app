<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Memories;
use App\Data\TrainingPrograms;
use App\Data\UserInstructions;
use App\Support\ProgramInput;

/**
 * Tool: the migration path — finds a training programme the user once gave you as a note (a remembered fact or a standing instruction), in full,
 * so it can be turned into a real programme. The note is returned verbatim; reading it and saving it is the assistant's job.
 */
final class FindProgramInMemory implements Tool
{
    private const MAX_CHARS = 20000;

    public function __construct(private Memories $memories, private UserInstructions $instructions, private TrainingPrograms $programs)
    {
    }

    public function name(): string
    {
        return 'find_program_in_memory';
    }

    public function description(): string
    {
        return 'Looks through what you remember about the user (facts and standing instructions) for a TRAINING PROGRAMME they gave you earlier as a note, and returns each '
            . 'candidate in full with its id. Use when they ask about their programme / "uge 43" and none is saved (get_training_program is empty), or when they ask to '
            . 'move their old programme note into the new programme. To migrate: read the note, save_training_program (source_note = the note), add_program_days a week at '
            . 'a time keeping the exact numbers, show it with get_training_program, and ask the user to check it. ONLY when they confirm it is right, offer to remove the old '
            . 'note (forget_about_me for a fact, forget_instruction for an instruction) — never remove it before.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function execute(array $arguments, int $userId): array
    {
        $found = [];
        foreach ($this->memories->all($userId) as $m) {
            if (ProgramInput::looksLikeProgramme((string) $m['content'])) {
                $found[] = ['source' => 'fact', 'id' => (int) $m['id'], 'forget_with' => 'forget_about_me', 'text' => mb_substr((string) $m['content'], 0, self::MAX_CHARS), 'cut' => mb_strlen((string) $m['content']) > self::MAX_CHARS];
            }
        }
        foreach ($this->instructions->all($userId) as $i) {
            if (ProgramInput::looksLikeProgramme((string) $i['instruction'])) {
                $found[] = ['source' => 'instruction', 'id' => (int) $i['id'], 'forget_with' => 'forget_instruction', 'text' => mb_substr((string) $i['instruction'], 0, self::MAX_CHARS), 'cut' => mb_strlen((string) $i['instruction']) > self::MAX_CHARS];
            }
        }
        $saved = array_map(static fn (array $p): string => $p['name'] . ' (' . $p['days'] . ' days)', $this->programs->all($userId));

        return [
            'candidates' => $found,
            'already_saved_programmes' => $saved,
            'note' => $found === [] ? 'Nothing that looks like a training programme is remembered. Ask the user to paste it.'
                : 'These are notes from earlier. Read them, then build the programme as described. If a note is cut, ask the user to paste the rest.',
        ];
    }
}
