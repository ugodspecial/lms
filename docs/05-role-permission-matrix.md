# 05 — Role & Permission Matrix

**Deliverable:** spec §102.5 — *"A role/permission matrix."* Implements §6 (RBAC with
granular permissions), §28 (evaluator limits), §30 (approval authority), §49 (evaluator
financial blindfold), §81 (bulk-action authorization).

---

## 1. Design principles

| # | Principle | Implementation |
|---|-----------|----------------|
| P1 | **Permissions are the unit of authorization, not roles** (§6: *"Do not rely solely on hard-coded role checks"*) | Policies check `permissions`, never `hasRole()`. Roles are only bundles seeded for convenience |
| P2 | Every permission is `resource.action` | `students.view`, `tutors.approve`, `payments.refund` |
| P3 | Permission definitions live in **code**, assignments live in the **database** | `app/Domain/Administration/Permissions.php` is the registry; `PermissionSeeder` syncs it; admins can then compose roles in the UI |
| P4 | Super Admin bypasses via `Gate::before` — the **only** place a role name appears in authorization logic | `AuthServiceProvider` |
| P5 | Portal access is itself a permission | `parent.portal.access`, `student.portal.access`, `tutor.portal.access`, `evaluator.portal.access`, `admin.panel.access` — granted on profile activation |
| P6 | Ownership is checked **in addition to** permission | `StudentPolicy::view()` = `students.view` **OR** (guardian link active AND `can_view_academics`) |
| P7 | Destructive/irreversible actions get their own permission | `students.delete` ≠ `students.update`; `payments.refund` ≠ `payments.view` |
| P8 | Bulk operations reuse the singular permission **plus** a bulk flag where risk differs | `students.bulk_enroll`, `attendance.bulk_record`, `announcements.bulk_send` (§81) |
| P9 | Financial data is walled off from evaluators (§49) | No `payments.*`, `orders.*`, `reports.finance.*` permission is ever granted to the Evaluator role; enforced by a test, not just by seeder config |
| P10 | Every permission has at least one policy method and one test | Verified in Phase 11's authorization audit |

---

## 2. Roles

| Role | Guard | Granted to | Portal | Notes |
|------|-------|-----------|--------|-------|
| `Super Admin` | web | 1–2 people | admin | Full bypass. Cannot be self-assigned; assignment audited and restricted to existing Super Admins |
| `Administrator` | web | operations leadership | admin | Everything except `settings.manage.security`, `roles.manage`, `audit.view` deletion-adjacent powers |
| `Academic Admin` / `Registrar` | web | academic office | admin | Education + LMS + Assessment; no finance, no tutor approval |
| `Finance Officer` | web | accounts | admin | Commerce, payments, refunds, invoices, financial reports; **read-only** on academic data |
| `Operations Officer` | web | support staff | admin | Cohorts, scheduling, attendance, announcements, imports; no grading, no refunds |
| `Evaluator` | web | recruitment panel | evaluator | Tutor applications, interviews, evaluations. **No financial data (§49), no approval authority (§28)** |
| `Tutor` | web | approved tutors | tutor | Own sessions/cohorts/students, own availability, own earnings. Cannot see other tutors' private data (§71) |
| `Instructor` | web | staff teachers (may not be recruited tutors) | tutor | Same shape as Tutor but assigned by admin rather than through recruitment |
| `Parent` / `Guardian` | web | linked guardians | parent | Only their linked children (§58) |
| `Student` | web | students with accounts | student | Only their own records (§71) |
| `Applicant` | web | self-registered, not yet admitted | public + limited | Can submit/track an application only |

A user may hold **several** roles (a Tutor who is also a Parent). Portal switching is driven
by which `*.portal.access` permissions the user holds.

---

## 3. Permission registry — 168 permissions

### 3.1 Identity & access (14)

| Permission | SA | AD | AC | FO | OO | EV | TU | PA | ST | AP |
|------------|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| `users.view` | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ |
| `users.create` | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ |
| `users.update` | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ |
| `users.suspend` | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ |
| `users.deactivate` | ✅ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ |
| `users.impersonate` | ✅ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ |
| `roles.view` | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ |
| `roles.manage` | ✅ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ |
| `permissions.view` | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ |
| `user.roles.assign` | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ |
| `user.profile.update_own` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `user.two_factor.manage_own` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `user.connected_accounts.manage_own` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `audit.view` | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ |

