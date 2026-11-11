<?php

declare(strict_types=1);

namespace App\Tools;

use App\Assistant\GeminiClient;

/**
 * Tool: work something out with real code — dates, sun times, unit and money maths, statistics over data the user pasted or a tool returned — instead of guessing.
 * It runs on Gemini's built-in Python sandbox, on its OWN model call (like WebSearch): no network, no access to the user's data except what is passed in `data`,
 * nothing kept between calls, and it can only COMPUTE — it never acts. What the sandbox ran (the Python and its output) comes back so the answer can show its work.
 */
final class RunPython implements Tool
{
    private const SYSTEM =
        'You solve a calculation task by writing and running Python. Always run code for the numbers — never work them out in your head. Use only what is in the task '
        . 'and the data; if something needed is missing, say what is missing instead of assuming. Print the result clearly with units. Keep it short; reply in the '
        . 'language of the task. Today is %s.';

    private const MAX_DATA = 20000;

    public function __construct(private GeminiClient $gemini)
    {
    }

    public function name(): string
    {
        return 'run_python';
    }

    public function description(): string
    {
        return 'Works something out with real Python code in a sandbox and returns the result plus the code that produced it. Use it for calculation you would otherwise guess or do in '
            . 'your head: dates and durations, sunrise/sunset or other astronomy, unit conversions, percentages and money maths, statistics or sorting over a list of values, '
            . 'parsing messy text. Pass a complete, self-contained `task` and, if it needs numbers or text, put them in `data` (it has NO access to the user\'s data, the web or their '
            . 'devices). It only computes: it cannot change anything, so use the normal tools to act on the result. Mention that the figure was computed, and offer the code if '
            . 'they ask how. Call it ONLY when the user has asked for something to be calculated — never because they merely mention a topic ("I made a routine with sunset"), and never to double-check a figure already computed in this conversation. Do NOT use it for things a normal tool already does exactly (training maths, bookkeeping totals, routine conditions such as sunset).';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'task' => ['type' => 'string', 'description' => 'What to work out, in full (include place, dates, units, the exact question).'],
                'data' => ['type' => 'string', 'description' => 'Optional numbers or text the task needs (e.g. a list of values, a table), at most ~20,000 characters.'],
            ],
            'required' => ['task'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $task = trim((string) ($arguments['task'] ?? ''));
        if ($task === '') {
            return ['error' => 'What should I work out?'];
        }
        $data = trim((string) ($arguments['data'] ?? ''));
        if (mb_strlen($data) > self::MAX_DATA) {
            return ['error' => 'There is too much data for one calculation (limit ' . self::MAX_DATA . ' characters) — use a summary or a part of it.'];
        }
        $prompt = $task . ($data !== '' ? "\n\nDATA:\n" . $data : '');

        try {
            $models   = $this->gemini->models();
            $response = $this->gemini->generate(
                [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                [],
                sprintf(self::SYSTEM, gmdate('Y-m-d')),
                $models[0] ?? null,
                null,
                40000,
                null,
                false,
                true,
                'run_python',
            );
        } catch (\Throwable $e) {
            error_log('run_python: ' . $e->getMessage());

            return ['error' => 'The calculation did not run right now. Say so plainly; do not give a figure as if it had been computed.'];
        }

        $runs   = GeminiClient::extractCodeRuns($response);
        $answer = trim(GeminiClient::extractText($response));
        if ($runs === []) {
            return ['error' => 'No code was run, so there is no computed result. Say so; do not present a guess as a calculation.', 'unverified_text' => mb_substr($answer, 0, 500)];
        }
        $last = $runs[count($runs) - 1];
        if ($last['outcome'] !== '' && $last['outcome'] !== 'OUTCOME_OK') {
            return ['error' => 'The code failed (' . $last['outcome'] . '). Say the calculation did not work; do not guess the figure.', 'code' => mb_substr($last['code'], 0, 3000), 'output' => mb_substr($last['output'], 0, 1000)];
        }

        return [
            'computed' => true,
            'result'   => mb_substr($last['output'] !== '' ? trim($last['output']) : $answer, 0, 4000),
            'summary'  => mb_substr($answer, 0, 1500),
            'code'     => mb_substr($last['code'], 0, 4000),
            'note'     => 'This was computed by Python in a sandbox from what was passed in. State the answer plainly and say it was calculated; check that it is plausible (units, time zone, date) before relying on it.',
        ];
    }
}
