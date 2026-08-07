<?php

use App\Models\Source;
use App\Services\SourcePollingSettings;
use Illuminate\Support\Facades\Schedule;

foreach ([Source::TYPE_NEWS, Source::TYPE_AIR_ALERT] as $type) {
    Schedule::command("skyguardian:sources:process --type={$type} --limit=40")
        ->everySecond()
        ->when(fn (): bool => app(SourcePollingSettings::class)->shouldRun($type))
        ->withoutOverlapping(1);
}

Schedule::command('skyguardian:group-channel-publications:process --limit=20')
    ->everyTenSeconds()
    ->withoutOverlapping(1);

Schedule::command('skyguardian:group-channel-alerts:process --limit=50')
    ->everySecond()
    ->when(fn (): bool => in_array(now()->second, [5, 15, 25, 35, 45, 55], true))
    ->withoutOverlapping(1);

Schedule::command('skyguardian:group-channel-webhook-updates:process --limit=50')
    ->everySecond()
    ->when(fn (): bool => now()->second === 2)
    ->withoutOverlapping(1);
