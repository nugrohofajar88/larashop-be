<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Auto-expire: batalkan order pending_payment > 24 jam & kembalikan stok.
// Butuh cron di server: * * * * * php artisan schedule:run
//
// Pakai Schedule::call() (BUKAN ::command()): ::command() selalu men-spawn
// proses PHP terpisah lewat Symfony Process, yang butuh proc_open() - di
// hosting shared ini proc_open DINONAKTIFKAN, jadi ::command() selalu gagal
// dengan LogicException sebelum sempat jalan. Schedule::call() menjalankan
// command DALAM proses schedule:run yang sama, tidak butuh proc_open sama sekali.
Schedule::call(fn () => Artisan::call('orders:expire-unpaid'))->hourly();

// Backup database harian ke Cloudflare R2 (off-site), simpan 14 hari terakhir.
Schedule::call(fn () => Artisan::call('db:backup'))->dailyAt('02:00');
