# 08 — Integrations

**Deliverable:** spec §102.8 — *"A list of integrations."* Covers §3 (auth, payments, video),
§36–§37 (Google Meet, Zoom), §41–§43 (Paystack), §51–§52 (mail), §59 (storage).

Every integration sits behind a **contract in `app/Domain/Integration/Contracts`** with one or
more adapters, so a provider can be added or replaced without touching business logic (§14).

---

## 0. Integration inventory

| # | Integration | Contract | Adapters shipped | Phase | Config source | Required? |
|---|-------------|----------|------------------|-------|---------------|-----------|
| 1 | **Paystack** | `PaymentGateway` | `PaystackGateway`, `ManualPaymentGateway` | 8 | env (keys, mode) + `settings` (currency, fees) | Required for paid features; platform runs without it in "manual payments" mode |
| 2 | **Google Meet** (via Calendar API) | `VideoMeetingProvider` | `GoogleMeetProvider` | 7 | env (OAuth client) + `connected_accounts` (per tutor) | Optional |
| 3 | **Zoom** | `VideoMeetingProvider` | `ZoomProvider` (User-managed + Server-to-Server) | 7 | env (app credentials) + `connected_accounts` | Optional |
| 4 | Manual meeting links | `VideoMeetingProvider` | `ManualProvider` | 7 | none | Always available (the honest fallback) |
| 5 | **Google OAuth / OIDC** (login) | Laravel Socialite | `Socialite\GoogleProvider` | 1 | env | Optional |
| 6 | **Microsoft OAuth / OIDC** (login) | Laravel Socialite | `Socialite\AzureProvider` (Entra ID / Graph) | 1 | env | Optional |
| 7 | Mail (SMTP / API) | Laravel Mail | `smtp`, `log`, `mailgun`, `ses`, `resend` | 1 | env | Required for notifications |
| 8 | Object storage | Laravel Filesystem | `local` (default), `s3`-compatible | 1 | env, per-file `disk` | Optional upgrade |
| 9 | PDF rendering | `DocumentRenderer` | `DomPdfRenderer` (pure PHP) | 3 | none | Required for certificates/invoices/report cards |
| 10 | QR codes | `QrGenerator` | `BaconQrGenerator` (pure PHP) | 3 | none | Required for certificate verification |
| 11 | Search | `SearchIndex` | `DatabaseSearchIndex` (MySQL FULLTEXT) | 2 | none | Required; Scout/Meilisearch swappable later |
| 12 | SMS (future) | `SmsGateway` | *interface only, no adapter in v1* | — | env | Out of scope — interface reserved so the column `sms_enabled` isn't a lie |

**§93 compliance rule for all of the above:** if an optional integration is not configured,
its UI affordances are **not rendered**, `platform:doctor` reports it as
`not_configured` with a documentation link, and any code path that would need it degrades to
the `Manual*`/`Null*` adapter with an explicit, user-visible label. No integration is ever
faked.

```php
// app/Domain/Integration/Enums/IntegrationStatus.php
enum IntegrationStatus: string
{
    case Ready         = 'ready';          // configured + verified reachable
    case NotConfigured = 'not_configured'; // no credentials — UI hidden
    case Degraded      = 'degraded';       // configured, last call failed
    case Expired       = 'expired';        // per-user token needs reconnection
    case Unsupported   = 'unsupported';     // e.g. Google account without Meet licensing
}
```

---

## 1. Paystack (§41, §42, §43)

### 1.1 Configuration

```dotenv
# .env — NEVER committed (§41, §57)
PAYSTACK_MODE=test                          # test | live  (independent of APP_ENV, §75)
PAYSTACK_PUBLIC_KEY=pk_test_xxxxxxxxxxxxxxxx # safe for the browser (checkout inline only)
PAYSTACK_SECRET_KEY=sk_test_xxxxxxxxxxxxxxxx # server only — never in a view, JS bundle or log
PAYSTACK_WEBHOOK_URL=https://example.com/webhooks/paystack
PAYSTACK_WEBHOOK_IP_ALLOWLIST=               # optional, comma-separated (defence in depth)
PAYSTACK_CALLBACK_URL=                       # defaults to {APP_URL}/payments/paystack/callback
PAYSTACK_TIMEOUT=30
PAYSTACK_RETRY_TIMES=3
```

