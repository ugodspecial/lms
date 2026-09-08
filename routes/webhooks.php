<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Inbound webhook routes
|--------------------------------------------------------------------------
|
| Loaded inside the `webhooks` middleware group (bootstrap/app.php): CSRF is
| excluded, no session is started, and the raw body is preserved so an HMAC
| signature can be recomputed over exactly the bytes the provider signed (§41).
|
| Every webhook route registered here MUST:
|   1. Verify a cryptographic signature over the raw body — reject, never trust.
|   2. Persist the event with a UNIQUE (provider, provider_event_id) so a
|      re-delivery is a no-op (4-layer idempotency, §41).
|   3. Return 200 fast and push heavy work onto the queue.
|   4. Never trust an amount, status or reference from the payload alone —
|      re-verify against the provider API before recording a payment.
|
| This file is intentionally EMPTY in Phase 0. No webhook is registered until
| its provider is genuinely configured and its verification middleware exists:
| a route that accepts unsigned JSON is worse than no route at all (§63).
|
| Phase 8 registers:
|   POST /webhooks/paystack  →  VerifyPaystackSignature  →  ProcessPaystackEvent
|
*/
