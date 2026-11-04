<?php

namespace App\Services\Excel;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ZipArchive;

/**
 * Writes the workbook and then repairs two things PhpSpreadsheet gets subtly wrong
 * for this document, so the result is byte-comparable with the reference file.
 *
 * 1. `_xlfn.` prefix. PhpSpreadsheet marks AVERAGEIFS as a post-2007 function and
 *    writes `_xlfn.AVERAGEIFS(...)`. Excel itself writes the bare name (the
 *    reference workbook contains 10 occurrences of plain `AVERAGEIFS` and zero
 *    prefixed ones), and LibreOffice refuses the prefixed form in some versions.
 * 2. Solid fills. PhpSpreadsheet writes only `fgColor` for a solid pattern, while
 *    Excel writes `fgColor` and `bgColor` with the same RGB. Readers that honour
 *    bgColor render the default black background instead of the intended tint.
 *
 * Both are pure XML rewrites over the produced archive — no re-serialisation, so
 * everything else (styles, merged cells, defined names, conditional formatting)
 * stays exactly as the writer emitted it.
 */
final class XlsxPackager
{
    /** Functions Excel stores unprefixed even though PhpSpreadsheet prefixes them. */
    private const NATIVE_FUNCTIONS = ['AVERAGEIFS'];

    public function save(Spreadsheet $book, string $path): void
    {
        $writer = new Xlsx($book);
        // The workbook contains cross-sheet COUNTIFS/AVERAGEIFS over a literal
        // "미정" date column; letting the engine pre-calculate would raise on those
        // cells and, worse, bake values in place of formulas. Excel recalculates
        // on load thanks to fullCalcOnLoad=1, which is set for this mode.
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);

        $this->stripXlfnPrefix($path);
        $this->mirrorSolidFillColors($path);
    }

    /** @return list<string> archive entry names, in write order */
    private function worksheetEntries(ZipArchive $zip): array
    {
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', (string) $name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function stripXlfnPrefix(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return;
        }

        foreach ($this->worksheetEntries($zip) as $entry) {
            $xml = $zip->getFromName($entry);
            if ($xml === false || ! str_contains($xml, '_xlfn.')) {
                continue;
            }

            $patched = $xml;
            foreach (self::NATIVE_FUNCTIONS as $fn) {
                $patched = str_replace('_xlfn.'.$fn.'(', $fn.'(', $patched);
            }

            if ($patched !== $xml) {
                $zip->addFromString($entry, $patched);
            }
        }

        $zip->close();
    }

    private function mirrorSolidFillColors(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return;
        }

        $styles = $zip->getFromName('xl/styles.xml');
        if ($styles === false) {
            $zip->close();

            return;
        }

        // <patternFill patternType="solid"><fgColor .../><bgColor .../></patternFill>
        // -> give bgColor the same rgb as fgColor when the writer left it zeroed.
        $patched = preg_replace_callback(
            '#<patternFill patternType="solid">(.*?)</patternFill>#s',
            static function (array $m): string {
                $inner = $m[1];
                if (! preg_match('#<fgColor rgb="([0-9A-Fa-f]{8})"\s*/>#', $inner, $fg)) {
                    return $m[0];
                }
                $rgb = strtoupper($fg[1]);
                $inner = preg_replace('#<bgColor[^/]*/>#', '', $inner);

                return '<patternFill patternType="solid"><fgColor rgb="'.$rgb.'"/><bgColor rgb="'.$rgb.'"/></patternFill>';
            },
            $styles,
        );

        if (is_string($patched) && $patched !== $styles) {
            $zip->addFromString('xl/styles.xml', $patched);
        }

        $zip->close();
    }
}