```php
// config/paystack.php
return [
    'mode' => env('PAYSTACK_MODE', 'test'),
    'keys' => [
        'test' => ['public' => env('PAYSTACK_TEST_PUBLIC_KEY'), 'secret' => env('PAYSTACK_TEST_SECRET_KEY')],
        'live' => ['public' => env('PAYSTACK_LIVE_PUBLIC_KEY'), 'secret' => env('PAYSTACK_LIVE_SECRET_KEY')],
    ],
    'base_url' => 'https://api.paystack.co',
    'webhook' => [
        'path' => 'webhooks/paystack',
        'signature_header' => 'x-paystack-signature',
        'algorithm' => 'sha512',
        'ip_allowlist' => array_filter(explode(',', (string) env('PAYSTACK_WEBHOOK_IP_ALLOWLIST', ''))),
    ],
    'currency' => env('PAYSTACK_CURRENCY'),   // null → fall back to setting('currency')
];
```

**Mode resolution:** `PaystackConfigResolver` picks the key pair from `PAYSTACK_MODE`. Both
test and live keys can be present simultaneously, so a production deploy can run
`PAYSTACK_MODE=test` during a soft launch (§75). A `paystack.mode` value is written into
every `payments`/`payment_events` row so test transactions are always distinguishable in
reporting.

### 1.2 The contract

```php
// app/Domain/Integration/Contracts/PaymentGateway.php
interface PaymentGateway
{
    public function key(): string;                                        // 'paystack'
    public function initialize(InitializePaymentRequest $r): InitializePaymentResult;
    public function verify(string $reference): PaymentVerification;
    public function chargeAuthorization(ChargeAuthorizationRequest $r): PaymentVerification;
    public function createSubscriptionPlan(SubscriptionPlan $p): ProviderPlan;
    public function cancelSubscription(string $providerSubscriptionCode): void;
    public function refund(RefundRequest $r): RefundResult;               // where supported
    public function fetchTransaction(string $reference): ?array;
    public function supports(GatewayCapability $c): bool;                 // refunds, plans, split…
}
```

DTOs (`InitializePaymentRequest`, `PaymentVerification`, …) are plain readonly classes with
`Money` values — **never** Paystack-shaped arrays. The adapter translates. That is what keeps
`CheckoutService` provider-agnostic and testable with `ManualPaymentGateway`.

### 1.3 Endpoints used

| Operation | Method & path | Notes |
|-----------|---------------|-------|
| Initialize transaction | `POST /transaction/initialize` | body: `email`, `amount` (**minor units**), `currency`, `reference` (ours, unique), `callback_url`, `metadata` (order id, beneficiary, channel), `plan` (subscriptions), `channels` |
| Verify transaction | `GET /transaction/verify/{reference}` | **the authority** — amount, currency and status are compared to our order before any value is delivered |
| Charge authorization (returning customer) | `POST /transaction/charge_authorization` | uses a stored `authorization_code`; only after a successful first charge with `is_reusable_authorization` |
| Create plan | `POST /plan` | for recurring tutoring (§43); `interval`, `amount` in minor units |
| Fetch subscription | `GET /subscription/{id_or_code}` | reconciliation |
| Cancel subscription | `PUT /subscription/disable` | body: `customer`, `subscription_code`, `subscription_token` |
| Refund | `POST /refund` | where the account supports it; otherwise `RefundResult::unsupported()` and finance follows a manual checklist (§93) |
| Fetch transaction list | `GET /transaction?status=&from=&to=` | reconciliation sweep |

All calls go through a single `PaystackClient` built on Laravel's `Http` facade with:
`Authorization: Bearer {secret}`, `timeout`, `retry(times, sleep, throw: false)`,
`beforeSending` redaction, and an `IntegrationLogger` middleware that writes `integration_logs`
rows (provider, operation, status, latency, **redacted** request/response).

### 1.4 Money handling

