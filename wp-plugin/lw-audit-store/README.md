# LW Audit Store

WordPress plugin powering the Free Audit Tool at `linkwhisper.com/internal-link-checker` and the Free Broken Link Checker at `linkwhisper.com/tools/broken-link-checker`.

**Version:** 0.5.1
**Build:** Anmoll + Claude (Phase 3, in-house WP plugin)
**Operate:** Matt / Iliya
**Status:** ship-candidate — verified end-to-end against wp-now sandbox 2026-05-05

> **Read this first if you are Matt / Iliya.** This README + [`DEPLOY.md`](DEPLOY.md) + [`HANDOFF.md`](HANDOFF.md) are everything you need. You should not need any external doc to install, verify, or operate this plugin.
>
> - `README.md` (this file) — what this is, end-to-end install path, required settings.
> - `DEPLOY.md` — install + configure + verify-curls + ops + failure-state recovery.
> - `HANDOFF.md` — actual simulation evidence (curl output, SQL output), open questions back to you, things still pending.

---

## What this plugin is and why it exists

The Free Audit Tool is LinkWhisper's acquisition lead-magnet at `linkwhisper.com/internal-link-checker`. Visitor pastes their site URL, gets a link-health report, and submits an email to receive the full report. We capture them as a Kit.com subscriber in the process.

**Before this plugin:** the audit tool ran on Netlify (separate origin, separate function). Two problems:
1. We had no system of record. Every submission lived only in Kit.com — no way to query "how many audits ran last week," no way to recover failed Kit syncs, no failure log.
2. The Netlify function couldn't easily share auth/email infra with the WordPress site.

**With this plugin:**
- Crawl, capture, email, and Kit-sync all run inside WordPress.
- Every submission writes to a dedicated MySQL table (`wp_lw_audits`) — that's the system of record.
- Failed Kit syncs auto-retry hourly (max 3 attempts).
- Failed `wp_mail` sends auto-retry once at 30 min. If both fail → row marked `mail_dead`, operator handles manually.
- Operator-visible admin dashboard at **WP admin → LW Audit**.

The React frontend (in `smiliyas/linkwhisper-react` → `src/pages/LinkChecker.tsx`) ships inside the WP theme bundle and calls these endpoints same-origin in production.

---

## What is in this plugin

| Surface | Purpose |
|---|---|
| `POST /wp-json/lw/v1/scan` | Crawls a public site (≤75 pages, 50s budget). Returns link-health report. |
| `POST /wp-json/lw/v1/broken-links` | Confirms WordPress first, then crawls internal HTML pages and checks up to 150 unique HTTP(S) destinations. Returns broken links, redirects, timeouts, and source pages. Non-WordPress sites are denied before crawling. |
| `POST /wp-json/lw/v1/emails` | Captures the visitor email. Sends the audit email via `wp_mail`. Subscribes them to Kit.com. |
| **WP admin → Settings → LW Audit** | Operator config: HMAC secret, Kit credentials, sender identity, CORS allow-list, physical mailing address (CAN-SPAM). |
| **WP admin → LW Audit** (top-level menu) | Read-only dashboard listing every captured submission and its delivery status. |

---

## What was just built (changelog for 2026-05-05 ship-candidate)

These are the changes layered on top of v0.3.0 before this hand-off. Read them so you know the current shape:

1. **CORS hardening** — Removed the legacy Netlify origin from the built-in allow-list. `linkwhisper.com`, `www.linkwhisper.com`, `audit.linkwhisper.com` are still built-in. Any other origin (staging, dev) must be added in **Settings → LW Audit → Extra CORS Origins**. DEPLOY.md has the verify-curl. *This was a hard install step that previously caused a misleading "Network error" UI message on staging.*

2. **`wp_mail` one-shot retry** — When the inline send returns false, the row is marked `email_status: mail_failed` and a single retry is scheduled 30 min later via `wp_schedule_single_event` → `lw_audit_mail_retry`. On second failure, the row flips to `mail_dead` and an entry is written to `wp_lw_audits_errors`. Idempotent: the retry no-ops if the status is no longer `mail_failed` (operator already fixed manually, etc).

3. **Honeypot field-name fix** — The plugin was checking `payload['hp_field']` but the React frontend sends `lw_check`. Bots submitting via curl/non-React clients would have bypassed the honeypot entirely. Now matches the React form's `name="lw_check"`.

4. **HMAC Shared Secret promoted to Required** — Used to salt rate-limit transient keys and `ip_hash`. Must be 32+ chars. **Do not rotate without expecting rate-limit counters to reset** — every existing transient key derives from this secret.

