<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('skyguardian:sources:process --limit=40')
    ->everyFiveSeconds()
    ->withoutOverlapping(1);

Schedule::command('skyguardian:group-channel-publications:process --limit=20')
    ->everyTenSeconds()
    ->withoutOverlapping(1);

Schedule::command('skyguardian:group-channel-alerts:process --limit=50')
    ->everyTenSeconds()
    ->withoutOverlapping(1);

Schedule::command('skyguardian:group-channel-webhook-updates:process --limit=50')
    ->everyMinute()
    ->withoutOverlapping(1);

Schedule::command('skyguardian:promo-campaign:status')
    ->everyMinute()
    ->withoutOverlapping(1);

Schedule::command('skyguardian:anti-casino-campaign:status')
    ->everyMinute()
    ->withoutOverlapping(1);
