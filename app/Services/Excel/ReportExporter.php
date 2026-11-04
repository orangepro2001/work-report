<?php

namespace App\Services\Excel;

use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Builds the download payload: a workbook plus the RFC 5987 filename.
 *
 * The service is deliberately synchronous and streaming-free on the query side
 * (a daily report is tens of rows, not millions) but writes to a php://temp
 * stream so the response never buffers the whole file in the PHP heap twice.
 */
final class ReportExporter
{
    public function __construct(private readonly WorkbookWriter $writer) {}

    /**
     * @param  array{date?:string, project?:int|null, member?:int|null, status?:string|null}  $filters
     * @return array{0:Spreadsheet,1:string,2:string} [book, filename, ascii-fallback]
     */
    public function build(array $filters = []): array
    {
        $date = CarbonImmutable::parse($filters['date'] ?? 'today', config('workreport.timezone'));

        $issues = $this->issues($filters);
        $members = Member::active()->get(['id', 'name', 'key', 'color', 'position']);
        $projects = Project::visible()->get(['id', 'name', 'abbr', 'color', 'position']);

        $book = $this->writer->build(
            $date,
            config('workreport.department'),
            $this->authorName($filters),
            $issues,
            $members,
            $projects,
        );

        $stamp = $date->format('Ymd');
        $filename = "업무일보_{$stamp}.xlsx";
        $ascii = "work-report_{$stamp}.xlsx";

        return [$book, $filename, $ascii];
    }

    /**
     * Rows in workbook order: bucket order first, then the issue number — which is
     * exactly how a person reads the printed sheet.
     *
     * @param  array<string,mixed>  $filters
     * @return Collection<int,Issue>
     */
    private function issues(array $filters)
    {
        return Issue::query()
            ->forBoard()
            ->with(['reporter:id,name'])
            ->when($filters['project'] ?? null, fn ($q, $id) => $q->where('project_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $key) => $q->where('status_key', $key))
            ->when($filters['member'] ?? null, fn ($q, $id) => $q->forMember((int) $id))
            // Only rows that would actually be printed: a report without text is blank.
            ->whereNotNull('summary')
            ->where('summary', '<>', '')
            ->orderBy(Project::select('position')->whereColumn('projects.id', 'issues.project_id'))
            ->orderBy('issue_no')
            ->get();
    }

    private function authorName(array $filters): string
    {
        if (! empty($filters['member'])) {
            $name = Member::whereKey($filters['member'])->value('name');
            if ($name) {
                return $name;
            }
        }

        return (string) config('workreport.current_member');
    }

    /** Filename for the Xlsx writer to operate on. */
    public function write(Spreadsheet $book, string $path): void
    {
        (new Xlsx($book))->save($path);
    }
}
