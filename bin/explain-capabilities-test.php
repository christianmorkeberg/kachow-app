<?php

declare(strict_types=1);

/**
 * Unit tests for the explain_capabilities tool: it must describe the FULL, current tool set from the
 * live registry, surface the maintained operating rules + areas overview (no separate doc), and narrow
 * to a topic using the same routing the app uses. No DB / network. Run: php bin/explain-capabilities-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Assistant\AssistantLoop;
use App\Tools\ExplainCapabilities;
use App\Tools\Tool;
use App\Tools\ToolRegistry;

$pass = 0; $fail = 0;
function check(string $label, $got, $want): void
{
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label — got " . json_encode($got) . ", want " . json_encode($want) . "\n"; }
}

/** Minimal stand-in tool with a chosen name/description. */
function fakeTool(string $name, string $desc): Tool
{
    return new class ($name, $desc) implements Tool {
        public function __construct(private string $n, private string $d)
        {
        }
        public function name(): string
        {
            return $this->n;
        }
        public function description(): string
        {
            return $this->d;
        }
        public function parameters(): array
        {
            return ['type' => 'object', 'properties' => []];
        }
        public function execute(array $arguments, int $userId): array
        {
            return [];
        }
    };
}

// Real tool names so topic routing (ToolSelector) can narrow by group.
$registry = new ToolRegistry();
$registry->register(fakeTool('get_emails', 'Check the mailbox.'));
$registry->register(fakeTool('log_workout', 'Record a set.'));
$registry->register(new ExplainCapabilities($registry));

$explain = $registry->get('explain_capabilities');

echo "1) full overview lists every registered tool\n";
$r = $explain->execute([], 1);
$names = array_column($r['tools'], 'name');
check('tool_count = 3', $r['tool_count'], 3);
check('includes get_emails', in_array('get_emails', $names, true), true);
check('includes log_workout', in_array('log_workout', $names, true), true);
check('includes itself', in_array('explain_capabilities', $names, true), true);
check('topic = all areas', $r['topic'], 'all areas');
check('topic_matched null when no topic', $r['topic_matched'], null);

echo "2) sources are the live maintained ones (no separate doc)\n";
check('areas_overview is the AssistantLoop const', $r['areas_overview'], AssistantLoop::CAPABILITIES);
check('operating_rules is the AssistantLoop const', $r['operating_rules'], AssistantLoop::DEFAULT_SYSTEM_INSTRUCTION);

echo "3) topic narrows to that area's tools\n";
$r = $explain->execute(['topic' => 'email'], 1);
$names = array_column($r['tools'], 'name');
check('only get_emails for topic email', $names, ['get_emails']);
check('topic_matched true', $r['topic_matched'], true);

$r = $explain->execute(['topic' => 'workout'], 1);
check('only log_workout for topic workout', array_column($r['tools'], 'name'), ['log_workout']);

echo "4) an unknown topic falls back to the full set (never empty)\n";
$r = $explain->execute(['topic' => 'astrophysics'], 1);
check('full set returned', $r['tool_count'], 3);
check('topic_matched false on fallback', $r['topic_matched'], false);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