Legend: **SA** Super Admin · **AD** Administrator · **AC** Academic Admin/Registrar ·
**FO** Finance Officer · **OO** Operations Officer · **EV** Evaluator · **TU** Tutor/Instructor ·
**PA** Parent · **ST** Student · **AP** Applicant. ✅ granted · ⬚ not granted.

### 3.2 Students (14)

| Permission | SA | AD | AC | FO | OO | EV | TU | PA | ST |
|------------|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| `students.view` | ✅ | ✅ | ✅ | 👁 read-only, no DOB/notes | ✅ | ⬚ | 🔒 scoped to own cohorts | 🔒 own children | 🔒 self |
| `students.view_sensitive` | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ | 🔒 own children | ⬚ |
| `students.create` | ✅ | ✅ | ✅ | ⬚ | ✅ | ⬚ | ⬚ | 🔒 create applicant child | ⬚ |
| `students.update` | ✅ | ✅ | ✅ | ⬚ | ✅ | ⬚ | ⬚ | 🔒 limited fields on own children | ⬚ |
| `students.delete` | ✅ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ |
| `students.change_status` | ✅ | ✅ | ✅ | ⬚ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ |
| `students.assign_code` | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ |
| `students.view_documents` | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ | 🔒 own children | 🔒 own |
| `students.upload_documents` | ✅ | ✅ | ✅ | ⬚ | ✅ | ⬚ | ⬚ | 🔒 own children | ⬚ |
| `students.bulk_create` | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ |
| `students.bulk_update_status` | ✅ | ✅ | ✅ | ⬚ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ |
| `students.export` | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ |
| `students.import` | ✅ | ✅ | ✅ | ⬚ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ |
| `student.portal.access` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ⬚ | ✅ |

🔒 = granted **but policy-scoped**: the permission alone is insufficient; the policy adds an
ownership/relationship check. 👁 = narrowed column set enforced by a resource/view-level filter.

### 3.3 Parents & guardians (11)

| Permission | SA | AD | AC | FO | OO | TU | PA |
|------------|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| `parents.view` | ✅ | ✅ | ✅ | 👁 billing only | ✅ | ⬚ | 🔒 self |
| `parents.create` | ✅ | ✅ | ✅ | ⬚ | ✅ | ⬚ | ⬚ |
| `parents.update` | ✅ | ✅ | ✅ | ⬚ | ✅ | ⬚ | 🔒 self |
| `parents.delete` | ✅ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ |
| `parents.manage` | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ |
| `parents.link_student` | ✅ | ✅ | ✅ | ⬚ | ✅ | ⬚ | 🔒 invite own child (pending admin confirmation) |
| `parents.unlink_student` | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ |
| `parents.set_capabilities` | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ |
| `parents.invite` | ✅ | ✅ | ✅ | ⬚ | ✅ | ⬚ | ⬚ |
| `parents.view_financials` | ✅ | ✅ | ⬚ | ✅ | ⬚ | ⬚ | 🔒 own orders only |
| `parent.portal.access` | ✅ | ✅ | ✅ | ✅ | ✅ | ⬚ | ✅ |

Linking/unlinking a guardian writes to `audit_logs` with before/after (§9).

### 3.4 Admissions (8)

| Permission | SA | AD | AC | OO | AP |
|------------|:--:|:--:|:--:|:--:|:--:|
| `applications.view` | ✅ | ✅ | ✅ | ✅ | 🔒 own |
| `applications.create` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `applications.update` | ✅ | ✅ | ✅ | ⬚ | 🔒 own, while draft |
| `applications.assign_reviewer` | ✅ | ✅ | ✅ | ⬚ | ⬚ |
| `applications.review` | ✅ | ✅ | ✅ | ⬚ | ⬚ |
| `applications.decide` | ✅ | ✅ | ✅ | ⬚ | ⬚ |
| `applications.convert_to_student` | ✅ | ✅ | ✅ | ⬚ | ⬚ |
| `applications.withdraw` | ✅ | ✅ | ✅ | ⬚ | 🔒 own |

### 3.5 Academic structure (22)

