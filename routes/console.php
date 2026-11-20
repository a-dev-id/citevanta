<?php

use App\Services\Cron\CitevantaSchedule;
use Illuminate\Console\Scheduling\Schedule;

/*
| All scheduled tasks are defined in App\Services\Cron\CitevantaSchedule, which
| CronRunner also uses to run them in-process on hosts without proc_open.
*/

app(CitevantaSchedule::class)->register(app(Schedule::class));
