<?php

$starter = [
    'hotels' => 1,
    'prompts_per_hotel' => 30,
    'competitors' => 3,
    'languages' => 2,
    'engines' => ['openai', 'gemini'],
    'scans_per_month' => 2,
    'manual_scans_per_month' => 1,
    'runs_per_prompt' => 2,
    'content_generations_per_month' => 10,
];

return [

    /*
    |--------------------------------------------------------------------------
    | Release
    |--------------------------------------------------------------------------
    |
    | version + build_date identify a release: shown in the app footer for
    | admins and on Admin > System, and used in the release zip's file name,
    | so local and live can be compared at a glance. Change both when you
    | build a new release (php artisan citevanta:package).
    |
    | asset_version (APP_ASSET_VERSION): added as ?v= to the favicon and
    | manifest links. Raise it to make browsers fetch a changed icon.
    | brand_color: theme colour of the web app manifest (the Sass $cv-primary).
    */

    'version' => '1.6.0',

    'build_date' => '2026-11-24',

    'asset_version' => env('APP_ASSET_VERSION', 1),

    'brand_color' => '#0088cc',

    /*
    |--------------------------------------------------------------------------
    | Plans
    |--------------------------------------------------------------------------
    |
    | Limits per plan. "engines" lists what a plan allows; only engines that
    | are implemented (EngineManager::IMPLEMENTED) are actually used.
    | A new team starts on "trial": Starter limits for trial_days days.
    */

    'trial_days' => 7,

    'plans' => [
        // Kept small: free trials cost real AI calls (about one 15-question ChatGPT scan).
        'trial' => [
            'prompts_per_hotel' => 15,
            'runs_per_prompt' => 1,
            'engines' => ['openai'],
            'languages' => 1,
            'manual_scans_per_month' => 0,
            'content_generations_per_month' => 2,
        ] + $starter,
        'starter' => $starter,
        'pro' => [
            'hotels' => 1,
            'prompts_per_hotel' => 50,
            'competitors' => 5,
            'languages' => 4,
            'engines' => ['openai', 'gemini', 'perplexity'],
            'scans_per_month' => 2,
            'manual_scans_per_month' => 2,
            'runs_per_prompt' => 2,
            'content_generations_per_month' => 30,
        ],
        // One hotel on its own OpenAI key (no AI cost for us). Price and every
        // limit below can be changed per team in Admin > Teams.
        'private' => [
            'hotels' => 1,
            'prompts_per_hotel' => 50,
            'competitors' => 10,
            'languages' => 5,
            'engines' => ['openai', 'gemini', 'perplexity'],
            'scans_per_month' => 4,
            'manual_scans_per_month' => 4,
            'runs_per_prompt' => 2,
            'content_generations_per_month' => 50,
        ],
    ],

    // Limits an admin can change per team (teams.plan_limits).
    'editable_limits' => [
        'hotels', 'prompts_per_hotel', 'competitors', 'languages', 'scans_per_month',
        'manual_scans_per_month', 'runs_per_prompt', 'content_generations_per_month',
    ],

    /*
    |--------------------------------------------------------------------------
    | Prices (per hotel per month) shown on the website and used for billing
    |--------------------------------------------------------------------------
    |
    | Annual = annual_months x monthly (10 = two months free).
    | IDR prices: set before launch (CHANGE). While null, the website shows
    | USD only and the currency toggle is hidden.
    */
    'pricing' => [
        'annual_months' => 10,
        'prices' => [
            'starter' => ['usd' => 49, 'idr' => null], // CHANGE: IDR price
            'pro' => ['usd' => 99, 'idr' => null],     // CHANGE: IDR price
        ],
        'discounts' => [
            'group' => ['plan' => 'pro', 'min_hotels' => 5, 'percent' => 15],
            'agency' => ['plan' => 'starter', 'min_hotels' => 20, 'percent' => 25],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Public website
    |--------------------------------------------------------------------------
    */
    'site' => [
        // Where the contact form is sent; falls back to the admin users' addresses.
        'contact_email' => env('CONTACT_EMAIL'),
        // Absolute or /public path of a 1200x630 image for link previews (optional).
        'og_image' => env('SITE_OG_IMAGE'),
        'locales' => ['en' => 'English', 'id' => 'Bahasa Indonesia'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Free AI visibility audit (lead magnet)
    |--------------------------------------------------------------------------
    |
    | 10 English prompts, 1 run, ChatGPT only; no accuracy checks, competitor
    | reasons or recommendations. Runs on the "audits" queue.
    */
    'audit' => [
        'daily_budget' => (int) env('AUDIT_DAILY_BUDGET', 5),
        'per_ip_per_day' => 3,
        'per_email_per_day' => 3,
        'reuse_days' => 7,
        'prompts' => 10,
        'engine' => 'openai',
    ],

    /*
    |--------------------------------------------------------------------------
    | Google data (private plan): sync and tracking health
    |--------------------------------------------------------------------------
    */
    'google' => [
        // First sync, months back: GA4 13 (this year vs last year), Search Console 16 (its maximum).
        'ga4_first_sync_months' => 13,
        'gsc_first_sync_months' => 16,
        // Daily sync re-reads the last days, because Google adds late data.
        'ga4_daily_days' => 3,
        'gsc_daily_days' => 5,
        'ga4_page_size' => 10000,
        'gsc_page_size' => 25000,
        // Requests per minute to Google APIs from our jobs (well under Google's quotas).
        'requests_per_minute' => 30,
    ],

    /*
    | AI platforms, recognised by referrer domain / GA4 session source and by
    | utm_source. Add new assistants here.
    */
    'ai_sources' => [
        'chatgpt.com' => 'ChatGPT',
        'chat.openai.com' => 'ChatGPT',
        'openai.com' => 'ChatGPT',
        'chatgpt' => 'ChatGPT',
        'perplexity.ai' => 'Perplexity',
        'www.perplexity.ai' => 'Perplexity',
        'perplexity' => 'Perplexity',
        'gemini.google.com' => 'Gemini',
        'bard.google.com' => 'Gemini',
        'gemini' => 'Gemini',
        'copilot.microsoft.com' => 'Copilot',
        'copilot.cloud.microsoft' => 'Copilot',
        'copilot' => 'Copilot',
        'claude.ai' => 'Claude',
        'claude' => 'Claude',
        'chat.mistral.ai' => 'Mistral',
        'chat.deepseek.com' => 'DeepSeek',
        'deepseek' => 'DeepSeek',
        'meta.ai' => 'Meta AI',
        'grok.com' => 'Grok',
        'x.ai' => 'Grok',
        'you.com' => 'You.com',
        'poe.com' => 'Poe',
        'phind.com' => 'Phind',
        'kagi.com' => 'Kagi',
    ],

    /*
    | Booking date parameters in GA4 (event-scoped custom dimensions): the
    | health check proposes a mapping from these names; the owner confirms it.
    */
    'booking_date_parameters' => [
        'checkin' => ['check_in', 'checkin', 'checkin_date', 'check_in_date', 'arrival_date', 'arrival', 'start_date', 'date_from', 'from_date'],
        'checkout' => ['check_out', 'checkout', 'checkout_date', 'check_out_date', 'departure_date', 'departure', 'end_date', 'date_to', 'to_date'],
        'adults' => ['adults', 'num_adults', 'adult', 'adult_count', 'guests_adults'],
        'children' => ['children', 'num_children', 'kids', 'child', 'child_count'],
        'nights' => ['nights', 'num_nights', 'length_of_stay', 'los', 'stay_length'],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI budget (BudgetGuard, checked before every AI call)
    |--------------------------------------------------------------------------
    |
    | Monthly USD buckets, reset on the 1st in Asia/Makassar. Audits, trials
    | and admin (admin scans, scan:test, cost probes) have their own caps;
    | paying customers use the rest of the total. Above priority_percent of
    | the total only paid scheduled scans continue. A call that would go over
    | is not made: its scan or audit is paused ("paused_budget") and resumes
    | from Admin > System.
    |
    | Estimates: the measured average cost of the call type (last 30 days,
    | at least min_samples calls) x (1 + safety_margin), else defaults.
    | team_cost_cap_percent: a paid team's AI cost per month may not exceed
    | this share of its plan price for manual scans and content.
    */
    'budget' => [
        'timezone' => 'Asia/Makassar',
        'total' => (float) env('AI_BUDGET_TOTAL', 70),
        'buckets' => [
            'audits' => (float) env('AI_BUDGET_AUDITS', 10),
            'trials' => (float) env('AI_BUDGET_TRIALS', 10),
            'admin' => (float) env('AI_BUDGET_ADMIN', 10),
        ],
        'priority_percent' => 90,
        'alert_percents' => [50, 80, 100],
        'safety_margin' => 0.5,
        'min_samples' => 5,
        'default_estimates' => [
            'answer' => 0.03,
            'analysis' => 0.003,
            'insight' => 0.005,
            'content' => 0.01,
        ],
        'team_cost_cap_percent' => (float) env('TEAM_COST_CAP_PERCENT', 30),
        // Runaway guard: insight calls a scan may make on top of prompts x runs x engines x 2.
        'max_insight_calls_per_scan' => 40,
    ],

    /*
    |--------------------------------------------------------------------------
    | Free trial ("trial lite"): abuse limits
    |--------------------------------------------------------------------------
    |
    | - requires_approval: every new trial waits for an admin (Admin > Teams)
    |   before any AI call.
    | - unlocked_items: recommendations and accuracy issues shown in full.
    | - disposable_email_domains: refused at registration (subdomains too).
    | The trial's AI cost is capped by the "trials" budget bucket above.
    */
    'trial' => [
        'requires_approval' => (bool) env('TRIAL_REQUIRES_APPROVAL', true),
        'unlocked_items' => 3,
        'max_per_ip' => 2,
        'ip_window_days' => 30,
        'disposable_email_domains' => [
            '10minutemail.com', '10minutemail.net', '20minutemail.com', '33mail.com', 'anonaddy.me',
            'burnermail.io', 'discard.email', 'dispostable.com', 'dropmail.me', 'emailondeck.com',
            'fakeinbox.com', 'getairmail.com', 'getnada.com', 'guerrillamail.com', 'guerrillamail.net',
            'guerrillamail.org', 'guerrillamailblock.com', 'harakirimail.com', 'inboxkitten.com', 'maildrop.cc',
            'mailinator.com', 'mailinator.net', 'mailnesia.com', 'mailpoof.com', 'mintemail.com',
            'moakt.com', 'mohmal.com', 'mytemp.email', 'nada.email', 'sharklasers.com',
            'spam4.me', 'spamgourmet.com', 'temp-mail.io', 'temp-mail.org', 'tempail.com',
            'tempinbox.com', 'tempmail.dev', 'tempmail.net', 'tempmailo.com', 'tempr.email',
            'throwawaymail.com', 'trashmail.com', 'trashmail.de', 'yopmail.com', 'yopmail.fr',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Billing
    |--------------------------------------------------------------------------
    |
    | provider: "manual" (bank transfer, checked by an admin) or "xendit"
    | (payment links and automatic renewal). Switching to xendit keeps
    | existing subscriptions running; their next renewal invoice uses Xendit.
    | Prices: see "pricing" above. Amounts on invoices are stored in minor
    | units (rupiah for IDR, cents for USD).
    */
    'billing' => [
        'provider' => env('BILLING_PROVIDER', 'manual'),

        'tax' => [
            'name' => 'PPN',
            // Percent, e.g. 11. 0 = no tax line on invoices.
            'rate' => (float) env('BILLING_TAX_RATE', 0),
            // true: the plan prices already include the tax (shown as "incl.").
            'prices_include_tax' => (bool) env('BILLING_PRICES_INCLUDE_TAX', true),
        ],

        'invoice_prefix' => 'INV',
        'due_days' => 7,
        'renewal_days_before' => 7,
        'reminder_days_before' => 3,
        'grace_days' => 7,
        'trial_ending_notice_days' => 2,

        // Manual bank transfer: a small random amount is added so each
        // payment can be matched in the bank statement (IDR rupiah, USD cents).
        'unique_code' => [
            'idr' => [1, 999],
            'usd' => [1, 99],
        ],

        'proof_max_kb' => 5120,

        // Shown on invoices. CHANGE before launch.
        'business' => [
            'name' => 'CHANGE: Business name',
            'address' => "CHANGE: Street\nCity, Postal code\nIndonesia",
            'email' => env('BILLING_EMAIL'),
            'tax_id' => null, // e.g. NPWP once registered
        ],

        // Bank account for manual transfers. CHANGE before launch.
        'bank' => [
            'bank_name' => 'CHANGE: Bank name',
            'account_number' => 'CHANGE: 000-000-0000',
            'account_holder' => 'CHANGE: Account holder',
            'swift' => null, // for USD transfers from abroad
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Email
    |--------------------------------------------------------------------------
    |
    | Shared hosting limits outgoing mail: queued mail is throttled to
    | max_per_hour; the rest waits in the queue.
    */
    'mail' => [
        'max_per_hour' => (int) env('MAIL_MAX_PER_HOUR', 100),
    ],

    // Weekly email report: Monday 08:00 in each team's timezone.
    'weekly_report' => [
        'day' => 1, // ISO day: 1 = Monday
        'hour' => 8,
    ],

    /*
    | Manual "Scan now" scans:
    | - scans_per_month: scheduled scans (daily scheduler)
    | - manual_scans_per_month: extra "Scan now" scans per team, reset on the
    |   1st of each month in the team's timezone. Admin users have no allowance.
    | - at most one manual scan per hotel every manual_scan_cooldown_hours.
    */

    'manual_scan_cooldown_hours' => 24,

    'default_timezone' => 'Asia/Makassar',

    /*
    |--------------------------------------------------------------------------
    | Hotel setup
    |--------------------------------------------------------------------------
    */

    'hotel_types' => ['hotel', 'resort', 'villa', 'boutique hotel', 'hostel', 'guesthouse', 'apartment'],

    'max_guest_markets' => 5,

    // Prompt languages offered in the wizard (a plan limits how many are used).
    'languages' => [
        'en' => 'English',
        'id' => 'Indonesian',
        'de' => 'German',
        'fr' => 'French',
        'nl' => 'Dutch',
        'es' => 'Spanish',
        'it' => 'Italian',
        'ru' => 'Russian',
        'zh' => 'Chinese',
        'ja' => 'Japanese',
        'ko' => 'Korean',
        'ar' => 'Arabic',
    ],

    'prompt_min_length' => 15,

    // Completed scans shown in the dashboard trend charts (most recent N).
    'trend_scans' => 12,

    /*
    |--------------------------------------------------------------------------
    | Insights (run after each scan, on the analysis model, one AI call per job)
    |--------------------------------------------------------------------------
    |
    | Each feature can be switched off. With CITEVANTA_ENGINE=fake they use
    | offline fakes and cost nothing.
    */
    'insights' => [
        'accuracy' => [
            'enabled' => (bool) env('CITEVANTA_ACCURACY_CHECKS', true),
            // Claims whose fact key or text contains one of these words are "high" severity.
            'high_severity_words' => [
                'price', 'rate', 'cost', 'night', 'usd', 'idr', '$',
                'location', 'distance', 'airport', 'address', 'beach', 'centre', 'center', 'minutes', 'km',
                'pool', 'kids club', 'spa', 'restaurant', 'gym', 'fitness', 'parking', 'wi-fi', 'wifi',
                'pet', 'breakfast', 'villa', 'shuttle', 'transfer', 'facility', 'facilities',
            ],
        ],
        'competitor_reasons' => [
            'enabled' => (bool) env('CITEVANTA_COMPETITOR_REASONS', true),
            'max_per_scan' => 10,
        ],
    ],

    /*
    | Recommendations: built by rules from each scan's evidence; then one AI
    | call per scan rewrites the new ones for this hotel (ai_writing).
    */
    'recommendations' => [
        'enabled' => (bool) env('CITEVANTA_RECOMMENDATIONS', true),
        'ai_writing' => (bool) env('CITEVANTA_RECOMMENDATION_WRITING', true),
        'rewrite_per_scan' => 12,
    ],

    /*
    | Copy-ready content ("Create content" on a recommendation): one AI call
    | per piece; schema markup is built from the facts without AI.
    | Length limits (characters or words) are shown next to the text (not enforced by cutting).
    */
    'content' => [
        'enabled' => (bool) env('CITEVANTA_CONTENT', true),
        'limits' => [
            // Booking.com extranet "About the neighbourhood" (characters).
            'booking_neighbourhood' => 2000,
            // Description sent to Agoda support via "Need Help" in YCS (words).
            'agoda_description_words' => 160,
            'gbp_description' => 750,
            'gbp_post' => 1500,
            'review_request' => 600,
        ],

        /*
        | OTA listing update checklist: which Booking.com extranet / Agoda YCS
        | screen a fact belongs to, matched by words in the fact key. Price
        | facts are left out (rates live in Rates & availability).
        */
        'ota_fields' => [
            'facilities' => [
                'keywords' => ['pool', 'spa', 'kids club', 'restaurant', 'bar', 'wi-fi', 'wifi', 'parking', 'gym', 'fitness', 'breakfast', 'shuttle', 'transfer', 'babysitting', 'garden', 'bicycle', 'bikes', 'tour desk', 'meeting', 'co-working', 'yoga', 'laundry'],
                'booking' => 'Booking.com extranet > Property > Facilities & services',
                'agoda' => 'Agoda YCS > Property > Facilities',
            ],
            'rooms' => [
                'keywords' => ['room', 'villa', 'suite', 'bed', 'desk', 'view', 'bathtub', 'balcony'],
                'booking' => 'Booking.com extranet > Property > Room details',
                'agoda' => 'Agoda YCS > Property > Rooms',
            ],
            'location' => [
                'keywords' => ['airport', 'distance', 'beach', 'centre', 'center', 'location', 'nearby', 'neighbourhood', 'temple', 'market'],
                'booking' => 'Booking.com extranet > Property > Property details > About the neighbourhood',
                'agoda' => 'Agoda YCS > Property > Property information (location)',
            ],
            'policies' => [
                'keywords' => ['check-in', 'check-out', 'pet', 'children', 'child', 'cancellation', 'policy', 'smoking', 'age'],
                'booking' => 'Booking.com extranet > Property > Policies',
                'agoda' => 'Agoda YCS > Property > Policies',
            ],
        ],
    ],

    /*
    | Domain types for cited links (Top cited domains, "Where AI gets its
    | information", Citations page). Edit these lists freely.
    | "name.*" matches any domain ending, e.g. expedia.* = expedia.com, expedia.co.id.
    | Subdomains match too (www.booking.com, id.trip.com, maps.google.com).
    |
    | Checked in this order: the hotel's own website, a tracked competitor's
    | website, ota, review, media, social, a competitor's name in the domain,
    | chain, then "Other". So a chain that is also a tracked competitor
    | (by website or name) counts as Competitor.
    |
    | google.* counts as a review site: in hotel answers Google is cited for
    | Google Travel / Maps listings and their reviews.
    */
    'domain_types' => [
        'ota' => [
            'booking.com', 'agoda.com', 'expedia.*', 'hotels.com', 'trip.com', 'traveloka.com', 'tiket.com',
            'airbnb.*', 'vrbo.com', 'priceline.com', 'kayak.*', 'trivago.*', 'hostelworld.com', 'ctrip.com',
        ],
        'review' => [
            'tripadvisor.*', 'google.*', 'goo.gl', 'yelp.*', 'trustpilot.com', 'holidaycheck.*',
        ],
        'media' => [
            'lonelyplanet.com', 'cntraveler.com', 'travelandleisure.com', 'timeout.com', 'theculturetrip.com',
            'nomadicmatt.com', 'fodors.com', 'frommers.com', 'roughguides.com', 'afar.com', 'travelweekly.com',
            'cntraveller.com', 'nationalgeographic.com', 'thrillist.com',
            'wikivoyage.org', 'tripzilla.com', 'thehoneycombers.com', 'whatsnewbali.com', 'balibible.com',
            'smartertravel.com', 'theplanetd.com', 'travelawaits.com', 'luxurytraveladvisor.com',
        ],
        'social' => [
            'reddit.com', 'youtube.com', 'youtu.be', 'facebook.com', 'instagram.com', 'tiktok.com',
            'x.com', 'twitter.com', 'pinterest.*', 'quora.com',
        ],
        'chain' => [
            'hyatt.com', 'marriott.com', 'hilton.com', 'accor.com', 'ihg.com', 'fourseasons.com',
            'ritzcarlton.com', 'radissonhotels.com', 'wyndhamhotels.com', 'choicehotels.com', 'bestwestern.com',
            'mandarinoriental.com', 'shangri-la.com', 'aman.com', 'banyantree.com', 'anantara.com',
            'minorhotels.com', 'kempinski.com', 'rosewoodhotels.com', 'belmond.com', 'sixsenses.com',
            'comohotels.com', 'alilahotels.com', 'mgallery.com', 'sofitel.com', 'novotel.com', 'ibis.com',
        ],
    ],

    // Facts pre-filled (with empty values) in setup step b.
    'suggested_facts' => [
        'Price range per night', 'Number of rooms', 'Swimming pool', 'Kids club', 'Spa', 'Restaurant',
        'Distance to airport', 'Check-in time', 'Check-out time', 'Pet policy', 'Free Wi-Fi', 'Parking',
    ],

    /*
    |--------------------------------------------------------------------------
    | Engine mode
    |--------------------------------------------------------------------------
    |
    | "fake":   FakeEngine + FakeAnalyzer, no API calls (local development).
    | "openai": the real OpenAI engine and analysis model.
    */

    'engine' => env('CITEVANTA_ENGINE', 'fake'),

    'fake' => [
        'mention_rate' => 0.45,      // chance the tracked hotel appears in an answer
        'competitor_rate' => 0.5,    // chance each competitor appears
        'salt' => '',                // change to get a different (still repeatable) set of answers
    ],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | scan_model:     the model that plays "ChatGPT" and answers traveller
    |                 prompts with the web_search tool enabled.
    | analysis_model: the model that reads each answer and extracts structured
    |                 data (hotels named, sentiment, ...) via Structured Outputs.
    */

    'scan_model' => env('CITEVANTA_SCAN_MODEL', 'gpt-5-mini'),

    'analysis_model' => env('CITEVANTA_ANALYSIS_MODEL', 'gpt-5-mini'),

    'runs_per_prompt' => (int) env('CITEVANTA_RUNS_PER_PROMPT', 3),

    /*
    |--------------------------------------------------------------------------
    | Web search
    |--------------------------------------------------------------------------
    |
    | tool:        Responses API tool type used by the scan engine.
    | tool_choice: "auto" lets the model decide when to search (closest to how
    |              ChatGPT behaves); "required" forces a search on every answer.
    */

    'web_search' => [
        'tool' => env('CITEVANTA_WEB_SEARCH_TOOL', 'web_search'),
        'tool_choice' => env('CITEVANTA_WEB_SEARCH_TOOL_CHOICE', 'auto'),
        // low | medium | high: how much search content the model reads (billed as input tokens).
        'search_context_size' => env('CITEVANTA_SEARCH_CONTEXT_SIZE', 'low'),
    ],

    /*
    | Cost limits per call. Scan answers: at most max_tool_calls web searches
    | (each is billed), max_output_tokens (includes reasoning tokens) and the
    | lowest reasoning effort that works with web search for the model
    | ("minimal" does not support web search). Analysis: no tools.
    | Empty reasoning_effort = do not send it (models without reasoning).
    |
    | Scans have two tiers (OpenAiLimits::tierFor): "free" for free audits and
    | trials (cheapest), "paid" for paying and Private plan scans. Measured on
    | 20 Nov 2026 (10 real answers, gpt-5-mini): free 2 searches / 1200 tokens
    | = $0.016 per answer, 8 of 10 answers named hotels (2.4 per answer);
    | paid 3 searches / 1600 tokens = $0.017 per answer, 10 of 10 (3.9 per answer).
    */
    'ai_limits' => [
        'scan' => [
            'free' => [
                'max_tool_calls' => (int) env('CITEVANTA_SCAN_MAX_TOOL_CALLS_FREE', 2),
                'max_output_tokens' => (int) env('CITEVANTA_SCAN_MAX_OUTPUT_TOKENS_FREE', 1200),
            ],
            'paid' => [
                'max_tool_calls' => (int) env('CITEVANTA_SCAN_MAX_TOOL_CALLS_PAID', 3),
                'max_output_tokens' => (int) env('CITEVANTA_SCAN_MAX_OUTPUT_TOKENS_PAID', 1600),
            ],
            'reasoning_effort' => env('CITEVANTA_SCAN_REASONING_EFFORT', 'low'),
        ],
        'analysis' => [
            'max_output_tokens' => (int) env('CITEVANTA_ANALYSIS_MAX_OUTPUT_TOKENS', 800),
            'reasoning_effort' => env('CITEVANTA_ANALYSIS_REASONING_EFFORT', 'minimal'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Background scans (shared hosting: cron + database queue, no workers)
    |--------------------------------------------------------------------------
    |
    | How the timeouts relate (each must be larger than the one before):
    |   OPENAI_REQUEST_TIMEOUT (60)   one HTTP call to the AI engine
    |   < CITEVANTA_JOB_TIMEOUT (70)  one ScanPromptJob = exactly one API call
    |   < DB_QUEUE_RETRY_AFTER (180)  a job reserved by a run that died is retried
    |                                 only after this, so it never runs twice at once
    | CRON_WORK_SECONDS (50) is how long one CLI cron run processes jobs, so a run
    | finishes before the next one starts a minute later. CRON_TIME_LIMIT (120)
    | is what a web cron call asks set_time_limit() for.
    */

    'job_timeout' => (int) env('CITEVANTA_JOB_TIMEOUT', 70),

    'cron' => [
        'token' => env('CRON_TOKEN', ''),
        'work_seconds' => (int) env('CRON_WORK_SECONDS', 50),
        'time_limit' => (int) env('CRON_TIME_LIMIT', 120),
        'lock_seconds' => 300,
        'stale_after_minutes' => 15,
    ],

    // How engines are named in the app.
    'engine_labels' => [
        'openai' => 'ChatGPT',
        'gemini' => 'Gemini',
        'perplexity' => 'Perplexity',
    ],

    // API requests per minute per engine (ScanPromptJob RateLimited middleware).
    'rate_limits' => [
        'openai' => (int) env('CITEVANTA_RATE_LIMIT_OPENAI', 30),
        'gemini' => 30,
        'perplexity' => 30,
    ],

    // Scheduled task timezone and scan housekeeping.
    'schedule_timezone' => 'Asia/Makassar',
    'queued_dispatch_after_minutes' => 5,
    'stuck_after_minutes' => 90,

    /*
    |--------------------------------------------------------------------------
    | Retries
    |--------------------------------------------------------------------------
    |
    | Every API call is attempted up to `attempts` times. backoff_ms holds the
    | wait before attempt 2, 3, ... Client errors (4xx except 429) are not
    | retried because they will not succeed on a second try.
    */

    'retry' => [
        'attempts' => 3,
        'backoff_ms' => [2000, 6000],
    ],

    /*
    |--------------------------------------------------------------------------
    | Prices (USD)
    |--------------------------------------------------------------------------
    |
    | USD per 1M tokens, per model, from OpenAI's pricing page (18 Nov 2026).
    | Dated snapshots use their base model's price (gpt-6-luna-2026-10-01 ->
    | gpt-6-luna). A model missing here is costed at $0 and logs a warning;
    | add it here when you switch models.
    |
    | web_search_per_1000_calls: tool-call fee charged per web_search call.
    */

    'prices' => [
        'models' => [
            'gpt-6.1-terra' => ['input_per_million' => 1.00, 'output_per_million' => 5.00],
            'gpt-6-luna' => ['input_per_million' => 0.10, 'output_per_million' => 0.50],
            'gpt-5-mini' => ['input_per_million' => 0.25, 'output_per_million' => 2.00],
            // Fake mode is priced like gpt-5-mini so local costs look realistic.
            'fake-engine' => ['input_per_million' => 0.25, 'output_per_million' => 2.00],
        ],
        'web_search_per_1000_calls' => 10.00,
    ],

];