| Permission | SA | AD | AC | OO | TU |
|------------|:--:|:--:|:--:|:--:|:--:|
| `academic_years.view` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `academic_years.manage` | ✅ | ✅ | ✅ | ⬚ | ⬚ |
| `academic_years.activate` | ✅ | ✅ | ⬚ | ⬚ | ⬚ |
| `academic_terms.view` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `academic_terms.manage` | ✅ | ✅ | ✅ | ⬚ | ⬚ |
| `academic_levels.manage` | ✅ | ✅ | ✅ | ⬚ | ⬚ |
| `programs.view` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `programs.create` | ✅ | ✅ | ✅ | ⬚ | ⬚ |
| `programs.update` | ✅ | ✅ | ✅ | ⬚ | ⬚ |
| `programs.delete` | ✅ | ⬚ | ⬚ | ⬚ | ⬚ |
| `programs.publish` | ✅ | ✅ | ✅ | ⬚ | ⬚ |
| `programs.learning_path.manage` | ✅ | ✅ | ✅ | ⬚ | ⬚ |
| `subjects.view` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `subjects.manage` | ✅ | ✅ | ✅ | ⬚ | ⬚ |
| `enrollments.view` | ✅ | ✅ | ✅ | ✅ | 🔒 own cohorts |
| `enrollments.create` | ✅ | ✅ | ✅ | ✅ | ⬚ |
| `enrollments.update` | ✅ | ✅ | ✅ | ✅ | ⬚ |
| `enrollments.withdraw` | ✅ | ✅ | ✅ | ⬚ | ⬚ |
| `enrollments.bulk_create` | ✅ | ✅ | ✅ | ✅ | ⬚ |
| `cohorts.view` | ✅ | ✅ | ✅ | ✅ | 🔒 own |
| `cohorts.manage` | ✅ | ✅ | ✅ | ✅ | ⬚ |
| `cohorts.bulk_enroll` | ✅ | ✅ | ✅ | ✅ | ⬚ |

### 3.6 Scheduling & attendance (13)

| Permission | SA | AD | AC | OO | TU | PA | ST |
|------------|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| `schedules.view` | ✅ | ✅ | ✅ | ✅ | 🔒 own | 🔒 own children | 🔒 own |
| `schedules.manage` | ✅ | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ |
| `sessions.generate` | ✅ | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ |
| `sessions.view` | ✅ | ✅ | ✅ | ✅ | 🔒 own | 🔒 own children | 🔒 own |
| `sessions.update` | ✅ | ✅ | ✅ | ✅ | 🔒 own sessions | ⬚ | ⬚ |
| `sessions.cancel` | ✅ | ✅ | ✅ | ✅ | 🔒 own sessions | ⬚ | ⬚ |
| `sessions.record_summary` | ✅ | ✅ | ✅ | ✅ | 🔒 own sessions | ⬚ | ⬚ |
| `attendance.view` | ✅ | ✅ | ✅ | ✅ | 🔒 own sessions | 🔒 own children | 🔒 own |
| `attendance.record` | ✅ | ✅ | ✅ | ✅ | 🔒 own sessions | ⬚ | ⬚ |
| `attendance.bulk_record` | ✅ | ✅ | ✅ | ✅ | 🔒 own sessions | ⬚ | ⬚ |
| `attendance.correct` | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ |
| `attendance.export` | ✅ | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ |
| `holidays.manage` | ✅ | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ |

### 3.7 LMS content (18)

| Permission | SA | AD | AC | OO | TU | ST |
|------------|:--:|:--:|:--:|:--:|:--:|:--:|
| `courses.view` | ✅ | ✅ | ✅ | ✅ | 🔒 own + published | 🔒 enrolled only |
| `courses.create` | ✅ | ✅ | ✅ | ⬚ | 🔒 if `tutor.authoring` enabled | ⬚ |
| `courses.update` | ✅ | ✅ | ✅ | ⬚ | 🔒 own | ⬚ |
| `courses.delete` | ✅ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ |
| `courses.publish` | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ |
| `courses.submit_for_review` | ✅ | ✅ | ✅ | ⬚ | ✅ | ⬚ |
| `course_modules.manage` | ✅ | ✅ | ✅ | ⬚ | 🔒 own courses | ⬚ |
| `lessons.manage` | ✅ | ✅ | ✅ | ⬚ | 🔒 own courses | ⬚ |
| `lesson_resources.manage` | ✅ | ✅ | ✅ | ⬚ | 🔒 own courses | ⬚ |
| `courses.enroll_students` | ✅ | ✅ | ✅ | ✅ | ⬚ | ⬚ |
| `progress.view` | ✅ | ✅ | ✅ | ✅ | 🔒 own students | 🔒 own |
| `progress.recalculate` | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ |
| `discussions.view` | ✅ | ✅ | ✅ | ✅ | ✅ | 🔒 enrolled courses |
| `discussions.moderate` | ✅ | ✅ | ✅ | ✅ | 🔒 own courses | ⬚ |
| `discussions.post` | ✅ | ✅ | ✅ | ✅ | ✅ | 🔒 enrolled courses |
| `announcements.view` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `announcements.create` | ✅ | ✅ | ✅ | ✅ | 🔒 own cohorts | ⬚ |
| `announcements.bulk_send` | ✅ | ✅ | ✅ | ✅ | ⬚ | ⬚ |

