# Eduplatform — Architecture & Design Documentation

> **Status:** Design phase complete. Awaiting confirmation to begin **Phase 0 → Phase 1**.
> **Repository:** `ugodspecial/lms` · **Branch:** `arena/01a07c5b-lms`
> **Last updated:** 2026-09-07

This directory contains the complete system design for a production-ready, **online-only**
Education Management System + Learning Management System + Parent/Student Portals +
Tutor Recruitment & Tutoring Marketplace + Commerce platform, built as a
**Laravel modular monolith**.

The design deliberately reproduces the *business concepts and workflows* of modern
education platforms (Frappe Education, Frappe Learning and similar products) using an
**original Laravel-native architecture**. No source code, schema, branding or UI has been
copied from any existing product.

---

## 1. Document index

| # | Document | Contents | Spec § |
|---|----------|----------|--------|
| 00 | [Environment & Constraints](00-environment-and-constraints.md) | Verified toolchain versions, sandbox limitations, verification strategy | §3, §71 |
| 01 | [System Architecture](01-system-architecture.md) | Layered modular monolith, directory layout, request lifecycle, cross-cutting concerns | §4, §102.1 |
| 02 | [Domain Model](02-domain-model.md) | Bounded contexts, aggregates, entities, value objects, invariants, domain events | §5, §102.2 |
| 03 | [Database ERD](03-database-erd.md) | Mermaid + text ERD, per-context diagrams, cardinalities | §67, §102.3 |
| 04 | [Database Table Inventory](04-database-table-inventory.md) | All **124 tables**: purpose, key columns, indexes, constraints, relations | §66, §102.4 |
| 05 | [Role & Permission Matrix](05-role-permission-matrix.md) | 11 roles × 168 granular permissions, policy mapping | §6, §102.5 |
| 06 | [Feature / Module Matrix](06-feature-module-matrix.md) | Module × phase × tables × screens × permissions × tests | §102.6 |
| 07 | [User Workflows](07-user-workflows.md) | 24 end-to-end workflows with failure paths & side effects | §100, §102.7 |
| 08 | [Integrations](08-integrations.md) | Paystack, Google, Zoom, Microsoft, mail, storage, PDF/QR | §36–§43, §102.8 |
| 09 | [Shared-Hosting Deployment](09-shared-hosting-deployment.md) | cPanel architecture, cron, queues, SSL, backups, scaling path | §96–§98, §102.9 |
| 10 | [Phased Implementation Plan](10-phased-implementation-plan.md) | Phase 0–11 with entry/exit criteria and definition of done | §91, §102.10 |
| 11 | [Architecture Decision Records](11-architecture-decisions.md) | 15 ADRs: identity separation, money, entitlements, minors rule, video abstraction, webhook idempotency, privacy… | §4, §90 |

### Reading order

- **Technical reviewer / architect:** 01 → 02 → 11 → 03 → 04
- **Product owner:** 06 → 07 → 10 → 05
- **Security / compliance reviewer:** 05 → 11 (ADR-04, ADR-10, ADR-11) → 08 → 09
- **DevOps / host:** 00 → 09 → 10

---

## 2. The platform in one picture

```
                        ┌──────────────────────────────────────────────┐
   Public visitors ───► │  Marketing · Tutor directory · Admissions     │
                        │  Certificate verification (/certificates/verify)│
                        └───────────────────┬──────────────────────────┘
                                            │
        ┌───────────────┬───────────────┬───┴───────────┬───────────────┬──────────────┐
        ▼               ▼               ▼               ▼               ▼              ▼
  ┌──────────┐   ┌───────────┐   ┌───────────┐   ┌───────────┐  ┌────────────┐ ┌────────────┐
  │  Admin   │   │  Parent   │   │  Student  │   │   Tutor   │  │ Evaluator  │ │  Finance   │
  │ dashboard│   │  portal   │   │  portal   │   │ dashboard │  │ dashboard  │ │ / Ops      │
  └────┬─────┘   └─────┬─────┘   └─────┬─────┘   └─────┬─────┘  └─────┬──────┘ └─────┬──────┘
       └───────────────┴───────────────┴───────────────┴──────────────┴───────────────┘
                                        │  Livewire 4 + Blade + Alpine + Tailwind 4
       ┌────────────────────────────────┴────────────────────────────────────────┐
       │  HTTP layer: thin Controllers · Form Requests · Policies · Livewire UI   │
       ├──────────────────────────────────────────────────────────────────────────┤
       │  Application layer: Actions (single-purpose use cases) · DTOs · Jobs     │
       ├──────────────────────────────────────────────────────────────────────────┤
       │  DOMAIN LAYER  (no HTTP, no Eloquent leaking, no framework coupling)     │
       │                                                                          │
       │  Identity    Education     Lms         Assessment    Tutoring            │
       │  Commerce    Communication Integration Administration                   │
       │                                                                          │
       │  ★ Business rules live here: MinorPurchaseGuard · CourseAccessResolver   │
       │    GradeResolver · SlotFinder · EntitlementGranter · QuizScorer          │
       ├──────────────────────────────────────────────────────────────────────────┤
       │  Infrastructure: Eloquent repositories · Paystack · Zoom · Google ·      │
       │    Mail · Filesystem · Queue(database) · Cache · Audit · Settings        │
       └──────────────────────────────────────────────────────────────────────────┘
                                        │
                 ┌──────────────────────┼──────────────────────┐
                 ▼                      ▼                      ▼
          MySQL 8.x / MariaDB     storage/app (private)   External APIs
          (124 tables)            + optional S3           (Paystack, Zoom, Google)
```

