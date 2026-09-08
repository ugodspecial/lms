<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Resolved credentials
|--------------------------------------------------------------------------
|
| Computed once, here, so no service or controller ever has to decide which key
| pair is active. `config('services.paystack.secret')` is always the correct
| key for the configured mode — the only place a mode branch exists.
|
*/

$paystackMode = strtolower((string) env('PAYSTACK_MODE', 'test'));

$paystackKeys = $paystackMode === 'live'
    ? ['public' => env('PAYSTACK_LIVE_PUBLIC_KEY'), 'secret' => env('PAYSTACK_LIVE_SECRET_KEY')]
    : ['public' => env('PAYSTACK_TEST_PUBLIC_KEY'), 'secret' => env('PAYSTACK_TEST_SECRET_KEY')];

$zoomClientId = filter_var(env('ZOOM_S2S_ENABLED', false), FILTER_VALIDATE_BOOLEAN)
    ? env('ZOOM_S2S_CLIENT_ID')
    : env('ZOOM_CLIENT_ID');

$zoomClientSecret = filter_var(env('ZOOM_S2S_ENABLED', false), FILTER_VALIDATE_BOOLEAN)
    ? env('ZOOM_S2S_CLIENT_SECRET')
    : env('ZOOM_CLIENT_SECRET');

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | Credentials for third-party services. Nothing here is a secret in the
    | sense of being hidden — the values come from .env, which is never
    | committed. What matters is that these keys are only ever read
    | server-side: no `config()` value from this file may reach a Blade view, a
    | Livewire payload or an API response (§41, §75).
    |
    | Paystack's *public* key is the one exception — it is designed to be sent
    | to the browser. Its *secret* key is not, and
    | `php artisan platform:doctor` fails the build if a secret ever appears in
    | a rendered response.
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
    |--------------------------------------------------------------------------
    | Paystack (§41)
    |--------------------------------------------------------------------------
    |
    | Webhooks are authenticated by HMAC-SHA512 of the RAW request body using
    | the SECRET key — Paystack does not issue a separate webhook secret. The
    | signature middleware must therefore read `$request->getContent()` and
    | never a re-serialised array, or verification will fail on every payload
    | containing a nested object.
    |
    | Amounts are integer minor units (kobo). See App\Domain\Commerce\
    | ValueObjects\Currency for the exponent — do not assume 2.
    |
    */

    'paystack' => [
        // §75: payment MODE is independent of APP_ENV. A staging server may run
        // live keys against a real merchant account, and a production server may
        // be pointed at test while a migration is rehearsed.
        'mode' => $paystackMode,

        // The active pair — always read these.
        'public' => $paystackKeys['public'],
        'secret' => $paystackKeys['secret'],

        // Both pairs are retained so an administrator can see which mode is
        // configured and so a mode switch needs no re-entry of credentials.
        'test' => [
            'public' => env('PAYSTACK_TEST_PUBLIC_KEY'),
            'secret' => env('PAYSTACK_TEST_SECRET_KEY'),
        ],
        'live' => [
            'public' => env('PAYSTACK_LIVE_PUBLIC_KEY'),
            'secret' => env('PAYSTACK_LIVE_SECRET_KEY'),
        ],

        'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
        // Signature algorithm is fixed by Paystack; kept explicit so a future
        // provider cannot silently reuse this config with a different scheme.
        'signature_header' => 'x-paystack-signature',
        'signature_algorithm' => 'sha512',
        'callback_url' => env('PAYSTACK_CALLBACK_URL'),
        'webhook_url' => env('PAYSTACK_WEBHOOK_URL'),
        // Off by default: shared hosts often sit behind proxies that rewrite
        // REMOTE_ADDR, so the signature is the primary control (docs/08 §1.5).
        'webhook_ip_allowlist' => array_values(array_filter(
            array_map('trim', explode(',', (string) env('PAYSTACK_WEBHOOK_IP_ALLOWLIST', '')))
        )),
        'timeout' => (int) env('PAYSTACK_TIMEOUT', 30),
        'retry_times' => (int) env('PAYSTACK_RETRY_TIMES', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google — OAuth, Calendar, Meet (§14, §36)
    |--------------------------------------------------------------------------
    |
    | Meetings are created through the Calendar API with
    | `conferenceDataVersion=1` and a `conferenceData.createRequest` carrying a
    | unique requestId. The Meet REST API alone is not a reliable way to create
    | a meeting, so the Calendar scope is required even when the feature the
    | user sees is "add a Meet link".
    |
    */

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
        // Scopes are requested incrementally: sign-in must not demand Calendar
        // access from a parent who only ever logs in (§75, least privilege).
        'scopes' => [
            'openid',
            'email',
            'profile',
        ],
        'calendar_scopes' => [
            'https://www.googleapis.com/auth/calendar.events',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Microsoft — OAuth (§14)
    |--------------------------------------------------------------------------
    */

    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect' => env('MICROSOFT_REDIRECT_URI'),
        'tenant' => env('MICROSOFT_TENANT', 'common'),
        'scopes' => [
            'openid',
            'email',
            'profile',
            'User.Read',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Zoom (§37)
    |--------------------------------------------------------------------------
    |
    | Zoom JWT apps are retired. Two real options remain:
    |   • Server-to-Server OAuth — one account-level app, meetings created on
    |     behalf of the platform's Zoom host account.
    |   • User-managed OAuth — each tutor connects their own Zoom account, so
    |     the meeting is genuinely theirs.
    | Neither is chosen by guessing: `platform.meetings.providers.zoom` decides,
    | and the UI only offers a provider that an administrator has connected.
    |
    */

    'zoom' => [
        // Server-to-Server OAuth and User-managed OAuth need different credential
        // sets. Whichever is enabled is resolved above; the client reads
        // `services.zoom.client_id` and never branches on the app type itself.
        's2s' => filter_var(env('ZOOM_S2S_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'client_id' => $zoomClientId,
        'client_secret' => $zoomClientSecret,
        'account_id' => env('ZOOM_ACCOUNT_ID'),
        'redirect' => env('ZOOM_REDIRECT_URI'),
        'base_url' => env('ZOOM_BASE_URL', 'https://api.zoom.us/v2'),
        'token_url' => env('ZOOM_TOKEN_URL', 'https://zoom.us/oauth/token'),
        // Scopes differ by app type: `:admin` scopes only exist for S2S apps, and
        // requesting them from a user-managed app fails with "Invalid scope".
        'scopes' => filter_var(env('ZOOM_S2S_ENABLED', false), FILTER_VALIDATE_BOOLEAN)
            ? ['meeting:write:admin', 'meeting:read:admin', 'user:read:admin']
            : ['meeting:write', 'meeting:read', 'user:read'],
    ],

];
