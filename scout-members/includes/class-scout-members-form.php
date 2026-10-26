<?php
/**
 * The application questions, written by the owner in Settings, one per line:
 *
 *   type | Question | extra
 *
 * A star after the type makes it required ("textarea* | Why ..."). Types:
 *
 *   heading   a section title; extra is a statement under it (the title
 *             may be left empty for a statement alone)
 *   text, email, tel, date, url, textarea
 *             a plain answer; extra is help text under the field
 *   yesno     Yes or No; extra labels a short "if yes" field under it
 *   choice    one of several; extra lists the options, separated by ";"
 *   agree     a statement to agree with; extra is the tick-box words
 *   check     one tick box (a policy, the newsletter)
 *   address   street, city, state, ZIP and country
 *
 * Every application also asks first name, last name and email, and, when they
 * are set, the plan, the minimum age and the community agreements.
 *
 * @package Scout_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Members_Form {

	const TYPES = array( 'heading', 'text', 'email', 'tel', 'date', 'url', 'textarea', 'yesno', 'choice', 'agree', 'check', 'address' );

	/** Used when the owner has not written their own questions. */
	public static function default_questions(): string {
		return implode(
			"\n",
			array(
				'textarea* | ' . __( 'What draws you to this community?', 'scout-members' ),
				'text | ' . __( 'How did you hear about us?', 'scout-members' ),
				'textarea | ' . __( 'Anything else you would like us to know?', 'scout-members' ),
			)
		);
	}

	/**
	 * The questions, parsed.
	 *
	 * @return array<int,array{key:string,type:string,required:bool,label:string,extra:string}>
	 */
	public static function questions(): array {
		$src = trim( (string) get_option( 'scout_members_questions', '' ) );
		return self::parse( '' !== $src ? $src : self::default_questions() );
	}

	/**
	 * Questions written one per line as "type | Question | extra". The
	 * application and member invitations both use it.
	 *
	 * @return array<int,array{key:string,type:string,required:bool,label:string,extra:string}>
	 */
	public static function parse( string $src ): array {
		$out = array();
		$n   = 0;
		foreach ( preg_split( '/\R/', $src ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line, 3 ) );
			$type  = strtolower( rtrim( $parts[0], '*' ) );
			// A heading may be a statement alone, with no title.
			if ( count( $parts ) < 2 || ( '' === $parts[1] && ! ( 'heading' === $type && '' !== ( $parts[2] ?? '' ) ) ) ) {
				continue;
			}
			$required = '*' === substr( $parts[0], -1 );
			if ( ! in_array( $type, self::TYPES, true ) ) {
				continue;
			}
			++$n;
			$out[] = array(
				'key'      => 'q' . $n,
				'type'     => $type,
				'required' => $required && ! in_array( $type, array( 'heading' ), true ),
				'label'    => $parts[1],
				'extra'    => $parts[2] ?? '',
			);
		}
		return $out;
	}

	/* ---------- rendering ---------- */

	public static function render( WP_User $user ): string {
		$s     = Scout_Members::settings();
		$plans = Scout_Members::plans();
		ob_start();
		?>
		<form class="sm-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="sm-apply">
			<input type="hidden" name="action" value="scout_members_apply">
			<?php echo self::guard_fields(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?>
			<fieldset class="sm-group">
				<legend><?php esc_html_e( 'About you', 'scout-members' ); ?></legend>
				<div class="sm-row">
					<?php
					echo self::input( 'first', __( 'First name', 'scout-members' ), 'text', true, $user->exists() ? (string) $user->first_name : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo self::input( 'last', __( 'Last name', 'scout-members' ), 'text', true, $user->exists() ? (string) $user->last_name : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</div>
				<?php echo self::input( 'email', __( 'Email', 'scout-members' ), 'email', true, $user->exists() ? $user->user_email : '', __( 'Your sign-in, and where we write to you. Never shown on the site.', 'scout-members' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php if ( $plans ) : ?>
					<div class="sm-field" role="radiogroup" aria-labelledby="sm-plan-label">
						<span class="sm-label" id="sm-plan-label"><?php esc_html_e( 'The membership you are interested in', 'scout-members' ); ?></span>
						<?php foreach ( $plans as $slug => $p ) : ?>
							<label class="sm-choice"><input type="radio" name="m[plan]" value="<?php echo esc_attr( $slug ); ?>"> <?php echo esc_html( Scout_Members::plan_label( $slug ) ); ?></label>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</fieldset>
			<?php
			$open = false;
			foreach ( self::questions() as $q ) {
				if ( 'heading' === $q['type'] ) {
					if ( $open ) {
						echo '</fieldset>';
					}
					echo '<fieldset class="sm-group">' . ( '' !== $q['label'] ? '<legend>' . esc_html( $q['label'] ) . '</legend>' : '' );
					if ( '' !== $q['extra'] ) {
						echo '<p class="sm-statement">' . esc_html( $q['extra'] ) . '</p>';
					}
					$open = true;
					continue;
				}
				if ( ! $open ) {
					echo '<fieldset class="sm-group"><legend>' . esc_html__( 'A little more about you', 'scout-members' ) . '</legend>';
					$open = true;
				}
				echo self::question( $q ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			}
			if ( $open ) {
				echo '</fieldset>';
			}
			?>
			<?php if ( $s['min_age'] > 0 || '' !== $s['agreements_url'] ) : ?>
				<fieldset class="sm-group">
					<legend><?php esc_html_e( 'Before you send it', 'scout-members' ); ?></legend>
					<?php if ( $s['min_age'] > 0 ) : ?>
						<?php /* translators: %d: minimum age. */ ?>
						<p class="sm-check"><label><input type="checkbox" name="m[age]" value="1" required> <?php echo esc_html( sprintf( __( 'I am %d or older.', 'scout-members' ), $s['min_age'] ) ); ?></label></p>
					<?php endif; ?>
					<?php if ( '' !== $s['agreements_url'] ) : ?>
						<p class="sm-check"><label><input type="checkbox" name="m[agreements]" value="1" required> <?php esc_html_e( 'I have read the community agreements and will follow them.', 'scout-members' ); ?> <a href="<?php echo esc_url( $s['agreements_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Read them', 'scout-members' ); ?></a></label></p>
					<?php endif; ?>
				</fieldset>
			<?php endif; ?>
			<p><button type="submit" class="sm-button wp-element-button"><?php esc_html_e( 'Send my application', 'scout-members' ); ?></button></p>
			<p class="sm-help"><?php esc_html_e( 'A person reads every application. Your answers are private and only the people who review applications see them.', 'scout-members' ); ?></p>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	public static function guard_fields(): string {
		return wp_nonce_field( 'scout_members_apply', '_sm_nonce', true, false )
			. '<input type="hidden" name="sm_t" value="' . esc_attr( (string) time() ) . '">'
			. '<p class="sm-hp" aria-hidden="true"><label for="sm-website2">' . esc_html__( 'Leave this empty', 'scout-members' ) . '</label><input type="text" id="sm-website2" name="sm_website2" tabindex="-1" autocomplete="off"></p>';
	}

	/** One labelled input. */
	public static function input( string $key, string $label, string $type = 'text', bool $required = false, string $value = '', string $help = '' ): string {
		$id   = 'sm-' . $key;
		$auto = array(
			'first' => 'given-name',
			'last'  => 'family-name',
			'email' => 'email',
		);
		$out  = '<p class="sm-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . ( $required ? ' <span class="sm-req" aria-hidden="true">*</span>' : '' ) . '</label>';
		if ( 'textarea' === $type ) {
			$out .= '<textarea id="' . esc_attr( $id ) . '" name="m[' . esc_attr( $key ) . ']" rows="4"' . ( $required ? ' required' : '' ) . '>' . esc_textarea( $value ) . '</textarea>';
		} else {
			$out .= '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="m[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '"' . ( isset( $auto[ $key ] ) ? ' autocomplete="' . esc_attr( $auto[ $key ] ) . '"' : '' ) . ( $required ? ' required' : '' ) . '>';
		}
		if ( '' !== $help ) {
			$out .= '<span class="sm-help">' . esc_html( $help ) . '</span>';
		}
		return $out . '</p>';
	}

	public static function question( array $q ): string {
		$k   = $q['key'];
		$req = $q['required'];
		switch ( $q['type'] ) {
			case 'yesno':
				$out = '<div class="sm-field" role="radiogroup" aria-labelledby="sm-' . esc_attr( $k ) . '-label"><span class="sm-label" id="sm-' . esc_attr( $k ) . '-label">' . esc_html( $q['label'] ) . ( $req ? ' <span class="sm-req" aria-hidden="true">*</span>' : '' ) . '</span>';
				foreach ( array( __( 'Yes', 'scout-members' ), __( 'No', 'scout-members' ) ) as $opt ) {
					$out .= '<label class="sm-choice"><input type="radio" name="m[' . esc_attr( $k ) . ']" value="' . esc_attr( $opt ) . '"' . ( $req ? ' required' : '' ) . '> ' . esc_html( $opt ) . '</label>';
				}
				$out .= '</div>';
				if ( '' !== $q['extra'] ) {
					$out .= self::input( $k . '_more', $q['extra'] );
				}
				return $out;
			case 'choice':
				$opts = array_filter( array_map( 'trim', explode( ';', $q['extra'] ) ) );
				$out  = '<div class="sm-field" role="radiogroup" aria-labelledby="sm-' . esc_attr( $k ) . '-label"><span class="sm-label" id="sm-' . esc_attr( $k ) . '-label">' . esc_html( $q['label'] ) . ( $req ? ' <span class="sm-req" aria-hidden="true">*</span>' : '' ) . '</span>';
				foreach ( $opts as $opt ) {
					$out .= '<label class="sm-choice"><input type="radio" name="m[' . esc_attr( $k ) . ']" value="' . esc_attr( $opt ) . '"' . ( $req ? ' required' : '' ) . '> ' . esc_html( $opt ) . '</label>';
				}
				return $out . '</div>';
			case 'agree':
				return '<div class="sm-field sm-agree"><p class="sm-statement">' . esc_html( $q['label'] ) . '</p><p class="sm-check"><label><input type="checkbox" name="m[' . esc_attr( $k ) . ']" value="1"' . ( $req ? ' required' : '' ) . '> ' . esc_html( '' !== $q['extra'] ? $q['extra'] : __( 'I agree.', 'scout-members' ) ) . '</label></p></div>';
			case 'check':
				return '<p class="sm-check"><label><input type="checkbox" name="m[' . esc_attr( $k ) . ']" value="1"' . ( $req ? ' required' : '' ) . '> ' . esc_html( $q['label'] ) . '</label></p>';
			case 'address':
				$out = '<fieldset class="sm-address"><legend class="sm-label">' . esc_html( $q['label'] ) . ( $req ? ' <span class="sm-req" aria-hidden="true">*</span>' : '' ) . '</legend>';
				$out .= self::input( $k . '_line1', __( 'Street address', 'scout-members' ), 'text', $req );
				$out .= self::input( $k . '_line2', __( 'Apartment, suite or unit', 'scout-members' ) );
				$out .= '<div class="sm-row">' . self::input( $k . '_city', __( 'City', 'scout-members' ), 'text', $req ) . self::input( $k . '_state', __( 'State', 'scout-members' ), 'text', $req ) . self::input( $k . '_zip', __( 'ZIP code', 'scout-members' ), 'text', $req ) . '</div>';
				$out .= self::input( $k . '_country', __( 'Country', 'scout-members' ) );
				return $out . '</fieldset>';
			default:
				return self::input( $k, $q['label'], $q['type'], $req, '', $q['extra'] );
		}
	}

	/* ---------- reading what was sent ---------- */

	/**
	 * The answers, as question => answer, in the order asked. Null when a
	 * required answer is missing.
	 *
	 * @param array<string,mixed> $m The posted m[] array, unslashed.
	 */
	public static function answers( array $m, ?array $questions = null ): ?array {
		$out = array();
		foreach ( $questions ?? self::questions() as $q ) {
			$k = $q['key'];
			if ( 'heading' === $q['type'] ) {
				continue;
			}
			if ( 'address' === $q['type'] ) {
				$parts = array();
				foreach ( array( 'line1', 'line2', 'city', 'state', 'zip', 'country' ) as $p ) {
					$v = sanitize_text_field( (string) ( $m[ $k . '_' . $p ] ?? '' ) );
					if ( '' !== $v ) {
						$parts[ $p ] = $v;
					}
				}
				if ( $q['required'] && ( empty( $parts['line1'] ) || empty( $parts['city'] ) || empty( $parts['state'] ) || empty( $parts['zip'] ) ) ) {
					return null;
				}
				$out[ $q['label'] ] = implode( ', ', $parts );
				continue;
			}
			if ( in_array( $q['type'], array( 'agree', 'check' ), true ) ) {
				$yes = ! empty( $m[ $k ] );
				if ( $q['required'] && ! $yes ) {
					return null;
				}
				$label              = 'agree' === $q['type'] ? ( '' !== $q['extra'] ? $q['extra'] : __( 'I agree.', 'scout-members' ) ) : $q['label'];
				$out[ $label ] = $yes ? __( 'Yes', 'scout-members' ) : __( 'No', 'scout-members' );
				continue;
			}
			$v = 'textarea' === $q['type'] ? sanitize_textarea_field( (string) ( $m[ $k ] ?? '' ) ) : sanitize_text_field( (string) ( $m[ $k ] ?? '' ) );
			if ( 'url' === $q['type'] && '' !== $v ) {
				$v = esc_url_raw( $v, array( 'http', 'https' ) );
			}
			if ( 'email' === $q['type'] && '' !== $v ) {
				$v = is_email( $v ) ? sanitize_email( $v ) : '';
			}
			if ( 'date' === $q['type'] ) {
				$v = Scout_Members::clean_date( $v );
			}
			if ( in_array( $q['type'], array( 'choice', 'yesno' ), true ) ) {
				$allowed = 'yesno' === $q['type'] ? array( __( 'Yes', 'scout-members' ), __( 'No', 'scout-members' ) ) : array_filter( array_map( 'trim', explode( ';', $q['extra'] ) ) );
				$v       = in_array( $v, $allowed, true ) ? $v : '';
			}
			if ( $q['required'] && '' === $v ) {
				return null;
			}
			$out[ $q['label'] ] = $v;
			if ( 'yesno' === $q['type'] && '' !== $q['extra'] ) {
				$more = sanitize_text_field( (string) ( $m[ $k . '_more' ] ?? '' ) );
				if ( '' !== $more ) {
					$out[ $q['extra'] ] = $more;
				}
			}
		}
		return $out;
	}
}
