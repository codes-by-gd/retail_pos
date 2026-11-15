<?php

namespace App\Modules\Reports\Reports;

use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\Section;

/**
 * A report = metadata + the filters it understands + build() returning one or more sections.
 * The same sections drive the screen, Excel, CSV and PDF, so every format shows identical numbers.
 */
abstract class Report
{
    public const SALES = 'Sales';

    public const INVENTORY = 'Inventory & purchases';

    public const FINANCIAL = 'Accounts & profit';

    public const GST = 'GST';

    abstract public function key(): string;

    abstract public function title(): string;

    abstract public function group(): string;

    abstract public function permission(): string;

    public function description(): string
    {
        return '';
    }

    /** Does the report take a date range? (Stock valuation, receivables… are "as of now".) */
    public function usesDates(): bool
    {
        return true;
    }

    /** 'branch' (pick a branch / all), 'gstin' (pick a GST registration) or null (business-wide). */
    public function scope(): ?string
    {
        return 'branch';
    }

    /** Default date preset: today | month | last_month | fy */
    public function defaultRange(): string
    {
        return 'month';
    }

    /**
     * Extra select filters: name => ['label' => …, 'options' => [value => label], 'default' => value].
     *
     * @return array<string, array{label: string, options: array, default: mixed}>
     */
    public function options(): array
    {
        return [];
    }

    /** Optional bar chart over the first section: ['x' => column key, 'y' => column key, 'label' => …]. */
    public function chart(ReportFilters $f): ?array
    {
        return null;
    }

    /** @return list<Section> */
    abstract public function build(ReportFilters $f): array;

    public function meta(): array
    {
        return [
            'key' => $this->key(),
            'title' => $this->title(),
            'group' => $this->group(),
            'description' => $this->description(),
            'permission' => $this->permission(),
        ];
    }
}
