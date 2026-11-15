<?php

namespace App\Modules\Reports\Services;

use App\Modules\Reports\Reports\Report;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\Section;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Turns report sections into Excel (one sheet per section), CSV or PDF. */
class ReportExporter
{
    /** @param  list<Section>  $sections */
    public function export(string $format, Report $report, ReportFilters $f, array $sections): Response
    {
        $name = Str::slug($report->title()).'-'.($report->usesDates() ? $f->fromDate().'-to-'.$f->toDate() : now()->toDateString());

        return match ($format) {
            'xlsx' => $this->xlsx($report, $f, $sections, "{$name}.xlsx"),
            'csv' => $this->csv($report, $f, $sections, "{$name}.csv"),
            'pdf' => $this->pdf($report, $f, $sections, "{$name}.pdf"),
        };
    }

    public function header(Report $report, ReportFilters $f): array
    {
        return array_values(array_filter([
            settings('business.name'),
            $report->title(),
            $report->usesDates() ? 'Period: '.$f->periodLabel() : 'As of '.now()->format('d M Y, h:i A'),
            $report->scope() ? ($f->gstin ? "GSTIN {$f->gstin} · " : '').$f->branchLabel : null,
        ]));
    }

    /** Value as a spreadsheet cell: numbers stay numbers, dates become text the user can read. */
    private function cell(array $col, mixed $v): mixed
    {
        if ($v === null) {
            return '';
        }

        return match ($col['type']) {
            'money', 'qty', 'percent' => (float) $v,
            'int' => (int) $v,
            'date' => Carbon::parse($v)->format('d-m-Y'),
            'datetime' => Carbon::parse($v)->format('d-m-Y H:i'),
            default => (string) $v,
        };
    }

    private function xlsx(Report $report, ReportFilters $f, array $sections, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($report, $f, $sections) {
            $writer = new Writer;
            $writer->openToFile('php://output');
            $bold = (new Style)->withFontBold(true);
            $styles = [
                'money' => (new Style)->withFormat('#,##0.00'),
                'qty' => (new Style)->withFormat('#,##0.###'),
                'percent' => (new Style)->withFormat('0.0'),
            ];
            $used = [];

            foreach ($sections as $i => $section) {
                $sheet = $i === 0 ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
                // Sheet names: max 31 chars, no []:*?/\ and unique
                $base = trim(Str::limit(preg_replace('/[\[\]:*?\/\\\\]/', '', $section->title), 27, '')) ?: 'Sheet';
                $title = $base;
                for ($n = 2; isset($used[$title]); $n++) {
                    $title = "{$base} {$n}";
                }
                $used[$title] = true;
                $sheet->setName($title);

                foreach ($this->header($report, $f) as $line) {
                    $writer->addRow(Row::fromValuesWithStyles([$line], [$bold]));
                }
                $writer->addRow(Row::fromValuesWithStyles([$section->title], [$bold]));
                $writer->addRow(Row::fromValues([]));
                $writer->addRow(Row::fromValuesWithStyles(array_column($section->columns, 'label'), array_fill(0, count($section->columns), $bold)));

                $colStyles = array_map(fn ($c) => $styles[$c['type']] ?? null, $section->columns);
                foreach ($section->rows as $row) {
                    $writer->addRow(Row::fromValuesWithStyles(array_map(fn ($c) => $this->cell($c, $row[$c['key']] ?? null), $section->columns), array_filter($colStyles)));
                }
                if ($totals = $section->totals()) {
                    $values = array_map(fn ($c) => array_key_exists($c['key'], $totals) ? $this->cell($c, $totals[$c['key']]) : '', $section->columns);
                    $values[0] = $values[0] === '' ? 'Total' : $values[0];
                    $writer->addRow(Row::fromValuesWithStyles($values, array_map(fn ($c) => ($styles[$c['type']] ?? new Style)->withFontBold(true), $section->columns)));
                }
                if ($section->note) {
                    $writer->addRow(Row::fromValues([]));
                    $writer->addRow(Row::fromValues([$section->note]));
                }
            }
            $writer->close();
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private function csv(Report $report, ReportFilters $f, array $sections, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($report, $f, $sections) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM so Excel shows ₹ and – correctly
            foreach ($this->header($report, $f) as $line) {
                fputcsv($out, [$line], escape: '');
            }
            foreach ($sections as $section) {
                fputcsv($out, [], escape: '');
                fputcsv($out, [$section->title], escape: '');
                fputcsv($out, array_column($section->columns, 'label'), escape: '');
                foreach ($section->rows as $row) {
                    fputcsv($out, array_map(fn ($c) => $this->cell($c, $row[$c['key']] ?? null), $section->columns), escape: '');
                }
                if ($totals = $section->totals()) {
                    $values = array_map(fn ($c) => array_key_exists($c['key'], $totals) ? $this->cell($c, $totals[$c['key']]) : '', $section->columns);
                    $values[0] = $values[0] === '' ? 'Total' : $values[0];
                    fputcsv($out, $values, escape: '');
                }
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function pdf(Report $report, ReportFilters $f, array $sections, string $filename): Response
    {
        $wide = collect($sections)->max(fn ($s) => count($s->columns)) > 7;

        return Pdf::loadView('reports.pdf', [
            'header' => $this->header($report, $f),
            'sections' => $sections,
            'fmt' => fn (array $col, mixed $v) => $this->display($col, $v),
        ])->setOption('isFontSubsettingEnabled', true)
            ->setPaper('a4', $wide ? 'landscape' : 'portrait')->stream($filename);
    }

    /** Human format for PDF (Indian digit grouping; "Rs." because the PDF fonts have no ₹ glyph). */
    public function display(array $col, mixed $v): string
    {
        if ($v === null || $v === '') {
            return '';
        }

        return match ($col['type']) {
            'money' => self::inr((float) $v),
            'qty' => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.'),
            'percent' => number_format((float) $v, 1).'%',
            'int' => (string) (int) $v,
            'date' => Carbon::parse($v)->format('d M Y'),
            'datetime' => Carbon::parse($v)->format('d M Y, H:i'),
            default => (string) $v,
        };
    }

    /** 1234567.5 → 12,34,567.50 */
    public static function inr(float $v): string
    {
        $neg = $v < 0;
        [$int, $dec] = explode('.', number_format(abs($v), 2, '.', ''));
        $last3 = substr($int, -3);
        $rest = substr($int, 0, -3);
        $grouped = $rest !== '' ? preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest).','.$last3 : $last3;

        return ($neg ? '-' : '').$grouped.'.'.$dec;
    }
}
