<?php

namespace Tests\Feature\Site;

use App\Mail\ContactMessage;
use App\Models\User;
use App\Services\Site\PublicPages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> */
    public static function pages(): array
    {
        $pages = [];
        foreach (array_keys(PublicPages::PAGES) as $page) {
            foreach (['en', 'id'] as $locale) {
                $pages["{$page} {$locale}"] = [$page, $locale];
            }
        }

        return $pages;
    }

    #[DataProvider('pages')]
    public function test_every_public_page_has_its_own_title_description_canonical_hreflang_and_social_tags(string $page, string $locale): void
    {
        $url = PublicPages::url($page, $locale);

        $html = $this->get($url)->assertOk()->getContent();

        $this->assertStringContainsString('<html lang="'.$locale.'">', $html);
        $this->assertMatchesRegularExpression('/<title>[^<]{10,}<\/title>/', $html);
        $this->assertMatchesRegularExpression('/<meta name="description" content="[^"]{30,}">/', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.$url.'">', $html);
        $this->assertStringContainsString('hreflang="en" href="'.PublicPages::url($page, 'en').'"', $html);
        $this->assertStringContainsString('hreflang="id" href="'.PublicPages::url($page, 'id').'"', $html);
        $this->assertStringContainsString('hreflang="x-default"', $html);
        $this->assertStringContainsString('<meta property="og:title"', $html);
        $this->assertStringContainsString('<meta name="twitter:card"', $html);
        $this->assertStringNotContainsString('livewire.js', $html, 'marketing pages load no Livewire');
    }

    public function test_titles_differ_between_pages(): void
    {
        $titles = collect(array_keys(PublicPages::PAGES))->map(function ($page) {
            preg_match('/<title>(.*?)<\/title>/', $this->get(PublicPages::url($page))->getContent(), $m);

            return $m[1];
        });

        $this->assertSame($titles->count(), $titles->unique()->count());
    }

    public function test_home_and_pricing_have_valid_json_ld_with_offers_and_faq(): void
    {
        foreach (['home', 'pricing'] as $page) {
            preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $this->get(PublicPages::url($page))->getContent(), $m);
            $graph = collect(json_decode($m[1], true, 64, JSON_THROW_ON_ERROR)['@graph'])->keyBy('@type');

            $this->assertSame(config('app.name'), $graph['Organization']['name']);
            $this->assertSame(['49', '99'], collect($graph['SoftwareApplication']['offers'])->where('priceCurrency', 'USD')->pluck('price')->all());
            $this->assertNotEmpty($graph['FAQPage']['mainEntity']);
            $this->assertSame('Answer', $graph['FAQPage']['mainEntity'][0]['acceptedAnswer']['@type']);
        }
    }

    public function test_indonesian_pages_are_in_indonesian(): void
    {
        $this->get(PublicPages::url('home', 'id'))->assertOk()->assertSee('Lihat bagaimana ChatGPT merekomendasikan hotel Anda');
        $this->get(PublicPages::url('home', 'en'))->assertOk()->assertSee('See how ChatGPT recommends your hotel');
    }

    public function test_the_brand_comes_from_the_app_name(): void
    {
        config(['app.name' => 'StayCited']);

        $this->get('/')->assertOk()->assertSee('StayCited – See how ChatGPT')->assertDontSee('CiteVanta');
        $this->get('/llms.txt')->assertSee('# StayCited')->assertDontSee('CiteVanta');
    }

    public function test_pricing_shows_usd_and_only_shows_idr_once_set(): void
    {
        $this->get(PublicPages::url('pricing'))->assertSee('$49')->assertSee('$490')->assertDontSee('data-pricing-set="currency:idr"', false);

        config(['citevanta.pricing.prices.starter.idr' => 790000, 'citevanta.pricing.prices.pro.idr' => 1590000]);

        $this->get(PublicPages::url('pricing', 'id'))->assertSee('Rp 790.000')->assertSee('data-currency="idr"', false);
    }

    public function test_sitemap_robots_and_llms_list_the_pages(): void
    {
        $sitemap = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
        $xml = simplexml_load_string($sitemap);
        $this->assertNotFalse($xml);
        $this->assertCount(count(PublicPages::PAGES) * 2, $xml->url);
        $this->assertStringContainsString('<loc>'.PublicPages::url('pricing', 'id').'</loc>', $sitemap);
        $this->assertStringContainsString('hreflang="x-default"', $sitemap);

        $this->get('/robots.txt')->assertOk()->assertSee('Sitemap: '.route('sitemap'))->assertSee('Disallow: /audit/');

        $llms = $this->get('/llms.txt')->assertOk()->getContent();
        foreach (['home', 'pricing', 'free-audit', 'contact'] as $page) {
            $this->assertStringContainsString(PublicPages::url($page), $llms);
        }
        $this->assertStringContainsString('Starter: $49 per hotel per month', $llms);
    }

    public function test_signed_in_users_opening_the_home_page_go_to_their_dashboard(): void
    {
        $this->actingAs(User::factory()->withTeam()->create())->get('/')->assertRedirect(route('dashboard'));
    }

    public function test_the_contact_form_emails_the_admin(): void
    {
        Mail::fake();
        User::factory()->admin()->create(['email' => 'boss@example.test']);

        $this->post(route('contact.send'), ['name' => 'Wayan', 'email' => 'wayan@hotel.test', 'message' => 'We have 12 hotels, what does that cost?'])
            ->assertRedirect()->assertSessionHas('sent');

        Mail::assertQueued(ContactMessage::class, fn ($mail) => $mail->hasTo('boss@example.test') && $mail->senderEmail === 'wayan@hotel.test');
    }

    public function test_the_contact_form_ignores_bots_and_limits_repeats(): void
    {
        Mail::fake();
        User::factory()->admin()->create();
        $message = ['name' => 'Bot', 'email' => 'bot@spam.test', 'message' => 'Buy cheap followers now!!'];

        $this->post(route('contact.send'), $message + ['fax' => 'filled'])->assertSessionHas('sent');
        Mail::assertNothingQueued();

        foreach (range(1, 5) as $i) {
            $this->post(route('contact.send'), $message);
        }
        $this->post(route('contact.send'), $message)->assertSessionHasErrors('message');
        Mail::assertQueued(ContactMessage::class, 5);
    }
}
