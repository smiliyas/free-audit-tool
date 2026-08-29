<?php
/**
 * Plugin Name:       LW Audit Store
 * Plugin URI:        https://linkwhisper.com/
 * Description:       Free Audit Tool backend — internal-link, broken-link, and sitemap endpoints + capture endpoint + email send + Kit.com sync + hourly retry cron + admin dashboard. System of record for the free-tool acquisition funnel.
 * Version:           0.5.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            LinkWhisper
 * Author URI:        https://linkwhisper.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lw-audit-store
 *
 * Phase 1 scope (v0.1.0): plugin shell + DB schema.
 * Phase 2 scope (v0.2.0): REST capture endpoint + HMAC + email send + Kit.com
 * sync + hourly retry cron + admin settings + admin dashboard.
 * Phase 3 scope (v0.3.0): /scan REST route + LW_Audit_Crawler. Brings the
 * audit crawler in-house (PHP) so the React frontend on linkwhisper.com
 * calls one origin only — capture and crawl both served by this plugin.
 * Retires the standalone Netlify deploy at audit.linkwhisper.com.
 * Phase 4 scope (v0.4.0): adds edges[] to /scan fullReport, tool column to
 * DB schema, tool-aware email subjects. Enables Internal Link Map tool.
 * Phase 5 scope (v0.5.0): adds the bounded HTTP broken-link checker route.
 * Phase 5.1 scope (v0.5.1): gates the broken-link checker to detected
 * WordPress sites before any page crawl.
 *
 * @package LW_Audit_Store
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin constants. Bump LW_AUDIT_DB_VERSION on any schema change so the
 * installer re-runs dbDelta on the next activation / version check.
 */
define( 'LW_AUDIT_VERSION', '0.5.1' );
// DB v4: adds tool column for multi-tool segmentation (link-auditor, link-map, etc.).
define( 'LW_AUDIT_DB_VERSION', '4' );
define( 'LW_AUDIT_DIR', plugin_dir_path( __FILE__ ) );
define( 'LW_AUDIT_URL', plugin_dir_url( __FILE__ ) );
define( 'LW_AUDIT_CRON_HOOK', 'lw_audit_kit_retry' );

// Manual require — no Composer in v0. Matt/Iliya don't need a build step
// to read or edit this plugin.
require_once LW_AUDIT_DIR . 'includes/class-installer.php';
require_once LW_AUDIT_DIR . 'includes/class-settings.php';
require_once LW_AUDIT_DIR . 'includes/class-kit-client.php';
require_once LW_AUDIT_DIR . 'includes/class-mailer.php';
require_once LW_AUDIT_DIR . 'includes/class-crawler.php';
require_once LW_AUDIT_DIR . 'includes/class-broken-link-crawler.php';
require_once LW_AUDIT_DIR . 'includes/class-sitemap.php'; // extends LW_Audit_Crawler — must load after it
require_once LW_AUDIT_DIR . 'includes/class-rest-controller.php';
require_once LW_AUDIT_DIR . 'includes/class-cron.php';
require_once LW_AUDIT_DIR . 'includes/class-admin-page.php';

register_activation_hook( __FILE__, array( 'LW_Audit_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'LW_Audit_Installer', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'LW_Audit_Installer', 'maybe_upgrade' ) );
add_action( 'rest_api_init', array( 'LW_Audit_REST_Controller', 'register_routes' ) );
add_action( 'admin_init', array( 'LW_Audit_Settings', 'register_settings' ) );
add_action( 'admin_menu', array( 'LW_Audit_Admin_Page', 'register_pages' ) );
add_action( LW_AUDIT_CRON_HOOK, array( 'LW_Audit_Cron', 'run_retries' ) );
add_action( 'lw_audit_mail_retry', array( 'LW_Audit_Cron', 'retry_mail' ) );
