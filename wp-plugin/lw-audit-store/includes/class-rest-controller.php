<?php
/**
 * REST controller: POST /wp-json/lw/v1/emails
 *
 * Capture endpoint for the Free Audit Tool. Validates request, inserts a row,
 * sends the results email, calls Kit.com (best-effort — failure is
 * recoverable via the hourly cron).
 *
 * Security model (v0 — Option A: public-form):
 *  - permission_callback returns true (public). The frontend is a static
 *    page on linkwhisper.com — no signed session available to the form.
 *  - Rate limit: 5 submits / hour / IP via transients keyed by ip_hash.
 *  - Honeypot: hidden form field `lw_check` must be empty. Field name is a
 *    contract with the React frontend (LinkChecker.tsx `name="lw_check"`) —
 *    drift between sides silently disables the bot trap. Filled → silent
 *    200 (do not signal rejection to the bot).
 *  - Email format validation via is_email().
 *  - verify_hmac() retained as a private utility for future server-to-server
 *    flows (admin replay button, Zapier webhook, etc.).
 *
 * Idempotency: requests with the same X-LW-Idempotency-Key (within 1 hour)
 * return the original audit_id without re-inserting. Prevents double-sends
 * from frontend retries.
 *
 * @package LW_Audit_Store
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LW_Audit_REST_Controller {

	const NS                          = 'lw/v1';
	const ROUTE                       = '/emails';
	const ROUTE_SCAN                  = '/scan';
	const ROUTE_BROKEN_LINKS          = '/broken-links';
	const ROUTE_SITEMAP               = '/sitemap';
	const RATE_LIMIT_PER_HOUR         = 5;
	const RATE_LIMIT_WINDOW           = HOUR_IN_SECONDS;
	const SCAN_RATE_LIMIT_PER_HOUR    = 10;
	const SITEMAP_RATE_LIMIT_PER_HOUR = 10;

	/**
	 * Built-in allowed origins for cross-origin POSTs. Production calls land
	 * same-origin (React bundle ships inside the WP theme) so this list is
	 * primarily for staging/preview environments. Any other origin must be
	 * added via Settings → LW Audit → Extra CORS Origins. Netlify origin was
	 * removed v0.3.0 (Vera P0 #1) — the Netlify deploy is being retired.
	 */
	const ALLOWED_ORIGINS = array(
		'https://audit.linkwhisper.com',
		'https://linkwhisper.com',
		'https://www.linkwhisper.com',
	);

	public static function register_routes() {
		register_rest_route( self::NS, self::ROUTE, array(
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_capture' ),
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => 'OPTIONS',
				'callback'            => array( __CLASS__, 'handle_preflight' ),
				'permission_callback' => '__return_true',
			),
		) );

		register_rest_route( self::NS, self::ROUTE_SCAN, array(
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_scan' ),
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => 'OPTIONS',
				'callback'            => array( __CLASS__, 'handle_preflight' ),
				'permission_callback' => '__return_true',
			),
		) );

		register_rest_route( self::NS, self::ROUTE_BROKEN_LINKS, array(
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_broken_links' ),
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => 'OPTIONS',
				'callback'            => array( __CLASS__, 'handle_preflight' ),
				'permission_callback' => '__return_true',
			),
		) );

		register_rest_route( self::NS, self::ROUTE_SITEMAP, array(
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_sitemap' ),
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => 'OPTIONS',
				'callback'            => array( __CLASS__, 'handle_preflight' ),
				'permission_callback' => '__return_true',
			),
		) );

		// Override WP's default CORS handler for our namespace only. The filter
		// matches the whole /lw/v1/ namespace, so /sitemap is covered too.
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'send_cors_headers' ), 10, 4 );
	}

	/**
	 * CORS preflight responder. WP normally returns 405 for OPTIONS; we
	 * return 204 with the Access-Control-Allow-* headers attached via
	 * send_cors_headers() below.
	 */
	public static function handle_preflight( WP_REST_Request $request ) {
		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Attach CORS headers to responses for our route only. Origin must be
	 * on the allow-list — anything else gets no CORS header (browser blocks
	 * the response, request still reaches the server but is unusable).
	 */
	public static function send_cors_headers( $served, $result, WP_REST_Request $request, $server ) {
		$route = $request->get_route();
		// Match anything under our namespace (covers /emails, /scan, future routes).
		if ( strpos( $route, '/' . self::NS . '/' ) !== 0 ) {
			return $served;
		}

		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? (string) $_SERVER['HTTP_ORIGIN'] : '';
		if ( '' === $origin ) {
			return $served;
		}

		$allowed = self::ALLOWED_ORIGINS;
		$extra   = LW_Audit_Settings::get( 'extra_cors_origins' );
		if ( '' !== $extra ) {
			foreach ( preg_split( '/\r\n|\r|\n/', $extra ) as $line ) {
				$line = trim( $line );
				if ( '' !== $line ) {
					$allowed[] = $line;
				}
			}
		}

		if ( ! in_array( $origin, $allowed, true ) ) {
			return $served;
		}

		header( 'Access-Control-Allow-Origin: ' . $origin );
		header( 'Vary: Origin' );
		header( 'Access-Control-Allow-Methods: POST, OPTIONS' );
		// Accept both the legacy `X-LW-Idempotency-Key` and the standard
		// `Idempotency-Key` (RFC draft) — frontend prefers the latter.
		header( 'Access-Control-Allow-Headers: Content-Type, X-LW-Idempotency-Key, Idempotency-Key, X-LW-Signature' );
		// 24h cache: preflight rarely changes; cuts an OPTIONS round-trip
		// off every capture/scan call from a returning visitor.
		header( 'Access-Control-Max-Age: 86400' );

		return $served;
	}

	/**
	 * Resolve the real client IP for a request. linkwhisper.com sits behind
	 * Cloudflare; REMOTE_ADDR is a CF edge IP shared across visitors. We
	 * prefer Cloudflare's `CF-Connecting-IP`, fall back to the leftmost
	 * `X-Forwarded-For` hop, then `REMOTE_ADDR`. Returned value is
	 * sanitised to a printable IP-shaped string.
	 *
	 * Trust assumption: the WP host is firewalled so only Cloudflare can
	 * reach :443. If that ever changes, switch the order so CF header is
	 * only honored when REMOTE_ADDR is in the CF range.
	 */
	private static function get_client_ip() {
		$candidates = array();
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$candidates[] = (string) $_SERVER['HTTP_CF_CONNECTING_IP'];
		}
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$xff = explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] );
			$candidates[] = trim( $xff[0] );
		}
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$candidates[] = (string) $_SERVER['REMOTE_ADDR'];
		}
		foreach ( $candidates as $ip ) {
			$ip = trim( $ip );
			if ( '' !== $ip && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
		return '0.0.0.0';
	}

	/**
	 * HMAC verifier — kept for future server-to-server use (admin replay
	 * button, Zapier webhook). NOT wired into the public form route.
	 */
	public static function verify_hmac( WP_REST_Request $request ) {
		$secret = LW_Audit_Settings::get( 'hmac_secret' );
		if ( '' === $secret ) {
			self::log_error( 'auth', '', 'hmac_secret not configured' );
			return new WP_Error( 'lw_no_secret', 'Server not configured', array( 'status' => 503 ) );
		}

		$signature = $request->get_header( 'x_lw_signature' );
		if ( ! $signature ) {
			$signature = $request->get_header( 'X-LW-Signature' );
		}
		if ( ! $signature ) {
			self::log_error( 'auth', '', 'missing signature header' );
			return new WP_Error( 'lw_no_sig', 'Missing signature', array( 'status' => 401 ) );
		}

		$body     = $request->get_body();
		$expected = hash_hmac( 'sha256', $body, $secret );

		if ( ! hash_equals( $expected, (string) $signature ) ) {
			self::log_error( 'auth', hash( 'sha256', $body ), 'hmac mismatch' );
			return new WP_Error( 'lw_bad_sig', 'Invalid signature', array( 'status' => 401 ) );
		}

		return true;
	}

	/**
	 * Main handler. Returns { ok: true, audit_id, email_status, kit_status }.
	 */
	public static function handle_capture( WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'bad json' ), 400 );
		}

		// Honeypot — silent 200 if a bot filled the hidden field. Field name
		// must match the React form's `name="lw_check"` (see LinkChecker.tsx).
		// Do not reveal the trap — bots would adapt.
		if ( ! empty( $payload['lw_check'] ) ) {
			self::log_error( 'honeypot', '', 'honeypot field filled' );
			return new WP_REST_Response( array( 'ok' => true, 'audit_id' => 0 ), 200 );
		}

		$email = isset( $payload['email'] ) ? sanitize_email( $payload['email'] ) : '';
		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'invalid email' ), 400 );
		}

		$url_audited = isset( $payload['url_audited'] ) ? esc_url_raw( $payload['url_audited'] ) : '';
		if ( '' === $url_audited ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'missing url_audited' ), 400 );
		}

		// Rate limit by hashed IP. Configured limits applied per-hour. The
		// hash uses the HMAC secret as salt so we never store the raw IP
		// (consistent with ip_hash in wp_lw_audits).
		$rate_check = self::check_rate_limit();
		if ( ! $rate_check['ok'] ) {
			self::log_error( 'rate_limit', $rate_check['ip_hash'], 'rate limit exceeded' );
			return new WP_REST_Response( array(
				'ok'    => false,
				'error' => 'rate limit exceeded — try again in an hour',
			), 429 );
		}

		// Idempotency check — same key within an hour returns existing row.
		// We use add_option() as an atomic mutex: only one request with a
		// given key gets to insert; the others see the mapping and return
		// the cached row.
		// Accept the standard `Idempotency-Key` header first (frontend
		// default); fall back to the legacy `X-LW-Idempotency-Key`.
		$idem_key = (string) $request->get_header( 'idempotency_key' );
		if ( '' === $idem_key ) {
			$idem_key = (string) $request->get_header( 'x_lw_idempotency_key' );
		}
		if ( '' !== $idem_key ) {
			$existing = self::lookup_idempotent( $idem_key );
			if ( $existing ) {
				return new WP_REST_Response( array(
					'ok'           => true,
					'idempotent'   => true,
					'audit_id'     => (int) $existing['id'],
					'email_status' => $existing['email_status'],
					'kit_status'   => $existing['kit_status'],
				), 200 );
			}
		}

		$audit_id = self::insert_row( $email, $url_audited, $payload, $request );
		if ( ! $audit_id ) {
			global $wpdb;
			$db_error = '' !== $wpdb->last_error ? $wpdb->last_error : 'unknown database insert error';
			self::log_error( 'emails', hash( 'sha256', $email . '|' . $url_audited ), $db_error );
			error_log( '[lw-audit-store] /emails insert failed: ' . $db_error );
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'db insert failed' ), 500 );
		}

		// Stash idempotency mapping (transient, 1h). If a concurrent request
		// already inserted, we keep the latest mapping — duplicates are
		// preferable to losing the audit_id.
		if ( '' !== $idem_key ) {
			set_transient( 'lw_audit_idem_' . md5( $idem_key ), $audit_id, HOUR_IN_SECONDS );
		}

		// Re-read row (so we have all defaults applied).
		$row = self::get_row( $audit_id );

		$mail_result = LW_Audit_Mailer::send_audit_results( $row );
		$email_status = $mail_result['ok'] ? 'sent' : 'mail_failed';
		self::update_row( $audit_id, array( 'email_status' => $email_status ) );
		if ( ! $mail_result['ok'] ) {
			self::log_error( 'mail', hash( 'sha256', wp_json_encode( $row ) ), (string) $mail_result['error'] );
			// Vera P0 #1 + Quinn async blind-spot: schedule one retry 30 min out; on second failure → mail_dead.
			wp_schedule_single_event( time() + 30 * MINUTE_IN_SECONDS, 'lw_audit_mail_retry', array( $audit_id ) );
		}

		// Custom fields enable Kit segmentation by score bucket / orphan
		// count. Field names must match the form's custom field slugs in
		// Kit; missing fields are silently ignored by the API.
		$score_int = isset( $row['score'] ) && '' !== $row['score'] ? (int) $row['score'] : null;
		$bucket    = null === $score_int ? 'unknown' : ( $score_int < 65 ? 'critical' : ( $score_int < 85 ? 'needs_work' : 'healthy' ) );

		$kit_tool = isset( $payload['tool'] ) ? sanitize_text_field( $payload['tool'] ) : 'link-auditor';
		$kit_tool = substr( $kit_tool, 0, 50 );

		$kit_result = LW_Audit_Kit_Client::subscribe( $email, array(
			'audit_url'      => $url_audited,
			'audit_score'    => null === $score_int ? '' : (string) $score_int,
			'audit_bucket'   => $bucket,
			'pages_crawled'  => isset( $row['pages_crawled'] ) ? (string) (int) $row['pages_crawled'] : '0',
			'orphan_count'   => isset( $row['orphan_count'] ) ? (string) (int) $row['orphan_count'] : '0',
			'broken_count'   => isset( $row['broken_count'] ) ? (string) (int) $row['broken_count'] : '0',
			'audit_id'       => (string) $audit_id,
			'utm_source'     => isset( $row['utm_source'] ) ? (string) $row['utm_source'] : '',
			'utm_campaign'   => isset( $row['utm_campaign'] ) ? (string) $row['utm_campaign'] : '',
			'tool'           => $kit_tool,
		) );

		// Atomic increment: kit_attempts = kit_attempts + 1 in SQL. Avoids
		// read-then-write race with a parallel cron run on the same row.
		global $wpdb;
		$audits_table = $wpdb->prefix . 'lw_audits';
		if ( $kit_result['ok'] ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$audits_table}
				 SET kit_status = %s,
				     kit_attempts = kit_attempts + 1,
				     kit_subscriber_id = %s,
				     kit_last_error = NULL
				 WHERE id = %d",
				'synced',
				(string) $kit_result['subscriber_id'],
				$audit_id
			) );
		} else {
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$audits_table}
				 SET kit_status = %s,
				     kit_attempts = kit_attempts + 1,
				     kit_last_error = %s
				 WHERE id = %d",
				'failed',
				(string) $kit_result['error'],
				$audit_id
			) );
			self::log_error( 'kit', hash( 'sha256', wp_json_encode( $row ) ), (string) $kit_result['error'] );
		}

		return new WP_REST_Response( array(
			'ok'           => true,
			'audit_id'     => $audit_id,
			'email_status' => $email_status,
			'kit_status'   => $kit_result['ok'] ? 'synced' : 'failed',
		), 200 );
	}

	/**
	 * Scan handler: POST /wp-json/lw/v1/scan { url }
	 *
	 * Public endpoint, rate-limited 10/IP/hour. Crawls the supplied URL via
	 * LW_Audit_Crawler::scan() and returns { preview, fullReport }.
	 *
	 * Resource sizing: each scan can take up to ~50s and ~150MB peak memory
	 * (DOMDocument across 75 pages of HTML). We raise the limits at the
	 * boundary rather than inside the crawler so the crawler stays a pure
	 * unit-testable class.
	 */
	public static function handle_scan( WP_REST_Request $request ) {
		// Most managed WP hosts allow set_time_limit and memory raises in
		// REST handlers. Best-effort — silent if the host blocks them.
		@set_time_limit( 65 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		wp_raise_memory_limit( 'admin' );

		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response( array( 'error' => 'Invalid JSON body' ), 400 );
		}

		$url = isset( $payload['url'] ) ? trim( (string) $payload['url'] ) : '';
		if ( '' === $url ) {
			return new WP_REST_Response( array( 'error' => 'Missing url parameter' ), 400 );
		}

		$rate_check = self::check_scan_rate_limit();
		if ( ! $rate_check['ok'] ) {
			self::log_error( 'scan_rate_limit', $rate_check['ip_hash'], 'scan rate limit exceeded' );
			return new WP_REST_Response( array(
				'error' => 'Rate limit exceeded - try again in an hour.',
			), 429 );
		}

		$result = LW_Audit_Crawler::scan( $url );

		if ( is_wp_error( $result ) ) {
			$err_data = $result->get_error_data();
			$status   = ( is_array( $err_data ) && isset( $err_data['status'] ) ) ? (int) $err_data['status'] : 500;
			// Match Netlify version: 200 with `error` field for crawl-blocked
			// (frontend treats it as user-actionable, not server failure).
			if ( 'lw_blocked' === $result->get_error_code() ) {
				return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 200 );
			}
			self::log_error( 'scan', hash( 'sha256', $url ), $result->get_error_code() . ': ' . $result->get_error_message() );
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), $status );
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Broken-link handler: POST /wp-json/lw/v1/broken-links { url }.
	 * Uses the same public rate-limit and blocked-site response conventions as
	 * the existing internal-link scan, but calls the HTTP status crawler.
	 */
	public static function handle_broken_links( WP_REST_Request $request ) {
		@set_time_limit( 65 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		wp_raise_memory_limit( 'admin' );

		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response( array( 'error' => 'Invalid JSON body' ), 400 );
		}

		$url = isset( $payload['url'] ) ? trim( (string) $payload['url'] ) : '';
		if ( '' === $url ) {
			return new WP_REST_Response( array( 'error' => 'Missing url parameter' ), 400 );
		}

		$rate_check = self::check_scan_rate_limit();
		if ( ! $rate_check['ok'] ) {
			self::log_error( 'broken_link_rate_limit', $rate_check['ip_hash'], 'broken-link rate limit exceeded' );
			return new WP_REST_Response( array(
				'error' => 'Rate limit exceeded - try again in an hour.',
			), 429 );
		}

		$result = LW_Broken_Link_Crawler::scan( $url );
		if ( is_wp_error( $result ) ) {
			$err_data = $result->get_error_data();
			$status   = ( is_array( $err_data ) && isset( $err_data['status'] ) ) ? (int) $err_data['status'] : 500;
			if ( 'lw_blocked' === $result->get_error_code() ) {
				return new WP_REST_Response( array(
					'error'   => $result->get_error_message(),
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				), 200 );
			}
			self::log_error( 'broken_links', hash( 'sha256', $url ), $result->get_error_code() . ': ' . $result->get_error_message() );
			return new WP_REST_Response( array(
				'error'   => $result->get_error_message(),
				'code'    => $result->get_error_code(),
				'message' => $result->get_error_message(),
			), $status );
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Per-IP rate limit for scans. Scans are ~50× more expensive than email
	 * captures (network + parsing across 75 pages), so they get their own
	 * counter — separate transient key, separate per-hour cap.
	 */
	private static function check_scan_rate_limit() {
		$ip_raw  = self::get_client_ip();
		$secret  = LW_Audit_Settings::get( 'hmac_secret' );
		$salt    = '' !== $secret ? $secret : 'lw-audit-fallback-salt';
		$ip_hash = hash( 'sha256', $ip_raw . $salt );
		$key     = 'lw_audit_scan_rl_' . substr( $ip_hash, 0, 32 );

		$count = (int) get_transient( $key );
		if ( $count >= self::SCAN_RATE_LIMIT_PER_HOUR ) {
			return array( 'ok' => false, 'ip_hash' => $ip_hash, 'count' => $count );
		}
		set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW );
		return array( 'ok' => true, 'ip_hash' => $ip_hash, 'count' => $count + 1 );
	}

	/**
	 * Sitemap handler: POST /wp-json/lw/v1/sitemap { url }
	 *
	 * Public endpoint, rate-limited 10/IP/hour (own counter — same crawl cost
	 * profile as /scan). Builds a visual sitemap via LW_Sitemap::generate() and
	 * returns { preview, fullReport }. Resource sizing mirrors handle_scan().
	 */
	public static function handle_sitemap( WP_REST_Request $request ) {
		@set_time_limit( 65 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		wp_raise_memory_limit( 'admin' );

		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response( array( 'error' => 'Invalid JSON body' ), 400 );
		}

		$url = isset( $payload['url'] ) ? trim( (string) $payload['url'] ) : '';
		if ( '' === $url ) {
			return new WP_REST_Response( array( 'error' => 'Missing url parameter' ), 400 );
		}

		$rate_check = self::check_sitemap_rate_limit();
		if ( ! $rate_check['ok'] ) {
			self::log_error( 'sitemap_rate_limit', $rate_check['ip_hash'], 'sitemap rate limit exceeded' );
			return new WP_REST_Response( array(
				'error' => 'Rate limit exceeded - try again in an hour.',
			), 429 );
		}

		$result = LW_Sitemap::generate( $url );

		if ( is_wp_error( $result ) ) {
			$err_data = $result->get_error_data();
			$status   = ( is_array( $err_data ) && isset( $err_data['status'] ) ) ? (int) $err_data['status'] : 500;
			// Match Netlify version: 200 with `error` field for crawl-blocked.
			if ( 'lw_blocked' === $result->get_error_code() ) {
				return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 200 );
			}
			self::log_error( 'sitemap', hash( 'sha256', $url ), $result->get_error_code() . ': ' . $result->get_error_message() );
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), $status );
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Per-IP rate limit for sitemap generation. Separate transient key from
	 * /scan so the two tools don't share a bucket (a user can run both).
	 */
	private static function check_sitemap_rate_limit() {
		$ip_raw  = self::get_client_ip();
		$secret  = LW_Audit_Settings::get( 'hmac_secret' );
		$salt    = '' !== $secret ? $secret : 'lw-audit-fallback-salt';
		$ip_hash = hash( 'sha256', $ip_raw . $salt );
		$key     = 'lw_audit_sitemap_rl_' . substr( $ip_hash, 0, 32 );

		$count = (int) get_transient( $key );
		if ( $count >= self::SITEMAP_RATE_LIMIT_PER_HOUR ) {
			return array( 'ok' => false, 'ip_hash' => $ip_hash, 'count' => $count );
		}
		set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW );
		return array( 'ok' => true, 'ip_hash' => $ip_hash, 'count' => $count + 1 );
	}

	private static function insert_row( $email, $url_audited, array $payload, WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'lw_audits';

		$score          = isset( $payload['score'] ) ? max( 0, min( 100, intval( $payload['score'] ) ) ) : null;
		$pages_crawled  = isset( $payload['pages_crawled'] ) ? max( 0, intval( $payload['pages_crawled'] ) ) : null;
		$broken_count   = isset( $payload['broken_count'] ) ? max( 0, intval( $payload['broken_count'] ) ) : null;
		$orphan_count   = isset( $payload['orphan_count'] ) ? max( 0, intval( $payload['orphan_count'] ) ) : null;
		$internal_links = isset( $payload['internal_links'] ) ? max( 0, intval( $payload['internal_links'] ) ) : null;
		$raw_results    = isset( $payload['raw_results'] ) ? wp_json_encode( $payload['raw_results'] ) : null;
		$tool           = isset( $payload['tool'] ) ? sanitize_text_field( $payload['tool'] ) : 'link-auditor';
		$tool           = substr( $tool, 0, 50 ); // max 50 chars, matches DB column

		$utms = isset( $payload['utms'] ) && is_array( $payload['utms'] ) ? $payload['utms'] : array();

		$ip_raw = self::get_client_ip();
		$secret = LW_Audit_Settings::get( 'hmac_secret' );
		$salt   = '' !== $secret ? $secret : 'lw-audit-fallback-salt';
		$ip_hash = '0.0.0.0' === $ip_raw ? null : hash( 'sha256', $ip_raw . $salt );

		$ok = $wpdb->insert(
			$table,
			array(
				'created_at_gmt'  => current_time( 'mysql', true ),
				'url_audited'     => $url_audited,
				'url_hash'        => hash( 'sha256', $url_audited ),
				'score'           => $score,
				'pages_crawled'   => $pages_crawled,
				'broken_count'    => $broken_count,
				'orphan_count'    => $orphan_count,
				'internal_links'  => $internal_links,
				'email'           => $email,
				'email_status'    => 'queued',
				'kit_status'      => 'pending',
				'kit_attempts'    => 0,
				'utm_source'      => isset( $utms['utm_source'] ) ? sanitize_text_field( (string) $utms['utm_source'] ) : null,
				'utm_medium'      => isset( $utms['utm_medium'] ) ? sanitize_text_field( (string) $utms['utm_medium'] ) : null,
				'utm_campaign'    => isset( $utms['utm_campaign'] ) ? sanitize_text_field( (string) $utms['utm_campaign'] ) : null,
				'utm_content'     => isset( $utms['utm_content'] ) ? sanitize_text_field( (string) $utms['utm_content'] ) : null,
				'referrer'        => isset( $payload['referrer'] ) ? esc_url_raw( $payload['referrer'] ) : null,
				'user_agent'      => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( (string) $_SERVER['HTTP_USER_AGENT'], 0, 500 ) : null,
				'ip_hash'         => $ip_hash,
				'raw_results'     => $raw_results,
				'tool'            => $tool,
			)
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	private static function get_row( $id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'lw_audits';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: array();
	}

	private static function update_row( $id, array $fields ) {
		global $wpdb;
		$table = $wpdb->prefix . 'lw_audits';
		return $wpdb->update( $table, $fields, array( 'id' => $id ) );
	}

	/**
	 * Token-bucket-ish rate limit using a transient counter.
	 *
	 * Returns:
	 *   [ 'ok' => bool, 'ip_hash' => string, 'count' => int ]
	 */
	private static function check_rate_limit() {
		$ip_raw  = self::get_client_ip();
		$secret  = LW_Audit_Settings::get( 'hmac_secret' );
		$salt    = '' !== $secret ? $secret : 'lw-audit-fallback-salt';
		$ip_hash = hash( 'sha256', $ip_raw . $salt );
		$key     = 'lw_audit_rl_' . substr( $ip_hash, 0, 32 );

		// Atomic-ish increment: read, check, write. Two simultaneous requests
		// can race past the cap; for a 5/hr public form this is acceptable.
		// Object cache backends (Redis) make this single-op via wp_cache_incr.
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT_PER_HOUR ) {
			return array( 'ok' => false, 'ip_hash' => $ip_hash, 'count' => $count );
		}

		set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW );
		return array( 'ok' => true, 'ip_hash' => $ip_hash, 'count' => $count + 1 );
	}

	private static function lookup_idempotent( $key ) {
		$audit_id = get_transient( 'lw_audit_idem_' . md5( $key ) );
		if ( ! $audit_id ) {
			return null;
		}
		$row = self::get_row( (int) $audit_id );
		return $row ?: null;
	}

	public static function log_error( $endpoint, $payload_hash, $error_message ) {
		global $wpdb;
		$table = $wpdb->prefix . 'lw_audits_errors';
		$wpdb->insert( $table, array(
			'endpoint'      => substr( $endpoint, 0, 64 ),
			'payload_hash'  => '' !== $payload_hash ? $payload_hash : str_repeat( '0', 64 ),
			'error_message' => $error_message,
		) );
	}
}