Paystack amounts are **integers in minor units** (NGN 100 → `10000`). Our `Money` VO stores
`amountMinor:int` + `currency`, so the mapping is lossless:

```php
$money = Money::fromMajor('45000.00', 'NGN');   // ₦45,000.00
$paystackAmount = $money->minorUnits();          // 4500000  (int, exact)
```

Verification compares **minor units as integers**, never floats — no `==` on money, no
rounding drift (§61, ADR-02).

### 1.5 Webhook security & idempotency (§42)

```php
// routes/webhooks.php — CSRF-exempt, signature-verified
Route::post('/webhooks/paystack', PaystackWebhookController::class)
    ->middleware(['throttle:paystack-webhook', VerifyPaystackSignature::class])
    ->name('webhooks.paystack');
```

**`VerifyPaystackSignature`** — the four rules that make this correct:

1. Read the **raw** request body (`$request->getContent()`), *before* any JSON decoding.
   Paystack signs the exact bytes it sent; re-encoding a decoded array will not match.
2. `hash_hmac('sha512', $rawBody, $secretKey)` → lowercase hex.
3. Compare with `hash_equals()` (constant time) against the `x-paystack-signature` header.
4. On mismatch: log a `warning` with IP + user agent, respond **400**, change nothing.

```php
final class VerifyPaystackSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $signature = (string) $request->header('x-paystack-signature', '');
        $computed  = hash_hmac('sha512', $request->getContent(), $this->config->secretKey());

        if ($signature === '' || ! hash_equals($computed, $signature)) {
            Log::channel('webhooks')->warning('paystack.signature_mismatch', [
                'ip' => $request->ip(), 'path' => $request->path(),
            ]);
            abort(400, 'Invalid webhook signature.');
        }

        if ($this->ipAllowlistEnabled() && ! in_array($request->ip(), $this->allowlist, true)) {
            Log::channel('webhooks')->warning('paystack.unexpected_source_ip', ['ip' => $request->ip()]);
            if ($this->strictIpMode) abort(403, 'Unexpected webhook source.');
        }

        return $next($request);
    }
}
```

> **IP allowlist caveat (documented, not hidden):** Paystack publishes source IPs, but many
> shared hosts sit behind proxies/CDNs that rewrite `REMOTE_ADDR`. The allowlist is therefore
> **off by default** and, when enabled, uses `TrustProxies`-resolved IPs. Signature
> verification is the primary control; the IP list is defence in depth.

**Controller flow:**

```php
public function __invoke(Request $request): Response
{
    $payload  = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
    $event    = (string) ($payload['event'] ?? '');
    $providerEventId = (string) ($payload['id'] ?? $this->deriveId($payload));

    // ── IDEMPOTENCY GATE ────────────────────────────────────────────────
    // UQ(provider, provider_event_id). A duplicate insert throws → we are done.
    try {
        PaymentEvent::create([
            'provider' => 'paystack', 'provider_event_id' => $providerEventId,
            'event_type' => $event, 'reference' => $payload['data']['reference'] ?? null,
            'signature_valid' => true, 'received_ip' => $request->ip(),
            'payload' => Redactor::scrub($payload), 'processing_status' => 'queued',
        ]);
    } catch (UniqueConstraintViolationException) {
        return response('Duplicate ignored', 200);          // §42
    }

    // ── RESPOND 200 FAST, PROCESS ASYNC ─────────────────────────────────
    ProcessPaystackWebhook::dispatch($providerEventId)->onQueue('payments');

    return response('OK', 200);
}
```

**Handled events:**

| Event | Handler | Effect |
|-------|---------|--------|
| `charge.success` | `ChargeSuccessHandler` | `ConfirmPayment` (idempotent) → order paid → `EntitlementGranter` → invoice paid → notifications |
| `charge.failed` | `ChargeFailedHandler` | payment failed, order failed, reserved slot released, `FailedPayment` email |
| `subscription.create` | `SubscriptionCreatedHandler` | create `subscriptions` (UQ provider id) + `subscription_events` + initial entitlement |
| `subscription.disable` | `SubscriptionDisabledHandler` | status cancelled, `cancelled_at`, access retained to `ends_at`, notification |
| `charge.success` **with subscription metadata** | `RenewalHandler` | new invoice + payment, entitlement **extended** (never duplicated), sessions replenished |
| `refund.processed` / `refund.failed` | `RefundHandler` | update `refunds.status`, revoke entitlements on success |
| `transfer.success` / `transfer.failed` / `transfer.reversed` | logged only | reserved for future payouts (§89) — recorded, no v1 behaviour |
| unknown event | `IgnoredHandler` | `payment_events.processing_status = ignored` + log; never a 500 |

