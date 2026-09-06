#!/usr/bin/env bash
#
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
#
# Provision a freshly installed Nextcloud so Keepiq's e2e suite can run, for
# the shared `E2E Tests (Playwright)` CI job.
#
# Wired up as the workflow's `playwright-seed-command`. That step runs AFTER
# `php -S` is up and with cwd set to the Nextcloud SERVER ROOT, so this is
# invoked as:
#
#     playwright-seed-command: 'bash apps/keepiq/tests/e2e/ci-seed.sh'
#
# WHAT KEEPIQ ACTUALLY NEEDS (and why `occ app:enable` is not enough)
# -------------------------------------------------------------------
# Keepiq is a zero-knowledge vault. Nine of its fifteen e2e spec files live in
# tests/e2e/workflows/ and DRIVE the vault: they unlock it with a known
# development master password and then exercise secret CRUD, folders, sharing,
# import, health and audit. That only works when the admin account owns an
# EncryptionSuite whose AES-GCM private-key envelope was written with that
# password — which is what `lib/Repair/SeedDevelopmentData.php` creates.
#
# But `SeedDevelopmentData::run()` opens with:
#
#     if ($this->config->getSystemValueBool('debug', false) === false) { return; }
#
# and the shared workflow installs Nextcloud with `occ maintenance:install`,
# which leaves `debug` at its default of FALSE. So on a stock CI install the
# repair step runs, returns immediately, seeds nothing, and exits 0. The app
# enables cleanly, the SPA boots, and the vault sits in first-time-setup mode.
# The failure mode is a wall of workflow specs timing out on
# `expect('.lock-screen').toHaveCount(0)` — messages that accuse the unlock flow
# and are really an unprovisioned instance. (The same shape was observed on an
# isolated dev instance: 35 of 56 specs failed this way before `debug` was set;
# see the header of tests/e2e/workflows/_workflow-helpers.ts.)
#
# The generic `IRepairStep` trap applies on top of that: a repair step runs with
# NO user session, `InitializeSettings::run()` catches `\Throwable` and
# downgrades it to `$output->warning(...)`, and `occ app:enable` still exits 0.
# So nothing in the install path is load-bearing evidence that provisioning
# happened.
#
# This script therefore does the provisioning EXPLICITLY and then VERIFIES it, so
# a bad provision is ONE loud step failure here instead of ~20 misleading spec
# failures later. It is idempotent: every step either no-ops or re-verifies.

set -euo pipefail

# ── Target resolution ────────────────────────────────────────────────────────
# The workflow's "Seed test data" step exports BASE_URL / NEXTCLOUD_URL /
# NC_BASE_URL / ADMIN_USER / ADMIN_PASSWORD (ConductionNL/.github #124). Accept
# all of them, and fall back to the runner's own `php -S 0.0.0.0:8080` only when
# we are demonstrably in CI.
#
# Off CI an unset target is a HARD ERROR. On a developer box `localhost:8080` is
# the SHARED dev container, and this script performs ADMIN WRITES — it must
# never disable/re-enable an app or seed a vault in somebody else's environment.
BASE="${PLAYWRIGHT_BASE_URL:-${BASE_URL:-${NEXTCLOUD_URL:-${NC_BASE_URL:-}}}}"
IN_CI=false
if [ "${GITHUB_ACTIONS:-}" = "true" ] || [ "${CI:-}" = "true" ]; then
	IN_CI=true
fi
if [ -z "$BASE" ]; then
	if [ "$IN_CI" = "true" ]; then
		BASE="http://localhost:8080"
	else
		echo "ERROR: no base URL set. Export PLAYWRIGHT_BASE_URL or BASE_URL." >&2
		echo "       Refusing to default to http://localhost:8080 outside CI —" >&2
		echo "       that is the SHARED dev container and this script writes to it." >&2
		exit 1
	fi
fi
BASE="${BASE%/}"

USER_NAME="${ADMIN_USER:-${NC_ADMIN_USER:-admin}}"
USER_PASS="${ADMIN_PASSWORD:-${NC_ADMIN_PASS:-admin}}"

echo "[ci-seed] target: ${BASE}"

