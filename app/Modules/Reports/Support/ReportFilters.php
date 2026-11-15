<?php

namespace App\Modules\Reports\Support;

use Illuminate\Support\Carbon;

/** Validated filters a report runs with: date range, the branches it covers and report-specific options. */
final class ReportFilters
{
    /**
     * @param  list<int>  $branchIds
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly array $branchIds,
        public readonly string $branchLabel,
        public readonly array $options = [],
        public readonly ?string $gstin = null,
    ) {}

    public function start(): string
    {
        return $this->from->copy()->startOfDay()->toDateTimeString();
    }

    public function end(): string
    {
        return $this->to->copy()->endOfDay()->toDateTimeString();
    }

    public function fromDate(): string
    {
        return $this->from->toDateString();
    }

    public function toDate(): string
    {
        return $this->to->toDateString();
    }

    /** For DATE columns: up to the end of the last day (SQLite keeps date casts as "Y-m-d 00:00:00"). */
    public function dateRange(): array
    {
        return [$this->fromDate(), $this->toDate().' 23:59:59'];
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    public function periodLabel(): string
    {
        return $this->from->isSameDay($this->to)
            ? $this->from->format('d M Y')
            : $this->from->format('d M Y').' – '.$this->to->format('d M Y');
    }
}