**Replay safety belt:** even if the `payment_events` gate were bypassed, the downstream
uniques stop damage — `UQ(payments.provider, provider_reference)`,
`UQ(subscriptions.provider, provider_subscription_id)`,
`UQ(entitlements.source_type, source_id, product_id, beneficiary_student_id)`,
`UQ(entitlement_consumptions.entitlement_id, consumable_type, consumable_id)`.
**Four independent layers** (§42).

### 1.6 Verification-before-value rule (§41)

```
Browser redirect  →  "Verifying…" screen  →  dispatch VerifyPaystackTransaction
Webhook           →  signature + idempotency → dispatch ConfirmPayment
Both converge on  ConfirmPayment, which is idempotent and re-verifies with the API
                  if the payment row is not already 'success'.
⛔ No code path sets orders.status = 'paid' from request input alone.
```

`ConfirmPayment` additionally asserts:
- provider `status === 'success'`,
- `amount` (minor units) **equals** the order total,
- `currency` **equals** the order currency,
- the payment row belongs to this order.

Any mismatch ⇒ order stays unpaid, `audit_logs` + `integration_logs` record it, finance is
alerted (W24).

### 1.7 Reconciliation job (§41, §70)

```
schedule: every 5 minutes → payments:reconcile
  • orders in awaiting_confirmation older than settings.payments.reconcile_after_minutes
  • payments 'pending'/'processing' older than 15 minutes
  • for each: verify(reference) → confirm | fail | leave pending
  • unknown reference → flagged suspicious, never marked paid
  • summary logged to the 'paystack' channel + admin alert on repeated failures
```

### 1.8 Test mode vs live (§75)

| | test | live |
|---|---|---|
| Keys | `sk_test_…` / `pk_test_…` | `sk_live_…` / `pk_live_…` |
| Webhook URL | staging domain or a tunnel | production domain |
| Test cards | Paystack test card (`4084 0840 8408 4081`, any future expiry, cvv 408) | real cards |
| Automated tests | **never** hit the network — `Http::fake()` + committed fixtures; `ManualPaymentGateway` bound in the testing environment | — |
| Guard rail | `platform:doctor` **refuses** `APP_ENV=production` + `PAYSTACK_MODE=test` + live traffic without an explicit `settings.payments.allow_test_mode_in_production` acknowledgement | |

### 1.9 Setup checklist (for whoever configures production)

1. Create/select the Paystack account → **Settings → Preferences → Webhook URL** =
   `https://{domain}/webhooks/paystack`.
2. Copy the **test** public + secret keys into `.env`; verify with `php artisan paystack:ping`.
3. Run `php artisan paystack:verify-webhook` — it prints the exact HMAC the app expects for a
   sample payload so you can compare against Paystack's "test webhook" button.
4. Switch to `PAYSTACK_MODE=live` + live keys only after an end-to-end test purchase.
5. Confirm `php artisan platform:doctor` reports `paystack: ready`.
6. For subscriptions: `php artisan paystack:sync-plans` creates/updates plan codes for every
   product with `is_subscription = true`.

---

## 2. Google Meet (§36)

### 2.1 The API reality (verified)

**Google Meet has no general-purpose "create a meeting" REST endpoint.** Meet links are
produced by the **Calendar API**:

```
POST https://www.googleapis.com/calendar/v3/calendars/{calendarId}/events?conferenceDataVersion=1
Authorization: Bearer {access_token}

{
  "summary": "Mathematics 1-on-1 — Chidi & Ada",
  "description": "…",
  "start": { "dateTime": "2026-09-15T15:00:00Z", "timeZone": "UTC" },
  "end":   { "dateTime": "2026-09-15T16:00:00Z", "timeZone": "UTC" },
  "attendees": [{ "email": "…", "displayName": "…" }],
  "conferenceData": {
    "createRequest": {
      "requestId": "sess-918273-abcdef",            // MUST be unique per new link
      "conferenceSolutionKey": { "type": "hangoutsMeet" }
    }
  }
}
```

Two consequences that shaped the design:

| Reality | Design response |
|---------|-----------------|
| `conferenceDataVersion=1` **must** be a query parameter, not a body field | `GoogleMeetProvider` builds the URL explicitly; a common bug, now impossible |
| Conference data is generated **asynchronously** — the first response may lack `entryPoints` | `meetings.status = provisioning` → `GoogleMeetProvider` re-fetches the event (up to 3 attempts, then a queued retry). The UI shows "Setting up your meeting link…" and never a dead Join button. A `meetings:provision` scheduled sweep resolves stragglers |
| `requestId` must be unique per link | Generated as `"{$meetingableType}-{$meetingableId}-".Str::random(12)` and **stored** in `meetings.raw_payload` so a retry reuses the same id (idempotent) rather than leaking orphan links |
| The organizer's account needs Meet licensing (Workspace) | If `allowedConferenceSolutionTypes` lacks `hangoutsMeet`, the provider returns `Unsupported` with a reason and the flow falls back to `ManualProvider` — surfaced to the tutor as "Your Google account can't create Meet links. Connect a different account or paste a link." (§93) |

### 2.2 Configuration

```dotenv
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI="${APP_URL}/auth/google/callback"          # login
GOOGLE_MEET_REDIRECT_URI="${APP_URL}/integrations/google/callback"  # meetings
GOOGLE_MEET_ENABLED=true
GOOGLE_ORG_CALENDAR_ID=            # optional org-level fallback host
GOOGLE_ORG_REFRESH_TOKEN=          # optional, encrypted at rest by the app
```

Scopes requested (least privilege):

| Purpose | Scope |
|---------|-------|
| Login | `openid`, `email`, `profile` |
| Meetings | `https://www.googleapis.com/auth/calendar.events` (**not** full `calendar` — we don't need to read the tutor's whole calendar) |

Consent screen must list the app name, logo, privacy policy and terms URLs, and be submitted
for Google verification before production use with unverified users.

### 2.3 Token storage & refresh

`connected_accounts` stores `access_token` and `refresh_token` with Eloquent's `encrypted`
cast (AES-256 via `APP_KEY`). **Plaintext tokens never touch the database, a log line, or a
view** (§36, §57). `OAuthTokenRefresher` refreshes when `expires_at - 90s < now`, on demand
before each call, and in a nightly sweep; failures flip the row to `status=expired` and notify
the tutor to reconnect.

### 2.4 Operations

| Contract method | Google implementation |
|-----------------|----------------------|
| `createMeeting()` | `events.insert` with `conferenceDataVersion=1` → poll for `entryPoints[0].uri` |
| `updateMeeting()` | `events.patch` (time change, attendees); the Meet link is preserved |
| `cancelMeeting()` | `events.delete` (optionally `sendUpdates=all`) |
| `getMeeting()` | `events.get` → re-read `conferenceData` |

---

## 3. Zoom (§37)

### 3.1 The API reality (verified)

**Zoom JWT apps are retired** (no new JWT apps since 2023-09-08). Two supported models:

| Model | Best for | Credentials | Token endpoint |
|-------|----------|-------------|----------------|
| **User-managed OAuth** ("General" app) | *"the tutor connects their own Zoom"* (§37) — meetings are owned by the tutor | client id/secret + per-user authorization code + refresh token (1 h access / rolling 90-day refresh) | `POST https://zoom.us/oauth/token?grant_type=authorization_code` |
| **Server-to-Server OAuth** | org-level fallback: the platform hosts every meeting under one Zoom account | account id + client id/secret | `POST https://zoom.us/oauth/token?grant_type=account_credentials&account_id={id}` (Basic auth) |

