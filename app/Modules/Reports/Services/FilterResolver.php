<?php

namespace App\Modules\Reports\Services;

use App\Models\User;
use App\Modules\Reports\Reports\Report;
use App\Modules\Reports\Support\ReportFilters;
use App\Support\FinancialYear;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Turns query-string filters into ReportFilters, never letting a user see a branch they aren't assigned to.
 * branch = branch id | "all" (all of the user's branches); gstin = a GST registration (all of the user's branches using it).
 */
class FilterResolver
{
    public function resolve(Request $request, Report $report, User $user): ReportFilters
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch' => ['nullable', 'string', 'max:10'],
            'gstin' => ['nullable', 'string', 'max:20'],
        ]);

        [$from, $to] = $this->range($report->defaultRange());
        if ($report->usesDates() && ! empty($data['from'])) {
            $from = Carbon::parse($data['from']);
            $to = Carbon::parse($data['to'] ?? $data['from']);
        }

        $allowed = current_branch()->allowedFor($user);
        $gstin = null;
        [$branchIds, $label] = match ($report->scope()) {
            'gstin' => (function () use ($allowed, $data, &$gstin) {
                $groups = $this->gstinGroups($allowed);
                $current = current_branch()->get();
                $gstin = isset($data['gstin']) && $groups->has($data['gstin']) ? $data['gstin'] : $this->gstinOf($current);
                $branches = $groups->get($gstin) ?? collect([$current])->filter();

                return [$branches->pluck('id')->all(), $branches->pluck('name')->implode(', ')];
            })(),
            'branch' => ($data['branch'] ?? null) === 'all' && $allowed->count() > 1
                ? [$allowed->pluck('id')->all(), 'All branches']
                : (function () use ($allowed, $data) {
                    $b = $allowed->firstWhere('id', (int) ($data['branch'] ?? 0)) ?? current_branch()->get();

                    return [$b ? [$b->id] : [], $b?->name ?? '—'];
                })(),
            default => [$allowed->pluck('id')->all(), 'All branches'],
        };

        $options = [];
        foreach ($report->options() as $name => $opt) {
            $value = $request->query($name);
            $options[$name] = $value !== null && array_key_exists((string) $value, $opt['options']) ? (string) $value : (string) $opt['default'];
        }

        return new ReportFilters($from, $to, $branchIds, $label, $options, $gstin ?: null);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public function range(string $preset): array
    {
        return match ($preset) {
            'today' => [today(), today()],
            'last_month' => [today()->subMonthNoOverflow()->startOfMonth(), today()->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'fy' => [FinancialYear::start(), today()],
            default => [today()->startOfMonth(), today()],
        };
    }

    public function gstinOf($branch): string
    {
        return (string) ($branch?->gstin ?: settings('business.gstin') ?: '');
    }

    /** @return Collection<string, Collection> GSTIN => branches using it */
    public function gstinGroups(Collection $branches): Collection
    {
        return $branches->groupBy(fn ($b) => $this->gstinOf($b));
    }
}
