<?php

namespace App\Services\Tracking;

use App\Models\HotelTracking;
use App\Services\Support\Url;

/**
 * "Check installation": fetches the hotel's home page (safely, see SafeSiteFetcher) and looks for the script.
 */
final class TrackingInstallCheck
{
    public function __construct(private readonly SafeSiteFetcher $fetcher) {}

    /**
     * @return array{installed: bool, message: string}
     */
    public function run(HotelTracking $tracking): array
    {
        $website = Url::normalize($tracking->hotel->website);

        if ($website === null) {
            return $this->save($tracking, false, 'Add your hotel\'s website in the hotel settings first, then check again.');
        }

        $page = $this->fetcher->fetch($website);

        if (! $page['ok']) {
            return $this->save($tracking, false, 'We could not open your website: '.$page['error'].'.');
        }

        // The script address contains the public key; either form of the address counts.
        if (str_contains($page['body'], '/t/'.$tracking->public_key.'.js')) {
            return $this->save($tracking, true, 'The script was found on '.Url::host($website).'.');
        }

        return $this->save($tracking, false, 'We opened your home page but did not find the script. Ask your web developer to add the line above before the closing head tag, then check again. (A site that adds scripts after the page loads may still work: use the debug mode below.)');
    }

    /**
     * @return array{installed: bool, message: string}
     */
    private function save(HotelTracking $tracking, bool $installed, string $message): array
    {
        $tracking->forceFill(['last_checked_at' => now(), 'installed' => $installed, 'last_check_result' => mb_substr($message, 0, 300)])->save();

        return ['installed' => $installed, 'message' => $message];
    }
}
