<?php

namespace App\Support\Markdown\Tasks;

use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

/**
 * Renders a tasks block the way the board renders work: a progress bar over the
 * whole list, then one row per task with priority, progress and status chips.
 *
 * A row linked to an issue carries `data-issue-id`, so clicking it opens the same
 * drawer the board uses — the document is not a dead copy of the tracker.
 */
final class TasksRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        if (! $node instanceof TasksNode) {
            throw new \InvalidArgumentException('TasksRenderer can only render TasksNode.');
        }

        if ($node->rows === []) {
            return '<p class="muted tiny">작업 목록이 비어 있습니다.</p>';
        }

        $done = count(array_filter($node->rows, fn (array $row) => $row['done']));
        $percent = (int) round($done / count($node->rows) * 100);

        $html = '<div class="tasks">';
        $html .= '<div class="tasks__head">'
            .'<span class="tasks__title">할 일</span>'
            .'<span class="tasks__count">'.$done.' / '.count($node->rows).'</span>'
            .'<span class="tasks__meter"><i style="width:'.$percent.'%"></i></span>'
            .'<span class="tasks__percent">'.$percent.'% 완료</span>'
            .'</div>';

        $html .= '<table class="grid grid--tasks"><thead><tr>'
            .'<th>작업</th><th style="width:96px">우선순위</th><th style="width:88px" class="num">진행</th><th style="width:96px">상태</th>'
            .'</tr></thead><tbody>';

        foreach ($node->rows as $row) {
            $classes = 'row row--'.($row['done'] ? 'done' : 'planned');
            $classes .= $row['late'] ? ' row--late' : '';

            $html .= '<tr class="'.e($classes).'"'
                .($row['issue_id'] ? ' data-issue-id="'.(int) $row['issue_id'].'"' : '')
                .' style="cursor:'.($row['url'] ? 'pointer' : 'default').'"'
                .($row['url'] ? ' x-on:click="$dispatch(\'open-issue\', { url: \''.e($row['url']).'\' })"' : '')
                .'>';

            $html .= '<td class="summary-cell">';
            if ($row['key']) {
                $html .= '<span class="key">'.e($row['key']).'</span> ';
            }
            $html .= e($row['title']).'</td>';

            $html .= '<td><span class="chip chip--'.e($row['priority_tone']).'">'.e($row['priority']).'</span></td>';
            $html .= '<td class="num tnum">'.$row['percent'].'%'
                .'<span class="row__bar" aria-hidden="true"><i style="width:'.$row['percent'].'%"></i></span></td>';
            $html .= '<td><span class="chip chip--'.e($row['status_tone']).'">'.e($row['status']).'</span></td>';

            $html .= '</tr>';
        }

        return $html.'</tbody></table></div>';
    }
}
