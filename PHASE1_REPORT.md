# Nandini AI Intelligence â€” Phase 1 report

| Task | Before | After | Remarks |
|---|---|---|---|
| Laravel project | Existing skeleton | Verified Laravel 13.40.0 / PHP 8.3.0 | Laravel was not reinstalled; Boost 2.10.4 installed |
| Tabler UI | No admin interface | Shared responsive Blade layout and actual Tabler 1.4.0 assets | Bootstrap 5; MIT license bundled; no frontend build required |
| Authentication | No login | Session login/logout and administrator middleware | Rate limiting, CSRF, hashed password, regenerated sessions |
| Database | No migrated schema | MySQL migrations and initial data applied | InnoDB explicitly selected after default-engine index failure |
| OpenAI API | Key present, no integration | Responses API adapter implemented | Supported web_search tool; required search; model configurable |
| AI search test | Unavailable | Authenticated single-test form and executor | Live validation recorded below |
| Result storage | Unavailable | Responses, annotations, mentions, usage, audit metadata stored | Prompt and market snapshots survive subsequent edits |
| Dashboard | Unavailable | Real database-backed metrics and recent tests | Completed tests only; empty metrics until real completion |

Administrator: admin@admin.com. Generated password is in storage/app/private/administrator-credentials.txt. This file is outside the public web root and ignored through Laravel storage rules. Retrieve it locally and remove it after recording it securely. The `intelligence:admin` command also supports a hidden password prompt when setting up another installation.

## Database

New migration: database/migrations/2026_11_10_033821_create_intelligence_tables.php.

Creates ai_providers, search_prompts, market_scenarios, test_runs, ai_responses, citations, hotel_mentions, api_usages; adds the is_admin flag to users. Foreign keys, unique submission IDs, status/date indexes, and relevant detection indexes are included. The three original Laravel migrations were also applied. No existing populated table was deleted. The empty users table left by the initial failed migration was checked and removed before rerunning.

Seed data: OpenAI provider, the exact requested starter prompt (v1), and Singapore / English with supported approximate country search context. Nothing executes during seeding. The other eleven project-plan prompts were not supplied in this repository and were not invented.

## API integration

POST https://api.openai.com/v1/responses through Laravel HTTP client. OPENAI_API_KEY stays server-side; OPENAI_MODEL defaults to gpt-4.1-mini. `web_search` is required, output is limited to 2,500 tokens, and response storage at OpenAI is disabled. Each run records the exact request settings, prompt/version, market snapshot, repetition, timestamp, raw response, response ID, token usage, and safe structured URL citations. Inline citation links use annotation positions and all AI answer text is HTML escaped.

Global execution lock, unique session-issued submission IDs, two submissions per minute, and AI_DAILY_TEST_LIMIT (default 10) limit accidental spending. There are no automatic API retries, batches, recurring jobs, or permanent worker requirements. Synchronous requests allow 90 seconds for the provider; stale running records become interrupted when reopened or another test starts. Hosting request limits must accommodate this duration.

ProviderManager resolves an AiProviderAdapter; only OpenAI is implemented. Country is approximate API search context, not a claim of consumer-account geolocation or visibility across all AI products.

Cost is NULL / unavailable because complete verified model and web-search pricing has not been configured. No invented estimate is shown.

Exact-name hotel matching is curated in config/intelligence.php. Nandini aliases stay separate from Hanging Gardens of Bali. Recommendation detection is a conservative same-line heuristic with negative exclusions, presented as a signal for review; it is not a semantic classifier. Unlisted competitors can be missed. Official citation detection uses the exact nandinibali.com domain or subdomains, rejecting lookalike domains and non-HTTP(S) URLs.

## Validation

- 15 automated PHPUnit tests passed, 90 assertions, using mocked HTTP responses and isolated in-memory SQLite.
- Covers login/logout, administrator access, login throttling, route rendering, empty metrics, prompt validation/versioning, authenticated submission, duplicate submission, invalid submission tokens, response/citation/usage persistence, saved snapshots, provider errors, connection failures, invalid/incomplete responses, daily spending/configuration guards, hotel recognition, interrupted records, and escaping AI HTML.
- Actual MySQL migrations and seeds passed; all four migrations are recorded as run.
- Blade view compilation passed.
- Laravel Pint formatting passed. --dirty was unavailable because this folder is not a Git repository, so the formatter ran normally.
- Browser visual/responsive verification remains unconfirmed: the computer-use tool could not reliably identify the browser URL and ended inspection. Responsive Tabler components and mobile CSS are implemented, but this is not a claim of visual QA.
- Initial live attempt failed before the HTTP request because local PHP had no trusted CA store (cURL error 60). The standard curl.se CA bundle was installed under private storage; a nonbillable check then verified TLS successfully. TLS verification was never disabled.

