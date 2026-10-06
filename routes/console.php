<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Cancela los apartados que no se pagaron a tiempo y regresa su stock.
Schedule::command('orders:expire-unpaid')->everyTenMinutes()->withoutOverlapping();
