<?php

use App\Models\Setting;
use App\Services\Backup\BackupService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('restopro:backup {--no-upload : Only save on this server}', function () {
    $b = BackupService::create('scheduled', null, ! $this->option('no-upload'));
    $this->info("Backup {$b->filename} ({$b->sizeLabel()}, {$b->totalRows()} records)");
    foreach ($b->destinations ?? [] as $k => $d) {
        $this->line(($d['ok'] ? '  ✓ ' : '  ✗ ').$k.' — '.$d['message']);
    }
})->purpose('Create a backup ZIP and upload it to every connected cloud');

Artisan::command('restopro:import-old {file : Path to the old RESTO_FULL_*.sql backup}
        {--online=upi : Where old "online sales" go: upi or online}
        {--dry-run : Only show what would be imported}
        {--force : Import even if the new system already has sales/expenses/staff}', function () {
    $mode = $this->option('online');
    if (! in_array($mode, ['upi', 'online'], true)) {
        $this->error('--online must be upi or online');

        return 1;
    }
    try {
        $imp = \App\Services\LegacyImport::fromFile($this->argument('file'));
    } catch (\RuntimeException $e) {
        $this->error($e->getMessage());

        return 1;
    }
    $imp->plan($mode);
    $r = $imp->report;
    $inr = fn ($v) => '₹'.inr_group($v);
    $this->info("Old backup: {$r['range'][0]} → {$r['range'][1]}");
    $this->line("Sales: {$r['sales_rows_old']} old rows → {$r['sales_days']} days".($r['merged_days'] ? ' (added up same-day rows: '.implode(', ', $r['merged_days']).')' : ''));
    $this->line('  cash '.$inr($r['sales_cash']).' · UPI '.$inr($r['sales_upi']).' · online '.$inr($r['sales_online']).' · total '.$inr($r['sales_cash'] + $r['sales_upi'] + $r['sales_online']));
    $this->line("Expenses: {$r['expenses_old']} old → {$r['expenses']} (skipped {$r['skipped_zero']} zero-amount) · total ".$inr($r['expense_total']));
    foreach ($r['by_category'] as $c => $v) {
        $this->line("  {$c}: ".$inr($v));
    }
    foreach ($r['staff'] as $s) {
        $this->line("Staff {$s['name']} · ".$inr($s['salary']).'/month · joined '.$s['joined'].($s['left'] ? " · left {$s['left']}" : ' · active')
            ." · {$s['attendance']} attendance ".json_encode($s['att_by_status'])." · {$s['payments']} payments ".$inr($s['paid']));
    }

    if ($this->option('dry-run')) {
        $this->comment('Dry run — nothing written.');

        return 0;
    }
    if (($c = \App\Services\LegacyImport::conflicts()) && ! $this->option('force')) {
        $this->error('The new system already has data: '.json_encode($c).'. Importing again would duplicate it. Use --force only if you are sure.');

        return 1;
    }
    $imp->run($mode);
    $this->info('Imported.');

    return 0;
})->purpose('One-time import of the old Resto Pro PHP app backup (sales, expenses, staff)');

/*
 * Shared hosting has no long-running workers: one hPanel cron runs `php artisan schedule:run` every minute,
 * and the schedule below decides what actually runs.
 */
$time = preg_match('/^\d{2}:\d{2}$/', (string) Setting::get('backup_time', '02:00')) ? Setting::get('backup_time', '02:00') : '02:00';

Schedule::command('restopro:backup')
    ->dailyAt($time)
    ->when(fn () => Setting::get('backup_auto', '1') === '1')
    ->withoutOverlapping(60)
    ->onOneServer();
