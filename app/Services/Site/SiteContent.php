<?php

namespace App\Services\Site;

use App\Services\Plans\PlanCatalog;

/**
 * Shared data for public pages: translation placeholders (product name,
 * trial days, discounts), FAQ lists and the JSON-LD documents
 * (Organization, SoftwareApplication with offers, FAQPage).
 */
final class SiteContent
{
    public function __construct(private readonly PlanCatalog $plans) {}

    /**
     * Placeholders used across the site copy (lang/{locale}/site.php).
     *
     * @return array<string, string|int>
     */
    public function params(): array
    {
        $discounts = $this->plans->discounts();

        return [
            'app' => (string) config('app.name'),
            'days' => $this->plans->trialDays(),
            'months' => $this->plans->annualMonths(),
            'group' => (int) ($discounts['group']['percent'] ?? 0),
            'groupMin' => (int) ($discounts['group']['min_hotels'] ?? 0),
            'agency' => (int) ($discounts['agency']['percent'] ?? 0),
            'agencyMin' => (int) ($discounts['agency']['min_hotels'] ?? 0),
        ];
    }

    /**
     * Translated text with the site placeholders filled in.
     */
    public function t(string $key, array $replace = []): string
    {
        return (string) __("site.{$key}", $replace + $this->params());
    }

    /**
     * FAQ items with placeholders filled in.
     *
     * @return list<array{q: string, a: string}>
     */
    public function faq(string $set): array
    {
        $params = $this->params();

        return array_map(fn (array $item) => [
            'q' => strtr($item['q'], $this->placeholders($params)),
            'a' => strtr($item['a'], $this->placeholders($params)),
        ], (array) trans("site.faq.{$set}"));
    }

    /**
     * @return array<string, mixed>
     */
    public function organization(): array
    {
        $organization = [
            '@type' => 'Organization',
            '@id' => url('/').'#organization',
            'name' => config('app.name'),
            'url' => url('/'),
            'logo' => asset('icon-512.png'),
            'description' => $this->t('meta.tagline'),
        ];

        $email = config('citevanta.site.contact_email');
        if ($email) {
            $organization['contactPoint'] = ['@type' => 'ContactPoint', 'contactType' => 'customer support', 'email' => $email];
        }

        return $organization;
    }

    /**
     * The product with one offer per plan and currency (monthly price per hotel).
     *
     * @return array<string, mixed>
     */
    public function softwareApplication(): array
    {
        $offers = [];
        foreach ($this->plans->plans() as $plan) {
            foreach (['usd' => 'USD', 'idr' => 'IDR'] as $key => $currency) {
                if ($plan['monthly'][$key] !== null) {
                    $offers[] = [
                        '@type' => 'Offer',
                        'name' => $plan['name'],
                        'price' => (string) $plan['monthly'][$key],
                        'priceCurrency' => $currency,
                        'url' => PublicPages::url('pricing'),
                        'description' => 'Per hotel per month',
                    ];
                }
            }
        }

        return [
            '@type' => 'SoftwareApplication',
            'name' => config('app.name'),
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Web',
            'url' => url('/'),
            'description' => $this->t('meta.home_description'),
            'publisher' => ['@id' => url('/').'#organization'],
            'offers' => $offers,
        ];
    }

    /**
     * @param  list<array{q: string, a: string}>  $faq
     * @return array<string, mixed>
     */
    public function faqPage(array $faq): array
    {
        return [
            '@type' => 'FAQPage',
            'mainEntity' => array_map(fn (array $item) => [
                '@type' => 'Question',
                'name' => $item['q'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a']],
            ], $faq),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    public static function jsonLd(array $nodes): string
    {
        return (string) json_encode(
            ['@context' => 'https://schema.org', '@graph' => $nodes],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param  array<string, string|int>  $params
     * @return array<string, string>
     */
    private function placeholders(array $params): array
    {
        $map = [];
        // Longest names first so :groupMin is replaced before :group.
        uksort($params, fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($params as $key => $value) {
            $map[':'.$key] = (string) $value;
        }

        return $map;
    }
}
