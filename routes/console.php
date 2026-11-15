<?php

use App\Modules\Loyalty\Services\LoyaltyService;
use App\Modules\Notifications\Services\Alerts;
use App\Modules\Settings\Services\BackupService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
| Scheduled jobs — run `php artisan schedule:run` every minute from cron:
|   * * * * * cd /path/to/pos && php artisan schedule:run >> /dev/null 2>&1
*/

Artisan::command('pos:daily-alerts {--date= : Day to summarise (Y-m-d), default today}', function (Alerts $alerts) {
    $sent = $alerts->daily($this->option('date') ? Carbon::parse($this->option('date')) : null);
    foreach ($sent as $type => $count) {
        $this->line("{$type}: {$count} notification(s)");
    }
})->purpose('Send the end-of-day sales summary and payment-due alerts');

Artisan::command('pos:backup', function (BackupService $backups, Alerts $alerts) {
    try {
        $name = $backups->create();
        $pruned = $backups->prune((int) settings('backup.keep_days', 14));
        $this->info("Backup {$name} created".($pruned ? ", {$pruned} old backup(s) deleted" : '').'.');
    } catch (Throwable $e) {
        $alerts->backupFailed($e->getMessage());
        $this->error($e->getMessage());

        return 1;
    }
})->purpose('Back up the database to storage/app/backups and delete old backups');

Artisan::command('pos:expire-points', function (LoyaltyService $loyalty) {
    $this->info($loyalty->expire().' loyalty point(s) expired.');
})->purpose('Expire loyalty points past their expiry date');

Schedule::command('pos:expire-points')->dailyAt('00:30');
Schedule::command('pos:daily-alerts')->dailyAt('21:45');
Schedule::command('pos:backup')->dailyAt('23:30');