`ZoomProvider` supports **both**, selected per meeting by: tutor's own connection → org S2S
account → `ManualProvider`. Granular scopes are used (classic `meeting:read`/`meeting:write`
are still accepted, but new apps should request the granular equivalents):

| Model | Scopes |
|-------|--------|
| User-managed | `meeting:read`, `meeting:write`, `user:read:admin` (only if the app is admin-installed) |
| Server-to-Server | `meeting:read:admin`, `meeting:write:admin`, `user:read:admin` |

> **Known constraint (documented):** meeting/recording scopes on a **user-managed** app require
> the app to be installed by a Zoom **account admin**, otherwise authorization fails with
> "Invalid scope". The setup guide states this explicitly rather than letting a tutor hit an
> unexplained error.

### 3.2 Configuration

```dotenv
ZOOM_ENABLED=true
# User-managed OAuth (tutor connects their own Zoom)
ZOOM_CLIENT_ID=
ZOOM_CLIENT_SECRET=
ZOOM_REDIRECT_URI="${APP_URL}/integrations/zoom/callback"
# Server-to-Server OAuth (org-level fallback host)
ZOOM_S2S_ENABLED=false
ZOOM_ACCOUNT_ID=
ZOOM_S2S_CLIENT_ID=
ZOOM_S2S_CLIENT_SECRET=
```

### 3.3 Operations

| Contract method | Zoom implementation |
|-----------------|---------------------|
| `createMeeting()` | `POST /v2/users/{userId|me}/meetings` with `type=2` (scheduled), `start_time` in UTC, `timezone`, `settings.join_before_host`, `settings.waiting_room=true`, `settings.auto_recording` per org settings |
| `updateMeeting()` | `PATCH /v2/users/{userId}/meetings/{meetingId}` |
| `cancelMeeting()` | `DELETE /v2/users/{userId}/meetings/{meetingId}` |
| `getMeeting()` | `GET /v2/users/{userId}/meetings/{meetingId}` → `join_url`, `start_url`, `password` |

The **join URL** is stored in `meetings.join_url` and shared with participants; the
**host/start URL** is stored encrypted and only ever rendered to the host (§58).

Zoom webhooks (optional, `meeting.updated`, `meeting.deleted`, `recording.completed`) use
Zoom's own `x-zm-signature` (HMAC-SHA256 of `v0:{timestamp}:{body}` with the webhook secret —
a **different** scheme from Paystack, so it has its own verifier class). Recording completion
updates `course_sessions.recording_url` / `meetings.recording_url`.

---

## 4. The video provider abstraction (§14)

```php
// app/Domain/Integration/Contracts/VideoMeetingProvider.php
interface VideoMeetingProvider
{
    public function key(): MeetingProvider;                       // google_meet | zoom | manual | none

    public function createMeeting(CreateMeetingRequest $request): MeetingResult;
    public function updateMeeting(UpdateMeetingRequest $request): MeetingResult;
    public function cancelMeeting(CancelMeetingRequest $request): void;
    public function getMeeting(MeetingReference $reference): MeetingResult;

    public function supports(MeetingCapability $capability): bool; // recording, waiting_room, dial_in…
    public function status(): IntegrationStatus;                  // for platform:doctor + the UI
    public function requiresHostConnection(): bool;               // google: yes; manual: no
}
```

```php
// app/Domain/Integration/DTOs/MeetingResult.php
final readonly class MeetingResult
{
    public function __construct(
        public MeetingStatus $status,      // provisioning | ready | failed | unsupported
        public ?string $externalId,
        public ?string $joinUrl,
        public ?string $hostUrl,
        public ?string $passcode,
        public ?string $recordingUrl,
        public ?string $failureReason,     // shown verbatim to staff when it fails (§93)
        public array $raw = [],            // redacted
    ) {}
}
```

**`MeetingManager`** is the only class callers touch. It picks the provider, persists the
`meetings` row, retries, logs, and falls back:

