<?php

namespace App\Services\Excel;

use App\Models\Issue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\ConditionalFormatting\ConditionalDataBar;
use PhpOffice\PhpSpreadsheet\Style\ConditionalFormatting\ConditionalFormatValueObject;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Style;
use PhpOffice\PhpSpreadsheet\Workbook;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Writes sheet 업무일보 exactly as docs/source-rev02.xlsx defines it.
 *
 * The class is split into small private steps so the golden test can point at the
 * failing block: geometry, header, summary, detail, print setup. Anything that
 * carries a literal colour or a formula lives here and nowhere else.
 */
final class ReportSheetWriter
{
    // Theme palette, sampled from the reference workbook.
    public const NAVY = '1F3864';

    public const BLUE = '2F5597';

    public const HAIR = 'BFC9D9';

    public const INK = '262626';

    public const MUTED = '8C8C8C';

    public const FAINT = '595959';

    public const CREAM = 'FFF8E1';

    public const ZEBRA = 'F3F6FB';

    public const TOTALFILL = 'D9E1F2';

    private const HEADER_ROW = 15;

    private const FIRST_DATA_ROW = 16;

    private const LAST_DATA_ROW = 76;

    private const TOTAL_ROW = 11;

    /** @var array<int,string> summary bucket labels in row order */
    private array $buckets = [];

    private CarbonImmutable $reportDate;

    private string $department = '';

    private string $authorName = '';

    private string $titlePrefix = '';

    /**
     * @param  Collection<int,Issue>  $issues  already sorted for display
     */
    public function write(
        Worksheet $sheet,
        $issues,
        $members,
        $projects,
        CarbonImmutable $reportDate,
        string $department,
        string $authorName,
    ): void {
        $this->reportDate = $reportDate;
        $this->department = $department;
        $this->authorName = $authorName;
        $this->titlePrefix = (string) config('workreport.title_prefix');
        $this->buckets = array_column(config('workreport.summary_buckets'), 'label');

        $sheet->setTitle(config('workreport.excel.sheet_report', '업무일보'));

        $this->applyGeometry($sheet);
        $this->writeTitle($sheet);
        $this->writeSummary($sheet);
        $this->writeDetailHeader($sheet);
        $this->writeDetailRows($sheet, $issues);
        $this->writeConditionalFormatting($sheet, $issues);
        $this->writeDropdowns($sheet);
        $this->applyPrintSetup($sheet);
    }

    /* ------------------------------------------------------------------ */
    /* §2.1 geometry */
    /* ------------------------------------------------------------------ */

    private function applyGeometry(Worksheet $sheet): void
    {
        $widths = [
            'A' => 2.0, 'B' => 7.0, 'C' => 10.0, 'D' => 13.0, 'E' => 75.5,
            'F' => 14.0, 'G' => 10.0, 'H' => 12.0, 'I' => 10.0, 'J' => 15.0,
            // K and L share one width in the original (<col min=11 max=12 width=11>).
            'K' => 11.0, 'L' => 11.0, 'M' => 16.0, 'N' => 2.0, 'O' => 0.0,
        ];

        foreach ($widths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
            if ($col === 'O') {
                $sheet->getColumnDimension($col)->setVisible(false);
            }
        }

        $heights = [
            1 => 8.1, 2 => 38.1, 3 => 24.0, 4 => 9.95, 5 => 21.95, 6 => 21.95,
            7 => 21.0, 8 => 21.0, 9 => 21.0, 10 => 21.0, 11 => 21.0, 12 => 14.1,
            14 => 21.95, 15 => 26.1,
        ];

        foreach ($heights as $row => $height) {
            $sheet->getRowDimension($row)->setRowHeight($height);
        }

        for ($row = self::FIRST_DATA_ROW; $row <= self::LAST_DATA_ROW; $row++) {
            $sheet->getRowDimension($row)->setRowHeight(21.95);
        }

        // Gridlines off, zoom 85 % — the sheet is printed, not browsed.
        $sheet->setShowGridlines(false);
        $sheet->getSheetView()->setZoomScale(85);

        foreach (['B2:M2', 'B3:C3', 'G3:H3', 'L3:M3'] as $range) {
            $sheet->mergeCells($range);
        }

        for ($row = 6; $row <= 11; $row++) {
            $sheet->mergeCells("B{$row}:D{$row}");
        }
    }