# cwd is the Nextcloud server root, so `occ` is right here. Guard anyway: if it
# is not, the occ-driven provisioning below would silently do nothing.
if [ ! -f occ ]; then
	echo "::error::ci-seed.sh must run from the Nextcloud server root (no ./occ here; cwd=$(pwd))."
	exit 1
fi

# ── 1. Turn on debug mode and re-run the repair steps ────────────────────────
# `debug` is what gates SeedDevelopmentData / SeedDevelopmentSecrets /
# SeedDevelopmentApplications / …SecretRequests / …LinkShares / …Shares /
# …SecretDelegations. Setting it after the app was already enabled is not
# enough on its own — repair steps only run during install/upgrade — so the app
# is disabled and re-enabled, which takes `Installer::installApp()` →
# `installAppLastSteps()` and executes both the `post-migration` and the
# `install` repair-step lists again (verified against nextcloud/server:
# lib/private/Installer.php:533-570 — `install` steps run unconditionally,
# `post-migration` steps run whenever `installed_version` is already set).
#
# Every seeder is idempotent (SeedDevelopmentData returns early when admin
# already owns a key-pair-consistent suite; SeedSecretTypes upserts by name), so
# running them twice is harmless.
echo "[ci-seed] enabling Nextcloud debug mode (gates the Keepiq dev seeders)"
php occ config:system:set debug --value=true --type=boolean

echo "[ci-seed] re-running Keepiq repair steps so the dev seeders execute"
php occ app:disable keepiq
php occ app:enable keepiq

# ── 2. Import the Keepiq register into OpenRegister ─────────────────────────
# Keepiq adopts OpenRegister's AppHost engine (lib/AppInfo/Application.php,
# ADR-040 / ADR-022) and ships lib/Settings/keepiq_register.json.
# `InitializeSettings` imports it with `force: false`, the version-guarded path,
# which can advance the recorded configuration version WITHOUT applying
# anything. Keepiq has no `settings#import` route of its own (appinfo/routes.php
# registers only getAdminSettings / updateAdminSettings / getUserSettings /
# updateUserSettings / getPolicy), so use OpenRegister's generic importer.
#
# That endpoint accepts exactly three input shapes — a multipart file under the
# literal key `file`, a `url` param, or a `json` param. The raw register JSON as
# the request body is NOT one of them (it 400s with "Missing required keys").
# `force` is compared `=== 'true' || === true`, so the multipart string "true"
# is accepted here.
#
# lib/Settings/register.d/ is the ADR-037 fragment directory. It currently holds
# only README.md; if fragments are ever added they must each be posted
# separately (the importer rejects multi-file uploads with "Expected only 1
# file"), so the loop below is written to handle that already.
APP_DIR="apps/keepiq"
IMPORT_URL="${BASE}/index.php/apps/openregister/api/configurations/import"

import_configuration() {
	local file="$1"
	local body code
	body="$(mktemp)"
	echo "[ci-seed] POST ${IMPORT_URL} <- ${file} (force=true)"
	code="$(
		curl -sS -o "$body" -w '%{http_code}' \
			-u "${USER_NAME}:${USER_PASS}" \
			-H 'OCS-APIRequest: true' \
			-F "file=@${file}" \
			-F 'force=true' \
			-F 'appId=keepiq' \
			"$IMPORT_URL" || echo 000
	)"
	echo "[ci-seed] import HTTP ${code}"
	head -c 1500 "$body"; echo
	if [ "$code" != "200" ]; then
		echo "::error::Keepiq configuration import failed for ${file} (HTTP ${code})."
		return 1
	fi
	return 0
}

import_configuration "${APP_DIR}/lib/Settings/keepiq_register.json"

# ADR-037 fragments, in stable (sorted) order. `|| true` on the glob expansion
# keeps `set -e` from aborting when the directory holds nothing but README.md.
for frag in $(find "${APP_DIR}/lib/Settings/register.d" -maxdepth 1 -name '*.json' | sort || true); do
	import_configuration "$frag"
done

