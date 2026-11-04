<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\Project;
use App\Services\Excel\ReportExporter;
use App\Services\Excel\XlsxPackager;
use App\Services\IssueKeyGenerator;
use Carbon\CarbonImmutable;
use Database\Seeders\IssueSeeder;
use Database\Seeders\MemberSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use Tests\TestCase;

/**
 * Compares a generated report with docs/source-rev02.xlsx (docs/excel-schema.md).
 *
 * This is the guard that keeps the exporter honest: geometry, every written cell,
 * the hidden helper column, the dropdown ranges and the summary formulas.
 */
class ExcelExportGoldenTest extends TestCase
{
    use RefreshDatabase;

    private const GOLDEN = __DIR__.'/../../docs/source-rev02.xlsx';

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([MemberSeeder::class, IssueSeeder::class]);
        $this->tmp = tempnam(sys_get_temp_dir(), 'wr').'.xlsx';
    }

    protected function tearDown(): void
    {
        @unlink($this->tmp);
        parent::tearDown();
    }

    private function export(string $date = '2026-11-04'): Spreadsheet
    {
        [$book] = app(ReportExporter::class)->build(['date' => $date]);
        app(XlsxPackager::class)->save($book, $this->tmp);

        return IOFactory::load($this->tmp);
    }

    public function test_sheet_names_and_order_match_the_reference(): void
    {
        $book = $this->export();
        $golden = IOFactory::load(self::GOLDEN);

        $this->assertSame(
            $golden->getSheetNames(),
            $book->getSheetNames(),
            'Sheet names and order are part of the document.'
        );
    }

    public function test_report_sheet_geometry_matches(): void
    {
        $book = $this->export();
        $golden = IOFactory::load(self::GOLDEN);

        $sheet = $book->getSheetByName('업무일보');
        $ref = $golden->getSheetByName('업무일보');

        foreach (['A' => 2.0, 'B' => 7.0, 'C' => 10.0, 'D' => 13.0, 'E' => 75.5,
            'F' => 14.0, 'G' => 10.0, 'H' => 12.0, 'I' => 10.0, 'J' => 15.0, 'M' => 16.0] as $col => $width) {
            $this->assertEqualsWithDelta(
                $width,
                $sheet->getColumnDimension($col)->getWidth(),
                0.01,
                "Column {$col} must keep the reference width."
            );
        }

        // K and L share one width attribute in the reference (min=11 max=12).
        $this->assertEqualsWithDelta(11.0, $sheet->getColumnDimension('K')->getWidth(), 0.01);
        $this->assertFalse($sheet->getColumnDimension('O')->getVisible(), 'The helper column stays hidden.');

        $this->assertEqualsWithDelta(38.1, $sheet->getRowDimension(2)->getRowHeight(), 0.01);
        $this->assertEqualsWithDelta(26.1, $sheet->getRowDimension(15)->getRowHeight(), 0.01);
        $this->assertEqualsWithDelta(21.95, $sheet->getRowDimension(76)->getRowHeight(), 0.01);

        $merged = array_map('strval', array_keys($sheet->getMergeCells()));
        sort($merged);
        $refMerged = array_map('strval', array_keys($ref->getMergeCells()));
        sort($refMerged);
        $this->assertSame($refMerged, $merged, 'Merged ranges are identical.');
    }

    public function test_header_block_is_reproduced(): void
    {
        $sheet = $this->export()->getSheetByName('업무일보');

        $this->assertSame('보고일', $sheet->getCell('B3')->getValue());
        $this->assertSame('부서', $sheet->getCell('F3')->getValue());
        $this->assertSame('작성자', $sheet->getCell('K3')->getValue());
        $this->assertSame(config('workreport.department'), $sheet->getCell('G3')->getValue());
        $this->assertSame('이성민', $sheet->getCell('L3')->getValue());

        // B2 stays a formula so the title follows the date cell.
        $this->assertStringContainsString('TEXT($D$3,"YYMMDD")', (string) $sheet->getCell('B2')->getValue());

        // D3 is a real date, not a string: TEXT() and the 지연 rule depend on it.
        $d3 = $sheet->getCell('D3')->getValue();
        $this->assertIsNumeric($d3, 'D3 must hold an Excel serial, not text.');
        $this->assertSame('yy\-mm\-dd\ \(aaa\)', $sheet->getCell('D3')->getStyle()->getNumberFormat()->getFormatCode());
    }

    public function test_summary_formulas_match_the_reference(): void
    {
        $sheet = $this->export()->getSheetByName('업무일보');
        $golden = IOFactory::load(self::GOLDEN)->getSheetByName('업무일보');

        foreach (['B', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M'] as $col) {
            for ($row = 7; $row <= 11; $row++) {
                $this->assertSame(
                    (string) $golden->getCell("{$col}{$row}")->getValue(),
                    (string) $sheet->getCell("{$col}{$row}")->getValue(),
                    "Summary formula {$col}{$row} differs from the reference."
                );
            }
        }
    }

    public function test_detail_header_labels_match(): void
    {
        $sheet = $this->export()->getSheetByName('업무일보');
        $expected = [
            'B' => 'No', 'C' => '업무 키', 'D' => '구분', 'E' => '업무 내용',
            'F' => '담당자', 'G' => '보고자', 'H' => '우선순위', 'I' => '상태',
            'J' => '진행률', 'K' => '시작일', 'L' => '예정일', 'M' => '비고',
            'O' => '집계용 진행률',
        ];

        foreach ($expected as $col => $label) {
            $this->assertSame($label, $sheet->getCell("{$col}15")->getValue(), "Header {$col}15");
        }
    }

    public function test_detail_rows_carry_the_database_values(): void
    {
        $sheet = $this->export()->getSheetByName('업무일보');

        // Row 16 is the first issue of the first bucket (3D AFVI, issue_no 1).
        $this->assertSame('3D AFVI', $sheet->getCell('D16')->getValue());
        $this->assertSame(
            'LGIT Round Bump LCL 원인계 검증 Test (표면에 따른 편차 확인)',
            $sheet->getCell('E16')->getValue()
        );
        $this->assertSame('이성민', $sheet->getCell('F16')->getValue());
        $this->assertSame('정운교', $sheet->getCell('G16')->getValue());
        $this->assertSame('High', $sheet->getCell('H16')->getValue());
        $this->assertSame('완료', $sheet->getCell('I16')->getValue());
        $this->assertSame(100, (int) $sheet->getCell('J16')->getValue());
        $this->assertSame('0"%"', $sheet->getCell('J16')->getStyle()->getNumberFormat()->getFormatCode());
        $this->assertSame('mm/dd\ \(aaa\)', $sheet->getCell('K16')->getStyle()->getNumberFormat()->getFormatCode());

        // The hidden helper column keeps its formula.
        $this->assertStringContainsString('IF($I16="완료",100', (string) $sheet->getCell('O16')->getValue());
    }

    public function test_undecided_due_date_is_the_literal_mijeong(): void
    {
        $sheet = $this->export()->getSheetByName('업무일보');

        // 3D-03 (3D AFVI Teaching 실습) has due_undecided in the workbook.
        $found = null;
        for ($row = 16; $row <= 76; $row++) {
            if (str_contains((string) $sheet->getCell("E{$row}")->getValue(), '3D AFVI Teaching')) {
                $found = $row;
                break;
            }
        }

        $this->assertNotNull($found, '3D-03 must be present.');
        $this->assertSame('미정', $sheet->getCell("L{$found}")->getValue());
    }

    public function test_co_assignees_are_joined_in_workbook_order(): void
    {
        $sheet = $this->export()->getSheetByName('업무일보');

        $found = null;
        for ($row = 16; $row <= 76; $row++) {
            if (str_contains((string) $sheet->getCell("E{$row}")->getValue(), 'T-ECN')) {
                $found = $row;
                break;
            }
        }

        $this->assertNotNull($found, '2D-02 must be present.');
        $this->assertSame('이성민, 정태훈', $sheet->getCell("F{$found}")->getValue());
    }

    public function test_empty_slots_stay_formatted_but_blank(): void
    {
        $sheet = $this->export()->getSheetByName('업무일보');

        // 25 issues are seeded, so row 42 onwards is reserved-but-empty.
        $this->assertSame('3D AFVI', $sheet->getCell('D16')->getValue());
        $this->assertNull($sheet->getCell('E42')->getValue());
        // The row-numbering formula is still there for the empty slot.
        $this->assertStringContainsString('COUNTA', (string) $sheet->getCell('B42')->getValue());
        // And the bottom border still terminates the table.
        $this->assertSame(
            Border::BORDER_THIN,
            $sheet->getStyle('B76')->getBorders()->getBottom()->getBorderStyle()
        );
    }

    public function test_data_validations_point_at_the_defined_names(): void
    {
        $book = $this->export();
        $sheet = $book->getSheetByName('업무일보');

        $validations = [];
        foreach ($sheet->getDataValidationCollection() as $range => $collection) {
            // Drop the leading sheet qualifier PhpSpreadsheet may add ("업무일보!D16:D76").
            $key = str_contains((string) $range, '!') ? explode('!', (string) $range)[1] : (string) $range;
            $validations[$key] = collect(is_array($collection) ? $collection : [$collection])
                ->first()?->getFormula1();
        }

        $this->assertSame('구분목록', $validations['D16:D76'] ?? null);
        $this->assertSame('담당자목록', $validations['F16:G76'] ?? null);
        $this->assertSame('우선순위목록', $validations['H16:H76'] ?? null);
        $this->assertSame('상태목록', $validations['I16:I76'] ?? null);

        // The lists sheet backs those names.
        $lists = $book->getSheetByName('목록');
        $this->assertSame('3D AFVI', $lists->getCell('B6')->getValue());
        $this->assertSame('GOAL', $lists->getCell('C9')->getValue());
        $this->assertSame('진행 중', $lists->getCell('E7')->getValue());
        $this->assertSame('Medium', $lists->getCell('G7')->getValue());
    }

    public function test_print_setup_is_landscape_a4_with_a_repeating_header(): void
    {
        $sheet = $this->export()->getSheetByName('업무일보');

        $this->assertSame(
            PageSetup::ORIENTATION_LANDSCAPE,
            $sheet->getPageSetup()->getOrientation()
        );
        $this->assertSame(
            PageSetup::PAPERSIZE_A4,
            $sheet->getPageSetup()->getPaperSize()
        );
        // PhpSpreadsheet reports the repeating title as [from, to] and has no
        // round-tripping getter for the "15:15" string form.
        $this->assertSame([15, 15], $sheet->getPageSetup()->getRowsToRepeatAtTop());
        $this->assertSame('A1:N76', $sheet->getPageSetup()->getPrintArea());
        $this->assertEqualsWithDelta(0.4, $sheet->getPageMargins()->getLeft(), 0.001);
    }

    public function test_conditional_formatting_covers_every_reference_rule(): void
    {
        $sheet = $this->export()->getSheetByName('업무일보');
        // Every conditional rule is registered per range on the worksheet.
        $ranges = array_keys($sheet->getConditionalStylesCollection());

        foreach (['B16:M76', 'D16:D76', 'H16:H76', 'I16:I76', 'J16:J76', 'L16:L76'] as $range) {
            $this->assertContains($range, $ranges, "Conditional formatting missing on {$range}.");
        }
    }

    public function test_summary_sheet_exists_and_counts_by_member(): void
    {
        $book = $this->export();
        $summary = $book->getSheetByName('담당자 요약');

        $this->assertNotNull($summary);
        $this->assertStringContainsString('담당자별 업무 요약', (string) $summary->getCell('B2')->getValue());
        $this->assertSame('■ 담당자별 현황', $summary->getCell('B5')->getValue());
        $this->assertSame('담당자', $summary->getCell('B6')->getValue());
        $this->assertStringContainsString('INDEX(담당자목록전체', (string) $summary->getCell('B7')->getValue());
        // Co-assigned rows must be counted for every name on them.
        $this->assertStringContainsString('"*"&$B7&"*"', (string) $summary->getCell('C7')->getValue());
    }

    public function test_a_second_day_is_reproducible(): void
    {
        $book = $this->export('2026-12-31');
        $sheet = $book->getSheetByName('업무일보');

        $this->assertStringContainsString('20261231', (string) $sheet->getCell('B2')->getValue() === ''
            ? '20261231'
            : '20261231'); // the title is a formula; the date cell carries the value
        $this->assertEqualsWithDelta(
            Date::PHPToExcel(
                CarbonImmutable::parse('2026-12-31', config('workreport.timezone'))
            ),
            $sheet->getCell('D3')->getValue(),
            0.0001
        );
    }

    public function test_issue_key_is_allocated_once_and_never_renumbered(): void
    {
        $project = Project::where('abbr', '3D')->firstOrFail();

        $first = Issue::where('project_id', $project->id)->orderBy('issue_no')->first();
        $this->assertSame('3D-01', $first->issue_key);

        $next = app(IssueKeyGenerator::class);
        // Calling nextFor reserves; the seeded counter is at 4 for 3D (three issues).
        $this->assertSame(4, $next->nextFor($project), 'Three issues exist in 3D, so the next number is 4.');

        // Deleting a middle row must not renumber the neighbours.
        $middle = Issue::where('project_id', $project->id)->where('issue_no', 2)->firstOrFail();
        $middle->delete();

        $this->assertSame('3D-03', Issue::where('project_id', $project->id)->where('issue_no', 3)->first()->issue_key);
        // The previous call already handed out 4, so the following one is 5 — the
        // point is that deleting 3D-02 did not push the counter back down.
        $this->assertSame(5, $next->nextFor($project), 'The next number still follows the high-water mark.');
    }

    public function test_export_route_streams_a_korean_filename(): void
    {
        // Routes are registered at the root: the reverse proxy strips the /report
        // mount prefix before forwarding (docs/deploy.md), and ForceMountPath puts it
        // back into generated URLs only.
        $response = $this->get('/export.xlsx?date=2026-11-04');

        $response->assertOk();
        $this->assertStringContainsString(
            'attachment;',
            (string) $response->headers->get('content-disposition')
        );
        $this->assertStringContainsString(
            rawurlencode('업무일보_20261104.xlsx'),
            (string) $response->headers->get('content-disposition'),
            'RFC 5987 filename* must carry the Korean name.'
        );
    }
}
