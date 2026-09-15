# cPanel shared-hosting deployment

Files in this directory are **templates to copy from**, not files the application
loads. Nothing here is executed automatically, and nothing here should be placed
inside the Laravel root's own `public/` directory (which already has its own
`.htaccess`).

| File | Goes where | Purpose |
|---|---|---|
| `crontab.txt` | cPanel → Cron Jobs | The two entries that replace supervisor |
| `document-root.htaccess` | `public_html/.htaccess` | Pretty URLs, security headers, caching of built assets |
| `deny-all.htaccess` | any web-exposed directory that must not be served | Belt-and-braces denial for `storage/`, `.env`, dotfiles |
| `deploy.sh` | run over SSH, if your host allows it | Ordered deploy steps with a health check at the end |

Full narrative instructions, including the document-root layout and every
integration, are in [`../../SETUP.md`](../../SETUP.md).

---

## The one thing that must not be wrong

```
/home/<user>/eduplatform/        ← Laravel root: .env, app/, storage/, vendor/
/home/<user>/public_html/        ← document root: contents of eduplatform/public/
```

**The Laravel root must sit outside the document root.**

If it is inside `public_html`, then `.env` — holding the Paystack secret key and
the database password — and the whole of `storage/` — holding student records,
signed certificates and purchased product files — are downloadable by anyone who
guesses a URL. No amount of PHP-level authorization matters, because Apache
serves the file before PHP is ever involved.

`deny-all.htaccess` is a mitigation for hosts that will not let you move the
document root. It is a mitigation, not a fix. Prefer moving the root.

Verify from outside the server:

```bash
curl -I https://your-domain.test/.env                       # must be 403 or 404
curl -I https://your-domain.test/../eduplatform/.env        # must be 403 or 404
curl -I https://your-domain.test/storage/app/private/       # must be 403 or 404
```

---

## What shared hosting forces, and how the codebase responds

| Constraint | Response |
|---|---|
| No Node runtime | Compiled assets are committed to `public/build`; CI fails if they are stale (ADR-13) |
| No supervisor, long-lived processes killed | `queue:work --stop-when-empty` from cron, every minute |
| `proc_open()` usually disabled | `runInBackground()` is never used in `routes/console.php` |
| No Redis | Database-backed queue, cache and sessions (ADR-14). Swapping to Redis later needs no code change |
| Small disk quota | Log channels are daily with `max_files` retention; `platform:doctor` warns above 500 MB |
| Cron PHP ≠ Apache PHP | `crontab.txt` documents how to pin the 8.3+ binary explicitly |
| Behind a proxy / SSL terminator | `trustProxies` is configured, and HSTS is emitted only on genuinely secure requests |

---

## After every deploy

```bash
cd /home/<user>/eduplatform
php artisan platform:doctor
```

It reports PHP version and extensions, environment sanity, storage writability
(by actually writing a probe file), database connectivity and pending migrations,
queue and mail configuration, committed-asset integrity, and every integration
that is enabled but not configured. It exits non-zero on any failure.

A daily 03:15 cron run mails its output, so a configuration that breaks after
deploy reaches an operator instead of degrading a feature silently.
