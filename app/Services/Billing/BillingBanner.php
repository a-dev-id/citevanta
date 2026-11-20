<?php

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\TeamAiKey;
use Carbon\CarbonInterface;

/**
 * The subscription notice at the top of every app page: trial days left,
 * payment overdue, canceled, or read-only.
 */
final class BillingBanner
{
    /**
     * @return array{variant: string, icon: string, text: string, link: ?string}|null
     */
    public function for(?User $user, ?Team $team): ?array
    {
        if ($user === null || $team === null) {
            return null;
        }

        $link = $user->ownsTeam($team) ? route('settings.billing') : null;
        $date = fn ($at) => $at->copy()->setTimezone($team->timezoneName())->format('j M Y');
        $owner = $link !== null ? '' : ' Ask the team owner to renew.';

        if ($team->isReadOnly()) {
            return [
                'variant' => 'danger',
                'icon' => 'fa-lock',
                'text' => ($team->trialHasExpired() ? 'Your trial has ended.' : 'Your subscription has expired.')
                    .' The account is read-only: past results stay visible, but no new scans or content run.'.$owner,
                'link' => $link,
            ];
        }

        if ($team->isPrivate() && ! $team->hasOwnAiKey()) {
            return [
                'variant' => 'warning',
                'icon' => 'fa-key',
                'text' => 'Add your OpenAI API key to start scanning. Your plan uses your own key for every AI call.'.($link ? '' : ' Ask the team owner to add it.'),
                'link' => $user->ownsTeam($team) ? route('settings.ai-key') : null,
                'label' => 'AI key',
            ];
        }

        if ($team->isPrivate() && $team->openai_key_stopped_at !== null) {
            return [
                'variant' => 'danger',
                'icon' => 'fa-circle-stop',
                'text' => 'Scans are paused. '.TeamAiKey::stopMessage($team->openai_key_stop_code),
                'link' => $user->ownsTeam($team) ? route('settings.ai-key') : null,
                'label' => 'AI key',
            ];
        }

        if ($team->awaitsTrialApproval()) {
            return [
                'variant' => 'info',
                'icon' => 'fa-hourglass-half',
                'text' => 'We are preparing your account. You can set up your hotel now; the first scan starts once it is ready, and we will email you.',
                'link' => null,
            ];
        }

        return match ($team->subscription_status) {
            SubscriptionStatus::Trialing => $team->trial_ends_at === null ? null : [
                'variant' => 'info',
                'icon' => 'fa-circle-info',
                'text' => 'Trial: '.$team->trial_ends_at->diffForHumans(['parts' => 1, 'syntax' => CarbonInterface::DIFF_ABSOLUTE]).' left (ends '.$date($team->trial_ends_at).').',
                'link' => $link,
            ],
            SubscriptionStatus::PastDue => [
                'variant' => 'warning',
                'icon' => 'fa-triangle-exclamation',
                'text' => 'Payment overdue. Scans keep running until '.$date($team->grace_ends_at ?? now()).', then the account becomes read-only.'.$owner,
                'link' => $link,
            ],
            SubscriptionStatus::Canceled => [
                'variant' => 'secondary',
                'icon' => 'fa-circle-pause',
                'text' => 'Your subscription is canceled and ends on '.$date($team->current_period_ends_at ?? now()).'.',
                'link' => $link,
            ],
            default => null,
        };
    }
}