5. **`DEPLOY.md` expanded** — added pre-install SMTP checklist (4 items, see below), CORS verify-curl, full failure-states table, operator recovery steps for `mail_dead` and `kit_dead`.

6. **Bounded HTTP broken-link checker** — added `POST /lw/v1/broken-links`, which follows internal pages only and validates unique HTTP(S) destinations without crawling external sites. The endpoint reports when page, destination, or time caps make the result partial.
7. **WordPress-only admission check** — the broken-link endpoint checks the homepage and WordPress REST root before crawling. Sites without a recognized WordPress fingerprint receive a clear `lw_not_wordpress` response.

---

## What's been verified (2026-05-05 wp-now simulation)

Tested against the wp-now PHP-WASM sandbox before hand-off. All ten checks green:

| Test | Result |
|---|---|
| Scan endpoint returns 200 with `{ preview, fullReport }` | ✓ |
| Capture endpoint returns 200 with `audit_id` | ✓ |
| Inline `wp_mail` failure → `email_status: mail_failed` | ✓ |
| 30-min `lw_audit_mail_retry` event scheduled with correct args | ✓ |
| Retry handler flips `mail_failed → mail_dead` on second failure | ✓ |
| Retry handler logs to `wp_lw_audits_errors` (endpoint=`mail_dead`) | ✓ |
| Retry idempotency: re-firing on `mail_dead` row is a no-op | ✓ |
| Idempotency-Key replay: same key returns original `audit_id`, no duplicate row | ✓ |
| Honeypot (`lw_check` filled): silent 200, no row inserted, error logged | ✓ |
| CORS: allowed origin gets full headers (incl. `Idempotency-Key`); Netlify origin does not | ✓ |

Schema (after the v3 dbDelta runs on activation):
- `wp_lw_audits` — 26 columns (audit data + email/kit status + UTM + ip_hash + raw_results)
- `wp_lw_audits_errors` — 5 columns (id, created_at, endpoint, payload_hash, error_message)

---

## End-to-end install path (Matt's punch list)

This is the full sequence from "plugin in GitHub" to "live on linkwhisper.com." Tick as you go.

### Phase A — Staging install (futurearmyofficers.com or whichever staging host LW uses)

1. [ ] Clone or download `Anmoll-W/free-audit-tool`. The plugin lives at `wp-plugin/lw-audit-store/`.
2. [ ] Confirm outbound SMTP on the staging host — see DEPLOY.md "Pre-install — confirm outbound SMTP" (4-item tick-list). **If SMTP isn't routed through a configured relay, audit emails will silently never arrive — no error from the plugin.**
3. [ ] Upload `lw-audit-store/` to `/wp-content/plugins/` (zip via Plugins → Add New → Upload, or rsync).
4. [ ] Activate via **Plugins → LW Audit Store**.
5. [ ] Go to **Settings → LW Audit** and fill in every field marked Required in DEPLOY.md (HMAC secret, Kit creds, sender identity, physical address, Extra CORS Origins for the staging frontend host).
6. [ ] Verify schema with the SQL in DEPLOY.md ("Install" step 3).
7. [ ] Verify cron registered with the SQL in DEPLOY.md ("Install" step 4).
8. [ ] Verify CORS preflight with the curl in DEPLOY.md ("CORS allow-list — hard install step"). The response must include `Idempotency-Key` in `Access-Control-Allow-Headers`.

### Phase B — Staging end-to-end test

9. [ ] Open the React app's staging URL. Run a scan against any small WP site.
10. [ ] Submit a real email you control. Confirm:
    - **WP admin → LW Audit** shows the row with `email_status: sent`, `kit_status: synced` (or `pending` — cron will pick it up within an hour).
    - The inbox actually receives the email (not spam) within ~30s.
    - Footer renders the Unsubscribe line, the "you received this because…" line, and the physical mailing address.
11. [ ] Check Kit.com directly — confirm the subscriber landed on the right form, with the right tag (if configured).

### Phase C — Production deploy

12. [ ] Repeat steps 1–7 on `linkwhisper.com` production WP. **Production already has SMTP + SPF + DKIM configured** (same path that sends purchase receipts) — Phase A step 2 is a no-op for prod.
13. [ ] On production, **Extra CORS Origins** stays empty — the React bundle ships inside the WP theme so it's same-origin.
14. [ ] Run one production audit on a low-stakes URL (e.g., a personal blog you own). Confirm same green result as staging.
15. [ ] Once green: redirect the old Netlify origin (link-whisperer-internal-link-checker.netlify.app) → 301 → `linkwhisper.com/internal-link-checker`. Then retire the Netlify deploy.

### Phase D — Ongoing operations

