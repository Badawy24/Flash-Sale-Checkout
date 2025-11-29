<?php

use App\Jobs\ExpireHoldsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule hold expiry job to run every minute
Schedule::call(function () {
    ExpireHoldsJob::dispatch();
})->everyMinute();
