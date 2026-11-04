<?php

namespace Tests\Unit;

use App\Services\Excel\ReportSheetWriter;
use Tests\TestCase;

/**
 * The palette and geometry constants are the contract with the reference
 * workbook; a typo here would break the exported document in a way that is hard
 * to spot by eye.
 */
class ReportSheetWriterConstantsTest extends TestCase
{
    public function test_palette_matches_the_reference_workbook(): void
    {
        $this->assertSame('1F3864', ReportSheetWriter::NAVY);
        $this->assertSame('2F5597', ReportSheetWriter::BLUE);
        $this->assertSame('BFC9D9', ReportSheetWriter::HAIR);
        $this->assertSame('262626', ReportSheetWriter::INK);
        $this->assertSame('8C8C8C', ReportSheetWriter::MUTED);
        $this->assertSame('595959', ReportSheetWriter::FAINT);
        $this->assertSame('FFF8E1', ReportSheetWriter::CREAM);
        $this->assertSame('F3F6FB', ReportSheetWriter::ZEBRA);
        $this->assertSame('D9E1F2', ReportSheetWriter::TOTALFILL);
    }

    public function test_colours_are_six_hex_digits(): void
    {
        foreach ([
            ReportSheetWriter::NAVY, ReportSheetWriter::BLUE, ReportSheetWriter::HAIR,
            ReportSheetWriter::INK, ReportSheetWriter::MUTED, ReportSheetWriter::FAINT,
            ReportSheetWriter::CREAM, ReportSheetWriter::ZEBRA, ReportSheetWriter::TOTALFILL,
        ] as $color) {
            $this->assertMatchesRegularExpression('/^[0-9A-F]{6}$/', $color);
        }
    }

    public function test_workbook_geometry_is_declared_in_config(): void
    {
        $excel = config('workreport.excel');

        $this->assertSame('업무일보', $excel['sheet_report']);
        $this->assertSame('담당자 요약', $excel['sheet_members']);
        $this->assertSame('목록', $excel['sheet_lists']);
        $this->assertSame(15, $excel['header_row']);
        $this->assertSame(16, $excel['first_data_row']);
        $this->assertSame(76, $excel['last_data_row']);
        $this->assertSame(61, $excel['max_data_rows']);
        $this->assertSame(11, $excel['total_row']);

        // The slot count has to agree with the row boundaries, or the exporter and
        // the summary formulas would disagree about the range.
        $this->assertSame(
            $excel['max_data_rows'],
            $excel['last_data_row'] - $excel['first_data_row'] + 1
        );
    }

    public function test_vocabulary_matches_the_workbook_dropdowns(): void
    {
        $this->assertSame(
            ['예정', '진행 중', '완료', '보류'],
            array_column(config('workreport.statuses'), 'label')
        );
        $this->assertSame(
            ['High', 'Medium', 'Low'],
            array_column(config('workreport.priorities'), 'label')
        );
        $this->assertSame(
            ['3D AFVI', '2D AFVI', '기타', '목표'],
            array_column(config('workreport.summary_buckets'), 'label')
        );
        $this->assertSame(
            ['3D', '2D', 'ETC', 'GOAL'],
            array_column(config('workreport.summary_buckets'), 'abbr')
        );
    }
}
