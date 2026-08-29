# LW Audit Store — Deploy Guide

Plugin version: 0.5.1
Target: linkwhisper.com (production WP)

---

## What this plugin does

- **`POST /wp-json/lw/v1/scan`** — crawls a public website (up to 75 pages, 50s budget) and returns a link-health report. Used by the React audit page on linkwhisper.com. Replaces the old Netlify function.
- **`POST /wp-json/lw/v1/broken-links`** — confirms the submitted site is WordPress, then crawls internal HTML pages, checks up to 150 unique HTTP(S) destinations, and returns broken links, redirects, timeouts, and source pages. Non-WordPress sites are denied before crawling; external sites are checked as destinations but not crawled.
- **`POST /wp-json/lw/v1/emails`** — captures the visitor email after they see results, sends the audit email via `wp_mail`, and subscribes them to Kit.com. Failed Kit syncs are retried hourly via WP-Cron.
- **WP admin → Settings → LW Audit** — operator config: Kit credentials, sender identity, CORS allow-list, physical address (CAN-SPAM).
- **WP admin → LW Audit (top-level menu)** — read-only dashboard listing every captured submission and its delivery status.

The plugin is the **system of record** for every free-tool acquisition. Kit.com is the delivery layer only.

---

## Install

1. Upload the unzipped `lw-audit-store/` folder to `/wp-content/plugins/`.
2. Activate via **Plugins → LW Audit Store**.
3. Verify schema:
   ```sql
   SHOW TABLES LIKE '%lw_audits%';
   -- expected: wp_lw_audits, wp_lw_audits_errors
   ```
4. Verify the cron is scheduled:
   ```sql
   SELECT option_value FROM wp_options WHERE option_name = 'cron' \G
   -- look for `lw_audit_kit_retry`
   ```

---

## Configure — required before first capture

### Pre-install — confirm outbound SMTP

This plugin uses WordPress core `wp_mail()` to send the audit-results email. **Production LinkWhisper WP already routes `wp_mail` through a transactional relay with SPF + DKIM configured (the same path that sends purchase receipts and password resets). The staging WP needs the same path — confirm before installing this plugin.**

Matt tick-list before activating the plugin:

