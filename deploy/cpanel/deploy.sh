#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
#  Ordered deployment for cPanel shared hosting.
# ─────────────────────────────────────────────────────────────────────────────
#
#  Run over SSH from the Laravel root (the directory containing `artisan`):
#
#      bash deploy/cpanel/deploy.sh
#
#  It is safe to re-run. Every step is idempotent, and it stops at the first
#  failure rather than continuing into a half-migrated database — which matters,
#  because a migration that failed halfway is far more expensive to recover from
#  than a deploy that simply did not finish.
#
#  It does NOT:
#    • take a backup       — do that first (SETUP.md §11); an untested backup is
#                            a hypothesis, and this script assumes you have one
#    • create .env         — first deploy only, by hand, because the values are
#                            deployment-specific and secret
#    • build front-end     — assets are committed (ADR-13); the server has no Node
#
#  First deploy? See SETUP.md §3 instead. This script assumes .env exists and the
#  database is reachable.
#
# ─────────────────────────────────────────────────────────────────────────────

set -euo pipefail

# ── Resolve the application root ──────────────────────────────────────────────
# Derived from this file's own location so the script works from any working
# directory and from cron, where PATH and cwd are not what you expect.
APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$APP_ROOT"

# The PHP binary cron/SSH uses is independent of the one Apache uses on most
# shared hosts. Override explicitly when the default is not 8.3+:
#   PHP_BIN=/opt/cpanel/ea-php83/root/usr/bin/php bash deploy/cpanel/deploy.sh
PHP_BIN="${PHP_BIN:-php}"

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$1"; }
fail() { printf '\n\033[1;31mFAILED: %s\033[0m\n' "$1" >&2; exit 1; }

# ── 0. Preflight ─────────────────────────────────────────────────────────────
step "Preflight"

[ -f artisan ] || fail "artisan not found — run this from the Laravel root, not from public_html"
[ -f .env ]    || fail ".env is missing. Copy .env.example and fill it in (SETUP.md §3.3)"

PHP_VERSION="$("$PHP_BIN" -r 'echo PHP_VERSION;')"
echo "PHP: ${PHP_VERSION} (${PHP_BIN})"

# Fail before touching anything if PHP is too old. `composer install` would
# otherwise half-succeed and leave vendor/ in a mixed state.
"$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' \
  || fail "PHP 8.3+ is required, found ${PHP_VERSION}. Point PHP_BIN at the right binary."

# Composer is optional on some hosts; without it we can still deploy a vendor/
# tree committed or uploaded separately, but the operator should know.
if command -v composer >/dev/null 2>&1; then
  HAS_COMPOSER=1
else
  HAS_COMPOSER=0
  echo "WARNING: composer not on PATH — skipping dependency install."
  [ -d vendor ] || fail "No composer and no vendor/ directory. Nothing to run."
fi

# ── 1. Maintenance mode ──────────────────────────────────────────────────────
# Enabled before migrations so a user cannot submit a form against a schema that
# is halfway to its new shape. Skipped when there is nothing to migrate.
step "Checking for pending migrations"

PENDING="$("$PHP_BIN" artisan migrate:status --no-ansi 2>/dev/null | grep -c 'Pending' || true)"
echo "Pending migrations: ${PENDING}"

if [ "$PENDING" -gt 0 ]; then
  step "Enabling maintenance mode"
  # --retry tells the browser when to come back instead of showing a bare error.
  "$PHP_BIN" artisan down --retry=120 --render=errors::503 || true
  MAINTENANCE=1
else
  MAINTENANCE=0
fi

# From here on, always try to bring the site back up, even on failure. Leaving a
# platform in maintenance mode because a deploy script errored is worse than the
# error.
trap '[ "${MAINTENANCE:-0}" = "1" ] && { echo; echo "==> Leaving maintenance mode"; php_bin_up || true; }; true' EXIT

php_bin_up() { "$PHP_BIN" artisan up || true; }

