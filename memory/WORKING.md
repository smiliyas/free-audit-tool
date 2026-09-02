# WORKING.md — Current Task State

*Update this file after completing work. Read it first after compaction.*

## Current Task
Fix the broken-link checker timing failure reported for `wpbeginner.com`, where the browser could sit at 88% and then receive a generic request failure.

## Status
Implemented and verified a global 55-second broken-link scan deadline with a 2-second outbound-request guard. The crawler now passes remaining time into WordPress detection, page batches, HEAD checks, and GET fallbacks so slow runs return a partial report before the staging web server's request boundary. The React fallback for HTTP 502/504 now explains that the check took too long.

## Blockers
Mission Control tooling is not available in this Codex session, so findings were recorded locally and reported in chat.

## Next Steps
1. Deploy the updated React bundle if the production theme is not rebuilt from `linkwhisper-react`.
2. Re-test a slower WordPress site after the staging rate-limit window resets.
3. Regenerate `dist/lw-audit-store-0.3.0.zip` before using the zip artifact.

## Notes
2026-09-02: Timed `wpbeginner.com` at 58.75s before the fix and 54.82s after it; the mounted staging browser returned a report with 130 links checked and no timeout error. PHP syntax, timeout-guard smoke, focused React tests, focused ESLint, and a production build passed.
2026-05-12: PHP lint passed for all plugin PHP files. No Composer/npm build is required for `wp-plugin/lw-audit-store`; `builds/internal-link-checker/package.json` belongs to the legacy Netlify implementation, not the WP plugin.
2026-05-21: Updated `class-crawler.php` to inspect up to 10 child sitemaps from a sitemap index instead of 2. `php -l wp-plugin/lw-audit-store/includes/class-crawler.php` passed.
2026-05-21: Bumped crawler fetch timeout from 8s to 15s and made sitemap candidate/sub-sitemap fetches use the same `FETCH_TIMEOUT`. PHP lint passed.
2026-05-21: Temporarily commented out scan rate-limit enforcement in `class-rest-controller.php` for live repeated scan testing. Added `// NOTE` reminder to re-enable before production. PHP lint passed.
2026-05-21: Fixed likely PictureCorrect sitemap issue: crawler now treats `www` and non-`www` variants as the same internal origin, accepts redirects between them, filters sitemap URLs with the relaxed match, and tries alternate `www`/non-`www` sitemap candidate origins. PHP lint passed.
2026-05-21: Expanded scan reach for testing: `MAX_PAGES` 75 -> 100, `MAX_TIME_S` 50 -> 55, `CONCURRENCY` 3 -> 5, sitemap fallback now samples up to `MAX_PAGES`, and REST `set_time_limit` 60 -> 65. PHP lint passed for crawler and REST controller.
