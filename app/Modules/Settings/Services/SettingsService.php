<?php

namespace App\Modules\Settings\Services;

use App\Modules\Settings\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Key/value application settings, cached as one array.
 * Usage: settings('business.name') or app(SettingsService::class)->set('invoice.prefix', 'INV').
 */
class SettingsService
{
    private const CACHE_KEY = 'app_settings';

    /** Defaults used when a key has never been saved. */
    public const DEFAULTS = [
        'business.name' => 'My Clothing Store',
        'business.legal_name' => null,
        'business.gstin' => null,
        'business.state_code' => '24',
        'business.address' => null,
        'business.city' => null,
        'business.pincode' => null,
        'business.phone' => null,
        'business.email' => null,
        'business.logo' => null,
        'business.financial_year_start' => '04-01',   // MM-DD, Indian FY starts 1 April
        'business.currency_symbol' => '₹',
        'invoice.prefix' => '',                       // optional, ≤ 4 chars: GST invoice numbers max 16 chars
        'invoice.footer' => 'Thank you for shopping with us!',
        'invoice.terms' => null,
        'tax.prices_include_tax' => true,
        'tax.round_off' => true,
        'pos.allow_negative_stock' => false,
        'pos.beep_on_scan' => true,
        'pos.receipt_size' => '80mm',                 // 58mm | 80mm | a4
        'pos.print_after_sale' => true,
        'pos.accept_card' => true,
        'pos.accept_upi' => true,
        'alerts.discount_percent' => 20,              // alert managers when a bill is discounted this much (0 = off)
        'alerts.cash_difference' => 100,              // alert when a register closes this much short/excess
        'alerts.email_enabled' => false,              // also email users who chose email (needs MAIL_* in .env)
        'backup.keep_days' => 14,                     // nightly backups older than this are deleted
    ];

    private ?array $loaded = null;

    public function all(): array
    {
        if ($this->loaded === null) {
            $stored = Cache::rememberForever(self::CACHE_KEY, function () {
                if (! Schema::hasTable('settings')) {
                    return [];
                }

                return Setting::query()->pluck('value', 'key')->all();
            });
            $this->loaded = array_merge(self::DEFAULTS, $stored);
        }

        return $this->loaded;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /** All settings starting with "$group." with the prefix stripped. */
    public function group(string $group): array
    {
        $prefix = $group.'.';
        $out = [];
        foreach ($this->all() as $key => $value) {
            if (str_starts_with($key, $prefix)) {
                $out[substr($key, strlen($prefix))] = $value;
            }
        }

        return $out;
    }

    public function set(string|array $key, mixed $value = null): void
    {
        $values = is_array($key) ? $key : [$key => $value];

        foreach ($values as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }

        $this->flush();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->loaded = null;
    }
}
