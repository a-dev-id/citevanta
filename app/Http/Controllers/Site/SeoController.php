<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Services\Plans\PlanCatalog;
use App\Services\Site\PublicPages;
use App\Services\Site\SiteContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;

/**
 * sitemap.xml (with hreflang alternates), robots.txt and /llms.txt.
 */
class SeoController extends Controller
{
    public function sitemap(): Response
    {
        $xml = ['<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'];

        foreach (PublicPages::PAGES as $page => $priority) {
            $alternates = PublicPages::alternates($page);

            foreach (PublicPages::locales() as $locale) {
                $xml[] = '  <url>';
                $xml[] = '    <loc>'.e(PublicPages::url($page, $locale)).'</loc>';
                foreach ($alternates as $hreflang => $url) {
                    $xml[] = '    <xhtml:link rel="alternate" hreflang="'.e($hreflang).'" href="'.e($url).'"/>';
                }
                $xml[] = '    <changefreq>'.($page === 'home' || $page === 'pricing' ? 'weekly' : 'monthly').'</changefreq>';
                $xml[] = '    <priority>'.$priority.'</priority>';
                $xml[] = '  </url>';
            }
        }

        // Direct Rates Pages of published hotels (the date pages are reached from them).
        foreach ($this->ratesHotels() as $hotel) {
            $updated = $hotel->rates()->max('updated_at');
            $xml[] = '  <url>';
            $xml[] = '    <loc>'.e(route('rates.show', $hotel->slug)).'</loc>';
            if ($updated) {
                $xml[] = '    <lastmod>'.e(gmdate('Y-m-d', strtotime((string) $updated))).'</lastmod>';
            }
            $xml[] = '    <changefreq>weekly</changefreq>';
            $xml[] = '    <priority>0.6</priority>';
            $xml[] = '  </url>';
        }

        $xml[] = '</urlset>';

        return response(implode("\n", $xml)."\n", 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /**
     * Everyone, AI crawlers included, may read the public site (we practise
     * what we sell); app pages and private reports are off limits.
     */
    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            'Allow: /',
            'Allow: /rates/',
            'Disallow: /dashboard',
            'Disallow: /hotels',
            'Disallow: /admin',
            'Disallow: /settings',
            'Disallow: /billing',
            'Disallow: /email/',
            'Disallow: /audit/',
            'Disallow: /go/',
            'Disallow: /cron',
            'Disallow: /webhooks',
            '',
            'Sitemap: '.route('sitemap'),
        ];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * Web app manifest for the browser tab / home screen icons. Served by the
     * app (not a static file) so the name always comes from config('app.name').
     */
    public function manifest(): JsonResponse
    {
        $name = (string) config('app.name');
        $version = '?v='.config('citevanta.asset_version');

        return response()->json([
            'name' => $name,
            'short_name' => $name,
            'description' => __('site.meta.home_description', ['app' => $name]),
            'start_url' => '/',
            'scope' => '/',
            'display' => 'browser',
            'theme_color' => (string) config('citevanta.brand_color'),
            'background_color' => '#ffffff',
            'icons' => [
                ['src' => asset('icon-192.png').$version, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => asset('icon-512.png').$version, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => asset('favicon.svg').$version, 'sizes' => 'any', 'type' => 'image/svg+xml'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json; charset=UTF-8']);
    }

    /**
     * Plain-text summary for AI assistants (llmstxt.org format), always in English.
     */
    public function llms(SiteContent $content, PlanCatalog $plans): Response
    {
        App::setLocale(PublicPages::DEFAULT_LOCALE);
        $app = (string) config('app.name');
        $lines = [
            "# {$app}",
            '',
            '> '.$content->t('meta.home_description'),
            '',
            $content->t('hero.lead'),
            '',
            '## Key pages',
            '',
            '- [Home]('.PublicPages::url('home').'): what '.$app.' does for hotels',
            '- [Pricing]('.PublicPages::url('pricing').'): plans and prices per hotel',
            '- [Free AI visibility audit]('.PublicPages::url('free-audit').'): '.$content->t('meta.audit_description'),
            '- [Contact]('.PublicPages::url('contact').')',
            '- [Bahasa Indonesia]('.PublicPages::url('home', 'id').'): the same site in Indonesian',
            '',
            '## Features',
            '',
        ];

        foreach ((array) trans('site.features.items') as $feature) {
            $lines[] = '- '.$feature['title'].': '.strtr($feature['text'], [':app' => $app]);
        }

        $lines[] = '';
        $lines[] = '## Plans (per hotel per month)';
        $lines[] = '';
        foreach ($plans->plans() as $plan) {
            $prices = array_filter([
                $plan['monthly']['usd'] !== null ? PlanCatalog::format($plan['monthly']['usd'], 'usd') : null,
                $plan['monthly']['idr'] !== null ? PlanCatalog::format($plan['monthly']['idr'], 'idr') : null,
            ]);
            $limits = $plan['limits'];
            $lines[] = "- {$plan['name']}: ".implode(' / ', $prices).
                " per hotel per month; {$limits->promptsPerHotel} traveller questions, {$limits->competitors} competitors, up to {$limits->languages} languages, {$limits->scansPerMonth} scheduled scans per month, {$limits->contentGenerationsPerMonth} content pieces per month.";
        }
        $lines[] = '- Annual billing: '.$plans->annualMonths().' x the monthly price.';
        $lines[] = '- Free trial: '.$plans->trialDays().' days with a limited scan of 15 questions on ChatGPT, no card needed.';
        $lines[] = '- '.$content->t('pricing.groups_text');
        $hotels = $this->ratesHotels();
        if ($hotels->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '## Direct rates';
            $lines[] = '';
            $lines[] = 'Hotels publish the rates they entered themselves, with the time they were last updated. A date without a rate says "rates on request". Dates have their own pages: /rates/{hotel}/{yyyy-mm-dd}.';
            foreach ($hotels as $hotel) {
                $lines[] = '- ['.$hotel->name.']('.route('rates.show', $hotel->slug).'): direct rates and booking link, '.$hotel->city.($hotel->country ? ', '.$hotel->country : '');
            }
        }

        $lines[] = '';
        $lines[] = '## FAQ';
        $lines[] = '';
        foreach ([...$content->faq('home'), ...$content->faq('pricing')] as $item) {
            $lines[] = '### '.$item['q'];
            $lines[] = '';
            $lines[] = $item['a'];
            $lines[] = '';
        }

        return response(implode("\n", $lines), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * Hotels whose Direct Rates Page is public.
     *
     * @return Collection<int, Hotel>
     */
    private function ratesHotels()
    {
        return Hotel::query()->where('rates_published', true)->where('is_audit', false)->with('team')->orderBy('name')->get()
            ->filter(fn (Hotel $hotel) => $hotel->team->isPrivate())->values();
    }
}
