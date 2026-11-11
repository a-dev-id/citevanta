<?php

namespace App\Http\Controllers;

use App\Models\Citation;
use App\Models\Hotel;
use App\Models\TestRun;
use App\Services\CitationAnalysis;
use App\Services\TenantContext;
use App\Services\VisibilityAnalysis;
use App\Services\VisibilityMetrics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class VisibilityController extends Controller
{
    public function competitors(VisibilityMetrics $metrics): View
    {
        $runs = $metrics->runs();

        return view('visibility.competitors', ['rows' => $metrics->hotels($runs)->filter(fn ($row) => ! $row['hotel']->isMonitoredBrand()), 'total' => $runs->count()]);
    }

    public function opportunities(VisibilityMetrics $metrics): View
    {
        $runs = $metrics->runs();

        return view('visibility.opportunities', ['opportunities' => $metrics->opportunities($runs), 'total' => $runs->count()]);
    }

    public function review(TestRun $testRun, VisibilityAnalysis $analysis): View
    {
        abort_unless($testRun->response && $analysis->isValid($testRun->response), 422, 'Only completed, valid web-search responses can be analyzed.');
        $analysis->preview($testRun->response);
        $testRun->response->loadMissing(['assessments.hotel', 'citations.hotel']);

        return view('visibility.review', ['run' => $testRun, 'hotels' => Hotel::orderBy('name')->get()]);
    }

    public function correct(Request $request, TestRun $testRun, VisibilityAnalysis $analysis): RedirectResponse
    {
        abort_unless($testRun->response && $analysis->isValid($testRun->response), 422);
        $data = $request->validate(['hotel_id' => ['required', Rule::exists('hotels', 'id')->where('organization_id', app(TenantContext::class)->organizationId)->where('monitored_brand_id', app(TenantContext::class)->brandId)], 'decision' => 'required|in:not_mentioned,mentioned,recommended', 'rank' => 'nullable|integer|min:1|max:999', 'evidence' => 'nullable|string|max:6000', 'review_note' => 'required|string|min:5|max:2000', 'citation_ids' => 'nullable|array', 'citation_ids.*' => ['integer', Rule::exists('citations', 'id')->where('organization_id', app(TenantContext::class)->organizationId)->where('monitored_brand_id', app(TenantContext::class)->brandId)->where('ai_response_id', $testRun->response->id)]]);
        $hotel = Hotel::findOrFail($data['hotel_id']);
        $evidence = $data['evidence'] ?? '';
        $mentioned = $data['decision'] !== 'not_mentioned';
        $recommended = $data['decision'] === 'recommended';
        $rank = $data['rank'] ?? null;
        if ($mentioned && ($evidence === '' || ! str_contains($testRun->response->text, $evidence))) {
            throw ValidationException::withMessages(['evidence' => 'Copy an exact excerpt from the original answer as evidence.']);
        }
        if ($rank !== null) {
            $aliases = implode('|', array_map(fn ($a) => preg_quote($a, '/'), [$hotel->name, ...$hotel->aliases]));
            $detected = $analysis->detect($testRun->response->text, Hotel::all());
            $detectedRank = $detected['hotels'][$hotel->id]['rank'] ?? null;
            if (! $recommended || $detectedRank !== (int) $rank || $analysis->hasUnorderedDisclaimer($testRun->response->text) || ! preg_match('/(?:^|\n)\s*(?:#{1,6}\s*)?(?:\*\*)?(?:#\s*)?'.$rank.'(?:[.)]|st|nd|rd|th)\s+[^\n]*(?:'.$aliases.')/iu', $evidence)) {
                throw ValidationException::withMessages(['rank' => 'A comparative rank requires an explicitly ranked comparison of multiple brands in the original evidence, without an unordered-list disclaimer.']);
            }
        }
        DB::transaction(function () use ($testRun, $request, $data, $mentioned, $recommended, $rank, $evidence) {
            $testRun->response()->lockForUpdate()->firstOrFail();
            $testRun->response->assessments()->updateOrCreate(['hotel_id' => $data['hotel_id']], ['mentioned' => $mentioned, 'recommended' => $recommended, 'rank' => $recommended ? $rank : null, 'evidence' => $evidence, 'occurrences' => [], 'supporting_citation_ids' => $mentioned ? array_values(array_unique($data['citation_ids'] ?? [])) : [], 'rule' => 'administrator-evidence-review:v1', 'manual' => true, 'reviewed_by' => $request->user()->id, 'review_note' => $data['review_note']]);
        });

        return redirect()->route('visibility.review', $testRun)->with('status', 'Analysis correction saved. The original AI response is unchanged.');
    }

    public function correctCitation(Request $request, TestRun $testRun, Citation $citation, VisibilityAnalysis $analysis, CitationAnalysis $sources): RedirectResponse
    {
        abort_unless($testRun->response && $analysis->isValid($testRun->response) && $citation->ai_response_id === $testRun->response->id, 404);
        $data = $request->validate(['classification' => ['required', Rule::in(CitationAnalysis::TYPES)], 'hotel_id' => ['nullable', Rule::exists('hotels', 'id')->where('organization_id', app(TenantContext::class)->organizationId)->where('monitored_brand_id', app(TenantContext::class)->brandId)], 'review_note' => 'required|string|min:5|max:2000']);
        $hotel = isset($data['hotel_id']) ? Hotel::findOrFail($data['hotel_id']) : null;
        $official = in_array($data['classification'], ['nandini_official', 'competitor_official'], true);
        if ($official && (! $hotel || ($data['classification'] === 'nandini_official') !== $hotel->isMonitoredBrand() || ! collect($hotel->official_websites)->contains(fn ($url) => $sources->matchesWebsite($citation->url, $url)))) {
            throw ValidationException::withMessages(['classification' => 'Official citations must match this verified brand website and business path.']);
        }
        DB::transaction(function () use ($citation, $testRun, $request, $data, $official, $hotel) {
            $testRun->response()->lockForUpdate()->firstOrFail();
            $citation->update(['classification' => $data['classification'], 'hotel_id' => $official ? $hotel->id : null, 'is_official' => $data['classification'] === 'nandini_official', 'manual' => true, 'reviewed_by' => $request->user()->id, 'review_note' => $data['review_note']]);
            $testRun->response->update(['analysis_fingerprint' => null]);
        });

        return redirect()->route('visibility.review', $testRun)->with('status', 'Citation classification corrected. The source URL is preserved.');
    }

    public function hotels(): View
    {
        return view('visibility.hotels', ['hotels' => Hotel::orderBy('name')->get()]);
    }

    public function saveHotel(Request $request, ?Hotel $hotel = null): RedirectResponse
    {
        abort_if($hotel?->isMonitoredBrand(), 422, 'Edit the monitored brand in Organization Settings.');
        $data = $request->validate(['name' => 'required|string|max:180', 'aliases' => 'nullable|string|max:4000', 'official_websites' => 'required|string|max:4000', 'verification_url' => 'required|url:http,https|max:2000', 'verified' => 'accepted']);
        $aliases = array_values(array_unique(array_filter(array_map('trim', preg_split('/\r?\n/', $data['aliases'] ?? '')))));
        $sites = array_values(array_unique(array_filter(array_map('trim', preg_split('/\r?\n/', $data['official_websites'])))));
        $normalizer = app(CitationAnalysis::class);
        if (! $normalizer->normalize($data['verification_url']) || ! $sites || collect($sites)->contains(fn ($url) => ! $normalizer->normalize($url))) {
            throw ValidationException::withMessages(['official_websites' => 'Use complete HTTP(S) URLs without credentials. Include business-specific paths for shared domains.']);
        }
        $blocked = [...config('visibility.booking_domains'), ...config('visibility.travel_domains'), 'google.com'];
        if (collect($sites)->contains(function ($site) use ($normalizer, $blocked) {
            $normalized = $normalizer->normalize($site);

            return collect($blocked)->contains(fn ($domain) => $normalized['domain'] === $domain || str_ends_with($normalized['domain'], '.'.$domain)) || parse_url($site, PHP_URL_QUERY) !== null;
        })) {
            throw ValidationException::withMessages(['official_websites' => 'Use the business-owned website, not a third-party directory, map listing, or query-dependent URL.']);
        }
        if (collect([$data['name'], ...$aliases])->contains(fn ($a) => mb_strlen($a) < 4)) {
            throw ValidationException::withMessages(['aliases' => 'Use unambiguous business names of at least four characters.']);
        }
        $existing = Hotel::when($hotel?->exists, fn ($q) => $q->where('id', '!=', $hotel->id))->get();
        foreach ([$data['name'], ...$aliases] as $alias) {
            if ($existing->contains(fn ($h) => collect([$h->name, ...$h->aliases])->contains(fn ($a) => mb_strtolower($a) === mb_strtolower($alias)))) {
                throw ValidationException::withMessages(['aliases' => 'This name is already assigned to another verified business.']);
            }
        }
        $hotel ??= new Hotel(['identifier' => Str::slug($data['name']).'-'.Str::lower(Str::random(6))]);
        $hotel->fill(['name' => $data['name'], 'aliases' => $aliases, 'official_websites' => $sites, 'verification_url' => $data['verification_url'], 'verified_at' => now()]);
        $hotel->save();

        return redirect()->route('visibility.hotels')->with('status', 'Verified identity saved. Automatic analysis will refresh while manual corrections are retained.');
    }
}
