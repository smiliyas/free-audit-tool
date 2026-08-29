<?php
/**
 * LW_Broken_Link_Crawler — bounded HTTP link checker for the free tool.
 *
 * The existing LW_Audit_Crawler builds an internal-link graph. This class
 * deliberately has a separate flow because a broken-link report needs to
 * check the HTTP response for every discovered HTTP(S) destination, including
 * external destinations, without crawling external pages.
 *
 * @package LW_Audit_Store
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LW_Broken_Link_Crawler extends LW_Audit_Crawler {

	const MAX_PAGES              = 50;
	const MAX_DEPTH              = 4;
	const MAX_LINKS              = 150;
	const MAX_SOURCES_PER_LINK   = 5;
	const CRAWL_TIME_S           = 30;
	const CHECK_TIME_S           = 25;
	const FETCH_TIMEOUT          = 10;
	const LINK_TIMEOUT           = 8;
	const WORDPRESS_DETECT_TIMEOUT = 8;
	const WORDPRESS_DETECT_BODY_SIZE = 131072;
	const CONCURRENCY            = 5;
	const USER_AGENT             = 'Mozilla/5.0 (compatible; LinkWhisperBrokenLinkBot/1.0; +https://linkwhisper.com/bot)';
	const WORDPRESS_ONLY_MESSAGE = 'This free broken link checker currently supports WordPress sites only. We could not detect WordPress on this site. Please enter a WordPress site URL.';
	const WORDPRESS_DETECTION_ERROR_MESSAGE = 'This free broken link checker currently supports WordPress sites only. We could not confirm WordPress on this site. Please check the URL and try again.';

	/**
	 * Crawl internal HTML pages, collect HTTP(S) destinations, then check them.
	 *
	 * @param string $raw_url Site URL supplied by the public form.
	 * @return array|WP_Error { preview, fullReport } on success.
	 */
	public static function scan( $raw_url ) {
		$site = self::site_context( $raw_url );
		if ( is_wp_error( $site ) ) {
			return $site;
		}

		$wordpress = self::detect_wordpress_site( $site );
		if ( is_wp_error( $wordpress ) ) {
			return $wordpress;
		}

		$crawl_started = microtime( true );
		$queue         = array( array( 'url' => $site['start_url'], 'depth' => 0 ) );
		$queued        = array( $site['start_url'] => true );
		$visited       = array();
		$pages         = array();
		$links         = array();
		$hit_link_cap  = false;
		$hit_page_cap  = false;
		$hit_time_cap  = false;

		while ( ! empty( $queue ) ) {
			if ( self::time_exceeded( $crawl_started, self::CRAWL_TIME_S ) ) {
				$hit_time_cap = true;
				break;
			}
			if ( count( $visited ) >= self::MAX_PAGES ) {
				$hit_page_cap = ! empty( $queue );
				break;
			}

			$batch = array();
			while ( ! empty( $queue ) && count( $batch ) < self::CONCURRENCY && count( $visited ) < self::MAX_PAGES ) {
				$item = array_shift( $queue );
				if ( isset( $visited[ $item['url'] ] ) ) {
					continue;
				}
				$visited[ $item['url'] ] = true;
				$batch[] = $item;
			}

			if ( empty( $batch ) ) {
				continue;
			}

			$responses = self::fetch_pages( array_column( $batch, 'url' ) );
			foreach ( $batch as $item ) {
				$page_url = $item['url'];
				if ( empty( $responses[ $page_url ] ) ) {
					continue;
				}

				$pages[] = array(
					'url'   => $page_url,
					'depth' => (int) $item['depth'],
				);

				$page_links = self::parse_links( $responses[ $page_url ]['html'], $page_url );
				foreach ( $page_links as $link ) {
					self::record_link( $links, $hit_link_cap, $link['url'], $page_url, $link['anchor'] );

					if ( ! self::is_internal_url( $link['url'], $site['origin'] ) ) {
						continue;
					}
					if ( (int) $item['depth'] >= self::MAX_DEPTH ) {
						continue;
					}
					if ( ! isset( $visited[ $link['url'] ] ) && ! isset( $queued[ $link['url'] ] ) ) {
						$queued[ $link['url'] ] = true;
						$queue[] = array(
							'url'   => $link['url'],
							'depth' => (int) $item['depth'] + 1,
						);
					}
				}
			}
		}

		if ( empty( $pages ) ) {
			return new WP_Error(
				'lw_blocked',
				'Could not crawl this site. It may be blocking crawlers or require JavaScript to render.',
				array( 'status' => 200 )
			);
		}

		$check_result = self::check_links( $links );
		$hit_time_cap  = $hit_time_cap || $check_result['hitTimeCap'];
		$statuses      = $check_result['statuses'];

		return self::build_report(
			$pages,
			$links,
			$statuses,
			$hit_link_cap,
			$hit_page_cap,
			$hit_time_cap
		);
	}

	/**
	 * Normalize and validate the submitted site before any outbound request.
	 *
	 * @param string $raw_url User input.
	 * @return array|WP_Error { origin, start_url }.
	 */
	private static function site_context( $raw_url ) {
		$raw_url = is_string( $raw_url ) ? trim( $raw_url ) : '';
		if ( '' === $raw_url ) {
			return new WP_Error( 'lw_no_url', 'Missing url parameter', array( 'status' => 400 ) );
		}
		if ( ! preg_match( '#^https?://#i', $raw_url ) ) {
			$raw_url = 'https://' . $raw_url;
		}

		$parts = wp_parse_url( $raw_url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new WP_Error( 'lw_bad_url', 'Invalid URL', array( 'status' => 400 ) );
		}
		if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'lw_bad_scheme', 'URL must be http or https', array( 'status' => 400 ) );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new WP_Error( 'lw_bad_url', 'URL credentials are not allowed', array( 'status' => 400 ) );
		}

		$origin = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] );
		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . intval( $parts['port'] );
		}
		$start_url = $origin . '/';

		if ( ! self::is_safe_url( $start_url ) ) {
			return new WP_Error( 'lw_unsafe_url', 'This URL cannot be checked.', array( 'status' => 400 ) );
		}

		return array(
			'origin'    => $origin,
			'start_url' => $start_url,
			'rest_url'  => $origin . '/wp-json/',
		);
	}

	/**
	 * Admit only sites with a WordPress fingerprint before crawling pages.
	 *
	 * The homepage is the first signal. The REST root is a bounded fallback for
	 * themes that remove generator tags and WordPress asset paths from markup.
	 *
	 * @param array $site Normalized site context.
	 * @return true|WP_Error
	 */
	private static function detect_wordpress_site( array $site ) {
		$page_response = wp_remote_get(
			$site['start_url'],
			array(
				'timeout'             => self::WORDPRESS_DETECT_TIMEOUT,
				'redirection'         => 5,
				'limit_response_size' => self::WORDPRESS_DETECT_BODY_SIZE,
				'user-agent'          => self::USER_AGENT,
				'headers'             => array(
					'Accept' => 'text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
				),
			)
		);

		if ( ! is_wp_error( $page_response ) ) {
			$page_code = (int) wp_remote_retrieve_response_code( $page_response );
			if ( $page_code >= 200 && $page_code < 400 && self::has_wordpress_fingerprint(
				(string) wp_remote_retrieve_body( $page_response ),
				array(
					'link' => (string) wp_remote_retrieve_header( $page_response, 'link' ),
				)
			) ) {
				return true;
			}
		}

		$rest_response = wp_remote_get(
			$site['rest_url'],
			array(
				'timeout'             => self::WORDPRESS_DETECT_TIMEOUT,
				'redirection'         => 2,
				'limit_response_size' => 65536,
				'user-agent'          => self::USER_AGENT,
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);

		if ( ! is_wp_error( $rest_response ) && self::is_wordpress_rest_response( $rest_response ) ) {
			return true;
		}

		if ( is_wp_error( $page_response ) || is_wp_error( $rest_response ) ) {
			return new WP_Error(
				'lw_wordpress_detection_failed',
				self::WORDPRESS_DETECTION_ERROR_MESSAGE,
				array( 'status' => 422 )
			);
		}

		return new WP_Error(
			'lw_not_wordpress',
			self::WORDPRESS_ONLY_MESSAGE,
			array( 'status' => 422 )
		);
	}

	/**
	 * Detect common WordPress signals in a public HTML response.
	 *
	 * @param string $html    Response body.
	 * @param array  $headers Selected response headers.
	 * @return bool
	 */
	private static function has_wordpress_fingerprint( $html, array $headers ) {
		$html = (string) $html;
		$link = isset( $headers['link'] ) ? strtolower( (string) $headers['link'] ) : '';

		if ( false !== strpos( $link, 'api.w.org' ) || false !== stripos( $html, 'api.w.org' ) ) {
			return true;
		}

		$generator_pattern = '#<meta\\b[^>]*(?:name\\s*=\\s*["\\\']generator["\\\'][^>]*content\\s*=\\s*["\\\'][^"\\\']*wordpress|content\\s*=\\s*["\\\'][^"\\\']*wordpress[^"\\\']*["\\\'][^>]*name\\s*=\\s*["\\\']generator["\\\'])#i';
		if ( preg_match( $generator_pattern, $html ) ) {
			return true;
		}

		$asset_pattern = '#<(?:script|link|img|style)\\b[^>]*(?:src|href)\\s*=\\s*["\\\'][^"\\\']*(?:/wp-content/|/wp-includes/)#i';
		return (bool) preg_match( $asset_pattern, $html );
	}

	/**
	 * Confirm the WordPress REST root exposes the standard wp/v2 namespace.
	 *
	 * @param mixed $response WordPress HTTP response.
	 * @return bool
	 */
	private static function is_wordpress_rest_response( $response ) {
		if ( ! is_array( $response ) && ! is_object( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return false;
		}

		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return false;
		}

		if ( ! empty( $data['namespaces'] ) && is_array( $data['namespaces'] ) ) {
			foreach ( $data['namespaces'] as $namespace ) {
				if ( 'wp/v2' === strtolower( (string) $namespace ) ) {
					return true;
				}
			}
		}

		if ( ! empty( $data['routes'] ) && is_array( $data['routes'] ) ) {
			foreach ( array_keys( $data['routes'] ) as $route ) {
				if ( false !== strpos( strtolower( (string) $route ), '/wp/v2/' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Use WordPress's URL safety validator to avoid making the public endpoint
	 * a server-side request forgery primitive. The fallback covers old/mocked
	 * WordPress runtimes used by local syntax checks.
	 */
	private static function is_safe_url( $url ) {
		if ( function_exists( 'wp_http_validate_url' ) ) {
			return (bool) wp_http_validate_url( $url );
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		$host = $parts['host'];
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return (bool) filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		return true;
	}

	private static function time_exceeded( $started, $budget ) {
		return ( microtime( true ) - $started ) >= $budget;
	}

	/**
	 * Fetch a small batch of HTML pages through Requests, with a sequential
	 * WordPress HTTP fallback for older installations.
	 */
	private static function fetch_pages( array $urls ) {
		if ( empty( $urls ) ) {
			return array();
		}

		$requests = array();
		foreach ( $urls as $url ) {
			$requests[ $url ] = array(
				'url'     => $url,
				'type'    => 'GET',
				'headers' => array(
					'User-Agent' => self::USER_AGENT,
					'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				),
			);
		}

		$requests_class = parent::requests_class();
		if ( ! $requests_class ) {
			return self::fetch_pages_sequential( $urls );
		}

		try {
			$responses = call_user_func(
				array( $requests_class, 'request_multiple' ),
				$requests,
				array(
					'timeout'          => self::FETCH_TIMEOUT,
					'connect_timeout'  => 5,
					'follow_redirects' => true,
					'redirects'        => 5,
					'verify'           => true,
				)
			);
		} catch ( \Exception $e ) {
			return self::fetch_pages_sequential( $urls );
		}

		$out = array();
		foreach ( $urls as $url ) {
			$response = isset( $responses[ $url ] ) ? $responses[ $url ] : null;
			$page     = self::page_response( $response );
			if ( null !== $page ) {
				$out[ $url ] = $page;
			}
		}
		return $out;
	}

	protected static function fetch_pages_sequential( array $urls ) {
		$args = array(
			'timeout'     => self::FETCH_TIMEOUT,
			'redirection' => 5,
			'user-agent'  => self::USER_AGENT,
			'headers'     => array(
				'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
			),
		);
		$out = array();
		foreach ( $urls as $url ) {
			$response = wp_remote_get( $url, $args );
			if ( is_wp_error( $response ) ) {
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 400 ) {
				continue;
			}
			$content_type = (string) wp_remote_retrieve_header( $response, 'content-type' );
			if ( '' !== $content_type && stripos( $content_type, 'html' ) === false ) {
				continue;
			}
			$out[ $url ] = array(
				'html' => (string) wp_remote_retrieve_body( $response ),
			);
		}
		return $out;
	}

	private static function page_response( $response ) {
		if ( $response instanceof \Exception || ! is_object( $response ) || ! isset( $response->status_code ) ) {
			return null;
		}
		$code = (int) $response->status_code;
		if ( $code < 200 || $code >= 400 ) {
			return null;
		}

		$content_type = '';
		if ( isset( $response->headers ) && method_exists( $response->headers, 'offsetGet' ) ) {
			$content_type = (string) $response->headers['content-type'];
		}
		if ( '' !== $content_type && stripos( $content_type, 'html' ) === false ) {
			return null;
		}

		return array(
			'html' => isset( $response->body ) ? (string) $response->body : '',
		);
	}

	/**
	 * Extract normalized anchor destinations and human-readable anchor text.
	 */
	private static function parse_links( $html, $page_url ) {
		$dom  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );

		$links = array();
		foreach ( $dom->getElementsByTagName( 'a' ) as $anchor_node ) {
			$href = trim( (string) $anchor_node->getAttribute( 'href' ) );
			if ( '' === $href ) {
				continue;
			}

			$url = parent::normalise( $href, $page_url );
			if ( ! $url || ! self::is_safe_url( $url ) ) {
				continue;
			}

			$anchor = trim( preg_replace( '/\s+/u', ' ', (string) $anchor_node->textContent ) );
			$links[] = array(
				'url'    => $url,
				'anchor' => substr( $anchor, 0, 160 ),
			);
		}

		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return $links;
	}

	private static function is_internal_url( $url, $origin ) {
		$link_origin = parent::origin_of( $url );
		return $link_origin && parent::origin_matches( $link_origin, $origin );
	}

	private static function record_link( array &$links, &$hit_link_cap, $url, $source_page, $anchor ) {
		if ( ! isset( $links[ $url ] ) ) {
			if ( count( $links ) >= self::MAX_LINKS ) {
				$hit_link_cap = true;
				return;
			}
			$links[ $url ] = array(
				'url'         => $url,
				'occurrences' => 0,
				'sources'     => array(),
			);
		}

		$links[ $url ]['occurrences']++;
		if ( count( $links[ $url ]['sources'] ) < self::MAX_SOURCES_PER_LINK ) {
			$links[ $url ]['sources'][] = array(
				'sourcePage' => $source_page,
				'anchor'     => $anchor,
			);
		}
	}

	private static function check_links( array $links ) {
		$started  = microtime( true );
		$urls     = array_keys( $links );
		$statuses = array();
		$offset   = 0;

		while ( $offset < count( $urls ) && ! self::time_exceeded( $started, self::CHECK_TIME_S ) ) {
			$batch   = array_slice( $urls, $offset, self::CONCURRENCY * 2 );
			$results = self::request_link_batch( $batch );
			foreach ( $results as $url => $result ) {
				$statuses[ $url ] = $result;
			}
			$offset += count( $batch );
		}

		return array(
			'statuses'   => $statuses,
			'hitTimeCap' => $offset < count( $urls ),
		);
	}

	private static function request_link_batch( array $urls ) {
		if ( empty( $urls ) ) {
			return array();
		}

		$requests = array();
		foreach ( $urls as $url ) {
			$requests[ $url ] = array(
				'url'     => $url,
				'type'    => 'HEAD',
				'headers' => array(
					'User-Agent' => self::USER_AGENT,
					'Accept'     => '*/*',
				),
			);
		}

		$requests_class = parent::requests_class();
		if ( ! $requests_class ) {
			return self::request_link_batch_sequential( $urls );
		}

		try {
			$responses = call_user_func(
				array( $requests_class, 'request_multiple' ),
				$requests,
				array(
					'timeout'          => self::LINK_TIMEOUT,
					'connect_timeout'  => 4,
					'follow_redirects' => false,
					'redirects'        => 0,
					'verify'           => true,
				)
			);
		} catch ( \Exception $e ) {
			return self::request_link_batch_sequential( $urls );
		}

		$out = array();
		foreach ( $urls as $url ) {
			$response = isset( $responses[ $url ] ) ? $responses[ $url ] : null;
			$result   = self::classify_response( $response );
			if ( in_array( $result['statusCode'], array( 405, 501 ), true ) ) {
				$result = self::request_link_get( $url );
			}
			$out[ $url ] = $result;
		}
		return $out;
	}

	private static function request_link_batch_sequential( array $urls ) {
		$out = array();
		foreach ( $urls as $url ) {
			$out[ $url ] = self::request_link_get( $url );
		}
		return $out;
	}

	private static function request_link_get( $url ) {
		$args = array(
			'timeout'          => self::LINK_TIMEOUT,
			'redirection'      => 0,
			'limit_response_size' => 1024,
			'user-agent'       => self::USER_AGENT,
			'headers'          => array( 'Accept' => '*/*' ),
		);
		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return self::transport_result( $response->get_error_message() );
		}

		return self::classify_code( (int) wp_remote_retrieve_response_code( $response ) );
	}

	private static function classify_response( $response ) {
		if ( $response instanceof \Exception ) {
			return self::transport_result( $response->getMessage() );
		}
		if ( ! is_object( $response ) || ! isset( $response->status_code ) ) {
			return self::transport_result( 'No response received' );
		}
		return self::classify_code( (int) $response->status_code );
	}

	private static function classify_code( $code ) {
		if ( $code >= 300 && $code < 400 ) {
			return array(
				'type'       => 'redirect',
				'statusCode' => $code,
				'statusText' => self::status_text( $code ),
			);
		}
		if ( $code >= 400 ) {
			return array(
				'type'       => 'broken',
				'statusCode' => $code,
				'statusText' => self::status_text( $code ),
			);
		}
		if ( $code >= 200 && $code < 300 ) {
			return array(
				'type'       => 'healthy',
				'statusCode' => $code,
				'statusText' => self::status_text( $code ),
			);
		}
		return self::transport_result( 'Unexpected response' );
	}

	private static function transport_result( $message ) {
		$message = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $message ) ) );
		return array(
			'type'       => 'timeout',
			'statusCode' => 0,
			'statusText' => '' !== $message ? substr( $message, 0, 160 ) : 'Request timed out or failed',
		);
	}

	private static function status_text( $code ) {
		$known = array(
			200 => 'OK',
			201 => 'Created',
			204 => 'No Content',
			301 => 'Moved Permanently',
			302 => 'Found',
			303 => 'See Other',
			307 => 'Temporary Redirect',
			308 => 'Permanent Redirect',
			400 => 'Bad Request',
			401 => 'Unauthorized',
			403 => 'Forbidden',
			404 => 'Not Found',
			410 => 'Gone',
			429 => 'Too Many Requests',
			500 => 'Server Error',
			502 => 'Bad Gateway',
			503 => 'Service Unavailable',
			504 => 'Gateway Timeout',
		);
		return isset( $known[ $code ] ) ? $known[ $code ] : (string) $code;
	}

	private static function build_report( array $pages, array $links, array $statuses, $hit_link_cap, $hit_page_cap, $hit_time_cap ) {
		$broken_count  = 0;
		$redirect_count = 0;
		$timeout_count = 0;
		$problem_rows  = array();

		foreach ( $statuses as $url => $status ) {
			if ( 'broken' === $status['type'] ) {
				$broken_count++;
			} elseif ( 'redirect' === $status['type'] ) {
				$redirect_count++;
			} elseif ( 'timeout' === $status['type'] ) {
				$timeout_count++;
			}

			if ( 'healthy' === $status['type'] ) {
				continue;
			}

			$source = isset( $links[ $url ]['sources'][0] ) ? $links[ $url ]['sources'][0] : array( 'sourcePage' => '', 'anchor' => '' );
			$problem_rows[] = array(
				'url'          => $url,
				'type'         => $status['type'],
				'statusCode'   => (int) $status['statusCode'],
				'statusText'   => $status['statusText'],
				'sourcePage'   => $source['sourcePage'],
				'anchor'       => $source['anchor'],
				'occurrences'  => isset( $links[ $url ]['occurrences'] ) ? (int) $links[ $url ]['occurrences'] : 1,
				'sources'      => isset( $links[ $url ]['sources'] ) ? $links[ $url ]['sources'] : array(),
			);
		}

		usort( $problem_rows, function ( $a, $b ) {
			$type_order = array( 'broken' => 0, 'timeout' => 1, 'redirect' => 2 );
			$a_order    = isset( $type_order[ $a['type'] ] ) ? $type_order[ $a['type'] ] : 3;
			$b_order    = isset( $type_order[ $b['type'] ] ) ? $type_order[ $b['type'] ] : 3;
			if ( $a_order !== $b_order ) {
				return $a_order - $b_order;
			}
			return strcmp( $a['url'], $b['url'] );
		} );

		$links_checked = count( $statuses );
		$score         = null;
		if ( $links_checked > 0 ) {
			$score = max(
				0,
				100
				- min( 75, $broken_count * 15 )
				- min( 15, $redirect_count )
				- min( 20, $timeout_count * 5 )
			);
		}

		$bucket = null === $score ? 'unreliable' : ( $score >= 85 ? 'healthy' : ( $score >= 65 ? 'needs-work' : 'critical' ) );
		$labels = array(
			'healthy'    => 'Healthy',
			'needs-work' => 'Needs Work',
			'critical'   => 'Critical',
			'unreliable' => 'Unreliable',
		);
		$messages = array(
			'healthy'    => 'Most checked links are responding normally.',
			'needs-work' => 'A few link problems need attention before they become bigger SEO and UX issues.',
			'critical'   => 'A significant number of checked links need attention.',
			'unreliable' => 'No HTTP links could be checked, so there is not enough data for a reliable score.',
		);

		$metrics = array(
			'pagesCrawled'   => count( $pages ),
			'totalLinks'     => count( $links ),
			'linksChecked'   => $links_checked,
			'linkOccurrences' => self::total_occurrences( $links ),
			'brokenLinks'    => $broken_count,
			'redirects'      => $redirect_count,
			'timeouts'       => $timeout_count,
		);

		$findings = array();
		if ( $broken_count > 0 ) {
			$findings[] = array(
				'type'   => 'broken',
				'label'  => $broken_count . ' broken link' . ( 1 === $broken_count ? '' : 's' ) . ' found',
				'detail' => 'These URLs returned an HTTP error and should be repaired, redirected, or removed.',
			);
		}
		if ( $redirect_count > 0 ) {
			$findings[] = array(
				'type'   => 'redirect',
				'label'  => $redirect_count . ' redirect' . ( 1 === $redirect_count ? '' : 's' ) . ' found',
				'detail' => 'Updating links to their final destination can reduce extra requests and improve user experience.',
			);
		}
		if ( $timeout_count > 0 ) {
			$findings[] = array(
				'type'   => 'timeout',
				'label'  => $timeout_count . ' link check' . ( 1 === $timeout_count ? '' : 's' ) . ' timed out',
				'detail' => 'These destinations did not respond within the check window and should be reviewed separately.',
			);
		}
		if ( empty( $findings ) && $links_checked > 0 ) {
			$findings[] = array(
				'type'   => 'clean',
				'label'  => 'No link problems found in the checked sample',
				'detail' => 'Every checked destination responded without an HTTP error, redirect, or timeout.',
			);
		}

		$warnings   = array();
		$is_partial = (bool) ( $hit_link_cap || $hit_page_cap || $hit_time_cap );
		if ( $hit_link_cap ) {
			$warnings[] = array(
				'type'    => 'partial',
				'message' => 'The checker caps its sample at ' . self::MAX_LINKS . ' unique destinations. Review the report as a bounded sample, not an exhaustive crawl.',
			);
		}
		if ( $hit_page_cap ) {
			$warnings[] = array(
				'type'    => 'partial',
				'message' => 'The checker caps the crawl at ' . self::MAX_PAGES . ' pages. Some pages may not have been reached.',
			);
		}
		if ( $hit_time_cap ) {
			$warnings[] = array(
				'type'    => 'partial',
				'message' => 'The checker stopped at its time budget. Some discovered destinations may not have been checked.',
			);
		}
		if ( empty( $links ) ) {
			$warnings[] = array(
				'type'    => 'empty',
				'message' => 'No HTTP links were found on the pages we could access.',
			);
		}

		$preview = array(
			'score'         => $score,
			'bucket'        => $bucket,
			'bucketLabel'   => $labels[ $bucket ],
			'bucketMessage' => $messages[ $bucket ],
			'metrics'       => $metrics,
			'topFindings'   => array_slice( $findings, 0, 3 ),
			'warnings'      => $warnings,
			'isPartialScan' => $is_partial,
		);

		return array(
			'preview'    => $preview,
			'fullReport' => array(
				'score'       => $score,
				'bucket'      => $bucket,
				'bucketLabel' => $labels[ $bucket ],
				'bucketMessage' => $messages[ $bucket ],
				'metrics'     => $metrics,
				'findings'    => $findings,
				'warnings'    => $warnings,
				'brokenLinks' => $problem_rows,
			),
		);
	}

	private static function total_occurrences( array $links ) {
		$total = 0;
		foreach ( $links as $link ) {
			$total += isset( $link['occurrences'] ) ? (int) $link['occurrences'] : 0;
		}
		return $total;
	}
}
