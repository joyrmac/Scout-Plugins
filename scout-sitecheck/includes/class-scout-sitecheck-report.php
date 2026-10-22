<?php
/**
 * The report: the rows on the page, the email to the visitor, and the note
 * to the team. The email follows Scout's email standards (600px white card on
 * #F5F5F7, mono eyebrow, one button), built with tables and inline styles.
 *
 * @package Scout_Sitecheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Sitecheck_Report {

	const COLORS = array(
		'pass' => array( '#0E9E73', '#FFFFFF', '&#10003;' ),
		'warn' => array( '#FBB024', '#1D1D1F', '!' ),
		'fail' => array( '#F2553D', '#FFFFFF', '!' ),
		'skip' => array( '#86868B', '#FFFFFF', '?' ),
	);

	public static function headline( int $passed, int $total ): string {
		$fixes = $total - $passed;
		if ( 0 === $fixes ) {
			/* translators: %d: number of checks */
			return sprintf( __( 'Your site passed all %d checks.', 'scout-sitecheck' ), $total );
		}
		if ( $passed * 10 >= $total * 6 ) {
			/* translators: %d: number of fixes */
			return sprintf( _n( 'Your site has a solid base, with one quick win.', 'Your site has a solid base, with %d quick wins.', $fixes, 'scout-sitecheck' ), $fixes );
		}
		/* translators: %d: number of fixes */
		return sprintf( _n( 'Your site has one fix that will help it get found.', 'Your site has %d fixes that will help it get found.', $fixes, 'scout-sitecheck' ), $fixes );
	}

	/** Keep the last two words of a heading together. */
	private static function nowrap_last( string $text ): string {
		return preg_replace( '/\s+(\S+)$/u', '&nbsp;$1', esc_html( $text ) );
	}

	/** The example report beside the form. */
	public static function sample(): array {
		return array(
			array( 'key' => 'speed', 'title' => __( 'Speed', 'scout-sitecheck' ), 'status' => 'warn', 'text' => __( 'The main content takes 4.8 seconds to show on phones. Aim for under 2.5 seconds.', 'scout-sitecheck' ), 'source' => __( 'Google speed tool', 'scout-sitecheck' ) ),
			array( 'key' => 'security', 'title' => __( 'Security lock', 'scout-sitecheck' ), 'status' => 'pass', 'text' => __( 'HTTPS is on and the security certificate is current.', 'scout-sitecheck' ), 'source' => '' ),
			array( 'key' => 'titles', 'title' => __( 'Page titles', 'scout-sitecheck' ), 'status' => 'pass', 'text' => __( 'All 5 pages we checked have their own title.', 'scout-sitecheck' ), 'source' => '' ),
			array( 'key' => 'schema', 'title' => __( 'Business info for Google', 'scout-sitecheck' ), 'status' => 'fail', 'text' => __( 'No business info for Google found, so Google has to guess your hours and address.', 'scout-sitecheck' ), 'source' => __( 'Schema markup', 'scout-sitecheck' ) ),
			array( 'key' => 'mobile', 'title' => __( 'Phone layout', 'scout-sitecheck' ), 'status' => 'pass', 'text' => __( 'The page is set up to fit phone screens.', 'scout-sitecheck' ), 'source' => '' ),
			array( 'key' => 'ai', 'title' => __( 'AI crawlers', 'scout-sitecheck' ), 'status' => 'warn', 'text' => __( 'ChatGPT, Claude, Perplexity and Gemini can read your site. Apple Intelligence is blocked.', 'scout-sitecheck' ), 'source' => __( 'Crawler check', 'scout-sitecheck' ) ),
			array( 'key' => 'sitemap', 'title' => __( 'Sitemap', 'scout-sitecheck' ), 'status' => 'pass', 'text' => __( 'Found at /sitemap.xml, so Google can find every page.', 'scout-sitecheck' ), 'source' => '' ),
			array( 'key' => 'photos', 'title' => __( 'Photo sizes', 'scout-sitecheck' ), 'status' => 'pass', 'text' => __( 'All 12 photos on your home page are under 500 KB, a good size for phones.', 'scout-sitecheck' ), 'source' => '' ),
		);
	}

	/** Report rows for the web page. */
	public static function rows_html( array $rows ): string {
		$out = '<ul class="ssc-rows">';
		foreach ( $rows as $r ) {
			$status = isset( self::COLORS[ $r['status'] ] ) ? $r['status'] : 'skip';
			$out   .= '<li class="ssc-r is-' . esc_attr( $status ) . '" data-status="' . esc_attr( $status ) . '"><span class="ssc-ico" aria-hidden="true">' . self::COLORS[ $status ][2] . '</span>'
				. '<span class="ssc-rt"><strong>' . esc_html( $r['title'] ) . '</strong><span class="screen-reader-text"> (' . esc_html( self::status_word( $status ) ) . ')</span> ' . esc_html( $r['text'] )
				. ( '' !== (string) $r['source'] ? ' <span class="ssc-src">' . esc_html( $r['source'] ) . '</span>' : '' ) . '</span></li>';
		}
		return $out . '</ul>';
	}

	public static function status_word( string $status ): string {
		$words = array(
			'pass' => __( 'passed', 'scout-sitecheck' ),
			'warn' => __( 'worth fixing', 'scout-sitecheck' ),
			'fail' => __( 'needs a fix', 'scout-sitecheck' ),
			'skip' => __( 'not checked', 'scout-sitecheck' ),
		);
		return $words[ $status ] ?? '';
	}

	/** The follow-up line under the report, on the page and in the email. */
	public static function follow_up( int $fixes, bool $email ): string {
		$lead = trim( Scout_Sitecheck::settings()['lead_name'] );
		if ( $email ) {
			if ( 0 === $fixes ) {
				return '' !== $lead
					? sprintf( /* translators: %s: name */ __( 'Questions about your report? Reply to this email and %s will walk you through it.', 'scout-sitecheck' ), $lead )
					: __( 'Questions about your report? Reply to this email and we\'ll walk you through it.', 'scout-sitecheck' );
			}
			return '' !== $lead
				/* translators: 1: number of fixes, 2: name */
				? sprintf( _n( 'Want help with the fix? Reply to this email and %2$s will walk you through it.', 'Want help with the %1$d fixes? Reply to this email and %2$s will walk you through them.', $fixes, 'scout-sitecheck' ), $fixes, $lead )
				/* translators: %d: number of fixes */
				: sprintf( _n( 'Want help with the fix? Reply to this email and we\'ll walk you through it.', 'Want help with the %d fixes? Reply to this email and we\'ll walk you through them.', $fixes, 'scout-sitecheck' ), $fixes );
		}
		return '' !== $lead
			? sprintf( /* translators: %s: name */ __( 'Want to talk it through? Book a call with %s.', 'scout-sitecheck' ), $lead )
			: __( 'Want to talk it through? Book a call with us.', 'scout-sitecheck' );
	}

	/* ---------- email ---------- */

	private static function report_for( int $id ): ?array {
		$rows = Scout_Sitecheck::rows( $id );
		return null === $rows ? null : Scout_Sitecheck::summary( $id, $rows );
	}

	/** Email the report to the person who asked for it. */
	public static function send( int $id ): bool {
		$r = self::report_for( $id );
		if ( ! $r ) {
			return false;
		}
		$to = (string) get_post_meta( $id, '_ssc_email', true );
		if ( ! is_email( $to ) ) {
			return false;
		}
		$notify  = Scout_Sitecheck::settings()['notify'];
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( is_email( $notify ) ) {
			$headers[] = 'Reply-To: ' . self::from_name() . ' <' . $notify . '>';
		}
		/* translators: %s: site */
		$subject = sprintf( __( 'Your site check for %s', 'scout-sitecheck' ), $r['site'] );
		add_filter( 'wp_mail_from_name', array( __CLASS__, 'from_name' ) );
		$ok = wp_mail( $to, $subject, self::email_html( $id, $r ), $headers );
		remove_filter( 'wp_mail_from_name', array( __CLASS__, 'from_name' ) );
		if ( $ok ) {
			update_post_meta( $id, '_ssc_sent', time() );
		}
		return $ok;
	}

	public static function from_name(): string {
		return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}

	public static function email_html( int $id, array $r ): string {
		$name   = (string) get_post_meta( $id, '_ssc_name', true );
		$first  = trim( (string) strtok( $name, ' ' ) );
		$photo  = Scout_Sitecheck::settings()['lead_photo'];
		$logo   = function_exists( 'get_site_icon_url' ) ? get_site_icon_url( 64 ) : '';
		$font   = "'Hanken Grotesk',Helvetica,Arial,sans-serif";
		$mono   = "'Geist Mono',Menlo,monospace";
		$date   = wp_date( get_option( 'date_format' ), (int) ( get_post_meta( $id, '_ssc_checked', true ) ?: time() ) );
		$rows   = '';
		foreach ( $r['rows'] as $row ) {
			$c     = self::COLORS[ $row['status'] ] ?? self::COLORS['skip'];
			$rows .= '<tr><td valign="top" style="padding:14px 12px 14px 0;border-top:1px solid #ECECEF;width:26px;">'
				. '<div style="width:24px;height:24px;border-radius:12px;background:' . $c[0] . ';color:' . $c[1] . ';text-align:center;font:600 13px ' . $font . ';line-height:24px;">' . $c[2] . '</div></td>'
				. '<td valign="top" style="padding:14px 0;border-top:1px solid #ECECEF;font:400 15px/1.5 ' . $font . ';color:#515154;">'
				. '<strong style="color:#1D1D1F;font-weight:600;">' . esc_html( $row['title'] ) . '</strong><br>' . esc_html( $row['text'] )
				. ( '' !== (string) $row['source'] ? '<br><span style="font:500 11px ' . $mono . ';letter-spacing:1px;text-transform:uppercase;color:#86868B;">' . esc_html( $row['source'] ) . '</span>' : '' )
				. '</td></tr>';
		}
		$note = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F5F9FE;border-radius:14px;"><tr>'
			. ( '' !== $photo ? '<td width="62" valign="top" style="padding:18px 0 18px 18px;"><img src="' . esc_url( $photo ) . '" width="46" height="46" alt="' . esc_attr( Scout_Sitecheck::lead_name() ) . '" style="display:block;border-radius:23px;"></td>' : '' )
			. '<td style="padding:18px;font:400 15px/1.55 ' . $font . ';color:#1D1D1F;">' . esc_html( self::follow_up( $r['fixes'], true ) ) . '</td></tr></table>';
		$button = '<a href="' . esc_url( Scout_Sitecheck::book_url() ) . '" style="display:inline-block;background:#0A78C2;color:#FFFFFF;text-decoration:none;font:500 15px ' . $font . ';padding:13px 26px;border-radius:980px;">' . esc_html__( 'Book a call', 'scout-sitecheck' ) . '</a>';

		ob_start();
		?>
<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( $r['site'] ); ?></title></head>
<body style="margin:0;padding:0;background:#F5F5F7;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F5F5F7;"><tr><td align="center" style="padding:32px 12px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#FFFFFF;border:1px solid #ECECEF;border-radius:16px;">
<tr><td style="padding:24px 32px;border-bottom:1px solid #ECECEF;">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
	<td style="font:600 17px <?php echo esc_attr( $font ); ?>;color:#1D1D1F;letter-spacing:-.02em;"><?php if ( $logo ) : ?><img src="<?php echo esc_url( $logo ); ?>" width="22" height="22" alt="" style="vertical-align:middle;margin-right:8px;border-radius:5px;"><?php endif; ?><?php echo esc_html( self::from_name() ); ?></td>
	<td align="right" style="font:500 11px <?php echo esc_attr( $mono ); ?>;letter-spacing:1.2px;text-transform:uppercase;color:#86868B;"><?php esc_html_e( 'Free site check', 'scout-sitecheck' ); ?></td>
	</tr></table>
</td></tr>
<tr><td style="padding:32px 32px 8px;">
	<p style="margin:0 0 10px;font:500 12px <?php echo esc_attr( $mono ); ?>;letter-spacing:1.4px;text-transform:uppercase;color:#0A6FB4;"><?php echo esc_html( $r['site'] ); ?> &middot; <?php echo esc_html( $date ); ?></p>
	<table role="presentation" cellpadding="0" cellspacing="0"><tr>
	<td valign="middle" width="82" style="width:82px;padding-right:18px;"><div style="width:72px;height:72px;border-radius:41px;border:5px solid #0E8FE6;text-align:center;font:600 20px <?php echo esc_attr( $mono ); ?>;line-height:72px;color:#1D1D1F;"><?php echo (int) $r['passed']; ?>/<?php echo (int) $r['total']; ?></div></td>
	<td valign="middle"><h1 style="margin:0;font:600 26px/1.15 <?php echo esc_attr( $font ); ?>;letter-spacing:-.03em;color:#1D1D1F;"><?php echo self::nowrap_last( $r['headline'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?></h1></td>
	</tr></table>
	<p style="margin:22px 0 0;font:400 16px/1.6 <?php echo esc_attr( $font ); ?>;color:#515154;"><?php echo '' !== $first ? esc_html( sprintf( /* translators: %s: first name */ __( 'Hi %s,', 'scout-sitecheck' ), $first ) ) : esc_html__( 'Hi there,', 'scout-sitecheck' ); ?> <?php esc_html_e( 'here\'s what we found. Each line says what we checked and what it means for your site.', 'scout-sitecheck' ); ?></p>
</td></tr>
<tr><td style="padding:12px 32px 8px;">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><?php echo $rows; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts. ?></table>
</td></tr>
<tr><td style="padding:16px 32px 8px;"><?php echo $note; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts. ?></td></tr>
<tr><td style="padding:16px 32px 32px;"><?php echo $button; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts. ?></td></tr>
<tr><td style="padding:20px 32px;border-top:1px solid #ECECEF;font:400 12px/1.65 <?php echo esc_attr( $font ); ?>;color:#86868B;">
	<?php echo esc_html( self::from_name() ); ?> &middot; <a href="<?php echo esc_url( home_url( '/' ) ); ?>" style="color:#86868B;"><?php echo esc_html( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></a><br>
	<?php esc_html_e( 'You asked for this report on our site. We checked your home page and a few linked pages, the way a visitor or Google would.', 'scout-sitecheck' ); ?>
</td></tr>
</table>
</td></tr></table>
</body></html>
		<?php
		return (string) ob_get_clean();
	}

	/* ---------- the team's copy ---------- */

	public static function notify_team( int $id, bool $unreachable = false ): void {
		$to = Scout_Sitecheck::settings()['notify'];
		if ( ! is_email( $to ) ) {
			return;
		}
		$name  = (string) get_post_meta( $id, '_ssc_name', true );
		$email = (string) get_post_meta( $id, '_ssc_email', true );
		$site  = get_post_field( 'post_title', $id );
		$link  = admin_url( 'post.php?post=' . $id . '&action=edit' );
		$lines = array(
			/* translators: 1: name, 2: email */
			sprintf( __( 'From: %1$s <%2$s>', 'scout-sitecheck' ), $name, $email ),
			/* translators: %s: site */
			sprintf( __( 'Site: %s', 'scout-sitecheck' ), $site ),
			'',
		);
		if ( $unreachable ) {
			/* translators: %s: site */
			$subject = sprintf( __( 'Site check could not reach %s', 'scout-sitecheck' ), $site );
			$lines[] = __( 'The check could not reach this site, so no report went out. The person saw a message asking them to check the address. Worth a personal reply.', 'scout-sitecheck' );
		} else {
			$r     = self::report_for( $id );
			$state = (string) get_post_meta( $id, '_ssc_status', true );
			/* translators: 1: site, 2: passed, 3: total */
			$subject = sprintf( __( 'New site check: %1$s (%2$d of %3$d)', 'scout-sitecheck' ), $site, $r['passed'] ?? 0, $r['total'] ?? 0 );
			if ( 'held' === $state ) {
				$lines[] = __( 'Waiting for your review. Open it and click Send report.', 'scout-sitecheck' );
			} elseif ( 'sent' === $state ) {
				$lines[] = __( 'The report was emailed to them. Reply to this email to reach them.', 'scout-sitecheck' );
			} else {
				$lines[] = __( 'The report email did not go out. Open it and click Send report.', 'scout-sitecheck' );
			}
			$lines[] = '';
			foreach ( (array) ( $r['rows'] ?? array() ) as $row ) {
				$lines[] = '[' . self::status_word( $row['status'] ) . '] ' . $row['title'] . ': ' . $row['text'];
			}
		}
		$lines[] = '';
		/* translators: %s: link */
		$lines[] = sprintf( __( 'Open it: %s', 'scout-sitecheck' ), $link );
		$headers = is_email( $email ) ? array( 'Reply-To: ' . $name . ' <' . $email . '>' ) : array();
		wp_mail( $to, $subject, implode( "\n", $lines ), $headers );
	}
}