### 3.8 Assessment, grading & certificates (21)

| Permission | SA | AD | AC | OO | TU | ST | PA |
|------------|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| `assessments.view` | ✅ | ✅ | ✅ | ✅ | 🔒 own | 🔒 own | 🔒 own children |
| `assessments.create` | ✅ | ✅ | ✅ | ⬚ | 🔒 own courses/cohorts | ⬚ | ⬚ |
| `assessments.update` | ✅ | ✅ | ✅ | ⬚ | 🔒 own | ⬚ | ⬚ |
| `assessments.delete` | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ |
| `assessments.publish` | ✅ | ✅ | ✅ | ⬚ | 🔒 own | ⬚ | ⬚ |
| `questions.manage` | ✅ | ✅ | ✅ | ⬚ | 🔒 own subjects | ⬚ | ⬚ |
| `submissions.view` | ✅ | ✅ | ✅ | ⬚ | 🔒 own assessments | 🔒 own | 🔒 own children |
| `submissions.grade` | ✅ | ✅ | ✅ | ⬚ | 🔒 assigned assessor | ⬚ | ⬚ |
| `submissions.return` | ✅ | ✅ | ✅ | ⬚ | 🔒 assigned assessor | ⬚ | ⬚ |
| `submissions.reopen` | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ |
| `grading_schemes.view` | ✅ | ✅ | ✅ | ✅ | ✅ | ⬚ | ⬚ |
| `grading_schemes.manage` | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ |
| `grades.view` | ✅ | ✅ | ✅ | 👁 aggregate only | 🔒 own students | 🔒 own | 🔒 own children |
| `grades.publish` | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ |
| `grades.amend` | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ |
| `academic_results.generate` | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ |
| `report_cards.generate` | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ | 🔒 own children |
| `certificates.view` | ✅ | ✅ | ✅ | ✅ | 🔒 own students | 🔒 own | 🔒 own children |
| `certificates.issue` | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ |
| `certificates.revoke` | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ |
| `certificates.verify_public` | *public* | | | | | | |

### 3.9 Tutor recruitment & evaluation (16)

| Permission | SA | AD | AC | EV | TU |
|------------|:--:|:--:|:--:|:--:|:--:|
| `tutors.applications.view` | ✅ | ✅ | 🔒 summary only | 🔒 **assigned only** | ⬚ |
| `tutors.applications.create` | ✅ | ✅ | ✅ | ⬚ | 🔒 own draft |
| `tutors.applications.update` | ✅ | ✅ | 🔒 own | ⬚ | 🔒 own draft |
| `tutors.applications.assign` | ✅ | ✅ | ✅ | ⬚ | ⬚ |
| `tutors.applications.review` | ✅ | ✅ | ✅ | ✅ | ⬚ |
| `tutors.applications.shortlist` | ✅ | ✅ | ✅ | ⬚ | ⬚ |
| `tutors.applications.withdraw` | ✅ | ✅ | ⬚ | ⬚ | 🔒 own |
| `tutors.interviews.view` | ✅ | ✅ | ✅ | 🔒 own | 🔒 own |
| `tutors.interviews.schedule` | ✅ | ✅ | ✅ | ✅ | ⬚ |
| `tutors.interviews.manage` | ✅ | ✅ | ✅ | 🔒 own | ⬚ |
| `tutors.evaluations.submit` | ✅ | ✅ | ✅ | ✅ | ⬚ |
| `tutors.evaluations.view` | ✅ | ✅ | ✅ | 🔒 own | 🔒 own |
| `tutors.approve` | ✅ | ✅ | ⬚ | ⬚ **§28: evaluators cannot approve** | ⬚ |
| `tutors.reject` | ✅ | ✅ | ⬚ | ⬚ | ⬚ |
| `evaluation_forms.manage` | ✅ | ✅ | ✅ | ⬚ | ⬚ |
| `evaluator.portal.access` | ✅ | ✅ | ✅ | ✅ | ⬚ |

