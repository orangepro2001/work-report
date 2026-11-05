<?php

namespace App\Support\Markdown\Tasks;

use League\CommonMark\Node\Block\AbstractBlock;

/**
 * A `tasks` fenced block, resolved into rows (app/Support/Markdown/Tasks).
 *
 * The rows are resolved *before* rendering: the processor already had to touch the
 * database to turn `DMT-373` into an issue, so the renderer stays a pure function of
 * the node.
 */
final class TasksNode extends AbstractBlock
{
    /**
     * @param  list<array{title: string, key: ?string, url: ?string, issue_id: ?int, priority: string, priority_tone: string, percent: int, status: string, status_tone: string, done: bool, late: bool}>  $rows
     */
    public function __construct(public readonly array $rows) {}
}
