<?php
/**
 * Plugin Name:       Scout Newsletter
 * Description:       Sends people who tick the newsletter box on your site to your email list in Beehiiv, tagged by where they joined: an event sign-up, a paid ticket, a membership, a booking or a form. Every agreement is saved with the date, the page and the words the person saw. Nobody is added without a ticked box, someone who unsubscribed is never added back, and an email service hiccup never stops a checkout or a form.
 * Version:           0.1.6
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Scout Media & Consulting
 * License:           GPL-2.0-or-later
 * Text Domain:       scout-newsletter
 * Update URI:        https://github.com/Scout-Media-Raleigh/Scout-Plugins/scout-newsletter
 *
 * @package Scout_Newsletter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCOUT_NEWSLETTER_VERSION', '0.1.6' );
define( 'SCOUT_NEWSLETTER_FILE', __FILE__ );
define( 'SCOUT_NEWSLETTER_URL', plugin_dir_url( __FILE__ ) );

// Self-updating from the Scout Plugins releases (see includes/class-scout-plugin-updater.php).
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-scout-plugin-updater.php';
		Scout_Plugin_Updater::boot( __DIR__ . '/scout-newsletter.php', 'scout-newsletter' );
	},
	0
);

require_once __DIR__ . '/includes/class-scout-admin-ui.php';
require_once __DIR__ . '/includes/class-scout-newsletter.php';
require_once __DIR__ . '/includes/class-scout-newsletter-store.php';
require_once __DIR__ . '/includes/class-scout-newsletter-beehiiv.php';
require_once __DIR__ . '/includes/class-scout-newsletter-queue.php';
require_once __DIR__ . '/includes/class-scout-newsletter-sources.php';
require_once __DIR__ . '/includes/class-scout-newsletter-form.php';
require_once __DIR__ . '/includes/class-scout-newsletter-admin.php';
require_once __DIR__ . '/includes/class-scout-newsletter-privacy.php';

Scout_Newsletter::boot();
Scout_Newsletter_Queue::boot();
Scout_Newsletter_Form::boot();
Scout_Newsletter_Admin::boot();
Scout_Newsletter_Privacy::boot();
// Scout Events, Scout Members, Gravity Forms and WooCommerce load after this file.
add_action( 'plugins_loaded', array( 'Scout_Newsletter_Sources', 'boot' ), 20 );

register_activation_hook(
	__FILE__,
	static function () {
		Scout_Newsletter_Store::install();
		Scout_Newsletter_Queue::sweep_schedule();
	}
);
register_deactivation_hook( __FILE__, array( 'Scout_Newsletter_Queue', 'unschedule' ) );
