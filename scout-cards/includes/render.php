<?php
/**
 * The pages. Each is a full, phone-first HTML document with no site nav. The
 * theme's stylesheet still loads (wp_head), so the card picks up the site's own
 * font, while every card component is styled by assets/cards.css under `.scc`.
 * Pages are noindex: they are for sharing, not for search.
 *
 * @package Scout_Cards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Cards_Render {

	/** Open the document. $meta: title, desc, canonical, app (manifest URL for share mode). */
	private static function open( array $meta, $body_class ) {
		$s = Scout_Cards_Settings::get();

		// These pages print their own title, canonical, and robots tags.
		remove_action( 'wp_head', '_wp_render_title_tag', 1 );
		remove_action( 'wp_head', 'rel_canonical' );
		if ( class_exists( 'Scout_SEO_Head' ) ) {
			remove_action( 'wp_head', array( 'Scout_SEO_Head', 'output' ), 1 );
		}
		add_filter(
			'wp_robots',
			function ( $robots ) {
				$robots['noindex'] = true;
				$robots['follow']  = true;
				unset( $robots['max-image-preview'] );
				return $robots;
			},
			99
		);
		wp_enqueue_style( 'scout-cards', SCOUT_CARDS_URL . 'assets/cards.css', array(), SCOUT_CARDS_VERSION );

		$bg     = 'light' === $s['mode'] ? '#F5F5F7' : '#0B0B0B';
		$accent = sanitize_hex_color( $s['accent'] ) ? sanitize_hex_color( $s['accent'] ) : '#0E8FE6';
		$font   = $s['font'] ? $s['font'] : 'inherit';
		?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $meta['title'] ); ?></title>
