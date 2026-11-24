<?php

namespace App\Console\Commands;

use App\Models\HotelGoogleLink;
use App\Services\Google\GoogleAgent;
use App\Services\Google\GoogleGapHealer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('citevanta:google-heal')]
#[Description('Queue jobs for GA4 days that are missing from the stored data (back to ga4_first_sync_months)')]
class GoogleHealCommand extends Command
{
    public function handle(GoogleGapHealer $healer, GoogleAgent $agent): int
    {
        if ($agent->isBroken()) {
            $this->warn('Google refused the service account key: check it in Admin > Integrations > Google (Test connection).');

            return self::SUCCESS;
        }

        $jobs = 0;
        HotelGoogleLink::query()->with('hotel.team')->each(function (HotelGoogleLink $link) use ($healer, &$jobs): void {
            if ($link->hotel?->team !== null && ! $link->hotel->team->isReadOnly()) {
                $jobs += $healer->heal($link);
            }
        });

        $this->line("{$jobs} job(s) queued for missing days.");

        return self::SUCCESS;
    }
}
