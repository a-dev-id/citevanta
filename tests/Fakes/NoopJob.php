<?php

namespace Tests\Fakes;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** A queued job that does nothing, for counting processed jobs. */
class NoopJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void {}
}