- [ ] `wp_mail` on this host is routed through the same SMTP relay LinkWhisper production uses (`WP Mail SMTP`, `Fluent SMTP`, or equivalent — pointed at Postmark / SendGrid / AWS SES / Resend / whichever relay LW uses).
- [ ] DNS for the From-domain has an **SPF** record that authorises that relay.
- [ ] DNS for the From-domain has a **DKIM** record matching the relay's signing key.
- [ ] A manual `wp_mail` test send (e.g. `WP Mail SMTP`'s built-in tester) lands in a real Gmail/Outlook inbox — not spam — when sent to an external address.

If any item above is unchecked, audit emails will record `email_status: sent` because PHP successfully handed the email to the relay, but the customer's inbox will never see them. The 30-min retry won't help — the failure is upstream of `wp_mail`.

If you confirm all four items: proceed to Configure below.

Go to **Settings → LW Audit** and fill in:

| Field | Required? | Notes |
|---|---|---|
| HMAC Shared Secret | **Required (32+ chars)** | Used to salt request fingerprinting (`ip_hash`) and rate-limit transient keys. Generate 32+ random chars (`openssl rand -hex 32`). **Do not rotate without expecting rate-limit counters to reset** — every existing transient key derives from this secret. |
| Kit.com V3 API Key | Required | Sent as `api_key` to `https://api.convertkit.com/v3/forms/{form_id}/subscribe`. |
| Kit.com V3 API Secret | Required | Sent as `api_secret` to the same V3 form/tag subscribe endpoints. |
| Kit.com Form ID | Required | The form subscribers should land on. |
| Kit.com Tag ID | Optional | Tag applied through the V3 tag subscribe endpoint after form subscription. |
| From Email | Required | Must be a domain wp_mail can authenticate (typically `support@linkwhisper.com`). |
| From Name | Required | Displayed sender name. |
| Reply-To Email | Required | Where unsubscribe replies land. |
| **Physical Mailing Address** | **Required** | **CAN-SPAM Sec. 5(a)(5)** — appears in every email footer. Multi-line OK. |
| Extra CORS Origins | **Required for any non-prod environment** | Newline-separated, no trailing slash, include scheme (e.g. `http://localhost:8080`). Built-in allow-list includes only `linkwhisper.com`, `www.linkwhisper.com`, `audit.linkwhisper.com` (Netlify origin removed v0.3.0). **Any other origin (staging URL, dev `localhost:*`, alternate domain) must be added here or the React app will fail with a misleading "Network error" UI message.** |

### CORS allow-list — hard install step

The React app at `https://linkwhisper.com/internal-link-checker` calls this plugin same-origin (the bundle ships inside the WP theme), so production needs no CORS config. **Every other environment does.** If you skip the Extra CORS Origins setting on a staging install, the browser will reject the email-submit OPTIONS preflight (the `Idempotency-Key` request header is not in WP core's default Allow-Headers), the React app will show "Network error. Please check your connection.", and curl from the same machine will still return 200 — making it look like a frontend bug. It isn't.

**Verify the allow-list took effect after editing the setting:**

```bash
curl -i -X OPTIONS \
  "https://YOUR-STAGING-HOST/wp-json/lw/v1/emails" \
  -H "Origin: https://YOUR-FRONTEND-ORIGIN" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: Idempotency-Key, Content-Type"
```

Expected response headers:
- `Access-Control-Allow-Origin: https://YOUR-FRONTEND-ORIGIN`
- `Access-Control-Allow-Headers: Content-Type, X-LW-Idempotency-Key, Idempotency-Key, X-LW-Signature`
- `Access-Control-Max-Age: 86400`

If `Idempotency-Key` is missing from the Allow-Headers list, your origin isn't in the allow-list — re-check Settings → LW Audit → Extra CORS Origins (one origin per line, no trailing slash, include scheme).

---

## Verify — first scan + capture

1. Visit `https://linkwhisper.com/internal-link-checker` for the internal-link health audit, or `https://linkwhisper.com/tools/broken-link-checker` for the HTTP broken-link checker.
2. Paste a small WordPress site URL (e.g. a personal blog) → **Check My Site**.
3. Wait for results (10–50s). The internal-link checker shows a score, four stat cards, and the top issues. The broken-link checker shows HTTP broken links, redirects, timeouts, and any partial-scan warning.
4. Enter a test email → **Unlock report**.
5. In WP admin → **LW Audit** → confirm the row appears with `email_status: sent` and `kit_status: synced` (or `pending` if Kit was slow — the cron will retry within an hour). If you see `email_status: mail_failed` after the curl POST, that's expected when SMTP isn't configured — see Failure States above. The retry will fire in 30 min if cron is running.
6. Check the inbox: subject begins with `Your link health score: …`. Footer must show:
   - The "you received this email because…" line
   - Your physical mailing address
   - The Unsubscribe link

---

## Failure States

| Status | What it means | Operator action |
|---|---|---|
| `email_status: mail_failed` | First `wp_mail` attempt failed. A 30-min retry is auto-scheduled. | Wait 30 min; status will flip to `sent` (success) or `mail_dead` (gave up). Check SMTP relay logs if the pattern repeats. |
| `email_status: mail_dead` | Retry also failed. No further automatic attempts. Customer never received the email. | Inspect `wp_lw_audits_errors` for the row's `mail_dead` entry. Most common causes: SMTP relay credentials expired, From-domain SPF/DKIM regression, recipient address bounce. After fixing root cause, re-send manually: `UPDATE wp_lw_audits SET email_status='queued' WHERE id=N;` + trigger via SQL or admin tool. |
| `kit_status: failed` | Kit subscribe call failed inline. Hourly cron retries, max 3 attempts. | No action needed unless persistent. |
| `kit_status: dead` | All 3 Kit retries failed. | Check Kit API key in Settings. Re-attempt by setting `kit_status='pending'` and `kit_attempts=0` for the row. |

---

## Operations

### Cron retry (Kit.com)

Failed Kit subscribes are retried hourly, up to 3 attempts. After the third failure the row is flagged `kit_dead` and not retried again. Manually retry by running:

```sql
UPDATE wp_lw_audits SET kit_status = 'pending', kit_attempts = 0 WHERE id = …;
```

Then wait for the next hourly tick (or trigger via WP-Cron's "Run now" if you have a cron viewer plugin).

### Rotating the HMAC secret

If signed requests are ever enabled: rotate the secret in **Settings → LW Audit**, then immediately update the same value in the React app's build env. Roll-forward only — there is no grace window.

### Inspecting failures

`wp_lw_audits_errors` records every write failure with timestamp + reason. Sort by `created_at DESC` for the most recent issues.

---

## Known limitations (v0.5.1)

- **Inline mail + Kit dispatch.** The capture endpoint sends `wp_mail` and calls Kit synchronously. Kit timeout is 3s; total inline budget is ~5s worst case. Async dispatch (background queue) is a follow-up.
- **No HMAC enforcement.** The controller accepts unsigned POSTs from allow-listed origins. Add HMAC enforcement once the operator workflow is settled.
- **Crawler is single-process.** No queue, no horizontal scaling. Each scan ties up one PHP-FPM worker for up to 50s. With the 10/hour rate limit per IP this is fine; revisit if traffic grows.
- **Broken-link checker is bounded.** It follows internal pages only, checks at most 50 pages and 150 unique destinations, and does not execute JavaScript. Redirects and timeouts are reported separately. The response includes a warning when the result is partial.
- **WordPress detection uses public fingerprints.** A heavily customized WordPress site that removes common asset, generator, and REST signals may be asked to retry even though it runs WordPress.
- **Single `wp_mail` retry only.** If the inline send fails, one retry fires 30 min later via `wp_schedule_single_event`. After the second failure the row is marked `mail_dead` and not retried again. See Failure States for operator recovery steps.

---

## Uninstall

Deleting the plugin via Plugins → Delete drops both tables. **There is no undo.** Take a `mysqldump` of `wp_lw_audits` and `wp_lw_audits_errors` first if there is any chance you'll want the data back.

Deactivating (without deleting) preserves the data.

---

## Files in this plugin

```
lw-audit-store/
├── lw-audit-store.php         — bootstrap, version, requires
├── readme.txt                 — wp.org-style metadata
├── uninstall.php              — drops tables on delete
├── DEPLOY.md                  — this file
├── includes/
│   ├── class-installer.php    — schema + dbDelta
│   ├── class-settings.php     — admin Settings page
│   ├── class-kit-client.php   — Kit.com API wrapper (3s timeout)
│   ├── class-mailer.php       — wp_mail + template render
│   ├── class-crawler.php      — PHP internal-link crawler (was Netlify function)
│   ├── class-broken-link-crawler.php — bounded HTTP destination checker
│   ├── class-rest-controller.php — /scan + /broken-links + /emails routes, CORS, rate limit
│   ├── class-cron.php         — hourly Kit retry
│   └── class-admin-page.php   — read-only dashboard
└── templates/
    └── email-audit-results.html — audit email layout
```