    /* ------------------------------------------------------------------ */
    /* §2.2 header */
    /* ------------------------------------------------------------------ */

    private function writeTitle(Worksheet $sheet): void
    {
        // B2 is a live formula so the title follows D3 when a user edits the date.
        $sheet->setCellValue('B2', '="'.$this->titlePrefix.' / "&TEXT($D$3,"YYMMDD")');
        $sheet->getStyle('B2')->applyFromArray([
            'font' => ['name' => '맑은 고딕', 'size' => 20, 'bold' => true, 'color' => ['rgb' => self::NAVY]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => self::NAVY]]],
        ]);

        $label = static function (string $cell, string $text) use ($sheet) {
            $sheet->setCellValue($cell, $text);
            $sheet->getStyle($cell)->applyFromArray([
                'font' => ['name' => '맑은 고딕', 'size' => 10, 'bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::BLUE]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            ]);
        };

        $value = static function (string $cell, string $text) use ($sheet) {
            $sheet->setCellValue($cell, $text);
            $sheet->getStyle($cell)->applyFromArray([
                'font' => ['name' => '맑은 고딕', 'size' => 10, 'bold' => true, 'color' => ['rgb' => self::NAVY]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            ]);
            self::box($sheet->getStyle($cell));
        };

        $label('B3', '보고일');
        $label('F3', '부서');
        $label('K3', '작성자');

        // D3: a real date, because TEXT($D$3,"YYMMDD") in B2 and the 지연 rule read it.
        $sheet->setCellValue('D3', Date::PHPToExcel($this->reportDate));
        $sheet->getStyle('D3')->applyFromArray([
            'font' => ['name' => '맑은 고딕', 'size' => 10, 'bold' => true, 'color' => ['rgb' => self::NAVY]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::CREAM]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'numberFormat' => ['formatCode' => 'yy\-mm\-dd\ \(aaa\)'],
        ]);
        self::box($sheet->getStyle('D3'));

        $value('G3', $this->department);
        $value('L3', $this->authorName);
    }

    /* ------------------------------------------------------------------ */
    /* §2.3 summary block */
    /* ------------------------------------------------------------------ */

    private function writeSummary(Worksheet $sheet): void
    {
        $sheet->setCellValue('B5', '■ 업무 현황 요약');
        $sheet->getStyle('B5')->applyFromArray([
            'font' => ['name' => '맑은 고딕', 'size' => 12, 'bold' => true, 'color' => ['rgb' => self::NAVY]],
        ]);

        $headers = ['B' => '구분', 'E' => '완료율', 'F' => '전체', 'G' => '예정',
            'H' => '진행 중', 'I' => '완료', 'J' => '평균 진행률', 'K' => '보류',
            'L' => '지연', 'M' => 'High 우선'];

        foreach ($headers as $col => $text) {
            $sheet->setCellValue("{$col}6", $text);
            $sheet->getStyle("{$col}6")->applyFromArray([
                'font' => ['name' => '맑은 고딕', 'size' => 10, 'bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::NAVY]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                'borders' => [
                    'left' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']],
                    'right' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']],
                ],
            ]);
        }

        $first = config('workreport.excel.summary_first_row', 7);
        $last = config('workreport.excel.summary_last_row', 10);
        $d0 = self::FIRST_DATA_ROW;
        $d1 = self::LAST_DATA_ROW;
        $noDash = '0;\-0;"-"';

        foreach ($this->buckets as $i => $bucket) {
            $r = $first + $i;
            $sheet->setCellValue("B{$r}", $bucket);

            $sheet->setCellValue("E{$r}", "=IFERROR(I{$r}/F{$r},0)");
            $sheet->setCellValue("F{$r}", "=COUNTIFS(\$D\${$d0}:\$D\${$d1},\$B{$r},\$E\${$d0}:\$E\${$d1},\"<>\")");
            $sheet->setCellValue("G{$r}", "=COUNTIFS(\$D\${$d0}:\$D\${$d1},\$B{$r},\$E\${$d0}:\$E\${$d1},\"<>\",\$I\${$d0}:\$I\${$d1},\"예정\")");
            $sheet->setCellValue("H{$r}", "=COUNTIFS(\$D\${$d0}:\$D\${$d1},\$B{$r},\$E\${$d0}:\$E\${$d1},\"<>\",\$I\${$d0}:\$I\${$d1},\"진행 중\")");
            $sheet->setCellValue("I{$r}", "=COUNTIFS(\$D\${$d0}:\$D\${$d1},\$B{$r},\$E\${$d0}:\$E\${$d1},\"<>\",\$I\${$d0}:\$I\${$d1},\"완료\")");
            $sheet->setCellValue("J{$r}", "=IFERROR(AVERAGEIFS(\$O\${$d0}:\$O\${$d1},\$D\${$d0}:\$D\${$d1},\$B{$r},\$E\${$d0}:\$E\${$d1},\"<>\"),0)");
            $sheet->setCellValue("K{$r}", "=COUNTIFS(\$D\${$d0}:\$D\${$d1},\$B{$r},\$E\${$d0}:\$E\${$d1},\"<>\",\$I\${$d0}:\$I\${$d1},\"보류\")");
            $sheet->setCellValue("L{$r}", "=COUNTIFS(\$D\${$d0}:\$D\${$d1},\$B{$r},\$E\${$d0}:\$E\${$d1},\"<>\",\$I\${$d0}:\$I\${$d1},\"<>완료\",\$L\${$d0}:\$L\${$d1},\"<\"&\$D\$3)");
            $sheet->setCellValue("M{$r}", "=COUNTIFS(\$D\${$d0}:\$D\${$d1},\$B{$r},\$E\${$d0}:\$E\${$d1},\"<>\",\$H\${$d0}:\$H\${$d1},\"High\",\$I\${$d0}:\$I\${$d1},\"<>완료\")");

            $this->styleSummaryRow($sheet, $r, bold: false);
            $sheet->getStyle("E{$r}")->getNumberFormat()->setFormatCode('0%');
            $sheet->getStyle("J{$r}")->getNumberFormat()->setFormatCode('0"%"');
            foreach (['G', 'K', 'L', 'M'] as $col) {
                $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode($noDash);
            }
        }

        // 합계 row.
        $r = config('workreport.excel.total_row', 11);
        $sheet->setCellValue("B{$r}", '합계');
        $sheet->setCellValue("E{$r}", "=IFERROR(I{$r}/F{$r},0)");
        $sheet->setCellValue("F{$r}", "=SUM(F{$first}:F{$last})");
        $sheet->setCellValue("G{$r}", "=SUM(G{$first}:G{$last})");
        $sheet->setCellValue("H{$r}", "=SUM(H{$first}:H{$last})");
        $sheet->setCellValue("I{$r}", "=SUM(I{$first}:I{$last})");
        $sheet->setCellValue("J{$r}", "=IFERROR(AVERAGE(\$O\${$d0}:\$O\${$d1}),0)");
        $sheet->setCellValue("K{$r}", "=SUM(K{$first}:K{$last})");
        $sheet->setCellValue("L{$r}", "=SUM(L{$first}:L{$last})");
        $sheet->setCellValue("M{$r}", "=SUM(M{$first}:M{$last})");

        $this->styleSummaryRow($sheet, $r, bold: true, total: true);
        $sheet->getStyle("E{$r}")->getNumberFormat()->setFormatCode('0%');
        $sheet->getStyle("J{$r}")->getNumberFormat()->setFormatCode('0"%"');
        foreach (['G', 'K', 'L', 'M'] as $col) {
            $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode($noDash);
        }
    }

    private function styleSummaryRow(Worksheet $sheet, int $row, bool $bold, bool $total = false): void
    {
        $style = $sheet->getStyle("B{$row}:M{$row}");
        $style->applyFromArray([
            'font' => [
                'name' => '맑은 고딕', 'size' => 10, 'bold' => $bold || $total,
                'color' => ['rgb' => $total ? self::BLUE : self::NAVY],
            ],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);

        if ($total) {
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::TOTALFILL);
            $style->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::NAVY);
            $style->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB(self::NAVY);
        } else {
            $sheet->getStyle("B{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDEBF7');
            $sheet->getStyle("B{$row}")->getFont()->setBold(true);
            $style->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::HAIR);
        }
    }

    /* ------------------------------------------------------------------ */
    /* §2.4 detail block */
    /* ------------------------------------------------------------------ */

    private function writeDetailHeader(Worksheet $sheet): void
    {
        $sheet->setCellValue('B14', '■ 업무 목록');
        $sheet->getStyle('B14')->applyFromArray([
            'font' => ['name' => '맑은 고딕', 'size' => 12, 'bold' => true, 'color' => ['rgb' => self::NAVY]],
        ]);

        $headers = [
            'B' => 'No', 'C' => '업무 키', 'D' => '구분', 'E' => '업무 내용',
            'F' => '담당자', 'G' => '보고자', 'H' => '우선순위', 'I' => '상태',
            'J' => '진행률', 'K' => '시작일', 'L' => '예정일', 'M' => '비고',
            'O' => '집계용 진행률',
        ];

        $r = self::HEADER_ROW;
        foreach ($headers as $col => $text) {
            $sheet->setCellValue("{$col}{$r}", $text);
            if ($col === 'O') {
                continue; // helper column keeps the document default style
            }
            $sheet->getStyle("{$col}{$r}")->applyFromArray([
                'font' => ['name' => '맑은 고딕', 'size' => 10, 'bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::NAVY]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                'borders' => [
                    'left' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']],
                    'right' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']],
                ],
            ]);
        }
    }

    /**
     * @param  Collection<int,Issue>  $issues
     */
    private function writeDetailRows(Worksheet $sheet, $issues): void
    {
        $rows = $issues->values();
        $lastWritten = self::FIRST_DATA_ROW - 1;
        $statusLabels = collect(config('workreport.statuses'))->keyBy('key');
        $priorityLabels = collect(config('workreport.priorities'))->keyBy('key');

        foreach ($rows as $i => $issue) {
            $r = self::FIRST_DATA_ROW + $i;
            if ($r > self::LAST_DATA_ROW) {
                break; // the template has exactly 61 slots; extra rows belong on page 2+
            }

            $sheet->setCellValue("B{$r}", "=IF(E{$r}=\"\",\"\",COUNTA(\$E\$16:E{$r}))");
            $sheet->setCellValue(
                "C{$r}",
                "=IF(\$E{$r}=\"\",\"\",IFERROR(VLOOKUP(\$D{$r},구분표,2,FALSE),\"W\")&\"-\"&TEXT(COUNTIFS(\$D\$16:\$D{$r},\$D{$r},\$E\$16:\$E{$r},\"<>\"),\"00\"))"
            );
            $sheet->setCellValueExplicit("D{$r}", $issue->project->name, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("E{$r}", $issue->summary, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("F{$r}", $issue->allAssigneeNames(), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("G{$r}", (string) ($issue->reporter?->name ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("H{$r}", $priorityLabels[$issue->priority_key]['label'] ?? $issue->priority_key, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("I{$r}", $statusLabels[$issue->status_key]['label'] ?? $issue->status_key, DataType::TYPE_STRING);
            $sheet->setCellValue("J{$r}", $issue->percent);

            if ($issue->starts_on) {
                $sheet->setCellValue("K{$r}", Date::PHPToExcel($issue->starts_on));
            }

            if ($issue->due_undecided) {
                // "미정" is a literal in the workbook, not an empty cell.
                $sheet->setCellValueExplicit("L{$r}", '미정', DataType::TYPE_STRING);
            } elseif ($issue->due_on) {
                $sheet->setCellValue("L{$r}", Date::PHPToExcel($issue->due_on));
            }

            $sheet->setCellValueExplicit("M{$r}", (string) ($issue->note ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValue("O{$r}", "=IF(\$E{$r}=\"\",\"\",IF(\$I{$r}=\"완료\",100,IF(\$J{$r}=\"\",\"\",\$J{$r})))");

            $lastWritten = $r;
        }

        // Empty slots stay "formatted but blank" like the template, yet they keep the
        // helper formulas: B/C/O are self-guarding (they render "" when E is empty),
        // and a user who types into an unused row gets its number and key for free.
        for ($r = $lastWritten + 1; $r <= self::LAST_DATA_ROW; $r++) {
            $sheet->setCellValue("B{$r}", "=IF(E{$r}=\"\",\"\",COUNTA(\$E\$16:E{$r}))");
            $sheet->setCellValue(
                "C{$r}",
                "=IF(\$E{$r}=\"\",\"\",IFERROR(VLOOKUP(\$D{$r},구분표,2,FALSE),\"W\")&\"-\"&TEXT(COUNTIFS(\$D\$16:\$D{$r},\$D{$r},\$E\$16:\$E{$r},\"<>\"),\"00\"))"
            );
            $sheet->setCellValue("O{$r}", "=IF(\$E{$r}=\"\",\"\",IF(\$I{$r}=\"완료\",100,IF(\$J{$r}=\"\",\"\",\$J{$r})))");
        }

        // Style every reserved slot, written or not: the empty rows must look unused
        // but keep their borders, exactly like the template.
        for ($r = self::FIRST_DATA_ROW; $r <= self::LAST_DATA_ROW; $r++) {
            $isLast = $r === self::LAST_DATA_ROW;
            $bottom = $isLast ? Border::BORDER_THIN : Border::BORDER_HAIR;
            $bottomColor = $isLast ? self::NAVY : self::HAIR;

            $sheet->getStyle("B{$r}:M{$r}")->applyFromArray([
                'font' => ['name' => '맑은 고딕', 'size' => 10, 'color' => ['rgb' => self::INK]],
                'borders' => ['bottom' => ['borderStyle' => $bottom, 'color' => ['rgb' => $bottomColor]]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            ]);

            foreach (['B', 'C', 'D', 'F', 'G', 'H', 'I', 'J', 'K', 'L'] as $col) {
                $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }
            $sheet->getStyle("E{$r}")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_LEFT)->setIndent(1);
            $sheet->getStyle("M{$r}")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_LEFT)->setIndent(1);

            $sheet->getStyle("B{$r}")->getFont()->setSize(9)->getColor()->setRGB(self::MUTED);
            $sheet->getStyle("C{$r}")->getFont()->setSize(9)->setBold(true)->getColor()->setRGB(self::BLUE);
            $sheet->getStyle("J{$r}")->getFont()->setBold(true);
            $sheet->getStyle("M{$r}")->getFont()->setSize(9)->getColor()->setRGB(self::FAINT);

            $sheet->getStyle("J{$r}")->getNumberFormat()->setFormatCode('0"%"');
            // Date columns get the format only where a date lives; a text "미정"
            // survives because the format is ignored for strings.
            $sheet->getStyle("K{$r}")->getNumberFormat()->setFormatCode('mm/dd\ \(aaa\)');
            $sheet->getStyle("L{$r}")->getNumberFormat()->setFormatCode('mm/dd\ \(aaa\)');
            $sheet->getStyle("O{$r}")->getFont()->setName('맑은 고딕')->setSize(11);
        }
    }

    /* ------------------------------------------------------------------ */
    /* §2.4 conditional formatting */
    /* ------------------------------------------------------------------ */

    private function writeConditionalFormatting(Worksheet $sheet, $issues): void
    {
        $d0 = self::FIRST_DATA_ROW;
        $d1 = self::LAST_DATA_ROW;
        $detail = "B{$d0}:M{$d1}";

        // Done rows: pale fill and grey text.
        $this->expression($sheet, $detail, '$I'.$d0.'="완료"', fill: 'F3F6FB', font: self::MUTED);
        // Zebra striping.
        $this->expression($sheet, $detail, 'AND($D'.$d0.'<>"",MOD(ROW(),2)=0)', fill: self::ZEBRA);

        // Bucket tints on the 구분 column.
        $bucketTints = ['3D AFVI' => 'E2EFDA', '2D AFVI' => 'DDEBF7', '기타' => 'EDEDED', '목표' => 'FCE4D6'];
        foreach ($bucketTints as $bucket => $tint) {
            $this->expression($sheet, "D{$d0}:D{$d1}", '$D'.$d0.'="'.$bucket.'"', fill: $tint, font: self::NAVY, bold: true);
        }

        // Priority emphasis.
        $this->expression($sheet, "H{$d0}:H{$d1}", '$H'.$d0.'="High"', font: 'C00000', bold: true);
        $this->expression($sheet, "H{$d0}:H{$d1}", '$H'.$d0.'="Medium"', font: 'B07D00', bold: true);
        $this->expression($sheet, "H{$d0}:H{$d1}", '$H'.$d0.'="Low"', font: self::FAINT);

        // Status emphasis.
        $this->expression($sheet, "I{$d0}:I{$d1}", '$I'.$d0.'="완료"', font: '375623', fill: 'E2EFDA', bold: true);
        $this->expression($sheet, "I{$d0}:I{$d1}", '$I'.$d0.'="진행 중"', font: '1F4E79', fill: 'DDEBF7', bold: true);
        $this->expression($sheet, "I{$d0}:I{$d1}", '$I'.$d0.'="예정"', font: self::FAINT, fill: 'EDEDED');
        $this->expression($sheet, "I{$d0}:I{$d1}", '$I'.$d0.'="보류"', font: 'C65911', fill: 'FCE4D6', bold: true);

        // Progress bar and the "done but not 100 %" contradiction flag.
        $bar = new Conditional;
        $bar->setConditionType(Conditional::CONDITION_DATABAR)
            ->setDataBar(self::dataBar(0, 100, 'FFC000'));
        $sheet->getStyle("J{$d0}:J{$d1}")->setConditionalStyles(
            array_merge($sheet->getStyle("J{$d0}:J{$d1}")->getConditionalStyles(), [$bar])
        );

        $this->expression($sheet, "J{$d0}:J{$d1}", 'AND($I'.$d0.'="완료",N($J'.$d0.')<>100)', fill: 'FDE9E9', font: 'C00000', bold: true);

        // Overdue highlight reads the report date in D3.
        $this->expression($sheet, "L{$d0}:L{$d1}", 'AND($L'.$d0.'<>"",$I'.$d0.'<>"완료",$L'.$d0.'<$D$3)', font: 'C00000', bold: true);
    }

    /** A 0-100 (or custom) data bar, the workbook's only non-expression rule. */
    private static function dataBar(float $min, float $max, string $color): ConditionalDataBar
    {
        $bar = new ConditionalDataBar;
        $bar->setMinimumConditionalFormatValueObject(new ConditionalFormatValueObject('num', $min));
        $bar->setMaximumConditionalFormatValueObject(new ConditionalFormatValueObject('num', $max));
        $bar->setColor($color);

        return $bar;
    }

    /**
     * Appends one formula-based conditional rule, merging with rules that already
     * apply to the same range (PhpSpreadsheet replaces the whole array otherwise).
     */
    private function expression(
        Worksheet $sheet,
        string $range,
        string $formula,
        ?string $font = null,
        ?string $fill = null,
        bool $bold = false,
    ): void {
        $rule = new Conditional;
        $rule->setConditionType(Conditional::CONDITION_EXPRESSION)
            ->setOperatorType(Conditional::OPERATOR_EQUAL)
            ->addCondition($formula);

        $style = new Style;
        if ($fill !== null) {
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($fill);
        }
        if ($font !== null || $bold) {
            if ($font !== null) {
                $style->getFont()->getColor()->setRGB($font);
            }
            if ($bold) {
                $style->getFont()->setBold(true);
            }
        }
        if ($fill !== null || $font !== null || $bold) {
            $rule->setStyle($style);
        }

        $existing = $sheet->getStyle($range)->getConditionalStyles();
        $existing[] = $rule;
        $sheet->getStyle($range)->setConditionalStyles($existing);
    }

    /* ------------------------------------------------------------------ */
    /* §2.4 dropdowns */
    /* ------------------------------------------------------------------ */

    private function writeDropdowns(Worksheet $sheet): void
    {
        $d0 = self::FIRST_DATA_ROW;
        $d1 = self::LAST_DATA_ROW;

        // Bound to the workbook-level defined names created by WorkbookWriter, so
        // the lists stay in sync with sheet 목록 like in the original.
        foreach ([
            ["D{$d0}:D{$d1}", '구분목록'],
            ["F{$d0}:G{$d1}", '담당자목록'],
            ["H{$d0}:H{$d1}", '우선순위목록'],
            ["I{$d0}:I{$d1}", '상태목록'],
        ] as [$range, $name]) {
            $validation = new DataValidation;
            $validation->setType(DataValidation::TYPE_LIST)
                ->setErrorStyle(DataValidation::STYLE_STOP)
                ->setAllowBlank(true)
                ->setShowDropDown(true)
                ->setFormula1($name);
            $sheet->setDataValidation($range, $validation);
        }

        $progress = new DataValidation;
        $progress->setType(DataValidation::TYPE_WHOLE)
            ->setOperator(DataValidation::OPERATOR_BETWEEN)
            ->setAllowBlank(true)
            ->setFormula1('0')->setFormula2('100');
        $sheet->setDataValidation("J{$d0}:J{$d1}", $progress);

        $dates = new DataValidation;
        $dates->setType(DataValidation::TYPE_DATE)
            ->setOperator(DataValidation::OPERATOR_GREATERTHANOREQUAL)
            ->setAllowBlank(true)
            ->setFormula1('36526'); // 2000-01-01
        $sheet->setDataValidation("K{$d0}:L{$d1}", $dates);
    }

    /* ------------------------------------------------------------------ */
    /* §2.1 print setup */
    /* ------------------------------------------------------------------ */

    private function applyPrintSetup(Worksheet $sheet): void
    {
        $setup = $sheet->getPageSetup();
        $setup->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            // The original fits to one page tall and lets the width flow
            // (fitToHeight="0", no fitToWidth, no explicit scale).
            ->setFitToHeight(0)
            ->setFitToWidth(1);

        $sheet->getPageSetup()->setPrintArea('A1:N'.self::LAST_DATA_ROW);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(self::HEADER_ROW, self::HEADER_ROW);

        $sheet->getPageMargins()
            ->setLeft(0.4)->setRight(0.4)->setTop(0.5)->setBottom(0.5)
            ->setHeader(0.5)->setFooter(0.5);
    }

    /* ------------------------------------------------------------------ */

    /** The thin BFC9D9 box used by the header value cells. */
    private static function box(Style $style): void
    {
        $style->applyFromArray([
            'borders' => [
                'left' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::HAIR]],
                'right' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::HAIR]],
                'top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::HAIR]],
                'bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::HAIR]],
            ],
        ]);
    }
}
