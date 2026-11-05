<?php

namespace App\Support\Markdown\Tasks;

use App\Models\Issue;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;

/**
 * Turns a ```` ```tasks ```` block into rows.
 *
 * One row per line. A line that is just an issue key (`3D-03`) is resolved from the
 * board, so the document shows the real title, priority, progress and status and
 * keeps showing them after the issue moves — a hand-copied table would be wrong by
 * tomorrow. Anything else is a free-standing task:
 *
 *     ```tasks
 *     3D-03                       ← live from the board
 *     Write the SOP | high | 40 | in_progress
 *     ```
 *
 * Fields after the first `|`: priority key, percent, status key — all optional.
 */
final class TasksProcessor
{
    private const KEY = '/^([A-Za-z0-9]{1,8})-(\d{1,5})$/';

    public function __invoke(DocumentParsedEvent $event): void
    {
        $blocks = [];
        $walker = $event->getDocument()->walker();

        while ($node = $walker->next()) {
            if ($node->isEntering() && $node->getNode() instanceof FencedCode
                && strtolower(trim((string) $node->getNode()->getInfo())) === 'tasks') {
                $blocks[] = $node->getNode();
            }
        }

        foreach ($blocks as $block) {
            $block->replaceWith(new TasksNode($this->rows((string) $block->getLiteral())));
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function rows(string $literal): array
    {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\R/', $literal) ?: []),
            fn (string $line) => $line !== '',
        ));

        if ($lines === []) {
            return [];
        }

        $issues = $this->resolveIssues($lines);
        $rows = [];

        foreach ($lines as $line) {
            $parts = array_map('trim', explode('|', $line));
            $title = array_shift($parts) ?? '';

            if (isset($issues[$title])) {
                $rows[] = $this->issueRow($issues[$title]);

                continue;
            }

            $rows[] = $this->plainRow($title, $parts);
        }

        return $rows;
    }

    /**
     * One query for every key in the block.
     *
     * @param  list<string>  $lines
     * @return array<string,Issue>
     */
    private function resolveIssues(array $lines): array
    {
        $wanted = [];

        foreach ($lines as $line) {
            if (preg_match(self::KEY, trim(explode('|', $line)[0])) === 1) {
                $wanted[] = strtoupper(trim(explode('|', $line)[0]));
            }
        }

        if ($wanted === []) {
            return [];
        }

        return Issue::query()
            ->with(['project:id,abbr,name,color', 'assignee:id,name,color'])
            ->get()
            ->filter(fn (Issue $issue) => in_array(strtoupper($issue->issue_key), $wanted, true))
            ->keyBy(fn (Issue $issue) => $issue->issue_key)
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function issueRow(Issue $issue): array
    {
        $status = collect(config('workreport.statuses'))->firstWhere('key', $issue->status_key);
        $priority = collect(config('workreport.priorities'))->firstWhere('key', $issue->priority_key);

        return [
            'title' => (string) $issue->summary,
            'key' => $issue->issue_key,
            'url' => route('issues.show', $issue, absolute: false),
            'issue_id' => $issue->id,
            'priority' => (string) ($priority['label'] ?? $issue->priority_key),
            'priority_tone' => (string) ($priority['tone'] ?? 'slate'),
            'percent' => $issue->effectivePercent(),
            'status' => (string) ($status['label'] ?? $issue->status_key),
            'status_tone' => (string) ($status['tone'] ?? 'slate'),
            'done' => $issue->status_key === 'done',
            'late' => $issue->isLateOn(now()),
        ];
    }

    /**
     * @param  list<string>  $parts
     * @return array<string,mixed>
     */
    private function plainRow(string $title, array $parts): array
    {
        $priorityKey = strtolower($parts[0] ?? '');
        $priority = collect(config('workreport.priorities'))->firstWhere('key', $priorityKey);

        $percent = isset($parts[1]) && is_numeric($parts[1]) ? max(0, min(100, (int) $parts[1])) : 0;

        $statusKey = strtolower($parts[2] ?? '');
        $status = collect(config('workreport.statuses'))->firstWhere('key', $statusKey);

        return [
            'title' => $title,
            'key' => null,
            'url' => null,
            'issue_id' => null,
            'priority' => (string) ($priority['label'] ?? ($parts[0] ?? '—')),
            'priority_tone' => (string) ($priority['tone'] ?? 'slate'),
            'percent' => $percent,
            'status' => (string) ($status['label'] ?? ($parts[2] ?? '예정')),
            'status_tone' => (string) ($status['tone'] ?? 'slate'),
            'done' => ($status['key'] ?? null) === 'done',
            'late' => false,
        ];
    }
}
