<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Google (GA4 Data API v1beta, GA4 Admin API v1beta, Search Console API
    | webmasters/v3) through one "agent" Google account that hotels share
    | access with. Read-only scopes, plus openid and email to check which
    | account signed in. Without a client id the app uses FakeGoogleApi
    | (local development and tests).
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'agent_email' => env('GOOGLE_AGENT_EMAIL'),
        'fake' => (bool) env('GOOGLE_FAKE', env('GOOGLE_CLIENT_ID') === null || env('GOOGLE_CLIENT_ID') === ''),
        // openid + email: to check that the agent account (not another one) signed in.
        'scopes' => [
            'openid',
            'email',
            'https://www.googleapis.com/auth/analytics.readonly',
            'https://www.googleapis.com/auth/webmasters.readonly',
        ],
        'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
        'token_url' => 'https://oauth2.googleapis.com/token',
        'revoke_url' => 'https://oauth2.googleapis.com/revoke',
        'userinfo_url' => 'https://openidconnect.googleapis.com/v1/userinfo',
        'analytics_data_url' => 'https://analyticsdata.googleapis.com/v1beta',
        'analytics_admin_url' => 'https://analyticsadmin.googleapis.com/v1beta',
        'search_console_url' => 'https://www.googleapis.com/webmasters/v3',
        'timeout' => 30,
    ],

    /*
    | Cloudflare Turnstile on the audit, contact and registration forms.
    | Empty keys: no challenge (the honeypot still applies).
    */
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
    ],

    /*
    | Xendit (BILLING_PROVIDER=xendit). Payment Sessions API (POST /sessions)
    | for payment links and automatic renewal; Recurring Plans API
    | (api-version 2026-01-01) to change or stop a renewal.
    */
    'xendit' => [
        'secret_key' => env('XENDIT_SECRET_KEY'),
        'callback_token' => env('XENDIT_CALLBACK_TOKEN'),
        'mode' => env('XENDIT_MODE', 'test'),
        'base_url' => 'https://api.xendit.co',
        'recurring_api_version' => '2026-01-01',
        'timeout' => 20,
        // Channels offered on a payment link; empty = all channels active in the dashboard.
        'invoice_channels' => [],
        // Channels for automatic renewal, e.g. ['CARDS', 'DANA', 'OVO']; empty = all.
        'automatic_channels' => [],
        // How long a payment link stays valid after the invoice due date.
        'link_valid_days' => 7,
    ],

];
