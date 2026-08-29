<?php
/**
 * Mailer: renders the audit-results email and sends via wp_mail.
 *
 * Template lives at templates/email-audit-results.html with {{merge_field}}
 * placeholders. Substitution is a single str_replace pass — no template
 * engine, no logic in templates. Conditional copy (zero-issue audits, score
 * coloring) is applied in PHP before substitution.
 *
 * @package LW_Audit_Store
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LW_Audit_Mailer {

	/**
	 * Send the audit-results email.
	 *
	 * @param array $row Row from wp_lw_audits (associative).
	 * @return array { ok: bool, error: string|null }
	 */
	public static function send_audit_results( $row ) {
		$to = isset( $row['email'] ) ? sanitize_email( $row['email'] ) : '';
		if ( '' === $to || ! is_email( $to ) ) {
			return array( 'ok' => false, 'error' => 'invalid email' );
		}

		$template_path = LW_AUDIT_DIR . 'templates/email-audit-results.html';
		if ( ! file_exists( $template_path ) ) {
			return array( 'ok' => false, 'error' => 'template missing' );
		}

		$html = (string) file_get_contents( $template_path );
		$html = self::render( $html, $row );

		$from_email = LW_Audit_Settings::get( 'support_email_from' );
		$from_name  = LW_Audit_Settings::get( 'support_email_name' );
		$replyto    = LW_Audit_Settings::get( 'support_email_replyto' );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $from_name . ' <' . $from_email . '>',
			'Reply-To: ' . $replyto,
		);

		$score   = isset( $row['score'] ) && '' !== $row['score'] ? intval( $row['score'] ) : null;
		$subject = self::subject_for( $row, $score );

		$sent = wp_mail( $to, $subject, $html, $headers );
		return array(
			'ok'    => (bool) $sent,
			'error' => $sent ? null : 'wp_mail returned false',
		);
	}

	/**
	 * Subject line. Score-aware: bucket label drives whether subject leads
	 * with reassurance or urgency. Buckets must match the frontend score
	 * thresholds (65 / 85) — otherwise the email contradicts the UI the
	 * user just saw.
	 */
	private static function subject_for( $row, $score ) {
		// Tool-specific subjects.
		if ( isset( $row['tool'] ) && 'link-map' === $row['tool'] ) {
			$orphan_count = isset( $row['orphan_count'] ) ? intval( $row['orphan_count'] ) : 0;
			return sprintf(
				'Your Internal Link Map — %d orphaned %s found',
				$orphan_count,
				1 === $orphan_count ? 'page' : 'pages'
			);
		}
		if ( isset( $row['tool'] ) && 'broken-link-checker' === $row['tool'] ) {
			$broken_count = isset( $row['broken_count'] ) ? intval( $row['broken_count'] ) : 0;
			return sprintf(
				'Your Broken Link Check — %d broken %s found',
				$broken_count,
				1 === $broken_count ? 'link' : 'links'
			);
		}

		if ( null === $score ) {
			return 'Your LinkWhisper audit is ready';
		}
		if ( $score < 65 ) {
			return 'Your link health score: ' . $score . '/100 — issues to fix';
		}
		if ( $score < 85 ) {
			return 'Your link health score: ' . $score . '/100 — room to improve';
		}
		return 'Your link health score: ' . $score . '/100 — healthy';
	}

	/**
	 * Substitute {{merge_field}} tokens. Every value passed through
	 * esc_html (the email is HTML, all merge data is user-controlled).
	 *
	 * Tokens produced here (definitive contract):
	 *   {{audit_id}}           — integer row ID, used in CTA UTM
	 *   {{url_audited}}        — URL that was scanned
	 *   {{score}}              — integer 0–100
	 *   {{score_color}}        — hex foreground for score number (#DC2626 / #D97706 / #3BB273)
	 *   {{score_label}}        — "Needs work" / "Mixed" / "Healthy"
	 *   {{score_label_color}}  — same hex as score_color
	 *   {{pages_crawled}}      — integer
	 *   {{broken_count}}       — integer
	 *   {{orphan_count}}       — integer
	 *   {{internal_links}}     — integer
	 *   {{issue_status_block}} — full HTML block (issues-found or clean-audit variant)
	 *   {{unsubscribe_url}}    — mailto: unsubscribe link
	 */
	public static function render( $html, $row ) {
		$audit_id       = isset( $row['id'] ) ? intval( $row['id'] ) : 0;
		$url_audited    = isset( $row['url_audited'] ) ? (string) $row['url_audited'] : '';
		$score_int      = isset( $row['score'] ) && '' !== $row['score'] ? intval( $row['score'] ) : null;
		$score          = null !== $score_int ? (string) $score_int : '—';
		$pages_crawled  = isset( $row['pages_crawled'] ) ? (string) intval( $row['pages_crawled'] ) : '0';
		$broken_count   = isset( $row['broken_count'] ) ? (string) intval( $row['broken_count'] ) : '0';
		$orphan_count   = isset( $row['orphan_count'] ) ? (string) intval( $row['orphan_count'] ) : '0';
		$internal_links = isset( $row['internal_links'] ) ? (string) intval( $row['internal_links'] ) : '0';

		// Score color coding. Thresholds (65 / 85) match the frontend
		// scoreBucket() in src/pages/LinkChecker.tsx — must stay in sync
		// or the email contradicts the on-screen label the user just saw.
		if ( null === $score_int ) {
			$score_color = '#64748B'; // muted gray when no score available
			$score_label = '';
		} elseif ( $score_int < 65 ) {
			$score_color = '#DC2626'; // red
			$score_label = 'Critical';
		} elseif ( $score_int < 85 ) {
			$score_color = '#D97706'; // amber
			$score_label = 'Needs work';
		} else {
			$score_color = '#3BB273'; // green
			$score_label = 'Healthy';
		}
		$score_label_color = $score_color;

		// Issue status block — zero-issue variant vs. issues-found variant.
		$broken_int = (int) $broken_count;
		$orphan_int = (int) $orphan_count;

		if ( isset( $row['tool'] ) && 'broken-link-checker' === $row['tool'] ) {
			$issue_status_block = self::issue_block_broken( $broken_count, $internal_links, $pages_crawled );
		} elseif ( 0 === $broken_int + $orphan_int ) {
			$issue_status_block = self::issue_block_clean( $internal_links, $pages_crawled );
		} else {
			$issue_status_block = self::issue_block_found( $broken_count, $orphan_count, $internal_links, $pages_crawled );
		}

		// Unsubscribe — mailto: satisfies CAN-SPAM for single transactional sends.
		$unsubscribe_url = 'mailto:' . LW_Audit_Settings::get( 'support_email_replyto' )
			. '?subject=' . rawurlencode( 'Unsubscribe from Free Audit Tool emails' );

		// CAN-SPAM Sec. 5(a)(5): commercial email must show a valid physical
		// postal address. Newlines → <br> so multi-line addresses render.
		$physical_address_raw = LW_Audit_Settings::get( 'support_physical_address' );
		$physical_address     = '' !== $physical_address_raw
			? nl2br( esc_html( $physical_address_raw ) )
			: '';

		$replacements = array(
			'{{audit_id}}'           => esc_html( (string) $audit_id ),
			'{{url_audited}}'        => esc_html( $url_audited ),
			'{{score}}'              => esc_html( $score ),
			'{{score_color}}'        => esc_attr( $score_color ),
			'{{score_label}}'        => esc_html( $score_label ),
			'{{score_label_color}}'  => esc_attr( $score_label_color ),
			'{{pages_crawled}}'      => esc_html( $pages_crawled ),
			'{{broken_count}}'       => esc_html( $broken_count ),
			'{{orphan_count}}'       => esc_html( $orphan_count ),
			'{{internal_links}}'     => esc_html( $internal_links ),
			'{{issue_status_block}}' => $issue_status_block, // pre-built HTML, not escaped
			'{{unsubscribe_url}}'    => esc_url( $unsubscribe_url ),
			'{{physical_address}}'   => $physical_address, // pre-escaped + nl2br
		);

		return strtr( $html, $replacements );
	}

	/**
	 * Issue block for the HTTP broken-link checker. Its `internal_links` field
	 * is the number of unique destinations checked, not a link-graph count.
	 */
	private static function issue_block_broken( $broken_count, $links_checked, $pages_crawled ) {
		$b  = esc_html( $broken_count );
		$il = esc_html( $links_checked );
		$pc = esc_html( $pages_crawled );

		if ( 0 === (int) $broken_count ) {
			return '
<p style="margin:0 0 8px 0;font-size:11px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;color:#3BB273;">All Clear</p>
<h2 style="margin:0 0 12px 0;font-size:22px;font-weight:700;color:#1A1F2E;line-height:1.3;">No broken links found</h2>
<p style="margin:0 0 16px 0;font-size:14px;color:#64748B;line-height:1.6;">
  We checked ' . $il . ' unique destinations across ' . $pc . ' pages. No HTTP errors were found in the checked sample.
</p>';
		}

		return '
<p style="margin:0 0 8px 0;font-size:11px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;color:#3BB273;">What This Means</p>
<h2 style="margin:0 0 16px 0;font-size:22px;font-weight:700;color:#1A1F2E;line-height:1.3;">Here is what we found</h2>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
  <tr>
    <td style="padding:12px 0;border-bottom:1px solid #E2E8F0;vertical-align:top;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
        <tr>
          <td width="20" style="vertical-align:top;padding-top:2px;"><div style="width:8px;height:8px;border-radius:50%;background-color:#DC2626;margin-top:4px;"></div></td>
          <td>
            <p style="margin:0;font-size:14px;color:#1A1F2E;font-weight:600;">' . $b . ' broken ' . ( 1 === (int) $broken_count ? 'link' : 'links' ) . '</p>
            <p style="margin:4px 0 0 0;font-size:13px;color:#64748B;line-height:1.5;">These URLs returned an HTTP error. Repair, redirect, or remove them to keep visitors and search crawlers moving.</p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
  <tr>
    <td style="padding:12px 0;vertical-align:top;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
        <tr>
          <td width="20" style="vertical-align:top;padding-top:2px;"><div style="width:8px;height:8px;border-radius:50%;background-color:#3BB273;margin-top:4px;"></div></td>
          <td>
            <p style="margin:0;font-size:14px;color:#1A1F2E;font-weight:600;">' . $il . ' unique destinations checked</p>
            <p style="margin:4px 0 0 0;font-size:13px;color:#64748B;line-height:1.5;">Across ' . $pc . ' pages in the free checker sample.</p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>';
	}

	/**
	 * Issue block — issues found variant.
	 * Shown when broken_count + orphan_count > 0.
	 */
	private static function issue_block_found( $broken_count, $orphan_count, $internal_links, $pages_crawled ) {
		$b = esc_html( $broken_count );
		$o = esc_html( $orphan_count );
		$il = esc_html( $internal_links );
		$pc = esc_html( $pages_crawled );

		return '
<p style="margin:0 0 8px 0;font-size:11px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;color:#3BB273;">What This Means</p>
<h2 style="margin:0 0 16px 0;font-size:22px;font-weight:700;color:#1A1F2E;line-height:1.3;">Here is what we found</h2>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
  <tr>
    <td style="padding:12px 0;border-bottom:1px solid #E2E8F0;vertical-align:top;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
        <tr>
          <td width="20" style="vertical-align:top;padding-top:2px;">
            <div style="width:8px;height:8px;border-radius:50%;background-color:#DC2626;margin-top:4px;"></div>
          </td>
          <td>
            <p style="margin:0;font-size:14px;color:#1A1F2E;font-weight:600;">' . $b . ' broken internal links</p>
            <p style="margin:4px 0 0 0;font-size:13px;color:#64748B;line-height:1.5;">These point to pages that no longer exist. Google crawls them and hits dead ends. Fixing them recovers lost link equity.</p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
  <tr>
    <td style="padding:12px 0;border-bottom:1px solid #E2E8F0;vertical-align:top;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
        <tr>
          <td width="20" style="vertical-align:top;padding-top:2px;">
            <div style="width:8px;height:8px;border-radius:50%;background-color:#D97706;margin-top:4px;"></div>
          </td>
          <td>
            <p style="margin:0;font-size:14px;color:#1A1F2E;font-weight:600;">' . $o . ' orphaned pages</p>
            <p style="margin:4px 0 0 0;font-size:13px;color:#64748B;line-height:1.5;">No other page links to these. They exist on your site but are effectively invisible to search engines and readers.</p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
  <tr>
    <td style="padding:12px 0;vertical-align:top;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
        <tr>
          <td width="20" style="vertical-align:top;padding-top:2px;">
            <div style="width:8px;height:8px;border-radius:50%;background-color:#3BB273;margin-top:4px;"></div>
          </td>
          <td>
            <p style="margin:0;font-size:14px;color:#1A1F2E;font-weight:600;">' . $il . ' internal links total</p>
            <p style="margin:4px 0 0 0;font-size:13px;color:#64748B;line-height:1.5;">Across ' . $pc . ' pages. Each gap in your link graph is a ranking opportunity you have not used yet.</p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>';
	}

	/**
	 * Issue block — clean audit variant.
	 * Shown when broken_count + orphan_count === 0.
	 */
	private static function issue_block_clean( $internal_links, $pages_crawled ) {
		$il = esc_html( $internal_links );
		$pc = esc_html( $pages_crawled );

		return '
<p style="margin:0 0 8px 0;font-size:11px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;color:#3BB273;">All Clear</p>
<h2 style="margin:0 0 12px 0;font-size:22px;font-weight:700;color:#1A1F2E;line-height:1.3;">No broken links or orphaned pages found</h2>
<p style="margin:0 0 16px 0;font-size:14px;color:#64748B;line-height:1.6;">
  Your site has ' . $il . ' internal links across ' . $pc . ' pages — and every one of them is clean. That puts you ahead of most WordPress sites we see.
</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
  <tr>
    <td style="padding:16px;background-color:#F0FDF4;border-radius:8px;border-left:3px solid #3BB273;">
      <p style="margin:0;font-size:14px;color:#1A1F2E;font-weight:600;">Keep it clean as you grow</p>
      <p style="margin:6px 0 0 0;font-size:13px;color:#64748B;line-height:1.5;">Every new page you publish is a chance for a broken link or an orphan to slip through. LinkWhisper flags them the moment they appear and surfaces new linking opportunities as you write — so your internal link graph stays healthy without manual audits.</p>
    </td>
  </tr>
</table>';
	}
}
