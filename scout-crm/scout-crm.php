<?php
/**
 * Plugin Name:       Scout CRM Sync
 * Description:       Keeps one customer record per person in FluentCRM, on your own site. Everyone who buys a ticket, signs up, volunteers, applies as a vendor or presenter, or joins the membership is added and tagged by what they did, the event and its region, ready for your own emails and segments. New people are added as transactional, never as marketing subscribers.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Scout Media & Consulting
 * License:           GPL-2.0-or-later
 * Text Domain:       scout-crm
 * Update URI:        https://github.com/joyrmac/Scout-Plugins/scout-crm
 *
 * @package Scout_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCOUT_CRM_VERSION', '0.1.0' );

// Self-updating from the Scout Plugins releases (see includes/class-scout-plugin-updater.php).
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-scout-plugin-updater.php';
		Scout_Plugin_Updater::boot( __DIR__ . '/scout-crm.php', 'scout-crm' );
	},
	0
);

require_once __DIR__ . '/includes/class-scout-crm.php';
require_once __DIR__ . '/includes/class-scout-crm-backfill.php';

// FluentCRM, Scout Events and Scout Members all load after this file.
add_action( 'plugins_loaded', array( 'Scout_CRM', 'boot' ), 20 );
add_action( 'plugins_loaded', array( 'Scout_CRM_Backfill', 'boot' ), 20 );