# ── 2. Code & dependencies ───────────────────────────────────────────────────
step "Fetching code"
if [ -d .git ]; then
  git pull --ff-only || fail "git pull failed — resolve the conflict before deploying"
else
  echo "Not a git checkout; assuming files were uploaded by hand."
fi

if [ "$HAS_COMPOSER" = "1" ]; then
  step "Installing PHP dependencies"
  # --no-dev: dev-only packages (PHPUnit, Pint, Larastan) must not be present in
  # production, both for disk quota and because a phpunit binary on a shared host
  # is an unnecessary attack surface.
  # --optimize-autoloader: a classmap is measurably faster than PSR-4 lookups on
  # shared hosting, where opcache is often limited.
  composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist \
    || fail "composer install failed"
fi

# ── 3. Cache clearing before migration ───────────────────────────────────────
# Stale cached config can make a migration read the wrong database or an old
# connection. Clear first, re-cache at the end.
step "Clearing compiled state"
"$PHP_BIN" artisan config:clear
"$PHP_BIN" artisan route:clear
"$PHP_BIN" artisan view:clear
"$PHP_BIN" artisan cache:clear || true

# ── 4. Migrate ───────────────────────────────────────────────────────────────
if [ "$PENDING" -gt 0 ]; then
  step "Running migrations"
  # --force is required because APP_ENV is not `local`. Migrations must be
  # backwards-compatible: they run while the previous code may still be serving
  # requests from another process.
  "$PHP_BIN" artisan migrate --force --no-interaction || fail "Migration failed — restore from backup before retrying"
fi

# ── 5. Storage ───────────────────────────────────────────────────────────────
step "Linking public storage"
# Idempotent. Only the `public` tier is ever linked; authenticated, private and
# restricted must have no web-accessible path at all (SETUP.md §10).
"$PHP_BIN" artisan storage:link || true

step "Ensuring storage directories exist and are writable"
for dir in storage/app/public storage/app/authenticated storage/app/private \
           storage/app/restricted storage/framework/cache storage/framework/sessions \
           storage/framework/views storage/logs bootstrap/cache; do
  mkdir -p "$dir"
done

# Permissions are set conservatively. 775 is enough for the PHP process; 777
# would make every uploaded file world-writable, which on a multi-tenant shared
# host means every other account on the machine can read student records.
chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || \
  echo "WARNING: could not chmod storage — check ownership matches the cPanel user"

# ── 6. Rebuild caches ────────────────────────────────────────────────────────
step "Compiling configuration, routes and views"
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache

# ── 7. Drain the queue ───────────────────────────────────────────────────────
# Jobs dispatched by migrations or seeders must not wait up to a minute for the
# next cron tick. --stop-when-empty exits when done, so this cannot hang the
# deploy on a host that kills long-lived processes.
step "Draining the queue"
"$PHP_BIN" artisan queue:work --stop-when-empty --tries=3 --timeout=90 || \
  echo "WARNING: queue drain did not complete cleanly — check storage/logs/laravel.log"

# ── 8. Health check ──────────────────────────────────────────────────────────
step "Verifying the deployment"
# platform:doctor exits non-zero if anything failed. This is the gate: a deploy
# that ends with a red doctor report has shipped something that will not work.
"$PHP_BIN" artisan platform:doctor --no-ansi || fail "platform:doctor reported failures — see above"

# ── 9. Back up ───────────────────────────────────────────────────────────────
step "Leaving maintenance mode"
if [ "$MAINTENANCE" = "1" ]; then
  "$PHP_BIN" artisan up
  MAINTENANCE=0
fi

printf '\n\033[1;32mDeploy complete.\033[0m\n'
cat <<'EOF'

Next:
  1. Take a backup now, while the deployment is known good (SETUP.md §11).
  2. Confirm the cron entries are present (deploy/cpanel/crontab.txt):
       php artisan schedule:list
  3. Load the site in a browser and sign in as each role you have configured.
  4. If anything is wrong, restore from the backup taken in step 1 rather than
     attempting a forward fix against live data.

EOF
