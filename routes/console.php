<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('skyguardian:sources:process --limit=40')
    ->everySecond()
    ->withoutOverlapping(1);
