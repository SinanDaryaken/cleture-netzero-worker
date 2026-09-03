<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('processing-tasks:consume')
    ->everyTenSeconds()
    ->withoutOverlapping(1)
    ->onOneServer();
