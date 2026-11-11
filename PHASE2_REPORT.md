# Phase 2 — Visibility Analysis

Implemented within the existing Laravel 13 / Tabler interface. No additional AI providers, scheduled jobs, authentication changes, new packages, or paid API requests.

## Existing evidence and manually checked calculations

Only saved test #3 qualifies: test and response are completed, the answer is nonempty, no response error is present, raw metadata reports completion, and a web-search call completed. Failed tests #1 and #2 are excluded.

| Metric | Result | Evidence |
|---|---|---|
| Valid completed tests | 1 | Test #3 |
| Nandini mention rate | 0% | 0 / 1 valid tests |
| Nandini recommendation rate | 0% | 0 / 1 valid tests |
| Nandini official website citation rate | 0% | 0 / 1 valid tests |
| Average Nandini recommendation position | Not ranked | 0 explicitly ranked Nandini recommendations |
| Recommended competitors | 6 | Each recommended in 1 / 1 valid tests |

The six verified competitors are Four Seasons Resort Bali at Sayan; Mandapa, a Ritz-Carlton Reserve; Capella Ubud; Buahan, a Banyan Tree Escape; The Kayon Jungle Resort; and COMO Shambhala Estate. Each has one linked Google Maps citation and zero official website citations in test #3. The answer is an unnumbered collection of recommendations: no rank was invented from its visual order.

## Behavior

- `/dashboard`: mention, recommendation, official-citation rates with numerators/denominators; explicit-rank averages and sample size; recommended competitors; improvement opportunities; existing recent tests.
- `/competitors`: distinct-test mention/recommendation counts, rank averages and ranked-test counts, unique linked source counts per hotel per test, official source counts, and selectable original test evidence.
- `/opportunities`: valid tests where Nandini was not recommended, recommended competitors, whether official property citations were attached, related verified Nandini pages, and clearly labeled review hypotheses. No explanation of model selection is invented.
- `/tests/3/analysis`: original escaped answer and citation links, evidence excerpts, hotel assessments, uncertain identities, manual assessment/rank/source-link corrections, and citation classification corrections. Review reason, administrator ID, and update time are stored. Evidence must be an exact excerpt; ranks require matching explicit numeric evidence without an unordered-list disclaimer. Citation associations must belong to the same response.
- `/hotels`: verified property names, aliases, property-specific official URLs, verification source/date, and administrator-confirmed identity additions/updates. Nandini and Hanging Gardens of Bali remain different identities. Duplicate aliases and booking/travel/map URLs presented as hotel-owned sites are rejected.
- `php artisan intelligence:analyze`: deterministic local reanalysis of saved valid results; never calls an AI provider. Dashboard, history, and result/review pages also refresh analysis as needed.

Hotel matching masks URL targets to avoid duplicate name counts and excludes conflicting identities and ambiguous short names in non-hotel context. Recommendation decisions use explicit language and recommendation-section context. Numeric ranks are recorded only for clear numbered recommendations; contradictory or unordered lists remain unranked. Citation relationships use annotation positions and unambiguous local property context. A link is evidence of association in the answer, not proof that a source independently endorses a recommendation.

Citation analysis normalizes hostnames, removes `www`, trailing domain dots, tracking query parameters and fragments, and resolves path segments. Official classification uses verified property URL prefixes with path boundaries, preventing another property on a shared brand domain from being attributed to the wrong hotel. Booking platforms, known travel publishers, map listings, other sources, and unverified URLs have separate classifications. Original citation URLs are retained.

Derived assessments/classifications are stored separately from original text and API metadata. Reanalysis is fingerprinted and preserves manual assessments. A previously reviewed official classification becomes unverified if its property URL no longer matches the current verified registry.

## Database

Applied migration: `2026_11_10_042706_add_visibility_analysis.php`.

Adds `hotels` and `hotel_assessments`; analysis fingerprints, unresolved identities, and timestamps on responses; and normalized source/classification/review fields on citations. Response/hotel assessments are unique, relationships use foreign keys, and source/status fields are indexed. Seeded eight verified identities, including Nandini, the six competitors from #3, and Hanging Gardens of Bali. The existing per-occurrence Phase 1 mention records remain available; Phase 2 screens use the separate per-hotel assessment layer.

## Verification

- 40 automated tests passed, 300 assertions; all HTTP provider calls in tests are mocked/prevented.
- Covers the actual saved response #3 fixture, name variants/boundaries, distinct identities, ambiguous aliases and non-hotel contexts, negative mentions, recommendation context, ranked/unranked/explicitly unordered lists, URL name duplication, citation links and ambiguous support, official-property paths, map/booking/travel/lookalike domains, exact metric arithmetic, invalid/failed exclusions, administrator access, correction validation/persistence, verified identity validation, and original-data preservation.
- 48 browser page checks passed at widths 1920, 768, 390, and 320px. Included dashboard, competitors, opportunities, identities, original results, analysis/review, existing pages, expanded review/identity forms, UTF-8, navigation, account menus, filters, and form state. No paid form was submitted. Screenshots/check output: `storage/app/ui-verification`.
- MySQL migration and seeded identities verified; Blade compilation and Pint formatting passed.
- Original answer text, API response metadata, response IDs, and citation URLs match pre-analysis snapshots. Database still contains 3 runs, 1 API usage record, and 9,040 tokens. No new paid API request was executed.

## Outstanding limitations

Recommendation rules currently target English response wording and a verified, curated identity registry. New hotels, unusual language, and ambiguous cases need administrator verification/correction. Source publisher lists are curated; unknown websites remain other/unverified. There is only one valid real test, so current rates describe that sample only. Unknown ranks stay unknown. Hypotheses require further evidence before specific website changes can be justified.

The related Nandini page was verified at https://nandinibali.com/ubud-jungle-resort-in-bali. Property verification URLs are stored in the hotel registry and visible to the administrator; the seed file contains the exact official sources.

## Modified / created files

- `app/Console/Commands/AnalyzeSavedResponses.php`
- `app/Http/Controllers/DashboardController.php`
- `app/Http/Controllers/TestController.php`
- `app/Http/Controllers/VisibilityController.php`
- `app/Models/AiResponse.php`
- `app/Models/Citation.php`
- `app/Models/Hotel.php`
- `app/Models/HotelAssessment.php`
- `app/Services/CitationAnalysis.php`
- `app/Services/VisibilityAnalysis.php`
- `app/Services/VisibilityMetrics.php`
- `config/visibility.php`
- `database/migrations/2026_11_10_042706_add_visibility_analysis.php`
- `database/seeders/DatabaseSeeder.php`
- `database/seeders/HotelSeeder.php`
- `resources/views/components/hotel-analysis.blade.php`
- `resources/views/components/run-table.blade.php`
- `resources/views/dashboard.blade.php`
- `resources/views/layouts/app.blade.php`
- `resources/views/tests/show.blade.php`
- `resources/views/visibility/assessment-form.blade.php`
- `resources/views/visibility/competitors.blade.php`
- `resources/views/visibility/hotel-form.blade.php`
- `resources/views/visibility/hotels.blade.php`
- `resources/views/visibility/opportunities.blade.php`
- `resources/views/visibility/review.blade.php`
- `routes/web.php`
- `tests/Browser/tabler-ui.cjs`
- `tests/Feature/VisibilityAnalysisTest.php`
- `tests/Fixtures/saved-test-3.json`
