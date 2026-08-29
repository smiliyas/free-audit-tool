<?php
/**
 * Installer: creates / upgrades the lw_audits and lw_audits_errors tables.
 *
 * @package LW_Audit_Store
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LW_Audit_Installer
 *
 * Handles schema lifecycle:
 *  - activate(): runs dbDelta to create or upgrade tables. Idempotent.
 *  - deactivate(): preserves data. Logs to error_log only.
 *  - uninstall: handled by uninstall.php (drops tables + options).
 */
class LW_Audit_Installer {

	const OPTION_DB_VERSION = 'lw_audit_db_version';

	/**
	 * Apply schema changes after a plugin file update.
	 *
	 * WordPress only calls the activation hook when the plugin is explicitly
	 * activated. Deploying a new plugin version over an already-active install
	 * does not call it, so compare the stored schema version during bootstrap.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::OPTION_DB_VERSION ) !== LW_AUDIT_DB_VERSION ) {
			self::activate();
		}
	}

	/**
	 * Activation hook entry point.
	 *
	 * Idempotent: safe to call on every activation / re-activation. dbDelta
	 * compares current schema to the CREATE TABLE statement and issues only
	 * the ALTERs needed. If the option's DB version matches the constant,
	 * dbDelta still runs cheaply — the worst case is a no-op describe.
	 */
	public static function activate() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$installed_version = get_option( self::OPTION_DB_VERSION );

		dbDelta( self::get_schema_sql() );

		if ( $installed_version !== LW_AUDIT_DB_VERSION ) {
			update_option( self::OPTION_DB_VERSION, LW_AUDIT_DB_VERSION );
		}

		if ( ! wp_next_scheduled( LW_AUDIT_CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', LW_AUDIT_CRON_HOOK );
		}
	}

	/**
	 * Deactivation hook entry point.
	 *
	 * Does NOT drop tables. The plugin can be deactivated and reactivated
	 * without data loss — see uninstall.php for the destructive path.
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( LW_AUDIT_CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, LW_AUDIT_CRON_HOOK );
		}

		// Audit trail for ops. Matt/Iliya can grep error_log to confirm
		// when a deactivation happened.
		error_log( '[lw-audit-store] Plugin deactivated. Tables preserved. Use uninstall (Delete) to drop data.' );
	}

	/**
	 * Schema for wp_lw_audits + wp_lw_audits_errors.
	 *
	 * dbDelta quirks honoured here (these break silently if violated):
	 *  - Use $wpdb->prefix; never hardcode "wp_".
	 *  - Each column on its own line.
	 *  - Two spaces between PRIMARY KEY and the column list — `PRIMARY KEY  (id)`.
	 *  - KEY (not INDEX) for non-primary indexes.
	 *  - No backticks around column names in dbDelta input.
	 *  - Lowercase column types.
	 *  - No trailing comma before the closing paren.
	 *
	 * Schema reconciliation vs PRD (see knowledge/specs/wp-plugin-v0-prd.md
	 * and wp-plugin-integration-plan.md): documented inline at each delta.
	 *
	 * @return string Concatenated CREATE TABLE statements for dbDelta.
	 */
	public static function get_schema_sql() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$audits_table    = $wpdb->prefix . 'lw_audits';
		$errors_table    = $wpdb->prefix . 'lw_audits_errors';

		// ----- wp_lw_audits ---------------------------------------------------
		// Reconciliation notes (PRD vs integration plan vs final):
		//   email         — VARCHAR(190), not 255. MySQL utf8mb4 index limit
		//                   is 767 bytes pre-5.7.7 (191 chars at 4 bytes/char).
		//                   190 stays safely under that. Eng-driven correction
		//                   accepted by PRD owner.
		//   email_status  — VARCHAR(20), not ENUM. dbDelta cannot diff ENUM
		//                   value additions; adding a status later would
		//                   require a manual ALTER. VARCHAR is forward-compat.
		//   broken_count  — INT UNSIGNED NULL kept. The internal-link crawler
		//                   leaves this nullable; the broken-link checker writes
		//                   its HTTP-error count without a schema change.
		//   created_at_gmt— Added DATETIME alongside created_at. Standard WP
		//                   idiom (wp_posts has post_date + post_date_gmt).
		//                   Nullable: Phase 2 REST handler writes the real UTC
		//                   value on insert. We avoid the legacy '0000-00-00'
		//                   sentinel because modern MySQL strict mode
		//                   (NO_ZERO_DATE) rejects it on CREATE TABLE.
		//   nonce         — Deferred to Phase 2. Capture-email nonce is only
		//                   needed when REST endpoints exist. Add then.
		$sql_audits = "CREATE TABLE {$audits_table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			created_at datetime NOT NULL default CURRENT_TIMESTAMP,
			created_at_gmt datetime default NULL,
			updated_at datetime NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
			url_audited text NOT NULL,
			url_hash char(64) NOT NULL default '',
			score tinyint(3) unsigned default NULL,
			pages_crawled int(10) unsigned default NULL,
			broken_count int(10) unsigned default NULL,
			orphan_count int(10) unsigned default NULL,
			internal_links int(10) unsigned default NULL,
			email varchar(190) default NULL,
			email_status varchar(20) NOT NULL default 'none',
			kit_status varchar(16) NOT NULL default 'pending',
			kit_attempts tinyint(3) unsigned NOT NULL default 0,
			kit_last_error text default NULL,
			kit_subscriber_id varchar(64) default NULL,
			utm_source varchar(64) default NULL,
			utm_medium varchar(64) default NULL,
			utm_campaign varchar(64) default NULL,
			utm_content varchar(64) default NULL,
			utm_term varchar(64) default NULL,
			referrer text default NULL,
			user_agent text default NULL,
			ip_hash char(64) default NULL,
			raw_results longtext default NULL,
			tool varchar(50) NOT NULL default 'link-auditor',
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY url_hash (url_hash),
			KEY email (email),
			KEY email_status (email_status),
			KEY kit_status (kit_status),
			KEY tool (tool)
		) {$charset_collate};";

		// ----- wp_lw_audits_errors -------------------------------------------
		// Separate log table so the main audits table stays clean. Used by
		// Phase 2 REST handlers when a write fails or HMAC verification
		// rejects a payload.
		$sql_errors = "CREATE TABLE {$errors_table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			created_at datetime NOT NULL default CURRENT_TIMESTAMP,
			endpoint varchar(64) NOT NULL default '',
			payload_hash char(64) NOT NULL default '',
			error_message text NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) {$charset_collate};";

		return $sql_audits . "\n" . $sql_errors;
	}
}
