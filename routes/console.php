<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Auto-expire: batalkan order pending_payment > 24 jam & kembalikan stok.
// Butuh cron di server: * * * * * php artisan schedule:run
Schedule::command('orders:expire-unpaid')->hourly();

// Backup database harian ke Cloudflare R2 (off-site), simpan 14 hari terakhir.
Schedule::command('db:backup')->dailyAt('02:00');
