<?php

namespace App\Console\Commands;

use App\Models\HotelGoogleLink;
use App\Services\Google\GoogleAgent;
use App\Services\Google\GoogleSync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('citevanta:google-sync')]
#[Description('Queue the daily GA4 and Search Console sync jobs for linked hotels')]
class GoogleSyncCommand extends Command
{
    public function handle(GoogleSync $sync, GoogleAgent $agent): int
    {
        if ($agent->isBroken()) {
            $this->warn('Google is disconnected: reconnect in Admin > Integrations > Google.');

            return self::SUCCESS;
        }

        $jobs = 0;
        HotelGoogleLink::query()->with('hotel.team')->each(function (HotelGoogleLink $link) use ($sync, &$jobs): void {
            if ($link->hotel?->team !== null && ! $link->hotel->team->isReadOnly()) {
                $jobs += $sync->schedule($link);
            }
        });

        $this->line("{$jobs} sync job(s) queued.");

        return self::SUCCESS;
    }
}