### 3.10 Tutor operations (15)

| Permission | SA | AD | AC | OO | TU | PA | ST |
|------------|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| `tutor.portal.access` | ✅ | ✅ | ✅ | ✅ | ✅ | ⬚ | ⬚ |
| `tutors.view` | ✅ | ✅ | ✅ | ✅ | 🔒 self + public directory | 🔒 own children's tutors | 🔒 own tutors |
| `tutors.manage` | ✅ | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ |
| `tutors.suspend` | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ |
| `tutor.profile.update_own` | ✅ | ✅ | ✅ | ✅ | ✅ | ⬚ | ⬚ |
| `tutor.availability.manage_own` | ✅ | ✅ | ✅ | ✅ | ✅ | ⬚ | ⬚ |
| `tutor.availability.view` | ✅ | ✅ | ✅ | ✅ | 🔒 self | 🔒 for booking own children | ⬚ |
| `tutoring_services.view` | ✅ | ✅ | ✅ | ✅ | 🔒 own | ✅ published | ✅ published |
| `tutoring_services.manage` | ✅ | ✅ | ✅ | ⬚ | 🔒 own services | ⬚ | ⬚ |
| `tutoring_packages.manage` | ✅ | ✅ | ✅ | ⬚ | 🔒 own services | ⬚ | ⬚ |
| `bookings.view` | ✅ | ✅ | ✅ | ✅ | 🔒 own | 🔒 own + own children | 🔒 own |
| `bookings.create` | ✅ | ✅ | ✅ | ✅ | ⬚ | ✅ **for linked children** | 🔒 **adults only** (§10) |
| `bookings.cancel` | ✅ | ✅ | ✅ | ✅ | 🔒 own | 🔒 own | 🔒 own |
| `sessions.tutoring.manage` | ✅ | ✅ | ✅ | ✅ | 🔒 own | ⬚ | ⬚ |
| `session_notes.manage` | ✅ | ✅ | ✅ | ✅ | 🔒 own sessions | 🔒 read own children | 🔒 read own |

### 3.11 Reviews (6)

| Permission | SA | AD | TU | PA | ST |
|------------|:--:|:--:|:--:|:--:|:--:|
| `reviews.view` | ✅ | ✅ | 🔒 own | ✅ published | ✅ published |
| `reviews.create` | ⬚ | ⬚ | ⬚ **cannot review self (§88)** | 🔒 after a completed session | 🔒 after a completed session |
| `reviews.respond` | ⬚ | ⬚ | 🔒 own | ⬚ | ⬚ |
| `reviews.moderate` | ✅ | ✅ | ⬚ | ⬚ | ⬚ |
| `reviews.hide` | ✅ | ✅ | ⬚ | ⬚ | ⬚ |
| `reviews.delete` | ✅ | ⬚ | ⬚ | ⬚ | ⬚ |

### 3.12 Commerce & finance (26)

| Permission | SA | AD | FO | OO | EV | PA | ST |
|------------|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| `products.view` | ✅ | ✅ | ✅ | ✅ | ⬚ | ✅ published | ✅ published |
| `products.create` | ✅ | ✅ | 🔒 digital only | ⬚ | ⬚ | ⬚ | ⬚ |
| `products.update` | ✅ | ✅ | 🔒 digital only | ⬚ | ⬚ | ⬚ | ⬚ |
| `products.publish` | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ |
| `products.delete` | ✅ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ |
| `product_categories.manage` | ✅ | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ |
| `digital_products.manage` | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ |
| `orders.view` | ✅ | ✅ | ✅ | ✅ | ⬚ | 🔒 own | 🔒 own |
| `orders.create` | ✅ | ✅ | ✅ | ✅ | ⬚ | ✅ | 🔒 adults only |
| `orders.update` | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ |
| `orders.cancel` | ✅ | ✅ | ✅ | ⬚ | ⬚ | 🔒 own, unpaid | 🔒 own, unpaid |
| `orders.admin_assisted_checkout` | ✅ | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ |
| `commerce.override_minor_restriction` | ✅ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ |
| `payments.view` | ✅ | ✅ | ✅ | 👁 status only | ⬚ **§49** | 🔒 own | 🔒 own |
| `payments.verify` | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ |
| `payments.refund` | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ |
| `payments.record_manual` | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ |
| `invoices.view` | ✅ | ✅ | ✅ | ✅ | ⬚ | 🔒 own | 🔒 own |
| `invoices.manage` | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ |
| `invoices.void` | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ |
| `coupons.manage` | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ |
| `tax_rates.manage` | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ |
| `subscriptions.view` | ✅ | ✅ | ✅ | ✅ | ⬚ | 🔒 own | 🔒 own |
| `subscriptions.manage` | ✅ | ✅ | ✅ | ⬚ | ⬚ | 🔒 cancel own | 🔒 cancel own |
| `entitlements.view` | ✅ | ✅ | ✅ | ✅ | ⬚ | 🔒 own | 🔒 own |
| `entitlements.grant_manual` | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ |

