<?php

namespace App\Modules\Reports\Support;

use Illuminate\Support\Facades\DB;

/** Date expressions that work on both MySQL and SQLite (tests). Timestamps are stored in the app timezone. */
final class Sql
{
    private static function sqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }

    /** 2026-11-18 */
    public static function date(string $col): string
    {
        return self::sqlite() ? "strftime('%Y-%m-%d', {$col})" : "DATE_FORMAT({$col}, '%Y-%m-%d')";
    }

    /** 2026-11 */
    public static function month(string $col): string
    {
        return self::sqlite() ? "strftime('%Y-%m', {$col})" : "DATE_FORMAT({$col}, '%Y-%m')";
    }

    /** 0–23 */
    public static function hour(string $col): string
    {
        return self::sqlite() ? "CAST(strftime('%H', {$col}) AS INTEGER)" : "HOUR({$col})";
    }
}
