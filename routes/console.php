<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Recovery passes for what the queue did not process (a lost dispatch, a stopped worker).
 * They take only accepted records; a record that ran out of attempts or failed for good
 * stays «Ошибка» until `*:retry-failed` is run by hand, so nothing is retried forever.
 */
Schedule::command('site-requests:process --limit=100 --older-than=300')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

Schedule::command('mango-events:process --limit=200 --older-than=300')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

/*
 * Waiting callback orders get a responsible when an operator's schedule allows; an order
 * whose business period has ended is marked expired and not assigned.
 */
Schedule::command('orders:distribute --limit=50')
    ->everyMinute()
    ->withoutOverlapping(5);
