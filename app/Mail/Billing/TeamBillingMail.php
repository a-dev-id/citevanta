<?php

namespace App\Mail\Billing;

use App\Mail\ThrottledMail;
use App\Models\Team;
use Illuminate\Support\Carbon;

/**
 * Base for emails about a team's subscription state.
 */
abstract class TeamBillingMail extends ThrottledMail
{
    public function __construct(public Team $team) {}

    protected function date(?\DateTimeInterface $date): ?string
    {
        return $date === null ? null : Carbon::instance($date)->setTimezone($this->team->timezoneName())->format('j M Y');
    }

    /**
     * @return array<string, mixed>
     */
    protected function teamData(): array
    {
        return [
            'team' => $this->team,
            'billingUrl' => route('settings.billing'),
        ];
    }
}
