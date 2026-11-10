<?php

use App\Social\PostPublisher;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Post scheduler: send WhatsApp / Facebook posts whose time has come.
// Runs from the server's cron (php artisan schedule:run every minute).
Artisan::command('posts:publish-due', function (PostPublisher $publisher) {
    $count = $publisher->publishDue();
    $this->info($count ? "Published {$count} scheduled post(s)." : 'Nothing due.');
})->purpose('Send scheduled posts that are due');

Schedule::command('posts:publish-due')->everyMinute()->withoutOverlapping(10);
