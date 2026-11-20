<?php

namespace App\Services\Google;

use App\Jobs\GoogleHealthJob;
use App\Models\Hotel;
use App\Models\HotelGoogleLink;
use App\Services\Support\Url;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Settings > Connect Google: finds the GA4 properties and Search Console
 * sites shared with the agent that belong to the hotel's website, links
 * the ones the owner picks, and unlinks them again.
 */
final class GoogleLinks
{
    public function __construct(private readonly GoogleApi $api) {}

    /**
     * "Check connection".
     *
     * @return array{properties: list<array{property: string, displayName: string, urls: list<string>}>, sites: list<array{siteUrl: string, permissionLevel: string}>, errors: list<string>}
     *
     * @throws GoogleApiException when the agent itself is not connected
     */
    public function candidates(Hotel $hotel): array
    {
        $domain = Url::host((string) $hotel->website);
        $errors = [];

        if ($domain === '') {
            return ['properties' => [], 'sites' => [], 'errors' => ['Add your hotel website first (Hotels > Edit), so we can find its GA4 property and Search Console site.']];
        }

        $properties = [];
        foreach ($this->api->properties() as $property) {
            // Web stream URLs change rarely: cache them for a day (one Admin API call per property).
            $urls = Cache::remember('google:streams:'.$property['property'], now()->addDay(), fn () => $this->api->webStreamUrls($property['property']));

            if (collect($urls)->contains(fn (string $url) => Url::sameSite($url, $domain))) {
                $properties[] = $property + ['urls' => $urls];
            }
        }

        $sites = array_values(array_filter($this->api->sites(), fn (array $site) => Url::sameSite(str_replace('sc-domain:', '', $site['siteUrl']), $domain)));

        $email = (string) config('services.google.agent_email');
        if ($properties === []) {
            $errors[] = "We can't see any GA4 property for {$domain} yet. Did you add {$email} as Viewer in GA4 (Admin > Property access management)?";
        }
        if ($sites === []) {
            $errors[] = "We can't see a Search Console site for {$domain} yet. Did you add {$email} as a Restricted user (Settings > Users and permissions)?";
        }

        return ['properties' => $properties, 'sites' => $sites, 'errors' => $errors];
    }

    /**
     * Save the owner's choice; the health check and the first sync start.
     *
     * @throws GoogleApiException
     */
    public function link(Hotel $hotel, ?string $property, ?string $site): HotelGoogleLink
    {
        $candidates = $this->candidates($hotel);
        $property = collect($candidates['properties'])->firstWhere('property', $property);
        $site = collect($candidates['sites'])->firstWhere('siteUrl', $site);

        if ($property === null && $site === null) {
            throw new GoogleApiException('Pick a GA4 property or a Search Console site from the list.');
        }

        $details = $property ? $this->api->propertyDetails($property['property']) : ['timeZone' => null, 'currencyCode' => null];
        $link = HotelGoogleLink::query()->firstOrNew(['hotel_id' => $hotel->id]);
        $changed = $link->ga4_property !== ($property['property'] ?? null) || $link->gsc_site !== ($site['siteUrl'] ?? null);

        $link->fill([
            'team_id' => $hotel->team_id,
            'ga4_property' => $property['property'] ?? null,
            'ga4_property_name' => $property['displayName'] ?? null,
            'ga4_currency' => $details['currencyCode'],
            'ga4_timezone' => $details['timeZone'],
            'gsc_site' => $site['siteUrl'] ?? null,
        ]);

        if ($changed) {
            $link->fill(['first_synced_at' => null, 'health' => null, 'health_checked_at' => null]);
        }

        $link->save();

        GoogleHealthJob::dispatchAll($link);
        app(GoogleSync::class)->schedule($link);

        return $link;
    }

    /**
     * Remove the link on our side, with the synced data.
     */
    public function unlink(Hotel $hotel): void
    {
        $hotel->googleLink?->delete();

        foreach (['ga4_traffic_daily', 'ga4_events_daily', 'gsc_daily'] as $table) {
            DB::table($table)->where('hotel_id', $hotel->id)->delete();
        }
    }
}
