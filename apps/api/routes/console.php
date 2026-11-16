<?php

use Illuminate\Support\Facades\Schedule;

// Parent messages queued from devices go out every minute.
Schedule::command('ilmora:send-messages')->everyMinute()->withoutOverlapping();
