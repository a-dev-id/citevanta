<?php

namespace App\Services\Recommendations;

use App\Enums\DomainType;
use App\Enums\Effort;
use App\Enums\HotelHasFeature;
use App\Enums\Impact;
use App\Enums\IssueSeverity;
use App\Enums\IssueStatus;
use App\Enums\Persona;
use App\Enums\RecommendationCategory;
use App\Models\AccuracyIssue;
use App\Models\CompetitorInsight;
use App\Models\Hotel;
use App\Models\ScanRun;
use App\Services\Dashboard\DashboardService;
use App\Services\Dashboard\DomainClassifier;
use App\Services\Dashboard\DomainContext;
use App\Services\Insights\Accuracy\AccuracyService;
use App\Services\Insights\Competitors\CompetitorInsightReport;
use App\Services\Insights\FakeFeatureLexicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Builds recommendation drafts from one completed scan's evidence. Every
 * sentence uses only stored evidence and the hotel's own facts; nothing about
 * the hotel is invented. Each draft has a stable key, so the same problem
 * updates the same recommendation scan after scan.
 *
 * Rules:
 *  - persona visibility below the threshold (website content)
 *  - open accuracy issues (facts) and missing facts, including competitor
 *    features the facts do not cover
 *  - own website not cited / cited less than competitors' sites, with schema markup
 *  - sources that recommend competitors but not the hotel (by domain type)
 *  - competitor features the hotel also has (tell AI), or lacks (lead with
 *    other strengths)
 *  - OTA links far ahead of direct links
 */
final class RecommendationRules
{
    public const LOW_VISIBILITY = 40.0;

    public const MAX_SOURCES = 5;