- Every captured submission appears in **WP admin → LW Audit**.
- Failed Kit syncs auto-retry hourly. After 3 attempts → `kit_dead` and operator action.
- Failed `wp_mail` auto-retries once at 30 min. After 2 attempts → `mail_dead` and operator action.
- See DEPLOY.md "Failure States" for recovery SQL and root-cause checklist.

---

## Required settings checklist

Plugin will activate without these, but no audit will work until they are set:

- [ ] **HMAC Shared Secret** (32+ chars) — `openssl rand -hex 32`
- [ ] **Kit.com V3 API Key + V3 API Secret** and **Form ID** (Tag ID optional)
- [ ] **From Email**, **From Name**, **Reply-To Email** (must be a domain `wp_mail` can authenticate)
- [ ] **Physical Mailing Address** — required for CAN-SPAM compliance, appears in every email footer
- [ ] **Extra CORS Origins** — required on staging or any non-prod environment (built-in allow-list covers `linkwhisper.com` only)
- [ ] **SMTP relay confirmed** — see DEPLOY.md "Pre-install" — same path LinkWhisper production uses for purchase receipts and password resets

---

## Failure states (quick reference)

Full table in DEPLOY.md "Failure States."

| Status | Meaning | Action |
|---|---|---|
| `email_status: sent` | Inbox delivered | None |
| `email_status: mail_failed` | First send failed; retry scheduled in 30 min | Wait — auto-recovers or escalates |
| `email_status: mail_dead` | Both attempts failed | Check `wp_lw_audits_errors`. Most common: SMTP credentials, SPF/DKIM regression, recipient bounce |
| `kit_status: synced` | Subscriber in Kit | None |
| `kit_status: failed` | Inline call failed; hourly cron will retry up to 3 times | None unless persistent |
| `kit_status: dead` | All 3 Kit retries exhausted | Re-attempt: `UPDATE wp_lw_audits SET kit_status='pending', kit_attempts=0 WHERE id=N` |

---

## Files

```
lw-audit-store/
├── lw-audit-store.php         — bootstrap, hooks, cron registration
├── readme.txt                 — wp.org-style metadata
├── uninstall.php              — drops tables on plugin delete
├── README.md                  — this file (entry point for new operators)
├── DEPLOY.md                  — install + configure + verify + ops guide
├── includes/
│   ├── class-installer.php    — schema (dbDelta), activation/deactivation
│   ├── class-settings.php     — admin Settings page
│   ├── class-kit-client.php   — Kit.com API wrapper (3s timeout)
│   ├── class-mailer.php       — wp_mail + audit-email render
│   ├── class-crawler.php      — PHP internal-link crawler (replaced Netlify function)
│   ├── class-broken-link-crawler.php — bounded HTTP destination checker
│   ├── class-rest-controller.php — /scan + /broken-links + /emails routes, CORS, rate limit, honeypot
│   ├── class-cron.php         — hourly Kit retry + 30-min wp_mail retry
│   └── class-admin-page.php   — read-only dashboard
└── templates/
    └── email-audit-results.html — audit email layout
```

---

## Known limitations (v0.5.1)

- **Inline `wp_mail` + Kit dispatch.** Total budget ~5s worst case (3s Kit timeout + 2s wp_mail). Async dispatch (Action Scheduler / background queue) is a follow-up once volume justifies it.
- **No HMAC enforcement on capture.** Controller accepts unsigned POSTs from allow-listed origins. Enforcement is a follow-up once operator workflow is settled.
- **Crawler is single-process.** No queue, no horizontal scaling. Each scan ties up one PHP-FPM worker for up to 50s. Rate-limited to 10/hr per IP.
- **Broken-link checker is intentionally bounded.** It follows internal pages only, checks at most 50 pages and 150 unique destinations, and does not execute JavaScript. Redirects and timeouts are reported separately from HTTP errors; a partial-scan warning is returned when a cap is reached.
- **WordPress detection uses public fingerprints.** A heavily customized WordPress site that removes common asset, generator, and REST signals may be asked to retry even though it runs WordPress.
- **`wp_mail` retry is one-shot.** Single 30-min retry. If both fail, `mail_dead` and operator handles manually. Not enough volume yet to justify a multi-attempt scheduler.

---

## Support

- Plugin code: this repo (`Anmoll-W/free-audit-tool`) → `wp-plugin/lw-audit-store/`
- Operator guide: [`DEPLOY.md`](DEPLOY.md)
- Failure log queries: `wp_lw_audits_errors` table (sort by `created_at DESC`)
- Frontend: `smiliyas/linkwhisper-react` → `src/pages/LinkChecker.tsx`
