=== lw-audit-store ===
Contributors: linkwhisper
Tags: linkwhisper, audit, internal-use
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.5.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

System of record for Free Audit Tool submissions on linkwhisper.com. Internal-use plugin — not intended for wp.org.

== Description ==

Captures every Free Audit Tool submission (URL audited, scan stats, email, UTM) into a dedicated MySQL table on linkwhisper.com. Kit.com remains the email-delivery layer only — this plugin is the source of truth.

The current plugin provides:

* `wp_lw_audits` — one row per audit submission
* `wp_lw_audits_errors` — write-failure log
* `POST /lw/v1/scan` — internal-link health crawl
* `POST /lw/v1/broken-links` — bounded HTTP broken-link crawl
* `POST /lw/v1/emails` — email capture, audit email, and Kit.com sync

The plugin is for LinkWhisper internal use and is not intended for wp.org.

== Installation ==

1. Upload the `lw-audit-store/` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu in WordPress.
3. Confirm the tables exist: `SHOW TABLES LIKE '%lw_audits%';` should return two rows.

== Data lifecycle ==

* Deactivate — tables preserved, plugin can be reactivated without data loss.
* Delete (uninstall) — drops `wp_lw_audits` + `wp_lw_audits_errors` and removes the schema-version option. No undo. Take a mysqldump first if uncertain.

== Changelog ==

= 0.5.0 =
* Phase 5: add the bounded HTTP broken-link checker (`POST /lw/v1/broken-links`). It follows internal pages, validates unique HTTP(S) destinations, and reports broken links, redirects, timeouts, source pages, and partial-scan warnings.

= 0.3.0 =
* Phase 3: in-house PHP crawler (`POST /lw/v1/scan`) replaces the standalone Netlify deploy. React frontend on linkwhisper.com now calls this plugin for both crawl and capture — single origin, no CORS surface beyond what we control.
* Mailer: score buckets aligned with the frontend (Critical < 65, Needs work 65–84, Healthy 85+). Subject line now bucket-aware.
* Mailer: CAN-SPAM physical mailing address rendered in footer (configured via Settings → LW Audit).
* Settings: HMAC secret + Kit API key fields now `type=password` + `autocomplete=new-password`. New field: Physical Mailing Address.
* Kit client: timeout dropped to 3s for the inline subscribe call (cron handles retries). Subscribe payload now includes `audit_score`, `audit_bucket`, `pages_crawled`, `orphan_count`, `broken_count`, `audit_id`, `utm_source`, `utm_campaign` for segmentation.
* CORS: `Access-Control-Max-Age` extended to 86400 (24h preflight cache). `Idempotency-Key` (RFC standard) accepted alongside legacy `X-LW-Idempotency-Key`.
* Schema: bumped to v3 (adds `utm_term` + `KEY email_status`). dbDelta applies on activation; existing rows preserved.

= 0.2.0 =
* Phase 2: `POST /lw/v1/emails` capture endpoint, HMAC validation, mailer with HTML template, Kit.com client + hourly retry cron, admin settings page, admin dashboard.

= 0.1.0 =
* Phase 1 scaffold: plugin shell + DB schema (wp_lw_audits + wp_lw_audits_errors) via dbDelta. Activation, deactivation, uninstall hooks. No REST endpoints, no admin page, no Kit.com integration yet.
