<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// A missed webhook leaves an order Pending while holding its stock. Sweep for
// those every few minutes so the shelf frees itself up.
Schedule::command('cutluy:reconcile')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();