# ── 3. Provision the non-admin vault fixture user ────────────────────────────
# tests/e2e/workflows/compromise-recovery.spec.ts cannot drive ADMIN's vault:
# SeedDevelopmentData writes admin's AES-GCM private-key envelope with the PHP
# EncryptService and the browser's decryptPrivateKey() rejects it (that spec's
# header, and bug #5 in workflows/vault-unlock.spec.ts). Recovery lives behind
# the unlock gate, so the spec instead logs in as a NON-ADMIN account owning no
# EncryptionSuite, lets the BROWSER create the vault, and rotates that.
#
# On a dev box that account is `alice`, seeded by nextcloud-docker-dev. An
# instance installed with `occ maintenance:install` has no such user, so the
# spec sat on /login?user=alice&direct=1 until it timed out — four failures that
# read as a broken login flow and were really a missing fixture account.
#
# The shipped password_policy app rejects a password as short as the fixture's,
# so it is disabled first. This instance exists only for this run.
VAULT_FIXTURE_USER="${KEEPIQ_VAULT_USER:-alice}"
VAULT_FIXTURE_PASS="${KEEPIQ_VAULT_PASS:-alice}"

if php occ app:list | sed -n '/Enabled:/,/Disabled:/p' | grep -q 'password_policy'; then
	echo "[ci-seed] disabling password_policy so the short fixture password is accepted"
	php occ app:disable password_policy
fi

# Idempotent: an existing account has its password forced to the known value,
# because a run that inherits an unknown password fails the same way a missing
# account does.
if php occ user:info "$VAULT_FIXTURE_USER" >/dev/null 2>&1; then
	echo "[ci-seed] fixture user ${VAULT_FIXTURE_USER} exists; forcing its password"
	OC_PASS="$VAULT_FIXTURE_PASS" php occ user:resetpassword --password-from-env "$VAULT_FIXTURE_USER"
else
	echo "[ci-seed] creating fixture user ${VAULT_FIXTURE_USER}"
	OC_PASS="$VAULT_FIXTURE_PASS" php occ user:add --password-from-env \
		--display-name="Keepiq e2e vault fixture" "$VAULT_FIXTURE_USER"
fi

if ! php occ user:info "$VAULT_FIXTURE_USER" >/dev/null 2>&1; then
	echo "::error::ci-seed.sh could not provision the vault fixture user ${VAULT_FIXTURE_USER}."
	exit 1
fi
echo "[ci-seed] vault fixture user ready: ${VAULT_FIXTURE_USER}"
# Whether it still owns no suite is checked by the spec itself, which skips
# rather than asserting against the wrong surface if setup mode is absent.

# ── 4. Verify — every claim below is checked, none is assumed ────────────────
# A 200 from an importer is not the same as a register existing, and a repair
# step that exits 0 is not evidence that it seeded anything. Query the real
# endpoints and fail loudly on anything missing.
#
# ⚠️ Before believing any zero, the verifier proves the query CAN match: the
# secret-types check runs first and must return a non-empty list through the
# exact same auth + JSON-unwrapping path. A zero there means the probe is
# broken; a zero anywhere else, after that passed, is a real empty result.
api_get() {
	local path="$1" out="$2"
	curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
		-H 'Accept: application/json' "${BASE}${path}" -o "$out"
}