```php
final class MeetingManager
{
    public function provision(MeetingRequest $request): Meeting
    {
        $provider = $this->resolve($request);            // tutor preference → org default → manual

        try {
            $result = $provider->createMeeting($request->toDto());
        } catch (IntegrationException $e) {
            $this->logger->failure($provider->key(), $request, $e);

            if ($this->config->fallbackEnabled()) {
                $result = $this->manual->createMeeting($request->toDto());   // explicit, labelled
                $result = $result->withNote("Auto-creation failed: {$e->getUserMessage()}");
            } else {
                throw $e;
            }
        }

        return $this->persist($request, $provider->key(), $result);
    }
}
```

`ManualProvider` is **not** a stub: it stores a human-supplied join URL (pasted by the tutor
or an admin), validates it is a real `https://` URL, and marks `provider = manual` so the UI
says "Manual link" rather than implying Google or Zoom created it. That is the honest
behaviour §93 demands when credentials are missing.

**Adding Teams/Webex later** = one new adapter class + one config entry + one enum case. No
change to `course_sessions`, `tutoring_sessions`, `tutor_interviews` or any UI.

---

## 5. OAuth login: Google & Microsoft (§3)

| | Google | Microsoft (Entra ID) |
|---|---|---|
| Driver | `laravel/socialite` `google` | `laravel/socialite` `azure` / Microsoft Graph |
| Env | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` | `MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET`, `MICROSOFT_REDIRECT_URI`, `MICROSOFT_TENANT` (`common` for multi-tenant, or a tenant id) |
| Scopes | `openid email profile` | `openid email profile User.Read` |
| Callback | `/auth/{provider}/callback` | same |
| Linking rule | If a `users` row exists with the same **verified** email → link `connected_accounts` (purpose=login) and sign in. Otherwise create the user with `auth_provider={provider}`, `password=null`, `email_verified_at=now` (provider-verified) | same |
| Button visibility | Rendered **only** when the client id + secret are present | same |
| Security | `state` CSRF parameter (Socialite default), redirect URI exact-match allowlist, tokens encrypted, provider user id stored | same |
| Failure UX | "We couldn't sign you in with Google. You can still use your password." + logged to the `auth-failures` channel | same |

**Redirect URIs to register** (exact match — no wildcards):

```
https://{domain}/auth/google/callback
https://{domain}/auth/microsoft/callback
https://{domain}/integrations/google/callback
https://{domain}/integrations/zoom/callback
https://{staging-domain}/…  (separate OAuth apps per environment — never share credentials)
```

---

## 6. Mail & notifications (§51, §52)

| Aspect | Decision |
|--------|----------|
| Driver | `MAIL_MAILER=smtp` on shared hosting (cPanel gives you SMTP); `log` in local dev; `ses`/`mailgun`/`resend` available by config only |
| Channels | `database` (in-app bell) + `mail`. SMS interface reserved, no adapter in v1 |
| Queueing | **All** notifications implement `ShouldQueue` except password-reset/security notices, which are sent synchronously so a user isn't locked out by a queue backlog |
| Templates | `message_templates` (DB) with a Blade fallback in `resources/views/emails/`. Admins can edit subject/body without a deploy (§52). Variables are declared per template and validated on save |
| Preferences | `notification_preferences` per user × key × channel. Keys flagged `is_transactional` are **read-only in the UI and enforced in `NotificationPreferenceResolver`** (§82) |
| Marketing | Separate category, separate list, **opt-in only**, never implied by registration (§83) |
| Deduplication | Reminders use `ShouldBeUnique` with `uniqueId = {type}:{session_id}:{offset}` so a retried job cannot double-email |
| Deliverability | SPF/DKIM/DMARC documented in the deployment guide; `From` address must match the sending domain; unsubscribe + preference links in every non-transactional email |
| Failure handling | Retries with backoff → `failed_jobs` → admin alert; mail failures never roll back a business transaction |

**The 16 required templates (§52):** `welcome`, `verify_email`, `reset_password`,
`student_enrollment`, `parent_enrollment`, `payment_confirmation`, `payment_failed`,
`tutoring_booking`, `session_reminder`, `course_announcement`, `assignment_due`,
`assessment_result`, `tutor_application_received`, `interview_scheduled`, `tutor_approved`,
`tutor_rejected` — plus internally: `refund_processed`, `subscription_renewed`,
`subscription_past_due`, `subscription_cancelled`, `certificate_issued`,
`report_card_available`, `guardian_linked`, `guardian_unlinked`, `results_published`,
`review_published`, `session_cancelled`.

---

## 7. Storage (§59, §96)

| Tier | Disk | Contents | Access |
|------|------|----------|--------|
| `public` | `storage/app/public` → symlinked to `public/storage` | course thumbnails, tutor avatars, org logo, certificate **backgrounds** | Anyone. Only files explicitly marked `visibility=public` are ever written here |
| `authenticated` | `storage/app/authenticated` | course resources downloadable by any logged-in user | Through `/files/{uuid}/download` only |
| `private` | `storage/app/private` | tutor CVs, certifications, digital product files, invoices, report cards | Policy-checked streaming route |
| `restricted` | `storage/app/restricted` | student documents, minors' data, graded submissions, consent scans | Explicit per-request policy check + audit on every read |

**Never** in `public_html`. The `private`/`restricted` disks are outside the document root on
cPanel (see [09 §4](09-shared-hosting-deployment.md)). Filenames are
`{category}/{yyyy}/{mm}/{uuid}.{ext}` — the original name is stored in `files.original_name`
and only ever used to build a sanitized `Content-Disposition` header, never as a path.

**S3 upgrade path:** `files.disk` is **per row**, so a migration can move new uploads to `s3`
while old files stay on `local`, and a backfill command can move them later. No schema change,
no code change (§98).

---

## 8. PDF & QR (§26)

| Need | Library | Why |
|------|---------|-----|
| Certificates, invoices, report cards | **`barryvdh/laravel-dompdf`** | Pure PHP. `wkhtmltopdf`/Snappy needs an external binary and `exec()`, which cPanel almost always disables (§96) |
| Certificate QR | **`bacon/bacon-qr-code`** | Pure PHP, PNG/SVG output, no Imagick requirement |
| Images/thumbnails | GD (present on virtually every host) with an Imagick optional upgrade | Avatar/thumb generation is queued and skipped gracefully if neither extension exists |

Fonts: a self-hosted subset (e.g. Inter/DejaVu) is bundled so certificate rendering is
identical on every host and does not depend on system fonts.

---

## 9. Search (§54)

`DatabaseSearchIndex` implements the `SearchIndex` contract using MySQL **FULLTEXT** indexes
(with `LIKE` fallback for short tokens) across authorized providers. Each provider declares:

```php
final class StudentSearchProvider implements Searchable
{
    public function label(): string { return 'Students'; }
    public function permission(): string { return 'students.view'; }
    public function scope(Builder $q, User $user): Builder;   // ← authorization FIRST
    public function search(string $term, User $user, int $limit): SearchResultCollection;
}
```

Providers: students, parents, tutors, courses, programs, cohorts, products, orders,
assessments, applications. Swapping in Scout/Meilisearch later means implementing the same
contract — no caller changes.

---

## 10. Integration health & observability (§77)

`php artisan platform:doctor` and Admin → Health report, per integration:

```
paystack            ready          mode=test · last call 200 (43ms) · webhook verified 2h ago
google_meet         not_configured GOOGLE_CLIENT_ID missing → docs/08-integrations.md#2
zoom                degraded       last call 401 (token expired) · 3 tutors need to reconnect
mail (smtp)         ready          test message sent
storage             ready          local · writable · symlink OK
search              ready          database driver · 10 providers
```

Every outbound call writes an `integration_logs` row: provider, direction, operation,
correlation `request_id`, HTTP status, latency, redacted request/response, success flag,
error code/message, related entity. **Redaction is mandatory** — the scrubber strips
`Authorization`, `*secret*`, `*token*`, `password`, `card*`, `cvv`, and any key in
`config('platform.logging.redact')`. This is the single most useful artefact when a payment or
a meeting fails on a host you cannot SSH into (§77).

Logging channels: `paystack`, `webhooks`, `integrations`, `auth-failures`, `scheduler`,
`audit` — each a daily-rotating file with a configurable retention, so a small shared-hosting
disk isn't consumed by debug noise while payment evidence is kept for years.
