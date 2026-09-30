<?php

declare(strict_types=1);

namespace App\Tools;

use App\Assistant\AssistantLoop;

/**
 * Tool: a thorough, on-demand reference to everything the assistant can do, for tough or
 * specific meta questions ("can you handle EU VAT?", "how does the mileage feature decide
 * business vs commute?", "what exactly triggers the 9am work-from-home prompt?").
 *
 * By default the app routes a keyword-narrowed subset of tools each turn (ToolSelector), so for
 * a hard capability question the model is often answering blind about tools that weren't routed in.
 * This hands it the COMPLETE, current picture on request — assembled live from the two sources that
 * are already maintained because the running app depends on them:
 *   1. the tool registry (every tool's real name + description), and
 *   2. AssistantLoop's operating rules (the authoritative "how each feature behaves").
 * Nothing here is a separately-maintained doc, so it can never drift out of sync.
 *
 * Optional `topic` narrows the tool list to one area, reusing the same routing the app uses.
 */
final class ExplainCapabilities implements Tool
{
    public function __construct(private ToolRegistry $registry)
    {
    }

    public function name(): string
    {
        return 'explain_capabilities';
    }

    public function description(): string
    {
        return 'Returns a COMPLETE, current reference to what this assistant can do — the full list of '
            . 'tools with their real descriptions, plus the operating rules that govern how each feature '
            . 'behaves — so you can answer thorough or tricky questions about your own capabilities '
            . 'accurately instead of guessing. Call this whenever the user asks what you can do, whether '
            . 'you can do something specific, how a particular feature works, or any meta question about '
            . 'the assistant/app itself (English OR Danish — "hvad kan du", "kan du …", "hvordan virker …", '
            . '"hvilke funktioner"). Optional topic narrows the tool list to one area (e.g. "mileage", '
            . '"email", "work hours", "bookkeeping", "calendar", "location"). Use the result to explain the '
            . 'parts relevant to the question in the user\'s own language — do not dump it raw or quote the '
            . 'internal rules verbatim.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'topic' => [
                    'type'        => 'string',
                    'description' => 'Optional area to focus on (e.g. "mileage", "email", "work hours", '
                        . '"bookkeeping", "calendar", "location", "workouts"). Omit for a full overview.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $topic        = trim((string) ($arguments['topic'] ?? ''));
        $declarations = $this->registry->declarations();

        // Narrow to the topic's area using the very router the app uses (keeps this in step with
        // routing). ToolSelector::select falls back to ALL when a topic matches nothing, so a
        // vague topic still returns a useful, complete set rather than an empty one.
        $matched  = false;
        $relevant = $declarations;
        if ($topic !== '') {
            $relevant = ToolSelector::select($declarations, $topic);
            $matched  = count($relevant) < count($declarations);
        }

        $tools = [];
        foreach ($relevant as $d) {
            $tools[] = [
                'name'        => (string) ($d['name'] ?? ''),
                'description' => (string) ($d['description'] ?? ''),
            ];
        }
        // Stable, readable order.
        usort($tools, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return [
            'topic'           => $topic !== '' ? $topic : 'all areas',
            'topic_matched'   => $topic === '' ? null : $matched,
            'areas_overview'  => AssistantLoop::CAPABILITIES,
            'tool_count'      => count($tools),
            'tools'           => $tools,
            'operating_rules' => AssistantLoop::DEFAULT_SYSTEM_INSTRUCTION,
            'note'            => 'This is assembled live from the assistant\'s actual tools and operating '
                . 'rules — it is exhaustive and current. Answer the user\'s specific question from it, in '
                . 'their own language; explain in plain terms, do not paste this raw or quote the internal '
                . 'rules/markers verbatim. If a topic was given but nothing matched (topic_matched=false), '
                . 'the full set is returned as a fallback.',
        ];
    }
}