## Deployment and remaining items

Use PHP 8.3+, MySQL with InnoDB, and the normal Laravel public/ document root. Keep .env, vendor, storage/app/private, and application source outside the exposed document root. Set APP_ENV=production, APP_DEBUG=false, APP_URL to the HTTPS domain, SESSION_SECURE_COOKIE=true, and the OpenAI environment variables. Allow writes only where Laravel needs them (storage and bootstrap/cache). Run composer install --no-dev --optimize-autoloader, php artisan migrate --force, php artisan db:seed --force, and php artisan optimize. No npm build, Redis, WebSocket service, or permanent queue worker is needed for the Phase 1 interface. Ensure PHP and the hosting proxy allow the synchronous request timeout.

OPENAI_CA_BUNDLE is optional and relative to the project root. It is set locally to storage/app/private/cacert.pem to compensate for this PHP installation; hosts with a working CA store should leave it empty. Maintain the CA bundle if this fallback is retained.

The configured API key was inadvertently printed once during a diagnostic command in this development session. Rotate that key and replace OPENAI_API_KEY before deployment; it is not embedded in application code, templates, or browser JavaScript.

## References

- https://developers.openai.com/api/docs/guides/tools-web-search
- https://laravel.com/framework/docs/13.x/authentication
- https://laravel.com/framework/docs/13.x/http-client
- https://docs.tabler.io/ui/getting-started/installation
- https://docs.tabler.io/ui/getting-started
- https://preview.tabler.io/

## Modified and created files

Boost generated local .codex/.cursor/.mcp configuration. The existing .env was updated with the local CA bundle path. Private generated credentials and the CA bundle reside under storage/app/private and are not public assets.

- .env.example
- AGENTS.md
- CLAUDE.md
- PHASE1_REPORT.md
- app/Console/Commands/CreateAdministrator.php
- app/Console/Commands/RunFirstTest.php
- app/Http/Controllers/AuthController.php
- app/Http/Controllers/DashboardController.php
- app/Http/Controllers/PromptController.php
- app/Http/Controllers/SettingsController.php
- app/Http/Controllers/TestController.php
- app/Http/Middleware/EnsureAdministrator.php
- app/Models/AiProvider.php
- app/Models/AiResponse.php
- app/Models/ApiUsage.php
- app/Models/Citation.php
- app/Models/HotelMention.php
- app/Models/MarketScenario.php
- app/Models/SearchPrompt.php
- app/Models/TestRun.php
- app/Models/User.php
- app/Providers/AppServiceProvider.php
- app/Services/Ai/AiProviderAdapter.php
- app/Services/Ai/OpenAiProvider.php
- app/Services/Ai/ProviderManager.php
- app/Services/CitationRenderer.php
- app/Services/HotelRecognition.php
- app/Services/TestExecutor.php
- bootstrap/app.php
- composer.json
- composer.lock
- config/database.php
- config/intelligence.php
- config/session.php
- database/migrations/2026_11_10_033821_create_intelligence_tables.php
- database/seeders/DatabaseSeeder.php
- public/css/intelligence.css
- public/js/intelligence.js
- public/vendor/tabler/LICENSE
- public/vendor/tabler/tabler.min.css
- public/vendor/tabler/tabler.min.js
- resources/views/auth/login.blade.php
- resources/views/components/run-table.blade.php
- resources/views/components/status-badge.blade.php
- resources/views/dashboard.blade.php
- resources/views/layouts/app.blade.php
- resources/views/prompts/form.blade.php
- resources/views/prompts/index.blade.php
- resources/views/settings.blade.php
- resources/views/tests/create.blade.php
- resources/views/tests/index.blade.php
- resources/views/tests/show.blade.php
- routes/web.php
- tests/Feature/ExampleTest.php
- tests/Feature/IntelligenceTest.php

## Live validation result

```json
{
    "test_id": 2,
    "status": "failed",
    "model": "gpt-4.1-mini",
    "citations": 0,
    "hotel_mentions": 0,
    "total_tokens": null,
    "error": "OpenAI rejected the credentials or model access."
}
```

Nonbillable authenticated configuration check: `{"http_status":401,"error_type":"invalid_request_error","error_code":"invalid_api_key"}`. No successful live response exists; Phase 1 live acceptance is blocked until valid credentials/model access are supplied. No further paid request was attempted.
