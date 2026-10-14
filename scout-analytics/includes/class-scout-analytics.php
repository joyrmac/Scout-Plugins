<?php
/**
 * The front-end part: consent defaults, the tag, the cookie notice, and the
 * click reporting every Scout feature shares.
 *
 * Order matters. Google consent mode must be set before Tag Manager (or the
 * GA4 tag) loads, so the defaults print first in <head>, then the tag.
 *
 * Google Tag Manager is the standard on every Scout client site (Sean's call):
 * tags, conversions and the GA4 config live in the container, and this plugin
 * only loads it. A GA4 measurement ID on its own is the fallback for a site
 * with no container.
 *
 * Consent: "opt-out" (the US default we use) counts visits and shows a small
 * notice with a "No thanks" button; "opt-in" waits for a yes. A visitor's
 * browser sending Global Privacy Control counts as "no" either way. Ad signals
 * stay denied unless the site turns them on (only sites running paid ads).
 *
 * Clicks: any link with data-scout-track="event_name" (and optionally
 * data-scout-label) is reported, any element with data-scout-view="event_name"
 * reports once when the page loads, and scripts can call
 * window.scoutTrack( 'event_name', { link_label: '...' } ). With Tag Manager
 * the event goes into the dataLayer for the container's triggers; with only a
 * GA4 tag it is sent with gtag().
 *
 * Nothing loads on staging or local copies, or for people who edit the site.
 *
 * @package Scout_Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Analytics {

	const OPTION = 'scout_analytics';
	const COOKIE = 'scout_consent';

	public static function boot(): void {
		add_action( 'wp_head', array( __CLASS__, 'head' ), 1 );
		add_action( 'wp_body_open', array( __CLASS__, 'body_open' ), 1 );
		add_action( 'wp_footer', array( __CLASS__, 'footer' ), 20 );
		add_shortcode( 'scout_privacy_choices', array( __CLASS__, 'choices_link' ) );
	}

	/** @return array{gtm:string, ga4:string, mode:string, ads:bool, skip_editors:bool, notice:string, privacy_page:int, late:bool} */
	public static function settings(): array {
		$s = (array) get_option( self::OPTION, array() );
		return array(
			'gtm'          => strtoupper( trim( (string) ( $s['gtm'] ?? '' ) ) ),
			'ga4'          => strtoupper( trim( (string) ( $s['ga4'] ?? '' ) ) ),
			'mode'         => 'optin' === ( $s['mode'] ?? '' ) ? 'optin' : 'optout',
			'ads'          => ! empty( $s['ads'] ),
			'skip_editors' => ! isset( $s['skip_editors'] ) || ! empty( $s['skip_editors'] ),
			'notice'       => (string) ( $s['notice'] ?? '' ),
			'privacy_page' => (int) ( $s['privacy_page'] ?? (int) get_option( 'wp_page_for_privacy_policy' ) ),
			// On by default: the tag loads once the page has shown, so it never slows the first screen.
			'late'         => ! isset( $s['late'] ) || ! empty( $s['late'] ),
		);
	}

	public static function valid_gtm( string $id ): bool {
		return (bool) preg_match( '/^GTM-[A-Z0-9]{4,12}$/', $id );
	}

	public static function valid_ga4( string $id ): bool {
		return (bool) preg_match( '/^G-[A-Z0-9]{4,14}$/', $id );
	}

	/** Which tag this site loads: 'gtm', 'ga4', or '' for none. */
	public static function tag(): string {
		$s = self::settings();
		if ( self::valid_gtm( $s['gtm'] ) ) {
			return 'gtm';
		}
		return self::valid_ga4( $s['ga4'] ) ? 'ga4' : '';
	}

	/** The live, public site, with a visitor (not someone editing the site). */
	public static function should_load(): bool {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$live = '1' === (string) get_option( 'blog_public' )
			&& 'production' === wp_get_environment_type()
			&& ! preg_match( '/(wpengine\.com|wpenginepowered\.com|staging|\.local$|\.test$|localhost|^127\.|^10\.|^192\.168\.)/i', $host );
		$live = (bool) apply_filters( 'scout_analytics_is_live', $live );
		if ( ! $live || '' === self::tag() || is_admin() ) {
			return false;
		}
		return ! ( self::settings()['skip_editors'] && current_user_can( 'edit_posts' ) );
	}

	public static function default_notice( string $mode ): string {
		return 'optin' === $mode
			? __( 'May we count your visit? It shows us which pages help people. We never sell data or use it for ads.', 'scout-analytics' )
			: __( 'We count visits to see which pages help people. We never sell data or use it for ads.', 'scout-analytics' );
	}

	public static function head(): void {
		if ( ! self::should_load() ) {
			return;
		}
		$s   = self::settings();
		$cfg = array(
			'mode'   => $s['mode'],
			'ads'    => $s['ads'],
			'cookie' => self::COOKIE,
			'tag'    => self::tag(),
		);
		// Consent defaults first, before any Google tag.
		?>
<script id="scout-consent-default">
window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}
window.scoutConsentConfig=<?php echo wp_json_encode( $cfg ); ?>;
(function(c){var m=document.cookie.match(new RegExp('(?:^|; )'+c.cookie+'=(granted|denied)'));var gpc=navigator.globalPrivacyControl===true;
var a=m?m[1]:((c.mode==='optin'||gpc)?'denied':'granted');var ad=c.ads?a:'denied';
gtag('consent','default',{analytics_storage:a,ad_storage:ad,ad_user_data:ad,ad_personalization:ad,functionality_storage:'granted',security_storage:'granted',wait_for_update:500});
gtag('set','ads_data_redaction',ad==='denied');})(window.scoutConsentConfig);
</script>
		<?php
		if ( 'gtm' === self::tag() ) {
			$id = esc_js( $s['gtm'] );
			?>
<script>(function(w,d,s,l,i,late){function go(){if(w.scoutGtmLoaded)return;w.scoutGtmLoaded=1;w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);}
if(!late){go();return;}
/* After the page has shown: on load (plus a moment), or the first tap, scroll or key, whichever comes first. */
var ev=['pointerdown','keydown','scroll','touchstart'];function now(){ev.forEach(function(e){w.removeEventListener(e,now,{passive:true});});go();}
ev.forEach(function(e){w.addEventListener(e,now,{passive:true,once:true});});
function idle(){(w.requestIdleCallback||function(cb){setTimeout(cb,1500);})(go,{timeout:3000});}
if(d.readyState==='complete'){idle();}else{w.addEventListener('load',idle);}
})(window,document,'script','dataLayer','<?php echo $id; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_js above. ?>',<?php echo $s['late'] ? 'true' : 'false'; ?>);</script>
			<?php
		} else {
			$id = $s['ga4'];
			?>
<script async src="<?php echo esc_url( 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $id ) ); ?>"></script>
<script>gtag('js',new Date());gtag('config','<?php echo esc_js( $id ); ?>');</script>
			<?php
		}
	}

	/** Tag Manager's fallback for browsers with scripts off. */
	public static function body_open(): void {
		if ( 'gtm' !== self::tag() || ! self::should_load() ) {
			return;
		}
		$src = 'https://www.googletagmanager.com/ns.html?id=' . rawurlencode( self::settings()['gtm'] );
		echo '<noscript><iframe src="' . esc_url( $src ) . '" height="0" width="0" style="display:none;visibility:hidden" title="Google Tag Manager"></iframe></noscript>' . "\n";
	}

	/** The notice (hidden until the script decides to show it) and the script. */
	public static function footer(): void {
		if ( ! self::should_load() ) {
			return;
		}
		$s       = self::settings();
		$text    = '' !== trim( $s['notice'] ) ? $s['notice'] : self::default_notice( $s['mode'] );
		$privacy = $s['privacy_page'] ? get_permalink( $s['privacy_page'] ) : '';
		$yes     = 'optin' === $s['mode'] ? __( 'Allow', 'scout-analytics' ) : __( 'OK', 'scout-analytics' );
		?>
<div class="scout-consent" id="scout-consent" role="region" aria-label="<?php esc_attr_e( 'Cookie notice', 'scout-analytics' ); ?>" hidden>
	<p><?php echo esc_html( $text ); ?><?php if ( $privacy ) : ?> <a href="<?php echo esc_url( $privacy ); ?>"><?php esc_html_e( 'Privacy policy', 'scout-analytics' ); ?></a><?php endif; ?></p>
	<div class="scout-consent-actions">
		<button type="button" class="scout-consent-no" data-scout-consent="denied"><?php esc_html_e( 'No thanks', 'scout-analytics' ); ?></button>
		<button type="button" class="scout-consent-yes" data-scout-consent="granted"><?php echo esc_html( $yes ); ?></button>
	</div>
</div>
<style id="scout-consent-css"><?php echo self::css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static CSS. ?></style>
<script id="scout-consent-js"><?php echo self::js(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static JS. ?></script>
		<?php
	}

	/** [scout_privacy_choices]: a link that reopens the notice. */
	public static function choices_link( $atts = array() ): string {
		$atts = shortcode_atts( array( 'text' => __( 'Privacy choices', 'scout-analytics' ) ), $atts );
		return '<a href="#privacy-choices" data-scout-privacy>' . esc_html( $atts['text'] ) . '</a>';
	}

	private static function css(): string {
		return '.scout-consent{position:fixed;left:16px;right:16px;bottom:16px;z-index:9999;max-width:420px;padding:12px 14px;border-radius:18px;'
			. 'background:var(--surface,#161616);color:var(--text,#F5F5F7);border:1px solid var(--line,rgba(255,255,255,.1));'
			. 'box-shadow:0 18px 50px -12px rgba(0,0,0,.55);font:14px/1.45 var(--font-sans,"Hanken Grotesk",-apple-system,Helvetica,Arial,sans-serif)}'
			. '.scout-consent[hidden]{display:none}.scout-consent p{margin:0 0 10px;color:var(--text-2,#B5B5B8);text-wrap:pretty}'
			. '.scout-consent a{color:var(--accent-text,#5FB8F2)}.scout-consent-actions{display:flex;gap:8px;justify-content:flex-end}'
			. '.scout-consent button{font:inherit;font-weight:500;min-height:44px;padding:0 18px;border-radius:980px;cursor:pointer;border:1px solid var(--line,rgba(255,255,255,.1));background:transparent;color:var(--text,#F5F5F7)}'
			. '.scout-consent .scout-consent-yes{background:var(--accent,#0E8FE6);border-color:var(--accent,#0E8FE6);color:var(--on-accent,#fff)}'
			. '.scout-consent button:focus-visible{outline:2px solid var(--accent,#0E8FE6);outline-offset:2px}'
			. '@media (min-width:600px){.scout-consent{right:auto;left:24px;bottom:24px;padding:16px 18px;font-size:15px}}';
	}

	private static function js(): string {
		return <<<'JS'
(function(){var c=window.scoutConsentConfig||{};var box=document.getElementById('scout-consent');
function has(){return new RegExp('(?:^|; )'+c.cookie+'=(granted|denied)').test(document.cookie);}
function set(v){document.cookie=c.cookie+'='+v+'; Max-Age=15552000; Path=/; SameSite=Lax'+(location.protocol==='https:'?'; Secure':'');
var ad=c.ads?v:'denied';gtag('consent','update',{analytics_storage:v,ad_storage:ad,ad_user_data:ad,ad_personalization:ad});
window.dataLayer.push({event:'scout_consent_update',consent:v});if(box)box.hidden=true;}
function show(){if(box){box.hidden=false;var b=box.querySelector('.scout-consent-yes');if(b)b.focus({preventScroll:true});}}
if(box){box.addEventListener('click',function(e){var b=e.target.closest('[data-scout-consent]');if(b)set(b.getAttribute('data-scout-consent'));});
box.addEventListener('keydown',function(e){if(e.key==='Escape')box.hidden=true;});}
if(!has()&&navigator.globalPrivacyControl!==true&&box)box.hidden=false;
window.scoutTrack=function(name,params){params=params||{};if(c.tag==='gtm'){var o={event:name};for(var k in params)o[k]=params[k];window.dataLayer.push(o);}else{gtag('event',name,params);}};
[].forEach.call(document.querySelectorAll('[data-scout-view]'),function(v){window.scoutTrack(v.getAttribute('data-scout-view'),{link_label:v.getAttribute('data-scout-label')||document.title});});
document.addEventListener('click',function(e){var p=e.target.closest('[data-scout-privacy],a[href="#privacy-choices"]');if(p){e.preventDefault();show();return;}
var t=e.target.closest('[data-scout-track]');if(t){var params={link_label:t.getAttribute('data-scout-label')||(t.textContent||'').trim().slice(0,80)};if(t.href)params.link_url=t.href;window.scoutTrack(t.getAttribute('data-scout-track'),params);}});})();
JS;
	}
}