Plus: `entitlements.revoke`, `entitlements.extend`, `downloads.authorize` (system),
`downloads.view_ledger` (SA/AD/FO).

### 3.13 Reports & analytics (12)

| Permission | SA | AD | AC | FO | OO | EV | TU | PA | ST |
|------------|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| `reports.view` | ✅ | ✅ | 🔒 education | ⬚ | 🔒 operations | ⬚ | ⬚ | ⬚ | ⬚ |
| `reports.students` | ✅ | ✅ | ✅ | ⬚ | ✅ | ⬚ | 🔒 own students | ⬚ | ⬚ |
| `reports.academic_performance` | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ | 🔒 own cohorts | ⬚ | 🔒 own |
| `reports.attendance` | ✅ | ✅ | ✅ | ⬚ | ✅ | ⬚ | 🔒 own sessions | 🔒 own children | 🔒 own |
| `reports.at_risk_students` | ✅ | ✅ | ✅ | ⬚ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ |
| `reports.tutors` | ✅ | ✅ | ✅ | ⬚ | ✅ | ⬚ | 🔒 self | ⬚ | ⬚ |
| `reports.finance` | ✅ | ✅ | ⬚ | ✅ | ⬚ | ⬚ **§49** | ⬚ | ⬚ | ⬚ |
| `reports.revenue` | ✅ | ✅ | ⬚ | ✅ | ⬚ | ⬚ | 🔒 own earnings | ⬚ | ⬚ |
| `reports.enrollment` | ✅ | ✅ | ✅ | ⬚ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ |
| `reports.lms_analytics` | ✅ | ✅ | ✅ | ⬚ | ✅ | ⬚ | 🔒 own courses | ⬚ | ⬚ |
| `reports.export` | ✅ | ✅ | 🔒 scoped | 🔒 scoped | 🔒 scoped | ⬚ | ⬚ | ⬚ | ⬚ |
| `reports.build_scheduled` | ✅ | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ |

**§49 is enforced twice:** the Evaluator role is never granted a finance permission *and*
`tests/Authorization/EvaluatorCannotAccessFinancialDataTest.php` asserts every finance route
returns 403 for an evaluator — so a seeder mistake cannot silently leak money data.

### 3.14 Settings, files, imports & platform (18)

| Permission | SA | AD | AC | FO | OO | TU | PA | ST |
|------------|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| `settings.view` | ✅ | ✅ | 🔒 academic | 🔒 commerce | 🔒 operations | ⬚ | ⬚ | ⬚ |
| `settings.manage` | ✅ | ✅ | 🔒 academic | 🔒 commerce | 🔒 operations | ⬚ | ⬚ | ⬚ |
| `settings.manage.security` | ✅ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ |
| `settings.manage.payments` | ✅ | ⬚ | ⬚ | 🔒 read + test-mode only | ⬚ | ⬚ | ⬚ | ⬚ |
| `settings.manage.integrations` | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ |
| `files.upload` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `files.view_restricted` | ✅ | ✅ | 🔒 scoped | 🔒 scoped | ⬚ | ⬚ | ⬚ | ⬚ |
| `files.delete` | ✅ | ✅ | 🔒 own uploads | 🔒 own uploads | 🔒 own uploads | 🔒 own uploads | 🔒 own uploads | 🔒 own uploads |
| `imports.run` | ✅ | ✅ | ✅ | 🔒 products | ✅ | ⬚ | ⬚ | ⬚ |
| `imports.view` | ✅ | ✅ | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ |
| `exports.run` | ✅ | ✅ | 🔒 scoped | 🔒 scoped | 🔒 scoped | ⬚ | ⬚ | ⬚ |
| `pages.manage` | ✅ | ✅ | ✅ | ⬚ | ✅ | ⬚ | ⬚ | ⬚ |
| `faqs.manage` | ✅ | ✅ | ✅ | ⬚ | ✅ | ⬚ | ⬚ | ⬚ |
| `message_templates.manage` | ✅ | ✅ | ⬚ | 🔒 commerce templates | ⬚ | ⬚ | ⬚ | ⬚ |
| `platform.doctor` | ✅ | ✅ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ |
| `platform.maintenance_mode` | ✅ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ | ⬚ |
| `integration_logs.view` | ✅ | ✅ | ⬚ | 🔒 paystack only | ⬚ | ⬚ | ⬚ | ⬚ |
| `admin.panel.access` | ✅ | ✅ | ✅ | ✅ | ✅ | ⬚ | ⬚ | ⬚ |