<meta name="description" content="<?php echo esc_attr( $meta['desc'] ); ?>">
<link rel="canonical" href="<?php echo esc_url( $meta['canonical'] ); ?>">
<meta property="og:title" content="<?php echo esc_attr( $meta['title'] ); ?>">
<meta property="og:description" content="<?php echo esc_attr( $meta['desc'] ); ?>">
<meta property="og:url" content="<?php echo esc_url( $meta['canonical'] ); ?>">
<?php if ( ! empty( $meta['image'] ) ) : ?>
<meta property="og:image" content="<?php echo esc_url( $meta['image'] ); ?>">
<?php endif; ?>
<meta name="theme-color" content="<?php echo esc_attr( $bg ); ?>">
<?php if ( ! empty( $meta['app'] ) ) : ?>
<link rel="manifest" href="<?php echo esc_url( $meta['app'] ); ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?php echo esc_attr( $meta['short'] ); ?>">
<?php if ( get_site_icon_url( 180 ) ) : ?>
<link rel="apple-touch-icon" href="<?php echo esc_url( get_site_icon_url( 180 ) ); ?>">
<?php endif; ?>
<?php endif; ?>
<style>.scc{--scc-accent:<?php echo esc_html( $accent ); ?>;--scc-font:<?php echo esc_html( $font ); ?>}</style>
<?php wp_head(); ?>
</head>
<body <?php body_class( 'scc-page ' . $body_class ); ?>>
<main class="scc" data-mode="<?php echo esc_attr( 'light' === $s['mode'] ? 'light' : 'dark' ); ?>">
  <div class="scc-wrap">
  <a class="scc-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( $s['name'] ); ?></a>
		<?php
	}

	private static function close() {
		$s    = Scout_Cards_Settings::get();
		$host = preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		?>
  <?php echo self::social_links(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?>
  <p class="scc-foot"><?php echo $s['footer'] ? esc_html( $s['footer'] ) . ' &middot; ' : ''; ?><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( $host ); ?></a></p>
  </div>
</main>
<script src="<?php echo esc_url( SCOUT_CARDS_URL . 'assets/qrcode.min.js?ver=' . SCOUT_CARDS_VERSION ); ?>"></script>
<script>
(function(){
  document.querySelectorAll('[data-qr-url]').forEach(function(box){
    function draw(){ if(box.firstChild||!window.qrcode)return; var q=qrcode(0,'M');q.addData(box.dataset.qrUrl);q.make();box.innerHTML=q.createSvgTag({cellSize:8,margin:2,scalable:true}); }
    var d=box.closest('details'); if(d){ d.addEventListener('toggle',draw); } else { draw(); }
  });
  document.querySelectorAll('[data-share-url]').forEach(function(b){ b.addEventListener('click',function(e){ e.preventDefault(); var u=b.dataset.shareUrl;
    if(navigator.share){navigator.share({title:b.dataset.shareTitle,url:u}).catch(function(){});return;}
    if(navigator.clipboard){navigator.clipboard.writeText(u).then(function(){var t=b.querySelector('span');var o=t.textContent;t.textContent='Link copied';setTimeout(function(){t.textContent=o;},2000);});}
  });});
  document.querySelectorAll('[data-copy]').forEach(function(c){ c.addEventListener('click',function(e){ e.preventDefault();
    if(navigator.clipboard){navigator.clipboard.writeText(c.dataset.copy).then(function(){var t=c.querySelector('strong');var o=t.textContent;t.textContent='Link copied';setTimeout(function(){t.textContent=o;},2000);});}
  });});
})();
</script>
<?php wp_footer(); ?>
</body>
</html>
		<?php
	}

	public static function icon( $name ) {
		$paths = array(
			'save'  => '<path d="M12 3v12m0 0 5-5m-5 5-5-5M5 21h14"/>',
			'call'  => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
			'text'  => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>',
			'email' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
			'web'   => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
			'share' => '<path d="M12 3v13M7 8l5-5 5 5M5 14v5a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-5"/>',
			'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
			'copy'  => '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>',
		);
		return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[ $name ] . '</svg>';
	}

	private static function social_links() {
		$icons = array(
			'Facebook'  => '<path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>',
			'Instagram' => '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.2" cy="6.8" r="1.1" fill="currentColor" stroke="none"/>',
			'LinkedIn'  => '<path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-4 0v7h-4v-7a6 6 0 0 1 6-6z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/>',
			'X'         => '<path d="M4 4l16 16M20 4 4 20"/>',
			'Threads'   => '<path d="M16.5 11.4c1.7.7 2.6 2 2.7 3.7.1 2.6-2.2 4.9-6.7 4.9h-.1c-5 0-7.4-3.2-7.4-8.4S7.3 3.2 12.3 3.2c2.9 0 5 1.1 6.2 3.1"/><path d="M9 13.6c.2 1.3 1.3 2 2.7 1.9 1.7-.1 2.6-1.2 2.6-3.6 0-2-1.2-3-2.8-3-1.2 0-2.2.7-2.2 1.8 0 1 .8 1.6 1.8 1.6"/>',
			'YouTube'   => '<rect x="2" y="5" width="20" height="14" rx="4"/><path d="m10 9 5 3-5 3z"/>',
			'TikTok'    => '<path d="M14 3v11a4 4 0 1 1-4-4"/><path d="M14 3a5 5 0 0 0 5 5"/>',
			'Pinterest' => '<circle cx="12" cy="12" r="9"/><path d="M11 8c3-1 6 1 5 4s-4 3-5 1M10 13l-2 8"/>',
		);
		$socials = Scout_Cards_Settings::socials();
		if ( ! $socials ) {
			return '';
		}
		$out = '<nav class="scc-social" aria-label="' . esc_attr( Scout_Cards_Settings::get()['name'] ) . ' on social media">';
		foreach ( $socials as $label => $url ) {
			$out .= '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener" aria-label="' . esc_attr( $label ) . '"><svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $icons[ $label ] . '</svg></a>';
		}
		return $out . '</nav>';
	}

	private static function avatar( array $p, $size = 112, $class = 'scc-avatar' ) {
		if ( $p['photo'] ) {
			return '<img class="' . esc_attr( $class ) . '" src="' . esc_url( $p['photo'] ) . '" width="' . (int) $size . '" height="' . (int) $size . '" alt="' . esc_attr( $p['name'] ) . '">';
		}
		$initial = function_exists( 'mb_substr' ) ? mb_substr( $p['name'], 0, 1 ) : substr( $p['name'], 0, 1 );
		return '<div class="' . esc_attr( $class ) . ' scc-avatar-mark" aria-hidden="true">' . esc_html( strtoupper( $initial ) ) . '</div>';
	}

	/**
	 * The spotlight block under a card. With a heading or note it renders as a
	 * panel; on a person's card it is signed by them. A blank line in the note
	 * splits it, and the first paragraph becomes the lead.
	 */
	private static function spot( $spot, $person = null ) {
		if ( ! $spot ) {
			return;
		}
		$note = '' !== $spot['heading'] . $spot['text'];
		echo '<section class="scc-spot' . ( $note ? ' is-note' : '' ) . '"' . ( '' !== $spot['heading'] ? ' aria-labelledby="scc-spot-h"' : '' ) . '>';
		if ( $note && $person ) {
			echo '<p class="scc-spot-eyebrow">A note from ' . esc_html( $person['first'] ) . '</p>';
		}
		if ( '' !== $spot['heading'] ) {
			echo '<h2 id="scc-spot-h">' . esc_html( $spot['heading'] ) . '</h2>';
		}
		$paras = array_values( array_filter( array_map( 'trim', preg_split( '/\n\s*\n/', $spot['text'] ) ) ) );
		foreach ( $paras as $i => $para ) {
			echo '<p class="' . ( 0 === $i && count( $paras ) > 1 ? 'scc-spot-lead' : 'scc-spot-text' ) . '">' . esc_html( $para ) . '</p>';
		}
		if ( $note && $person ) {
			echo '<p class="scc-spot-sign">' . ( $person['photo'] ? '<img src="' . esc_url( $person['photo'] ) . '" width="36" height="36" alt="" loading="lazy">' : '' )
				. '<span><strong>' . esc_html( $person['name'] ) . '</strong>' . ( $person['title'] ? '<span>' . esc_html( $person['title'] ) . '</span>' : '' ) . '</span></p>';
		}
		if ( '' !== $spot['label'] && '' !== $spot['href'] ) {
			$host = 0 === strpos( $spot['url'], '/' ) ? '' : preg_replace( '/^www\./', '', (string) wp_parse_url( $spot['href'], PHP_URL_HOST ) );
			echo '<a class="scc-link scc-spot-link" href="' . esc_url( $spot['href'] ) . '"><span>' . ( $host ? '<em class="scc-spot-host">' . esc_html( $host ) . '</em>' : '' ) . '<strong>' . esc_html( $spot['label'] ) . '</strong>'
				. ( '' !== $spot['sub'] ? '<span>' . esc_html( $spot['sub'] ) . '</span>' : '' )
				. '</span>' . self::icon( 'arrow' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icon() returns fixed SVG.
		}
		echo '</section>';
	}

	public static function card_page( array $p, array $people ) {
		$s       = Scout_Cards_Settings::get();
		$is_team = 'team' === $p['key'];
		$tel     = Scout_Cards_Settings::phone_e164( $s['phone'] );
		$place   = trim( $s['city'] . ( $s['region'] ? ', ' . $s['region'] : '' ) );
		self::open(
			array(
				'title'     => $is_team ? $s['name'] . ' | Contact card' : $p['name'] . ' | ' . $s['name'],
				'desc'      => $is_team ? 'Save ' . $s['name'] . ' to your phone, then reach the right person directly.' : $p['name'] . ( $p['title'] ? ', ' . $p['title'] : '' ) . ' at ' . $s['name'] . '. Save the contact, call, text, or email.',
				'canonical' => $p['url'],
				'image'     => $p['photo'],
			),
			'scc-card scc-' . $p['key']
		);
		?>
  <section class="scc-card" aria-labelledby="scc-name">
    <?php echo self::avatar( $p ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    <?php if ( ! $is_team ) : ?><p class="scc-eyebrow"><?php echo esc_html( $s['name'] ); ?></p><?php endif; ?>
    <h1 id="scc-name"><?php echo esc_html( $p['name'] ); ?></h1>
    <?php if ( $p['title'] ) : ?><p class="scc-title"><?php echo esc_html( $p['title'] ); ?></p><?php endif; ?>
    <?php if ( $p['intro'] ) : ?><p class="scc-intro"><?php echo esc_html( $p['intro'] ); ?></p><?php endif; ?>
    <a class="scc-btn" href="<?php echo esc_url( $p['save'] ); ?>" download="<?php echo esc_attr( $p['file'] ); ?>.vcf"><?php echo self::icon( 'save' ); // phpcs:ignore ?><span>Save contact</span></a>
    <div class="scc-actions">
      <?php if ( $tel ) : ?>
      <a href="<?php echo esc_url( 'tel:' . $tel, array( 'tel' ) ); ?>"><?php echo self::icon( 'call' ); // phpcs:ignore ?><span>Call</span></a>
      <a href="<?php echo esc_url( 'sms:' . $tel, array( 'sms' ) ); ?>"><?php echo self::icon( 'text' ); // phpcs:ignore ?><span>Text</span></a>
      <?php endif; ?>
      <?php if ( $p['email'] ) : ?><a href="<?php echo esc_url( 'mailto:' . $p['email'], array( 'mailto' ) ); ?>"><?php echo self::icon( 'email' ); // phpcs:ignore ?><span>Email</span></a><?php endif; ?>
      <a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo self::icon( 'web' ); // phpcs:ignore ?><span>Website</span></a>
    </div>
    <dl class="scc-details">
      <?php if ( $tel ) : ?><div><dt>Phone</dt><dd><a href="<?php echo esc_url( 'tel:' . $tel, array( 'tel' ) ); ?>"><?php echo esc_html( $s['phone'] ); ?></a></dd></div><?php endif; ?>
      <?php if ( $p['email'] ) : ?><div><dt>Email</dt><dd><a href="<?php echo esc_url( 'mailto:' . $p['email'], array( 'mailto' ) ); ?>"><?php echo esc_html( $p['email'] ); ?></a></dd></div><?php endif; ?>
      <?php if ( $place ) : ?><div><dt>Based in</dt><dd><?php echo esc_html( $place ); ?></dd></div><?php endif; ?>
    </dl>
  </section>
		<?php
		$others = array_diff_key( $people, array( 'team' => 1 ) );
		if ( ! $is_team ) {
			self::spot( $p['spot'], $p );
		}
		if ( $is_team && $others ) :
			?>
  <section class="scc-pick" aria-labelledby="scc-pick-h">
    <h2 id="scc-pick-h"><?php echo esc_html( $s['pick_heading'] ); ?></h2>
			<?php foreach ( $others as $q ) : ?>
    <a class="scc-person" href="<?php echo esc_url( $q['url'] ); ?>">
      <?php echo self::avatar( $q, 64, 'scc-person-photo' ); // phpcs:ignore ?>
      <span class="scc-person-body">
        <strong><?php echo esc_html( $q['name'] ); ?></strong>
        <?php if ( $q['pick'] ) : ?><span class="scc-person-pick"><?php echo esc_html( $q['pick'] ); ?></span><?php endif; ?>
        <?php if ( $q['tags'] ) : ?><span class="scc-tags"><?php foreach ( $q['tags'] as $t ) { echo '<span>' . esc_html( $t ) . '</span>'; } ?></span><?php endif; ?>
      </span>
      <?php echo self::icon( 'arrow' ); // phpcs:ignore ?>
    </a>
			<?php endforeach; ?>
  </section>
		<?php endif; ?>
		<?php
		if ( $is_team ) {
			self::spot( $p['spot'] );
		}
		?>
		<?php if ( ! $is_team ) : ?>
  <a class="scc-backlink" href="<?php echo esc_url( Scout_Cards_Settings::card_url() ); ?>">See the full <?php echo esc_html( $s['name'] ); ?> card</a>
		<?php endif; ?>
  <div class="scc-share">
    <button type="button" class="scc-share-btn" data-share-url="<?php echo esc_url( $p['url'] ); ?>" data-share-title="<?php echo esc_attr( $p['name'] ); ?>"><?php echo self::icon( 'share' ); // phpcs:ignore ?><span>Share this card</span></button>
    <details class="scc-qr"><summary>Show QR code</summary><div class="scc-qr-code" data-qr-url="<?php echo esc_url( $p['url'] ); ?>" role="img" aria-label="QR code that opens this card"></div></details>
  </div>
		<?php
		self::close();
	}

	public static function share_page( array $p ) {
		$s   = Scout_Cards_Settings::get();
		$u   = $p['url'];
		$msg = 'Here is my contact card: ' . $u;
		self::open(
			array(
				'title'     => 'Share: ' . $p['name'],
				'desc'      => 'Share ' . $p['name'] . "'s contact card.",
				'canonical' => $p['share'],
				'app'       => $p['share'] . 'manifest/',
				'short'     => 'team' === $p['key'] ? $s['name'] : $p['first'],
			),
			'scc-sharemode scc-' . $p['key']
		);
		$buttons = array(
			array( 'Copy link', '', 'copy' ),
			array( 'Text', 'sms:?&body=' . rawurlencode( $msg ), 'text' ),
			array( 'Email', 'mailto:?subject=' . rawurlencode( $p['name'] . ' contact card' ) . '&body=' . rawurlencode( $msg ), 'email' ),
			array( 'WhatsApp', 'https://wa.me/?text=' . rawurlencode( $msg ), 'arrow' ),
			array( 'LinkedIn', 'https://www.linkedin.com/sharing/share-offsite/?url=' . rawurlencode( $u ), 'arrow' ),
		);
		?>
  <section class="scc-card scc-share-card" aria-labelledby="scc-name">
    <p class="scc-eyebrow">Scan to save my contact</p>
    <h1 id="scc-name"><?php echo esc_html( $p['name'] ); ?></h1>
    <div class="scc-qr-big" data-qr-url="<?php echo esc_url( $u ); ?>" role="img" aria-label="QR code that opens <?php echo esc_attr( $u ); ?>"></div>
    <p class="scc-intro">Point your phone's camera at the code to open the card and save it.</p>
  </section>
  <nav class="scc-linklist" aria-label="Share this card">
		<?php foreach ( $buttons as $b ) : ?>
    <a class="scc-link" href="<?php echo esc_url( $b[1] ? $b[1] : $u, array( 'https', 'http', 'sms', 'mailto' ) ); ?>"<?php echo 'copy' === $b[2] ? ' data-copy="' . esc_url( $u ) . '"' : ''; ?><?php echo 0 === strpos( $b[1], 'https' ) ? ' target="_blank" rel="noopener"' : ''; ?>><span><strong><?php echo esc_html( $b[0] ); ?></strong></span><?php echo self::icon( $b[2] ); // phpcs:ignore ?></a>
		<?php endforeach; ?>
  </nav>
  <p class="scc-tip">Tip: add this page to your home screen. On iPhone, tap Share, then Add to Home Screen. It opens like an app with your code ready.</p>
  <a class="scc-backlink" href="<?php echo esc_url( $u ); ?>">See the card as others see it</a>
		<?php
		self::close();
	}

	public static function links_page() {
		$s    = Scout_Cards_Settings::get();
		$team = Scout_Cards_Settings::people()['team'];
		self::open(
			array(
				'title'     => $s['name'] . ' | Links',
				'desc'      => $s['links_intro'],
				'canonical' => home_url( '/' . $s['links_base'] . '/' ),
				'image'     => $team['photo'],
			),
			'scc-links'
		);
		?>
  <section class="scc-card" aria-labelledby="scc-name">
    <?php echo self::avatar( $team ); // phpcs:ignore ?>
    <h1 id="scc-name"><?php echo esc_html( $s['name'] ); ?></h1>
    <?php if ( $s['links_intro'] ) : ?><p class="scc-intro scc-intro-last"><?php echo esc_html( $s['links_intro'] ); ?></p><?php endif; ?>
  </section>
  <nav class="scc-linklist" aria-label="<?php echo esc_attr( $s['name'] ); ?> links">
		<?php foreach ( Scout_Cards_Settings::links() as $l ) : ?>
    <a class="scc-link<?php echo $l['featured'] ? ' is-featured' : ''; ?>" href="<?php echo esc_url( $l['url'] ); ?>"<?php echo $l['external'] ? ' target="_blank" rel="noopener"' : ''; ?>>
      <span><strong><?php echo esc_html( $l['label'] ); ?></strong><?php if ( $l['sub'] ) : ?><span><?php echo esc_html( $l['sub'] ); ?></span><?php endif; ?></span>
      <?php echo self::icon( 'arrow' ); // phpcs:ignore ?>
    </a>
		<?php endforeach; ?>
  </nav>
		<?php
		self::close();
	}

	public static function manifest( array $p ) {
		$s     = Scout_Cards_Settings::get();
		$bg    = 'light' === $s['mode'] ? '#F5F5F7' : '#0B0B0B';
		$icons = array();
		foreach ( array( 192, 512 ) as $size ) {
			$u = get_site_icon_url( $size );
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
				'short_name'       => 'team' === $p['key'] ? $s['name'] : $p['first'],
				'start_url'        => $p['share'],
				'scope'            => $p['share'],
				'display'          => 'standalone',
				'background_color' => $bg,
				'theme_color'      => $bg,
				'icons'            => $icons,
			),
			JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
		);
	}
}
