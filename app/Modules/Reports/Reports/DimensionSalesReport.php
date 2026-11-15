<?php

namespace App\Modules\Reports\Reports;

use App\Modules\Reports\Services\SalesFacts;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\Section;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Net sales (or profit) grouped by one dimension — item, category, brand, size, cashier… Configured in ReportRegistry.
 * `dimensions` = option value => [label shown in the picker, column heading].
 */
class DimensionSalesReport extends Report
{
    /** @param  array<string, array{0: string, 1: string}>  $dimensions */
    public function __construct(
        private readonly string $key,
        private readonly string $title,
        private readonly string $description,
        private readonly array $dimensions,
        private readonly bool $profit = false,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function group(): string
    {
        return $this->profit ? self::FINANCIAL : self::SALES;
    }

    public function permission(): string
    {
        return $this->profit ? 'reports.financial' : 'reports.sales';
    }

    public function options(): array
    {
        $options = [];
        if (count($this->dimensions) > 1) {
            $options['by'] = ['label' => 'Group by', 'options' => array_map(fn ($d) => $d[0], $this->dimensions), 'default' => array_key_first($this->dimensions)];
        }
        if (isset($this->dimensions['attribute'])) {
            $attributes = DB::table('attributes')->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all();
            $size = collect($attributes)->search(fn ($n) => $n === 'Size') ?: array_key_first($attributes);
            $options['attribute_id'] = ['label' => 'Attribute', 'options' => $attributes, 'default' => $size];
        }

        return $options;
    }

    public function chart(ReportFilters $f): ?array
    {
        return in_array($this->dimension($f), ['hour', 'day', 'month'], true)
            ? ['x' => 'name', 'y' => $this->profit ? 'profit' : 'net_total', 'label' => $this->profit ? 'Gross profit' : 'Net sales']
            : null;
    }

    public function build(ReportFilters $f): array
    {
        $dim = $this->dimension($f);
        $data = app(SalesFacts::class)->byDimension($f, $dim, ['attribute_id' => $f->option('attribute_id')]);
        $heading = $this->dimensions[$dim][1];
        if ($dim === 'attribute') {
            $heading = $this->options()['attribute_id']['options'][$f->option('attribute_id')] ?? $heading;
        }

        $hasSub = in_array($dim, ['variant', 'product', 'customer'], true);
        $subLabel = ['variant' => 'Size / colour · SKU', 'product' => 'Article no.', 'customer' => 'Phone'][$dim] ?? null;
        $grand = $data->sum('net_total');

        $rows = $data->map(function ($r) use ($dim, $grand) {
            $sub = $r['sub'];
            if ($dim === 'variant' && $sub) {
                [$variant, $sku] = explode('|', $sub, 2) + [1 => ''];
                $sub = trim(($variant !== '' ? $variant.' · ' : '').$sku);
            }

            return [
                'name' => $dim === 'day' ? Carbon::parse($r['key'])->format('D, d M Y') : $r['label'],
                'sub' => $sub,
                'bills' => $r['bills'],
                'sold_qty' => $r['sold_qty'], 'ret_qty' => $r['ret_qty'], 'net_qty' => $r['net_qty'],
                'net_taxable' => $r['net_taxable'], 'net_tax' => $r['net_tax'], 'net_total' => $r['net_total'],
                'net_cost' => $r['net_cost'], 'profit' => $r['profit'], 'margin' => $r['margin'],
                'share' => $grand ? round($r['net_total'] / $grand * 100, 1) : null,
            ];
        });

        $c = fn (...$a) => Section::col(...$a);
        $columns = [$c('name', $heading)];
        if ($hasSub) {
            $columns[] = $c('sub', $subLabel);
        }

        if ($this->profit) {
            array_push($columns,
                $c('net_qty', 'Net qty', 'qty', true),
                $c('net_taxable', 'Net sales (excl. GST)', 'money', true),
                $c('net_cost', 'Cost', 'money', true),
                $c('profit', 'Gross profit', 'money', true),
                $c('margin', 'Margin %', 'percent', true),
            );
            $section = Section::make($this->title, $columns, $rows)
                ->totalsUsing(fn ($t) => [...$t, 'margin' => $t['net_taxable'] ? round($t['profit'] / $t['net_taxable'] * 100, 1) : null])
                ->note('Profit = sales excluding GST − cost (weighted-average cost saved on each bill line). Restocked returns give the cost back; damaged returns are a loss.');
        } else {
            if (! in_array($dim, ['variant', 'product'], true)) {
                $columns[] = $c('bills', 'Bills', 'int', true);
            }
            array_push($columns,
                $c('sold_qty', 'Sold', 'qty', true),
                $c('ret_qty', 'Returned', 'qty', true),
                $c('net_qty', 'Net qty', 'qty', true),
                $c('net_taxable', 'Taxable', 'money', true),
                $c('net_tax', 'GST', 'money', true),
                $c('net_total', 'Net sales', 'money', true),
                $c('share', 'Share %', 'percent', true),
            );
            $section = Section::make($this->title, $columns, $rows)
                ->totalsUsing(fn ($t) => [...$t, 'share' => $t['net_total'] ? 100.0 : null])
                ->note('Net = sold − returned in the period (returns on their credit-note date). Bill-level round-off is not included.');
        }

        return [$section];
    }

    private function dimension(ReportFilters $f): string
    {
        $by = $f->option('by');

        return isset($this->dimensions[$by]) ? $by : array_key_first($this->dimensions);
    }
}
