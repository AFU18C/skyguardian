<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('skyguardian:sources:process --limit=40')
    ->everySecond()
    ->withoutOverlapping(1);

Schedule::command('skyguardian:group-channel-publications:process --limit=20')
    ->everyMinute()
    ->withoutOverlapping(1);

Schedule::command('skyguardian:group-channel-webhook-updates:process --limit=50')
    ->everyMinute()
    ->withoutOverlapping(1);
