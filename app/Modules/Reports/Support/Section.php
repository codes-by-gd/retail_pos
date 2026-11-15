<?php

namespace App\Modules\Reports\Support;

use Closure;

/**
 * One table of a report. Columns: ['key', 'label', 'type' => text|money|qty|int|percent|date|datetime, 'total' => bool].
 * Rows are plain arrays keyed by column key. Totals sum the `total` columns unless a closure adjusts them
 * (e.g. a margin % recomputed from the summed profit and sales).
 */
final class Section
{
    private ?array $totals = null;

    private ?Closure $totalsUsing = null;

    public ?string $note = null;

    public function __construct(
        public readonly string $title,
        public readonly array $columns,
        public array $rows,
    ) {}

    public static function make(string $title, array $columns, iterable $rows): self
    {
        $rows = array_values(is_array($rows) ? $rows : iterator_to_array($rows, false));

        // round(-0.001, 2) gives -0.0, which prints as "-0"
        return new self($title, $columns, array_map(fn ($r) => array_map(fn ($v) => is_float($v) && $v == 0.0 ? 0.0 : $v, (array) $r), $rows));
    }

    public static function col(string $key, string $label, string $type = 'text', bool $total = false): array
    {
        return compact('key', 'label', 'type', 'total');
    }

    public function note(?string $note): self
    {
        $this->note = $note;

        return $this;
    }

    /** @param  Closure(array): array  $fn  receives the summed totals, returns the final totals row */
    public function totalsUsing(Closure $fn): self
    {
        $this->totalsUsing = $fn;

        return $this;
    }

    public function withoutTotals(): self
    {
        $this->totals = [];

        return $this;
    }

    public function totals(): array
    {
        if ($this->totals !== null) {
            return $this->totals;
        }

        $sum = [];
        foreach ($this->columns as $c) {
            if ($c['total']) {
                $sum[$c['key']] = 0.0 + round(array_sum(array_map(fn ($r) => (float) ($r[$c['key']] ?? 0), $this->rows)), $c['type'] === 'qty' ? 3 : 2);
            }
        }
        if ($sum && $this->totalsUsing) {
            $sum = ($this->totalsUsing)($sum);
        }

        return $this->totals = $sum;
    }

    public function toArray(?int $limit = null): array
    {
        $count = count($this->rows);

        return [
            'title' => $this->title,
            'columns' => $this->columns,
            'rows' => $limit !== null && $count > $limit ? array_slice($this->rows, 0, $limit) : $this->rows,
            'count' => $count,
            'truncated' => $limit !== null && $count > $limit,
            'totals' => $this->totals(),
            'note' => $this->note,
        ];
    }
}