verify() {
	python3 - "$1" "$2" <<'PY'
import json, sys

path, kind = sys.argv[1], sys.argv[2]

with open(path) as fh:
    raw = fh.read()

try:
    body = json.loads(raw)
except json.JSONDecodeError:
    print(f'::error::{kind}: endpoint did not return JSON. First 500 bytes:')
    print(raw[:500])
    sys.exit(1)

# Unwrap an OCS envelope if one is present, then the common list envelopes.
if isinstance(body, dict) and 'ocs' in body and isinstance(body['ocs'], dict):
    body = body['ocs'].get('data', body['ocs'])

items = body
if isinstance(items, dict):
    for key in ('results', 'data', 'items', 'secrets', 'types', 'suites'):
        if isinstance(items.get(key), list):
            items = items[key]
            break

if not isinstance(items, list):
    print(f'::error::{kind}: expected a list, got {type(items).__name__}. First 500 bytes:')
    print(raw[:500])
    sys.exit(1)


def field(item, *names):
    if not isinstance(item, dict):
        return None
    for n in names:
        if item.get(n) not in (None, ''):
            return item[n]
    return None


if kind == 'secret-types':
    # POSITIVE CONTROL. SeedSecretTypes runs unconditionally (no debug gate), so
    # this list is non-empty on ANY working install. If it is empty the probe
    # itself is wrong and every zero below would be meaningless.
    names = {field(i, 'name') for i in items}
    required = {'login', 'api_key', 'ssh_key', 'certificate', 'note', 'database', 'totp'}
    print(f'[ci-seed] secret types present: {sorted(n for n in names if n)}')
    missing = sorted(required - names)
    if missing:
        print(f'::error::secret-types: missing system types {missing}. '
              'SeedSecretTypes did not run — the probe/auth path is broken, so no '
              'other emptiness below can be trusted.')
        sys.exit(1)
    print(f'[ci-seed] secret-types OK ({len(items)} types) — probe proven able to match.')

elif kind == 'suites':
    active = [i for i in items if field(i, 'status') == 'active']
    print(f'[ci-seed] suites for admin: {len(items)} total, {len(active)} active')
    if not active:
        print('::error::admin owns NO active EncryptionSuite. SeedDevelopmentData did '
              'not seed the dev vault — check that `occ config:system:set debug` took '
              'effect BEFORE the app was re-enabled. Every workflow spec unlocks with '
              'the dev master password and would fail on the lock screen.')
        sys.exit(1)
    suite = active[0]
    for prop in ('certificate', 'privateKey'):
        if not field(suite, prop, prop.lower()):
            print(f'::error::the active suite has no `{prop}` — the browser cannot '
                  'unlock it.')
            sys.exit(1)
    print('[ci-seed] suites OK (active suite carries a certificate + wrapped private key).')

elif kind == 'secrets':
    print(f'[ci-seed] secrets visible to admin: {len(items)}')
    if not items:
        print('::error::the dev vault holds NO secrets. SeedDevelopmentSecrets did not '
              'run (it is gated on the same debug flag as SeedDevelopmentData). The '
              'audit-trail / password-health / keyboard specs all open "the first '
              'secret in the vault".')
        sys.exit(1)
    print('[ci-seed] secrets OK.')

elif kind == 'registers':
    # REPORTED, NOT ASSERTED — deliberately. OpenRegister's ImportHandler
    # creates registers only from `components.registers`, and
    # lib/Settings/keepiq_register.json declares none: it is still the
    # scaffold descriptor, carrying a single `example` schema and the comment
    # "replace with your app's actual schemas". Asserting a `keepiq` register
    # here would assert something the shipped config does not describe.
    slugs = {field(i, 'slug') for i in items}
    print(f'[ci-seed] openregister registers present: {sorted(s for s in slugs if s)}')

elif kind == 'schemas':
    # REPORTED, NOT ASSERTED — for the same reason as `registers` above.
    # lib/Settings/keepiq_register.json now declares no schemas at all.
    # It used to carry the scaffold's `example` placeholder, which was
    # asserted here as an import canary; that slug collided with shillinq's
    # copy of the same placeholder on a shared OpenRegister, and Keepiq
    # keeps its secrets in its own lib/Db tables rather than as OpenRegister
    # objects, so there was never a real schema to put in its place.
    slugs = {field(i, 'slug') for i in items}
    print(f'[ci-seed] openregister schemas present: {sorted(s for s in slugs if s)}')
PY
}

# Positive control FIRST — proves auth + unwrapping can return a non-empty list.
TYPES_BODY="$(mktemp)"
api_get '/index.php/apps/keepiq/api/v1/secret-types' "$TYPES_BODY"
verify "$TYPES_BODY" secret-types

SUITES_BODY="$(mktemp)"
api_get '/index.php/apps/keepiq/api/v1/suites' "$SUITES_BODY"
verify "$SUITES_BODY" suites

SECRETS_BODY="$(mktemp)"
api_get '/index.php/apps/keepiq/api/v1/secrets?limit=100' "$SECRETS_BODY"
verify "$SECRETS_BODY" secrets

REG_BODY="$(mktemp)"
api_get '/index.php/apps/openregister/api/registers?_limit=300' "$REG_BODY"
verify "$REG_BODY" registers

SCH_BODY="$(mktemp)"
api_get '/index.php/apps/openregister/api/schemas?_limit=1000' "$SCH_BODY"
verify "$SCH_BODY" schemas