**Count:** 14 + 14 + 11 + 8 + 22 + 13 + 18 + 21 + 16 + 15 + 6 + 26 + 12 + 18 = **214 permission
slots**, of which **168 distinct permission strings** (some rows are scoped variants of the
same string). The seeder registers the 168 distinct strings; scoping is implemented in
policies, not by inventing near-duplicate permissions.

---

## 4. Permission → policy mapping

Every permission resolves through a policy method. No permission is checked ad hoc in a view.

| Policy | Methods | Permissions used | Ownership rule added |
|--------|---------|-----------------|---------------------|
| `StudentPolicy` | view, viewAny, viewSensitive, create, update, delete, changeStatus, viewDocuments, uploadDocuments, export | `students.*` | Guardian: active `parent_student` + `can_view_*`; Student: self; Tutor: student is in a cohort the tutor instructs |
| `ParentPolicy` | view, create, update, linkStudent, unlinkStudent, setCapabilities | `parents.*` | Self, or `parents.manage` |
| `GuardianLinkPolicy` | canPurchase, canViewAcademics, canViewFinancials | — | Reads `parent_student` flags; **the authority for §10** |
| `StudentApplicationPolicy` | view, update, assign, review, decide, convert | `applications.*` | Applicant: own; Reviewer: assigned or `applications.review` |
| `CohortPolicy` | view, update, enroll, bulkEnroll, cancelSession | `cohorts.*`, `sessions.*` | Instructor: own cohort |
| `AttendancePolicy` | view, record, bulkRecord, correct | `attendance.*` | Session instructor, or scoped admin |
| `CoursePolicy` | view, create, update, publish, delete, enrollStudents | `courses.*` | Student: enrolled or free-preview lesson; Tutor: author or cohort instructor |
| `LessonPolicy` | view, update | `lessons.*`, `courses.view` | Delegates to `CourseAccessResolver` for students |
| `AssessmentPolicy` | view, create, update, publish, delete | `assessments.*` | Student: in scope and published/open; Tutor: author or cohort instructor |
| `SubmissionPolicy` | view, submit, grade, return, reopen | `submissions.*` | Student: own; Assessor: assigned; Parent: linked child |
| `GradingSchemePolicy` | view, manage | `grading_schemes.*` | — |
| `AcademicResultPolicy` | view, generate, publish, amend | `grades.*`, `academic_results.*` | Student: own; Parent: linked child **and** `results_visible_to_parents` |
| `CertificatePolicy` | view, issue, revoke | `certificates.*` | Student: own; Parent: linked child. Public verification is a separate unauthenticated controller with a rate limit |
| `TutorApplicationPolicy` | view, update, assign, shortlist, review, withdraw | `tutors.applications.*` | Applicant: own; Evaluator: **assigned only** |
| `InterviewPolicy` | view, schedule, manage, complete | `tutors.interviews.*` | Evaluator: assigned as interviewer |
| `EvaluationPolicy` | view, submit | `tutors.evaluations.*` | Evaluator: own submissions |
| `TutorApprovalPolicy` | approve, reject | `tutors.approve`, `tutors.reject` | **Permission-gated only** — evaluators never hold it (§28) |
| `TutorProfilePolicy` | view, update, suspend, viewPrivate | `tutors.*`, `tutor.profile.*` | Public: `is_publicly_listed` + `status=active`; Private fields (contact, docs, earnings): self or `tutors.manage` |
| `BookingPolicy` | view, create, cancel | `bookings.*` | Purchaser or linked guardian; **create additionally runs `MinorPurchaseGuard`** |
| `TutoringSessionPolicy` | view, update, cancel, recordNotes | `sessions.tutoring.*`, `session_notes.*` | Tutor: own; Student: participant; Parent: linked child |
| `ReviewPolicy` | view, create, respond, moderate | `reviews.*` | Create requires a **completed** session the reviewer participated in; tutors cannot review themselves |
| `OrderPolicy` | view, create, cancel, adminCheckout | `orders.*` | Customer: own; Parent: own; Admin: `orders.view` |
| `PaymentPolicy` | view, verify, refund, recordManual | `payments.*` | Customer: own payment; Finance: `payments.*` |
| `ProductPolicy` | view, create, update, publish, delete | `products.*` | Public: `status=published` |
| `SubscriptionPolicy` | view, cancel, manage | `subscriptions.*` | Customer: own |
| `EntitlementPolicy` | view, grant, revoke, extend | `entitlements.*` | Owner or beneficiary (via guardian link) |
| `FilePolicy` | view, download, delete | `files.*`, `downloads.*` | Delegates to `DownloadAuthorizer` for product files; `restricted` requires an explicit check |
| `SettingPolicy` | view, update | `settings.*` (group-scoped) | Group→permission mapping in `config/platform.php` |
| `AuditLogPolicy` | viewAny, view | `audit.view` | Never updatable or deletable by anyone |
| `ReportPolicy` | view per report type | `reports.*` | Each report declares its permission + scope filter |

