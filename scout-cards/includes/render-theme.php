<?php
/**
 * The theme's own card look. When cards move from a theme that drew them itself
 * (the Scout Media theme) to this plugin, visitors must see the same pages (Site
 * Standard rule 20). This prints the markup that theme printed (`bc-` classes,
 * styled by the theme's own stylesheet) from this plugin's data, and hands the
 * page title, description, canonical and robots to the theme's head through
 * `$GLOBALS['scout_seo']`, the way the theme's card templates did.
 *
 * It is on only when the `look` setting is `theme` (the copy from the theme sets
 * it) and the theme still carries the card look: Scout Media 1.54.0 and later keep
 * `scout_card_settings()` and the card styles. Any other theme gets the plugin's
 * own look. Set the look back to the plugin's under More options to switch.
 *
 * @package Scout_Cards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Cards_Render_Theme {

	/** Draw the cards in the theme's own look? */
	public static function on() {
		$on = 'theme' === ( Scout_Cards_Settings::get()['look'] ?? '' ) && function_exists( 'scout_card_settings' );
		return (bool) apply_filters( 'scout_cards_theme_look', $on );
	}

	/** The theme's file in assets/img, or ''. */
	private static function theme_img( $file ) {
		$path = get_template_directory() . '/assets/img/' . $file;
		return is_readable( $path ) ? get_template_directory_uri() . '/assets/img/' . $file : '';
	}

	/** JPEG bytes of the theme's contact photo for a card (assets/img/card-photo-<key>.jpg), or ''. */
	public static function contact_photo( $key ) {
		$path = get_template_directory() . '/assets/img/card-photo-' . sanitize_file_name( $key ) . '.jpg';
		return is_readable( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/** The card data this look reads, from the plugin's people(). */
	private static function people() {
		$s   = Scout_Cards_Settings::get();
		$out = array();
		foreach ( Scout_Cards_Settings::people() as $k => $p ) {
			if ( 'team' === $k ) {
				// The theme showed the team photo only when one was chosen, else its bolt mark.
				$p['photo'] = (int) $p['photo_id'] ? $p['photo'] : '';
				$p['pick_heading'] = $s['pick_heading'];
			} elseif ( '' === $p['photo'] ) {
				// No photo on the card or in People: the theme's own photo for that link name, as the theme's cards used.
				$p['photo'] = self::theme_img( 'team-' . sanitize_file_name( $k ) . '.webp' );
			}
			if ( $p['spot'] ) {
				$p['spot']['external'] = '' !== $p['spot']['href'] && 0 !== strpos( $p['spot']['url'], '/' );
			}
			$out[ $k ] = $p;
		}
		return $out;
	}

	/** The phone a card calls, in +1 form (the person's card phone, else the business one). */
	private static function tel( array $p, array $s ) {
		return Scout_Cards_Settings::phone_e164( '' !== (string) ( $p['phone'] ?? '' ) ? $p['phone'] : $s['phone'] );
	}

	private static function shell_open( $body_class, $title, $app = null ) {
		$s = Scout_Cards_Settings::get();
		$accent = strtoupper( sanitize_hex_color( $s['accent'] ) ? sanitize_hex_color( $s['accent'] ) : '#0E8FE6' );
		?><!DOCTYPE html>
<html <?php language_attributes(); ?> data-theme="dark">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( function_exists( 'scout_doc_title' ) ? scout_doc_title() : $title ); ?></title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><polygon points='58,0 0,58 42,58 30,100 100,40 54,40' fill='<?php echo esc_attr( rawurlencode( $accent ) ); ?>'/></svg>">
<?php
		if ( function_exists( 'scout_theme_boot' ) ) {
			scout_theme_boot();
		}
		if ( $app ) :
			$touch = self::theme_img( 'card-icon-180.png' );
			$touch = $touch ? $touch : (string) get_site_icon_url( 180 );
			?>
<link rel="manifest" href="<?php echo esc_url( $app['manifest'] ); ?>">
<?php if ( $touch ) : ?>
<link rel="apple-touch-icon" href="<?php echo esc_url( $touch ); ?>">
<?php endif; ?>
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?php echo esc_attr( $app['short'] ); ?>">
<meta name="theme-color" content="#0B0B0B">
<?php endif; ?>
<?php wp_head(); ?>
</head>
<body <?php body_class( $body_class ); ?>>
<?php wp_body_open(); ?>
<main class="bc-wrap">
  <a class="brand bc-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>"><span class="bolt"></span><?php echo esc_html( $s['name'] ); ?></a>
<?php
	}

	private static function shell_close() {
		$s    = Scout_Cards_Settings::get();
		$host = preg_replace( '#^www\.#', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		?>
  <div class="bc-social"><?php echo self::social(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?></div>
  <p class="bc-foot"><?php echo esc_html( $s['footer'] ); ?> &middot; <a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( $host ); ?></a></p>
</main>
<?php wp_footer(); ?>
</body>
</html>
<?php
	}

	/** The social icons row, in the theme's markup, from the plugin's profile links. */
	private static function social() {
		$icons = array(
			'Facebook'  => '<path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>',
			'Instagram' => '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.2" cy="6.8" r="1.1" fill="currentColor" stroke="none"/>',
			'X'         => '<path d="M4 4l16 16M20 4 4 20"/>',
			'LinkedIn'  => '<path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-4 0v7h-4v-7a6 6 0 0 1 6-6z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/>',
			'Threads'   => '<path d="M16.5 11.4c1.7.7 2.6 2 2.7 3.7.1 2.6-2.2 4.9-6.7 4.9h-.1c-5 0-7.4-3.2-7.4-8.4S7.3 3.2 12.3 3.2c2.9 0 5 1.1 6.2 3.1"/><path d="M9 13.6c.2 1.3 1.3 2 2.7 1.9 1.7-.1 2.6-1.2 2.6-3.6 0-2-1.2-3-2.8-3-1.2 0-2.2.7-2.2 1.8 0 1 .8 1.6 1.8 1.6"/>',
			'YouTube'   => '<rect x="2" y="5" width="20" height="14" rx="4"/><path d="m10 9 5 3-5 3z"/>',
			'TikTok'    => '<path d="M14 3v11a4 4 0 1 1-4-4"/><path d="M14 3a5 5 0 0 0 5 5"/>',
			'Pinterest' => '<circle cx="12" cy="12" r="9"/><path d="M11 8c3-1 6 1 5 4s-4 3-5 1M10 13l-2 8"/>',
		);
		$out = '<nav class="social-icons" aria-label="' . esc_attr( Scout_Cards_Settings::get()['name'] . ' on social media' ) . '">';
		foreach ( Scout_Cards_Settings::socials() as $label => $url ) {
			$out .= '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener" aria-label="' . esc_attr( $label ) . '">'
				. '<svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
				. ( $icons[ $label ] ?? '' ) . '</svg></a>';
		}
		return $out . '</nav>';
	}

	private static function icon( $name ) {
		return Scout_Cards_Render::icon( $name );
	}

	private static function qr_src() {
		return SCOUT_CARDS_URL . 'assets/qrcode.min.js?ver=' . SCOUT_CARDS_VERSION;
	}

	/** Share row: the native share sheet where there is one, and a QR code drawn in the browser. */
	private static function share( $url, $label ) {
		?>
    <div class="bc-share">
      <button type="button" class="bc-share-btn" data-share-url="<?php echo esc_url( $url ); ?>" data-share-title="<?php echo esc_attr( $label ); ?>"><?php echo self::icon( 'share' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>Share this card</button>
      <details class="bc-qr">
        <summary>Show QR code</summary>
        <div class="bc-qr-code" data-qr-url="<?php echo esc_url( $url ); ?>" role="img" aria-label="QR code that opens <?php echo esc_attr( $url ); ?>"></div>
        <p>Hold this up and have them scan it with their phone camera.</p>
      </details>
    </div>
    <script src="<?php echo esc_url( self::qr_src() ); ?>"></script>
    <script>
    (function(){var b=document.querySelector('.bc-share-btn');if(b){
      b.addEventListener('click',function(){var u=b.dataset.shareUrl,t=b.dataset.shareTitle;
        if(navigator.share){navigator.share({title:t,url:u}).catch(function(){});return;}
        if(navigator.clipboard){navigator.clipboard.writeText(u).then(function(){b.lastChild.textContent='Link copied';setTimeout(function(){b.lastChild.textContent='Share this card';},2000);});}
      });}
      var box=document.querySelector('.bc-qr-code');
      if(box&&window.qrcode){var q=qrcode(0,'M');q.addData(box.dataset.qrUrl);q.make();box.innerHTML=q.createSvgTag({cellSize:6,margin:3,scalable:true});}
    })();
    </script>
<?php
	}

	/** The Spotlight under a card: a panel with a heading or note, then one link row. */
	private static function spot( $spot, $person = null ) {
		if ( ! $spot ) {
			return;
		}
		$note = '' !== $spot['heading'] . $spot['text'];
		echo '<section class="bc-spot' . ( $note ? ' is-note' : '' ) . '"' . ( '' !== $spot['heading'] ? ' aria-labelledby="bc-spot-h"' : '' ) . '>';
		if ( $note && $person ) {
			echo '<p class="bc-spot-eyebrow"><span class="bolt" aria-hidden="true"></span>A note from ' . esc_html( $person['first'] ) . '</p>';
		}
		if ( '' !== $spot['heading'] ) {
			echo '<h2 id="bc-spot-h">' . esc_html( $spot['heading'] ) . '</h2>';
		}
		$paras = array_values( array_filter( array_map( 'trim', preg_split( '/\n\s*\n/', $spot['text'] ) ) ) );
		foreach ( $paras as $i => $para ) {
			echo '<p class="' . ( 0 === $i && count( $paras ) > 1 ? 'bc-spot-lead' : 'bc-spot-text' ) . '">' . esc_html( $para ) . '</p>';
		}
		if ( $note && $person ) {
			echo '<p class="bc-spot-sign">' . ( $person['photo'] ? '<img src="' . esc_url( $person['photo'] ) . '" width="36" height="36" alt="" loading="lazy">' : '' )
				. '<span><strong>' . esc_html( $person['name'] ) . '</strong>' . ( $person['title'] ? '<span>' . esc_html( $person['title'] ) . '</span>' : '' ) . '</span></p>';
		}
		if ( '' !== $spot['label'] && '' !== $spot['href'] ) {
			$host = $spot['external'] ? preg_replace( '/^www\./', '', (string) wp_parse_url( $spot['href'], PHP_URL_HOST ) ) : '';
			echo '<a class="bc-person bc-spot-link" href="' . esc_url( $spot['href'] ) . '"' . ( $spot['external'] ? ' rel="noopener"' : '' ) . '>'
				. '<span class="bc-spot-mark" aria-hidden="true"><span class="bolt"></span></span>'
				. '<span class="bc-person-body">' . ( $host ? '<span class="bc-spot-host">' . esc_html( $host ) . '</span>' : '' ) . '<strong>' . esc_html( $spot['label'] ) . '</strong>'
				. ( '' !== $spot['sub'] ? '<span class="bc-person-pick">' . esc_html( $spot['sub'] ) . '</span>' : '' )
				. '</span>' . self::icon( 'arrow' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</section>';
	}

	private static function short_name( array $p ) {
		return 'team' === $p['key'] ? $p['name'] : ( $p['first'] ? $p['first'] : $p['name'] );
	}

	/** A card page: the team card, or a person's (with their photo across the top when they have one). */
	public static function card_page( $who ) {
		$s       = Scout_Cards_Settings::get();
		$people  = self::people();
		$p       = $people[ $who ];
		$is_team = 'team' === $who;
		$brand   = $people['team']['name'];
		$org     = Scout_Cards_Settings::org( $s );
		$GLOBALS['scout_seo'] = array(
			'title'     => $is_team ? $brand . ' | Digital Business Card' : $p['name'] . ' | ' . $brand,
			'desc'      => $is_team
				? 'Save ' . $brand . ' to your phone, then reach the right person directly. ' . $people['team']['intro']
				: $p['name'] . ( $p['title'] ? ', ' . $p['title'] : '' ) . ' at ' . $brand . '. Save the contact, call, text, or email.',
			'canonical' => $p['url'],
			'robots'    => 'noindex, follow',
			'og_image'  => $p['photo'],
		);
		$tel   = 'tel:' . self::tel( $p, $s );
		$sms   = 'sms:' . self::tel( $p, $s );
		$phone = '' !== (string) ( $p['phone'] ?? '' ) ? $p['phone'] : $s['phone'];
		$email = '' !== (string) $p['email'] ? $p['email'] : $s['email'];
		$city  = $s['city'] . ', ' . $s['region'];
		$hero  = ! $is_team && $p['photo_id'] ? (string) wp_get_attachment_image_url( (int) $p['photo_id'], 'large' ) : '';
		if ( ! $is_team && '' === $hero ) {
			$hero = $p['photo'];
		}
		$book = function_exists( 'scout_booking_url' ) ? scout_booking_url() : home_url( '/contact/' );
		$host = preg_replace( '#^www\.#', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		self::shell_open( 'bc-page bc-' . $who . ( $hero ? ' bc-has-hero' : '' ), $GLOBALS['scout_seo']['title'] );
		?>
  <?php if ( $hero ) : ?>
  <section class="bc-card bc-card-hero" aria-labelledby="bc-name" data-scout-view="card_view" data-scout-label="<?php echo esc_attr( $p['name'] ); ?>">
    <div class="bc-hero">
      <img src="<?php echo esc_url( $hero ); ?>" width="480" height="340" alt="<?php echo esc_attr( $p['name'] ); ?>" fetchpriority="high">
      <a class="brand bc-hero-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>"><span class="bolt"></span><?php echo esc_html( preg_replace( '/\s+Media.*$/i', '', $brand ) ); ?></a>
    </div>
    <div class="bc-hero-body">
      <h1 id="bc-name"><?php echo esc_html( $p['name'] ); ?></h1>
      <p class="bc-role"><?php echo esc_html( trim( ( $p['title'] ? $p['title'] . ' · ' : '' ) . $brand ) ); ?></p>

      <?php if ( ! isset( $p['show']['save'] ) || $p['show']['save'] ) : ?><a class="btn btn-primary bc-save" href="<?php echo esc_url( $p['save'] ); ?>" download="<?php echo esc_attr( $p['file'] ); ?>.vcf" data-scout-track="card_save_contact" data-scout-label="<?php echo esc_attr( $p['name'] ); ?>">Save my contact</a><?php endif; ?>

      <div class="bc-actions bc-actions-3">
        <a href="<?php echo esc_url( $tel, array( 'tel' ) ); ?>" data-scout-track="card_call" data-scout-label="<?php echo esc_attr( $p['name'] ); ?>"><?php echo self::icon( 'call' ); // phpcs:ignore ?><span>Call</span></a>
        <a href="<?php echo esc_url( 'mailto:' . $email, array( 'mailto' ) ); ?>" data-scout-track="card_email" data-scout-label="<?php echo esc_attr( $p['name'] ); ?>"><?php echo self::icon( 'email' ); // phpcs:ignore ?><span>Email</span></a>
        <a href="<?php echo esc_url( $book ); ?>" data-scout-track="card_book" data-scout-label="<?php echo esc_attr( $p['name'] ); ?>"><?php echo self::icon( 'book' ); // phpcs:ignore ?><span>Book</span></a>
      </div>

      <?php if ( $p['intro'] ) : ?><p class="bc-intro"><?php echo esc_html( $p['intro'] ); ?></p><?php endif; ?>

      <dl class="bc-details">
        <div><dt>Phone</dt><dd><a href="<?php echo esc_url( $tel, array( 'tel' ) ); ?>"><?php echo esc_html( $phone ); ?></a></dd></div>
        <div><dt>Email</dt><dd><a href="<?php echo esc_url( 'mailto:' . $email, array( 'mailto' ) ); ?>"><?php echo esc_html( $email ); ?></a></dd></div>
        <div><dt>Web</dt><dd><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( $host ); ?></a></dd></div>
      </dl>
    </div>
  </section>
  <?php else : ?>
  <section class="bc-card" aria-labelledby="bc-name">
    <?php if ( $is_team ) : ?>
      <?php if ( $p['photo'] ) : ?><img class="bc-avatar" src="<?php echo esc_url( $p['photo'] ); ?>" width="112" height="112" alt="<?php echo esc_attr( $p['name'] ); ?>"><?php else : ?><div class="bc-avatar bc-avatar-mark"><span class="bolt"></span></div><?php endif; ?>
      <p class="eyebrow"><?php echo esc_html( $org ); ?></p>
      <h1 id="bc-name"><?php echo esc_html( $p['name'] ); ?></h1>
    <?php else : ?>
      <?php if ( $p['photo'] ) : ?><img class="bc-avatar" src="<?php echo esc_url( $p['photo'] ); ?>" width="112" height="112" alt="<?php echo esc_attr( $p['name'] ); ?>"><?php endif; ?>
      <p class="eyebrow"><?php echo esc_html( $org ); ?></p>
      <h1 id="bc-name"><?php echo esc_html( $p['name'] ); ?></h1>
      <?php if ( $p['title'] ) : ?><p class="bc-title"><?php echo esc_html( $p['title'] ); ?></p><?php endif; ?>
    <?php endif; ?>
    <p class="bc-intro"><?php echo esc_html( $p['intro'] ); ?></p>

    <?php if ( ! isset( $p['show']['save'] ) || $p['show']['save'] ) : ?><a class="btn btn-primary bc-save" href="<?php echo esc_url( $p['save'] ); ?>" download="<?php echo esc_attr( $p['file'] ); ?>.vcf"><?php echo self::icon( 'save' ); // phpcs:ignore ?>Save contact</a><?php endif; ?>

    <div class="bc-actions">
      <a href="<?php echo esc_url( $tel, array( 'tel' ) ); ?>"><?php echo self::icon( 'call' ); // phpcs:ignore ?><span>Call</span></a>
      <a href="<?php echo esc_url( $sms, array( 'sms' ) ); ?>"><?php echo self::icon( 'text' ); // phpcs:ignore ?><span>Text</span></a>
      <a href="<?php echo esc_url( 'mailto:' . $email, array( 'mailto' ) ); ?>"><?php echo self::icon( 'email' ); // phpcs:ignore ?><span>Email</span></a>
      <a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo self::icon( 'web' ); // phpcs:ignore ?><span>Website</span></a>
    </div>

    <dl class="bc-details">
      <div><dt>Phone</dt><dd><a href="<?php echo esc_url( $tel, array( 'tel' ) ); ?>"><?php echo esc_html( $phone ); ?></a></dd></div>
      <div><dt>Email</dt><dd><a href="<?php echo esc_url( 'mailto:' . $email, array( 'mailto' ) ); ?>"><?php echo esc_html( $email ); ?></a></dd></div>
      <div><dt>Based in</dt><dd><?php echo esc_html( $city ); ?></dd></div>
    </dl>
  </section>

  <?php endif; ?>

<?php
		$others = array_diff_key( $people, array( 'team' => 1 ) );
		if ( ! $is_team ) {
			self::spot( $p['spot'], $p );
		}
		?>
  <?php if ( $is_team && $others ) : ?>
  <section class="bc-pick" aria-labelledby="bc-pick-h">
    <h2 id="bc-pick-h"><?php echo esc_html( $p['pick_heading'] ); ?></h2>
    <?php foreach ( $others as $q ) : ?>
      <a class="bc-person" href="<?php echo esc_url( $q['url'] ); ?>">
        <?php if ( $q['photo'] ) : ?><img src="<?php echo esc_url( $q['photo'] ); ?>" width="64" height="64" alt="" loading="lazy"><?php endif; ?>
        <span class="bc-person-body">
          <strong><?php echo esc_html( $q['name'] ); ?></strong>
          <span class="bc-person-pick"><?php echo esc_html( $q['pick'] ); ?></span>
          <span class="bc-tags"><?php foreach ( $q['tags'] as $t ) { echo '<span>' . esc_html( $t ) . '</span>'; } ?></span>
        </span>
        <?php echo self::icon( 'arrow' ); // phpcs:ignore ?>
      </a>
    <?php endforeach; ?>
  </section>
  <?php self::spot( $p['spot'] ); ?>
  <a class="bc-backlink" href="<?php echo esc_url( home_url( '/' . $s['links_base'] . '/' ) ); ?>" data-scout-track="card_links_click">See all our links and latest posts</a>
  <?php elseif ( ! $is_team ) : ?>
  <section class="bc-pick">
    <a class="bc-backlink" href="<?php echo esc_url( home_url( '/' . $s['links_base'] . '/' ) ); ?>" data-scout-track="card_links_click">See all our links and latest posts</a>
    <a class="bc-backlink" href="<?php echo esc_url( Scout_Cards_Settings::card_url() ); ?>">See the full <?php echo esc_html( $brand ); ?> team card</a>
    <?php if ( ! $hero ) : // the hero card already has a Book button ?><a class="btn btn-outline bc-start" href="<?php echo esc_url( home_url( '/contact/' ) ); ?>">Start a project with us</a><?php endif; ?>
  </section>
  <?php endif; ?>

<?php
		self::share( $p['url'], $p['name'] . ' | ' . $brand );
		self::shell_close();
	}

	/** Share mode: the card owner's screen, a big QR code and one-tap share buttons. */
	public static function share_page( $who ) {
		$people = self::people();
		$p      = $people[ $who ];
		$brand  = $people['team']['name'];
		$GLOBALS['scout_seo'] = array(
			'title'     => 'Share: ' . $p['name'],
			'desc'      => 'team' === $who
				? 'Save ' . $brand . ' to your phone, then reach the right person directly. ' . $people['team']['intro']
				: $p['name'] . ( $p['title'] ? ', ' . $p['title'] : '' ) . ' at ' . $brand . '. Save the contact, call, text, or email.',
			'canonical' => $p['share'],
			'robots'    => 'noindex, follow',
			'og_image'  => $p['photo'],
		);
		$u       = $p['url'];
		$msg     = 'Here is my contact card: ' . $u;
		$buttons = array(
			array( 'Copy link', '#', 'copy' ),
			array( 'Text', 'sms:?&body=' . rawurlencode( $msg ), 'text' ),
			array( 'Email', 'mailto:?subject=' . rawurlencode( $p['name'] . ' contact card' ) . '&body=' . rawurlencode( $msg ), 'email' ),
			array( 'WhatsApp', 'https://wa.me/?text=' . rawurlencode( $msg ), 'share' ),
			array( 'LinkedIn', 'https://www.linkedin.com/sharing/share-offsite/?url=' . rawurlencode( $u ), 'share' ),
		);
		self::shell_open(
			'bc-page bc-share-page bc-' . $who,
			$GLOBALS['scout_seo']['title'],
			array(
				'manifest' => $p['share'] . 'manifest/',
				'short'    => self::short_name( $p ),
			)
		);
		?>
  <section class="bc-card bc-sharemode" aria-labelledby="bc-name">
    <p class="eyebrow">Scan to save my contact</p>
    <h1 id="bc-name"><?php echo esc_html( $p['name'] ); ?></h1>
    <div class="bc-qr-big" data-qr-url="<?php echo esc_url( $u ); ?>" role="img" aria-label="QR code that opens <?php echo esc_attr( $u ); ?>"></div>
    <p class="bc-intro">Point your phone's camera at the code to open the card and save it.</p>
  </section>
  <nav class="bc-sharelist" aria-label="Share this card">
    <?php foreach ( $buttons as $b ) : ?>
      <a class="bc-link" href="<?php echo esc_url( $b[1], array( 'https', 'sms', 'mailto' ) ); ?>"<?php echo 'copy' === $b[2] ? ' data-copy="' . esc_url( $u ) . '"' : ''; ?><?php echo 0 === strpos( $b[1], 'https' ) ? ' target="_blank" rel="noopener"' : ''; ?>>
        <span><strong><?php echo esc_html( $b[0] ); ?></strong></span><?php echo self::icon( 'share' === $b[2] ? 'arrow' : $b[2] ); // phpcs:ignore ?>
      </a>
    <?php endforeach; ?>
  </nav>
  <p class="bc-sharetip">Tip: add this page to your home screen. On iPhone, tap Share, then Add to Home Screen. It opens like an app with your code ready.</p>
  <a class="bc-backlink" href="<?php echo esc_url( $u ); ?>">See the card as others see it</a>
  <script src="<?php echo esc_url( self::qr_src() ); ?>"></script>
  <script>
  (function(){var box=document.querySelector('.bc-qr-big');
    if(box&&window.qrcode){var q=qrcode(0,'M');q.addData(box.dataset.qrUrl);q.make();box.innerHTML=q.createSvgTag({cellSize:8,margin:2,scalable:true});}
    var c=document.querySelector('[data-copy]');if(c)c.addEventListener('click',function(e){e.preventDefault();
      if(navigator.clipboard)navigator.clipboard.writeText(c.dataset.copy).then(function(){c.querySelector('strong').textContent='Link copied';setTimeout(function(){c.querySelector('strong').textContent='Copy link';},2000);});});
  })();
  </script>
<?php
		self::shell_close();
	}

	/** The link in bio: the cards row, the link pills, and the three newest posts when switched on. */
	public static function links_page() {
		$s      = Scout_Cards_Settings::get();
		$people = self::people();
		$team   = $people['team'];
		$GLOBALS['scout_seo'] = array(
			'title'     => $team['name'] . ' | Links',
			'desc'      => $s['links_intro'],
			'canonical' => home_url( '/' . $s['links_base'] . '/' ),
			'robots'    => 'noindex, follow',
		);
		self::shell_open( 'bc-page bc-links', $GLOBALS['scout_seo']['title'] );
		$links = array();
		foreach ( (array) $s['links'] as $l ) {
			if ( empty( $l['label'] ) || empty( $l['url'] ) ) {
				continue;
			}
			$links[] = array( 'label' => $l['label'], 'url' => Scout_Cards_Settings::href( $l['url'] ), 'external' => ! empty( $l['external'] ) );
		}
		$posts = array();
		if ( $s['links_latest'] ) {
			foreach ( get_posts( array( 'numberposts' => 3, 'post_status' => 'publish', 'ignore_sticky_posts' => true ) ) as $post ) {
				$cats  = get_the_category( $post->ID );
				$cat   = ( $cats && 'uncategorized' !== $cats[0]->slug ) ? $cats[0]->name : '';
				$pal   = function_exists( 'scout_cat_palette' ) ? scout_cat_palette( $cat ) : array( '#0E8FE6', '#0A78C2' );
				$posts[] = array(
					'title' => get_the_title( $post ),
					'url'   => get_permalink( $post ),
					'cat'   => $cat,
					'light' => $pal[0],
					'base'  => $pal[1],
					'date'  => get_the_date( 'M j', $post ),
					'thumb' => (string) get_the_post_thumbnail_url( $post, 'thumbnail' ),
				);
			}
		}
		?>
  <header class="bc-links-head">
    <?php if ( $team['photo'] ) : ?><img class="bc-links-avatar" src="<?php echo esc_url( $team['photo'] ); ?>" width="80" height="80" alt=""><?php else : ?><div class="bc-links-avatar bc-avatar-mark"><span class="bolt"></span></div><?php endif; ?>
    <h1 id="bc-name"><?php echo esc_html( $team['name'] ); ?></h1>
    <?php if ( $s['links_intro'] ) : ?><p><?php echo esc_html( $s['links_intro'] ); ?></p><?php endif; ?>
  </header>

  <nav class="bc-cards-row" aria-label="Our digital business cards">
    <p class="eyebrow">Save our contact</p>
    <div>
      <?php foreach ( $people as $k => $q ) : if ( 'team' === $k ) { continue; } ?>
        <a class="bc-card-chip" href="<?php echo esc_url( $q['url'] ); ?>" data-scout-track="links_card_click" data-scout-label="<?php echo esc_attr( $q['name'] ); ?>"><?php if ( $q['photo'] ) : ?><img src="<?php echo esc_url( $q['photo'] ); ?>" width="28" height="28" alt=""><?php endif; ?><span><?php echo esc_html( $q['first'] ? $q['first'] : $q['name'] ); ?></span></a>
      <?php endforeach; ?>
      <a class="bc-card-chip" href="<?php echo esc_url( $team['url'] ); ?>" data-scout-track="links_card_click" data-scout-label="Team"><span class="bc-card-chip-mark"><span class="bolt"></span></span><span>Team</span></a>
    </div>
  </nav>

  <nav class="bc-pills" aria-label="<?php echo esc_attr( $team['name'] ); ?> links">
    <?php foreach ( $links as $i => $l ) : ?>
      <a class="bc-pill<?php echo 0 === $i ? ' is-primary' : ''; ?>" data-scout-track="links_click" href="<?php echo esc_url( $l['url'] ); ?>"<?php echo $l['external'] ? ' target="_blank" rel="noopener"' : ''; ?>><?php echo esc_html( $l['label'] ); ?></a>
    <?php endforeach; ?>
  </nav>

  <?php if ( $posts ) : ?>
  <section class="bc-latest" aria-labelledby="bc-latest-h">
    <div class="bc-latest-head"><h2 id="bc-latest-h" class="eyebrow">Latest from the blog</h2></div>
    <?php foreach ( $posts as $q ) : ?>
      <a class="bc-post" style="--c:<?php echo esc_attr( $q['light'] ); ?>;--c2:<?php echo esc_attr( $q['base'] ); ?>" data-scout-track="links_post_click" data-scout-label="<?php echo esc_attr( $q['title'] ); ?>" href="<?php echo esc_url( $q['url'] ); ?>">
        <span class="bc-post-thumb"<?php echo $q['thumb'] ? ' style="background-image:url(\'' . esc_url( $q['thumb'] ) . '\')"' : ''; ?>><?php if ( ! $q['thumb'] ) : ?><span class="bolt"></span><?php endif; ?></span>
        <span class="bc-post-body">
          <span class="bc-post-meta"><?php echo esc_html( trim( $q['cat'] . ( $q['cat'] ? ' · ' : '' ) . $q['date'] ) ); ?></span>
          <strong><?php echo esc_html( $q['title'] ); ?></strong>
        </span>
      </a>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>
<?php
		self::shell_close();
	}

	/** The home-screen manifest, with the theme's card icons when it has them. */
	public static function manifest( array $p ) {
		$icons = array();
		foreach ( array( 192, 512 ) as $size ) {
			$u = self::theme_img( 'card-icon-' . $size . '.png' );
			$u = $u ? $u : (string) get_site_icon_url( $size );
			if ( $u ) {
				$icons[] = array( 'src' => $u, 'sizes' => $size . 'x' . $size, 'type' => 'image/png' );
			}
		}
		nocache_headers();
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo wp_json_encode(
			array(
				'name'             => $p['name'] . ' card',
				'short_name'       => self::short_name( $p ),
				'start_url'        => $p['share'],
				'scope'            => $p['share'],
				'display'          => 'standalone',
				'background_color' => '#0B0B0B',
				'theme_color'      => '#0B0B0B',
				'icons'            => $icons,
			),
			JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
		);
	}
}