    public const MAX_FEATURES = 5;

    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly CompetitorInsightReport $competitorInsights,
        private readonly AccuracyService $accuracy,
        private readonly DomainClassifier $classifier,
    ) {}

    /**
     * @return list<RecommendationDraft>
     */
    public function build(ScanRun $scan): array
    {
        $scan->loadMissing('hotel');
        $hotel = $scan->hotel;
        $facts = $this->accuracy->factsFor($hotel);
        $competitorSummary = $this->competitorInsights->summary($scan);

        return array_values(array_filter([
            ...$this->personas($hotel, $scan),
            ...$this->accuracyIssues($hotel),
            $this->missingFacts($hotel, $competitorSummary),
            ...$this->website($hotel, $scan),
            ...$this->sources($hotel, $competitorSummary),
            ...$this->competitorFeatures($hotel, $scan, $competitorSummary, $facts),
            $this->otaShare($hotel, $scan),
        ]));
    }

    /** @return list<RecommendationDraft> */
    private function personas(Hotel $hotel, ScanRun $scan): array
    {
        $drafts = [];
        $prompts = $hotel->prompts()->withTrashed()->whereIn('id', $scan->prompt_ids ?? [])->get();

        foreach ($this->dashboard->personas($scan) as $row) {
            $persona = Persona::tryFrom($row['persona']);

            if ($persona === null || $row['answers'] < 2 || $row['visibility'] >= self::LOW_VISIBILITY) {
                continue;
            }

            $who = $persona->travellers();
            $evidence = $prompts->where('persona', $persona)->take(3)->map(fn ($prompt) => [
                'type' => 'prompt',
                'label' => Str::limit((string) $prompt->text, 90),
                'url' => route('hotels.scans.show', [$hotel, $scan]).'#prompt-result-'.$prompt->id,
            ])->values()->all();

            $drafts[] = new RecommendationDraft(
                key: 'persona-visibility:'.$persona->value,
                category: RecommendationCategory::WebsiteContent,
                title: "Show {$who} why {$hotel->name} is right for them",
                why: "In the latest scan, AI assistants mentioned {$hotel->name} in {$row['mentions']} of {$row['answers']} answers to questions from {$who} ("
                    .DashboardService::pct($row['visibility']).'). AI recommends hotels whose websites clearly say who they are for.',
                steps: [
                    "Open the page {$who} would read first (your home page, or a page made for them).",
                    "Add a short section that says plainly why {$hotel->name} suits {$who}: what you offer them and where you are.",
                    'Use the traveller questions listed under Evidence as a guide: answer them in full sentences.',
                    'Use only details you can confirm, and add them to your '.config('app.name').' facts as well.',
                    'Use "Create content" to draft the section from your facts.',
                ],
                where: 'Your website',
                effort: Effort::HalfDay,
                impact: $row['visibility'] < 15 ? Impact::High : Impact::Medium,
                evidence: $evidence,
                metric: ['type' => 'persona', 'value' => $persona->value],
                strength: (int) round((self::LOW_VISIBILITY - $row['visibility']) / 6),
            );
        }

        return $drafts;
    }

    /** @return list<RecommendationDraft> */
    private function accuracyIssues(Hotel $hotel): array
    {
        return $hotel->accuracyIssues()->issues()->where('status', IssueStatus::Open->value)
            ->orderBy('id')->get()
            ->map(fn (AccuracyIssue $issue) => new RecommendationDraft(
                key: 'accuracy:'.$issue->id,
                category: RecommendationCategory::Facts,
                title: "Correct what AI says about {$issue->fact_key}",
                why: "AI assistants said: “{$issue->ai_claim}” ({$issue->occurrences} "
                    .Str::plural('time', $issue->occurrences).'). Your facts say: '.($issue->true_value ?? '—').'. AI often repeats what old listings and pages say.',
                steps: [
                    "Check every page on your website that mentions {$issue->fact_key}, and make sure it says: ".($issue->true_value ?? 'the correct value').'.',
                    'Update the same detail on your Booking.com and Agoda listings and your Google Business Profile.',
                    'If an article or directory still shows the old information, ask the site to update it.',
                    'Use "Create content" for an FAQ answer with the correct information for your website.',
                ],
                where: 'Website, OTA listings, Google Business Profile',
                effort: Effort::Hour,
                impact: $issue->severity === IssueSeverity::High ? Impact::High : Impact::Medium,
                evidence: [[
                    'type' => 'issue',
                    'label' => "Accuracy issue: {$issue->fact_key}",
                    'url' => route('hotels.accuracy', $hotel).'#issue-'.$issue->id,
                ]],
                strength: min(7, $issue->occurrences),
            ))
            ->values()->all();
    }

    /**
     * @param  array{reasons: list<array{reason: string, competitors: list<string>, prompts: int, hotel_has: HotelHasFeature}>}  $competitorSummary
     */
    private function missingFacts(Hotel $hotel, array $competitorSummary): ?RecommendationDraft
    {
        $fromAnswers = $hotel->accuracyIssues()->missingFacts()->where('status', IssueStatus::Open->value)
            ->orderByDesc('occurrences')->pluck('fact_key');
        $fromCompetitors = collect($competitorSummary['reasons'])
            ->where('hotel_has', HotelHasFeature::Unknown)
            ->reject(fn ($row) => str_starts_with($row['reason'], 'mentioned by'))
            ->pluck('reason');
        $topics = $fromAnswers->merge($fromCompetitors)->map(fn ($t) => Str::ucfirst(trim((string) $t)))->unique(fn ($t) => Str::slug($t))->values();

        if ($topics->isEmpty()) {
            return null;
        }

        $list = $topics->take(8)->implode(', ');

        return new RecommendationDraft(
            key: 'missing-facts',
            category: RecommendationCategory::Facts,
            title: 'Add '.$topics->count().' missing '.Str::plural('fact', $topics->count()).' so we can check what AI says',
            why: "AI assistants talk about these topics, but your facts do not cover them, so we cannot tell whether AI is right: {$list}.",
            steps: [
                'Open your hotel in '.config('app.name').' and go to Edit > Facts.',
                "Add a fact for each topic: {$list}. Write what is true today, or \"No\" if you do not offer it.",
                'The next scan checks AI answers against them and shows anything it gets wrong.',
            ],
            where: config('app.name').': Hotel > Edit > Facts',
            effort: Effort::Minutes,
            impact: $topics->count() >= 5 ? Impact::High : Impact::Medium,
            evidence: [['type' => 'page', 'label' => 'Missing facts on the Accuracy page', 'url' => route('hotels.accuracy', $hotel)]],
            strength: min(7, $topics->count()),
        );
    }

    /** @return list<RecommendationDraft> */
    private function website(Hotel $hotel, ScanRun $scan): array
    {
        $summary = $scan->citation_summary ?? [];
        $ownDomain = $summary['own_domain'] ?? null;

        if ($ownDomain === null) {
            return [new RecommendationDraft(
                key: 'website-missing',
                category: RecommendationCategory::Facts,
                title: 'Add your website to '.config('app.name'),
                why: 'Without your website we cannot tell whether AI assistants cite it, which is one of the strongest signals of how they see your hotel.',
                steps: ['Open your hotel in '.config('app.name').' and go to Edit > Details.', 'Enter your website address (the home page).'],
                where: config('app.name').': Hotel > Edit > Details',
                effort: Effort::Minutes,
                impact: Impact::Medium,
            )];
        }

        $context = DomainContext::forHotel($hotel);
        $domains = collect($summary['domains'] ?? []);
        $competitorCitations = (int) $domains->filter(fn ($row) => $this->classifier->classify((string) $row['domain'], $context) === DomainType::Competitor)->sum('citations');
        $ownCitations = (int) ($summary['own_citations'] ?? 0);
        $total = (int) ($summary['total_citations'] ?? 0);
        $topCited = $domains->take(3)->pluck('domain')->implode(', ');
        $domainEvidence = $domains->take(3)->map(fn ($row) => [
            'type' => 'domain', 'label' => $row['domain'].' ('.$row['citations'].' citations)', 'url' => route('hotels.citations.domain', [$hotel, $row['domain']]),
        ])->values()->all();
        $drafts = [];

        if ($total > 0 && $ownCitations === 0) {
            $drafts[] = new RecommendationDraft(
                key: 'website-not-cited',
                category: RecommendationCategory::WebsiteContent,
                title: "Get {$ownDomain} cited by AI assistants",
                why: "None of the {$total} links in the latest scan pointed to {$ownDomain}. AI cited {$topCited} instead. AI assistants cite pages that answer travellers' questions directly.",
                steps: [
                    'Add an FAQ page that answers the questions travellers ask about your hotel, in plain text (not in images or PDFs).',
                    'Give each type of traveller (families, couples, business) a clear section that says what you offer them.',
                    'Put key facts as text on your pages: location and distances, facilities, room types, check-in times.',
                    'Ask your web developer to check that robots.txt does not block AI crawlers (GPTBot, OAI-SearchBot).',
                    'Add schema markup (see the Schema markup recommendation).',
                ],
                where: 'Your website',
                effort: Effort::HalfDay,
                impact: Impact::High,
                evidence: $domainEvidence,
                strength: 5,
            );
        } elseif ($competitorCitations > $ownCitations) {
            $drafts[] = new RecommendationDraft(
                key: 'website-cited-less',
                category: RecommendationCategory::WebsiteContent,
                title: "Get {$ownDomain} cited as often as your competitors' websites",
                why: "AI cited {$ownDomain} {$ownCitations} ".Str::plural('time', $ownCitations)." and your competitors' websites {$competitorCitations} times in the latest scan.",
                steps: [
                    'Compare your competitors\' cited pages (under Evidence) with your own pages on the same topic.',
                    'Add the information they give that you do not, where it is true for your hotel.',
                    'Add an FAQ section with clear answers to common traveller questions.',
                ],
                where: 'Your website',
                effort: Effort::HalfDay,
                impact: Impact::Medium,
                evidence: $domainEvidence,
                strength: min(7, $competitorCitations - $ownCitations),
            );
        }

        if ($total > 0 && ($ownCitations === 0 || $competitorCitations > $ownCitations)) {
            $drafts[] = new RecommendationDraft(
                key: 'schema-markup',
                category: RecommendationCategory::SchemaMarkup,
                title: 'Add hotel schema markup to your website',
                why: 'Schema markup tells search engines and AI assistants your hotel\'s key facts in a format they read reliably. It is built from your '.config('app.name').' facts.',
                steps: [
                    'Click "Create content" and choose Schema markup (JSON-LD).',
                    'Replace any [add: ...] placeholders with the real details, or add those facts in '.config('app.name').' and create it again.',
                    'Ask your web developer to paste it in a <script type="application/ld+json"> tag in the <head> of your home page.',
                    'Check it with Google\'s Rich Results Test (search.google.com/test/rich-results).',
                ],
                where: 'Your website (home page code)',
                effort: Effort::Hour,
                impact: Impact::Medium,
                strength: 3,
            );
        }

        return $drafts;
    }

    /**
     * @param  array{sources: list<array{domain: string, competitors: list<string>, prompts: int, answers: int}>}  $competitorSummary
     * @return list<RecommendationDraft>
     */
    private function sources(Hotel $hotel, array $competitorSummary): array
    {
        $context = DomainContext::forHotel($hotel);

        return collect($competitorSummary['sources'])->take(self::MAX_SOURCES)
            ->map(function (array $source) use ($hotel, $context) {
                $domain = $source['domain'];
                $names = implode(' and ', array_slice($source['competitors'], 0, 3));
                $type = $this->classifier->classify($domain, $context);
                $evidence = [['type' => 'domain', 'label' => "{$domain} (cited in {$source['answers']} ".Str::plural('answer', $source['answers']).')', 'url' => route('hotels.citations.domain', [$hotel, $domain])]];
                $why = "AI cited {$domain} in answers that recommended {$names} but not {$hotel->name} ({$source['prompts']} ".Str::plural('prompt', $source['prompts']).').';
                $impact = $source['prompts'] >= 2 ? Impact::High : Impact::Medium;

                return match ($type) {
                    DomainType::Ota => new RecommendationDraft(
                        key: 'source:'.$domain,
                        category: RecommendationCategory::OtaListing,
                        title: "Update your listing on {$domain}",
                        why: $why.' AI reads OTA listings to decide which hotels to suggest, and the OTA builds your description from the facilities and settings you enter.',
                        steps: [
                            'Use "Create content" > OTA listing update for a checklist of facilities, room details, location and policies to check, built from your facts.',
                            "Work through the checklist in the {$domain} extranet, and the same items in your Booking.com and Agoda listings.",
                            'Paste the neighbourhood text into Booking.com "About the neighbourhood" and send the Agoda text to Agoda support via "Need Help" in YCS.',
                            'Reply to recent reviews.',
                        ],
                        where: $domain,
                        effort: Effort::Hour,
                        impact: $impact,
                        evidence: $evidence,
                        strength: $source['answers'],
                    ),
                    DomainType::ReviewSite => new RecommendationDraft(
                        key: 'source:'.$domain,
                        category: str_contains($domain, 'google') ? RecommendationCategory::GoogleBusinessProfile : RecommendationCategory::Reviews,
                        title: str_contains($domain, 'google') ? 'Complete your Google Business Profile and grow Google reviews' : "Grow and answer your reviews on {$domain}",
                        why: $why.' AI often summarises what recent reviews say.',
                        steps: [
                            "Claim or log in to your {$domain} listing and complete every section.",
                            'Ask recent guests for a review, mentioning the things they enjoyed (use "Create content" for the message).',
                            'Reply to every review, briefly and personally.',
                        ],
                        where: $domain,
                        effort: Effort::Ongoing,
                        impact: $impact,
                        evidence: $evidence,
                        strength: $source['answers'],
                    ),
                    default => new RecommendationDraft(
                        key: 'source:'.$domain,
                        category: RecommendationCategory::TravelMedia,
                        title: "Get {$hotel->name} featured on {$domain}",
                        why: $why.' Travel guides and blogs are among the sources AI trusts most.',
                        steps: [
                            "Open the cited page on {$domain} (see Evidence) and find who wrote it.",
                            'Send a short, personal pitch: what makes your hotel a good fit for that article, with facts and photos.',
                            'Offer a stay or a call, and follow up once after a week.',
                            'Use "Create content" for a pitch email built from your facts.',
                        ],
                        where: $domain,
                        effort: Effort::Ongoing,
                        impact: $impact,
                        evidence: $evidence,
                        strength: $source['answers'],
                    ),
                };
            })
            ->values()->all();
    }

    /**
     * @param  array{reasons: list<array{reason: string, competitors: list<string>, prompts: int, hotel_has: HotelHasFeature}>}  $competitorSummary
     * @param  array<string, string>  $facts
     * @return list<RecommendationDraft>
     */
    private function competitorFeatures(Hotel $hotel, ScanRun $scan, array $competitorSummary, array $facts): array
    {
        $reasons = collect($competitorSummary['reasons'])->reject(fn ($row) => str_starts_with($row['reason'], 'mentioned by'));
        $insights = $this->competitorInsights->byPrompt($scan);
        $promptFor = fn (string $reason) => $insights->first(fn ($insight) => collect($insight->reasons)->contains(fn ($r) => Str::slug($r['reason']) === Str::slug($reason)))?->prompt_id;
        $drafts = [];

        foreach ($reasons->where('hotel_has', HotelHasFeature::HasIt)->take(self::MAX_FEATURES) as $row) {
            $names = implode(' and ', array_slice($row['competitors'], 0, 3));
            $feature = $row['reason'];
            $factValue = $this->factValueFor($facts, $insights, $feature);
            $promptId = $promptFor($feature);

            $drafts[] = new RecommendationDraft(
                key: 'feature:'.Str::slug($feature),
                category: RecommendationCategory::WebsiteContent,
                title: "Tell AI about your {$feature}",
                why: "AI recommended {$names} for {$row['prompts']} ".Str::plural('prompt', $row['prompts'])." because of their {$feature}. Your facts say you have it"
                    .($factValue !== null ? " ({$factValue})" : '').", but AI did not mention it for {$hotel->name}.",
                steps: [
                    "Add a clear sentence about your {$feature} to the relevant page of your website (and the home page if it matters to many guests).",
                    'Update your Booking.com and Agoda listings: make sure it is ticked in the facilities ("Create content" > OTA listing update gives a checklist), and mention it in your Google Business Profile.',
                    'Add a photo with a descriptive caption.',
                    'Use "Create content" to draft the text from your facts.',
                ],
                where: 'Website, OTA listings, Google Business Profile',
                effort: Effort::Hour,
                impact: Impact::High,
                evidence: $promptId ? [['type' => 'prompt', 'label' => 'Prompt where competitors won on this', 'url' => route('hotels.scans.show', [$hotel, $scan]).'#prompt-result-'.$promptId]] : [],
                metric: $promptId ? ['type' => 'prompt', 'value' => $promptId] : null,
                strength: $row['prompts'] + 2,
            );
        }

        $lacks = $reasons->where('hotel_has', HotelHasFeature::LacksIt)->pluck('reason')->unique()->values();
        $strengths = collect($facts)->reject(fn ($value) => FakeFeatureLexicon::isNegative($value))->keys()->take(3)->values();

        if ($lacks->isNotEmpty()) {
            $drafts[] = new RecommendationDraft(
                key: 'different-strengths',
                category: RecommendationCategory::WebsiteContent,
                title: 'Lead with your own strengths where competitors win on things you do not offer',
                why: 'Competitors were recommended for '.$lacks->take(4)->implode(', ').', which your facts say you do not have.'
                    .($strengths->isNotEmpty() ? ' Your facts list strengths AI could mention instead: '.$strengths->implode(', ').'.' : ''),
                steps: [
                    'Pick 2 or 3 things guests choose you for (from your facts), for the same type of traveller.',
                    'Write a short section about them on your website, and update your Booking.com and Agoda listings so those facilities are ticked.',
                    'Do not claim features you do not have: AI checks and travellers notice.',
                    'Use "Create content" to draft the section from your facts.',
                ],
                where: 'Website, OTA listings',
                effort: Effort::HalfDay,
                impact: Impact::Medium,
                strength: $lacks->count(),
            );
        }

        return $drafts;
    }

    private function otaShare(Hotel $hotel, ScanRun $scan): ?RecommendationDraft
    {
        $links = DashboardService::linkTypes(
            $this->dashboard->domainCounts($scan)->map(fn ($row) => $row + ['type' => $this->classifier->classify($row['domain'], DomainContext::forHotel($hotel))->value]),
        );
        $share = collect($links['types'])->mapWithKeys(fn ($row) => [$row['type']->value => $row['share']]);
        $ota = (float) ($share['ota'] ?? 0);
        $direct = (float) ($share['own'] ?? 0);

        if ($links['total'] < 5 || $ota < 30 || $direct >= $ota / 2) {
            return null;
        }

        return new RecommendationDraft(
            key: 'ota-share',
            category: RecommendationCategory::OtaListing,
            title: 'AI sends travellers to booking sites more than to your website',
            why: DashboardService::pct($ota).' of the links AI cited point to booking sites, and '.DashboardService::pct($direct).' to your own website.',
            steps: [
                'Update your Booking.com and Agoda listings so facilities, room details and policies are complete ("Create content" > OTA listing update gives a checklist): AI reads them, so they shape what it says about you.',
                'On your website, say clearly why booking direct is worth it (only the benefits you really offer).',
                'Make your own pages answer the same questions as your OTA listing: facilities, location, room types.',
            ],
            where: 'OTA listings and your website',
            effort: Effort::Hour,
            impact: Impact::Medium,
            evidence: [['type' => 'page', 'label' => 'Where AI gets its information (dashboard)', 'url' => route('hotels.scans.show', [$hotel, $scan])]],
            strength: (int) round(($ota - $direct) / 10),
        );
    }

    /**
     * The hotel's value for the fact a competitor reason points at, if any.
     *
     * @param  array<string, string>  $facts
     * @param  Collection<int, CompetitorInsight>  $insights
     */
    private function factValueFor(array $facts, Collection $insights, string $reason): ?string
    {
        $factKey = $insights->flatMap(fn ($insight) => $insight->reasons)
            ->first(fn ($r) => Str::slug($r['reason']) === Str::slug($reason) && ! empty($r['fact_key']))['fact_key'] ?? null;

        return $factKey !== null ? ($facts[$factKey] ?? null) : null;
    }
}