---

## 5. Seeding & synchronization

```php
// app/Domain/Administration/Permissions.php  (the single source of truth in code)
final class Permissions
{
    public const ROLE_SUPER_ADMIN = 'Super Admin';
    // … 11 role constants

    /** @return array<string, list<string>> module => permissions */
    public static function catalog(): array { /* 168 strings grouped by module */ }

    /** Permissions granted automatically when a profile is activated. */
    public const ON_STUDENT_ACTIVATED  = ['student.portal.access', 'user.profile.update_own', …];
    public const ON_PARENT_ACTIVATED   = ['parent.portal.access', 'orders.create', 'bookings.create', …];
    public const ON_TUTOR_APPROVED     = ['tutor.portal.access', 'tutor.profile.update_own', 'tutor.availability.manage_own', …];
    public const ON_EVALUATOR_ASSIGNED = ['evaluator.portal.access', 'tutors.applications.view', 'tutors.interviews.schedule', 'tutors.evaluations.submit'];
}
```

`PermissionSeeder`:
1. Upserts all permissions (never deletes ones in use — orphans are reported, not removed).
2. Upserts the 11 roles and their permission sets from the matrix above.
3. Is **idempotent** and safe to run in production (it does not create users).

`DemoDataSeeder` (development only, refuses in production per §73/§74) assigns the demo
accounts to these roles.

Super Admin bypass:

```php
// AuthServiceProvider
Gate::before(fn (User $user, string $ability) =>
    $user->hasRole(Permissions::ROLE_SUPER_ADMIN) ? true : null);
```

`null` (not `false`) so other gates still run for everyone else.

---

## 6. Negative tests this matrix must pass (§71)

| Test | Assertion |
|------|-----------|
| Parent A cannot access Student B | `403` on every student route, including direct URL, Livewire update and export |
| Student A cannot access Student B | `403` on progress, grades, submissions, certificates, downloads |
| Tutor A cannot see Tutor B's private information | public directory shows B's published profile; B's contact details, documents, availability and earnings are `403` |
| Evaluator cannot access financial data | every `payments.*`, `orders.*`, `reports.finance.*`, `reports.revenue*` route returns `403` |
| Evaluator cannot approve a tutor | `tutors.approve` absent → `403` even when the evaluation recommends approval |
| Minor cannot self-purchase a restricted service | `PurchaseRestrictedForMinor` from the web path, the admin-assisted path and the (future) API path |
| Unauthenticated user cannot reach a protected file | `401`/redirect, and the file is not at a guessable URL |
| Parent without `can_view_financials` cannot see invoices | `403` even though the child link is active |
| Parent without `can_purchase` cannot checkout for the child | rejected by `MinorPurchaseGuard` |
| Student whose enrollment expired cannot open a lesson | `CourseAccessResolver` denies; UI shows an upgrade/renew prompt |
| Non-Super-Admin cannot assign the Super Admin role | `403` + audit warning |