---

## 3. Non-negotiable design rules

These are enforced by the architecture, not by convention. Each is traced to a
decision record in [11-architecture-decisions.md](11-architecture-decisions.md).

| # | Rule | Mechanism |
|---|------|-----------|
| R1 | **Purchaser ≠ beneficiary.** Every sale records both. | `orders.customer_user_id` + `orders.beneficiary_student_id`; `entitlements.owner_*` + `beneficiary_student_id` (ADR-03) |
| R2 | **A minor can never self-purchase a restricted service** — enforced server-side at one choke point, never in the UI. | `MinorPurchaseGuard` invoked by `CheckoutService` for web/API/admin-assisted (ADR-04) |
| R3 | **Payment is proven by server-side verification, never by browser redirect.** | `VerifyPaystackTransaction` + webhook reconciliation (ADR-06) |
| R4 | **Webhooks are idempotent.** Duplicate delivery cannot duplicate orders, payments, entitlements or subscriptions. | `payment_events` unique `(provider, event_id)` + DB unique constraints on references (ADR-06) |
| R5 | **Commerce never grants access directly.** Payment → Order → OrderItem → **Entitlement** → Access. | `EntitlementGranter` + `CourseAccessResolver` (ADR-03) |
| R6 | **Scheduling is provider-agnostic.** | `VideoMeetingProvider` contract; Meet/Zoom/Manual implementations (ADR-05) |
| R7 | **Money is decimal-safe and currency-aware.** No floats, no hard-coded ₦. | `Money` VO + `amount_minor` INT + `currency` CHAR(3) (ADR-02) |
| R8 | **All timestamps stored UTC; all display timezone-aware.** | UTC `datetime` columns + IANA `timezone` columns + `Timezone` presenter |
| R9 | **Protected files are never at guessable public URLs.** | `files.visibility` ∈ {public, authenticated, private, restricted} + signed streaming route (ADR-10) |
| R10 | **Authorization is permission-based, not role-string-based.** | spatie/laravel-permission v7 + Policies; `Gate::before` only for Super Admin (ADR-09) |
| R11 | **No fake functionality.** An unconfigured integration degrades to an explicit, labelled state — never a button that lies. | `NullObject` providers + `IntegrationStatus` health reporting |
| R12 | **No hard-coded business data.** Grading schemes, academic years, prices, currency, age of majority are DB/env-driven. | `settings` table + seeders marked `demo` |
| R13 | **Student data is sensitive.** Least privilege everywhere; parents see only their linked children. | `parent_student` authorization + `StudentPolicy` + audit log |
| R14 | **Every state transition is auditable and immutable.** | `*_events` history tables + `audit_logs` (append-only) |

---

## 4. Scale targets (design assumptions)

Design and index for these numbers on shared hosting; the architecture must not require
rewriting to grow past them (see [09 §12](09-shared-hosting-deployment.md#12-scaling-path-98)).

| Dimension | Year 1 target | Design headroom |
|-----------|---------------|-----------------|
| Students | 5,000 | 100,000 |
| Parents/guardians | 3,500 | 70,000 |
| Approved tutors | 150 | 5,000 |
| Concurrent live sessions/day | 300 | 10,000 |
| Courses / lessons | 200 / 6,000 | 5,000 / 150,000 |
| Orders/month | 2,000 | 200,000 |
| Assessment submissions/term | 60,000 | 5,000,000 |
| Stored documents | 20 GB | S3-compatible offload |

---

## 5. What is intentionally **out of scope** for v1

Traced to spec instructions — recorded so it is a decision, not an omission.

| Out of scope | Why | Prepared how |
|--------------|-----|--------------|
| Native iOS/Android apps | §99 | Domain services + DTOs are transport-agnostic; Sanctum tables present; `/api/v1` reserved |
| Tutor payouts / bank transfers | §89 | `tutor_earnings` ledger records gross/fee/share/net/payout_status |
| Multi-organization tenancy | single-org business | No `organization_id` sprawl; org identity lives in `settings`; ADR-01 documents the migration path |
| Redis, Docker, Kubernetes, Node servers | §3, §96 | Database cache/session/queue drivers with a config-only switch to Redis |
| Live proctoring / AI plagiarism detection | not requested | `assessments.type` is extensible; `integration_logs` pattern reusable |
| Real-time chat / WebSockets | §51 asks for in-app notifications, not chat | Laravel notifications (database channel) + polling; Reverb database driver available in Laravel 13 if needed later |
| Offline-first PWA | not requested | Blade/Livewire is server-rendered; responsive per §63 |

---

## 6. Next step

Per spec **§102**, no application code has been generated yet. The design is complete and
awaiting confirmation. See [10-phased-implementation-plan.md](10-phased-implementation-plan.md)
for what Phase 0 and Phase 1 will produce, and
[00-environment-and-constraints.md](00-environment-and-constraints.md) for the three open
decisions that need your input before implementation starts.
