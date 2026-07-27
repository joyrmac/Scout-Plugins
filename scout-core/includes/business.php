<?php
/**
 * The site's business identity: NAP, hours, geo, and profile URLs. Stored as
 * one option, `scout_business`, because it is site identity, not per-post data.
 *
 * scout-schema reads this to build LocalBusiness JSON-LD; templates can pull
 * any value through the `scout/business` block binding. One source of truth for
 * the NAP that has to match the Google Business Profile byte-for-byte.
 *
 * @package Scout_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Core_Business {

	const OPTION = 'scout_business';

	/** @return array<string,string> field key => label */
	public static function fields() {
		return array(
			'name'          => 'Business name',
			'legal_name'    => 'Legal name',
			'business_type' => 'Schema type (e.g. LocalBusiness, Campground, LegalService)',
			'phone'         => 'Phone',
			'email'         => 'Email',
			'street'        => 'Street address',
			'city'          => 'City',
			'region'        => 'State / region',
			'postal'        => 'Postal code',
			'country'       => 'Country code (e.g. US)',
			'price_range'   => 'Price range (e.g. $$)',
			'latitude'      => 'Latitude',
			'longitude'     => 'Longitude',
			'hours'         => 'Hours (one line per day)',
			'same_as'       => 'Profile URLs (one per line: Google, Facebook, Yelp...)',
		);
	}

	/** @return array<string,string> */
	public static function get() {
		$defaults = array_fill_keys( array_keys( self::fields() ), '' );
		return wp_parse_args( (array) get_option( self::OPTION, array() ), $defaults );
	}

	public static function admin_menu() {
		add_options_page( 'Scout Business', 'Scout Business', 'manage_options', 'scout-business', array( __CLASS__, 'render_page' ) );
	}

	public static function register_settings() {
		register_setting(
			'scout_business_group',
			self::OPTION,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) )
		);
	}

	public static function sanitize( $input ) {
		$input = (array) $input;
		$clean = array();
		foreach ( self::fields() as $key => $label ) {
			$value = isset( $input[ $key ] ) ? $input[ $key ] : '';
			if ( in_array( $key, array( 'hours', 'same_as' ), true ) ) {
				$clean[ $key ] = sanitize_textarea_field( $value );
			} elseif ( 'email' === $key ) {
				$clean[ $key ] = sanitize_email( $value );
			} else {
				$clean[ $key ] = sanitize_text_field( $value );
			}
		}
		return $clean;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$values = self::get();
		?>
		<div class="wrap">
			<h1>Scout Business Identity</h1>
			<p>One source of truth for NAP and profiles. scout-schema renders this as LocalBusiness JSON-LD; templates can bind to it with the <code>scout/business</code> source.</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'scout_business_group' ); ?>
				<table class="form-table" role="presentation"><tbody>
				<?php foreach ( self::fields() as $key => $label ) : ?>
					<tr>
						<th scope="row"><label for="scout-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
						<td>
						<?php if ( in_array( $key, array( 'hours', 'same_as' ), true ) ) : ?>
							<textarea id="scout-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::OPTION ); ?>[<?php echo esc_attr( $key ); ?>]" rows="5" class="large-text"><?php echo esc_textarea( $values[ $key ] ); ?></textarea>
						<?php else : ?>
							<input type="text" id="scout-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::OPTION ); ?>[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $values[ $key ] ); ?>" class="regular-text" />
						<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody></table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}

/**
 * Public getter for the business identity array. Used by scout-schema, the
 * `scout/business` binding, and anywhere a template needs the canonical NAP.
 *
 * @return array<string,string>
 */
function scout_core_business() {
	return Scout_Core_Business::get();
}