# The CA underwrites every suite certificate. Report its health — the
# admin-settings spec asserts the "Healthy" label only when this agrees.
CA_BODY="$(mktemp)"
api_get '/index.php/apps/keepiq/api/v1/ca/status' "$CA_BODY" || true
echo "[ci-seed] CA status: $(head -c 400 "$CA_BODY")"

echo "[ci-seed] Keepiq dev vault provisioned."

# ── 5. Warm the SPA so the first spec doesn't pay the cold start ─────────────
# The shared workflow serves Nextcloud with `php -S 0.0.0.0:8080`. It now sets
# PHP_CLI_SERVER_WORKERS=8, but that is an UNVERIFIED fix for the measured
# cold-start effect (the first spec to run blew its test timeout waiting for the
# SPA and then passed on retry, while later specs ran in single-digit seconds).
# Warming here puts the cost in the environment-preparation step where it
# belongs, instead of hiding it inside an assertion timeout that would keep
# drifting upward. Failures are ignored on purpose — this is a warm-up, not a
# gate. The real checks are above and below.
for path in \
	"/index.php/apps/keepiq/" \
	"/index.php/apps/keepiq/lock" \
	"/index.php/settings/admin/keepiq" \
	"/index.php/apps/keepiq/api/v1/suites" \
	"/index.php/apps/keepiq/api/v1/secrets?limit=100"
do
	code="$(curl -sS -o /dev/null -w '%{http_code}' -u "${USER_NAME}:${USER_PASS}" \
		-H 'OCS-APIRequest: true' "${BASE}${path}" || echo 000)"
	echo "[ci-seed] warm ${path} -> ${code}"
done

# ── 6. The bundle gate ───────────────────────────────────────────────────────
# Do NOT hardcode the bundle URL. Nextcloud serves an app's assets from whichever
# apps directory it was installed into — `/apps/<app>/js/…` on the CI runner,
# `/custom_apps/<app>/js/…` in the docker dev images — and asking for the wrong
# one does not 404. It returns **HTTP 200 with `text/html`**: the Nextcloud error
# page, served through index.php. A status-code check therefore reports success
# while fetching a ~40 KB HTML page instead of the multi-MB bundle.
#
# So read the real `src` out of the rendered app page and verify the response is
# actually JavaScript.
APP_HTML="$(mktemp)"
curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/keepiq/" -o "$APP_HTML" || true

# `|| true` is load-bearing: grep exits 1 when it matches nothing, and under
# `set -euo pipefail` that aborts the script right here — so the case the gate
# below exists to explain (no bundle) would die with a bare non-zero exit and
# none of the diagnosis. Let it fall through to the gate instead.
BUNDLE_SRC="$(grep -oE 'src="[^"]*keepiq-main[^"]*"' "$APP_HTML" \
	| head -1 | sed 's/^src="//; s/"$//' || true)"

if [ -n "$BUNDLE_SRC" ]; then
	BUNDLE_INFO="$(curl -sS -o /dev/null \
		-w '%{http_code} %{content_type} %{size_download}' \
		-u "${USER_NAME}:${USER_PASS}" "${BASE}${BUNDLE_SRC}" || echo '000 - 0')"
	echo "[ci-seed] bundle ${BUNDLE_SRC} -> ${BUNDLE_INFO}"
else
	echo "[ci-seed] could not locate the bundle src in the rendered app page."
	BUNDLE_INFO=""
fi

# On CI this is a GATE, not a warm-up.
#
# The single most likely way this job "succeeds" dishonestly is by passing
# without ever loading the app — and the environment hides it well: when the
# bundle is absent Nextcloud does not 404, it serves its HTML error page with
# HTTP 200 and Content-Type text/html, so `npm run build` producing nothing
# looks, to every status-code check in the pipeline, exactly like success.
if [ "$IN_CI" = "true" ]; then
	case "$BUNDLE_INFO" in
		*javascript*)
			echo "[ci-seed] bundle verified as JavaScript."
			;;
		*)
			echo "::error::The Keepiq frontend bundle did not serve as JavaScript (got: ${BUNDLE_INFO:-<not found>})."
			echo "::error::The SPA cannot mount, so every UI spec would fail on a selector timeout with a misleading cause."
			echo "::error::Check the 'Build app frontend' step — a missing bundle returns HTTP 200 text/html, not 404."
			exit 1
			;;
	esac
fi

echo "[ci-seed] done."
