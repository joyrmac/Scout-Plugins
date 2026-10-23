<?php
/**
 * Template helpers for themes and other Scout features (the chat assistant
 * reading upcoming events, a theme placing the schedule itself).
 *
 * @package Scout_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'scout_events_upcoming' ) ) {
	/**
	 * Upcoming published events as plain data, soonest first.
	 *
	 * @param int $limit 0 for all.
	 * @return array<int,array{id:int,title:string,url:string,dates:string,times:string,price:string,tickets:string,ticket_url:string,status:string,event:array}>
	 */
	function scout_events_upcoming( int $limit = 0 ): array {
		$out = array();
		foreach ( Scout_Events::event_ids( 'upcoming', $limit ) as $id ) {
			$out[] = scout_event_data( $id );
		}
		return $out;
	}
}

if ( ! function_exists( 'scout_event_data' ) ) {
	/**
	 * One event as plain data with ready-made labels.
	 *
	 * @param int $id Event post ID.
	 */
	function scout_event_data( int $id ): array {
		$e = Scout_Events::event( $id );
		return array(
			'id'         => $id,
			'title'      => html_entity_decode( wp_strip_all_tags( get_the_title( $id ) ), ENT_QUOTES, 'UTF-8' ),
			'url'        => (string) get_permalink( $id ),
			'dates'      => Scout_Events::dates_label( $e ),
			'times'      => Scout_Events::times_label( $e ),
			'price'      => Scout_Events::price_label( $e ),
			'tickets'    => Scout_Events::availabilities()[ $e['availability'] ],
			'ticket_url' => Scout_Events::ticket_open( $e ) ? $e['ticket_url'] : '',
			'status'     => $e['status'],
			'event'      => $e,
		);
	}
}

if ( ! function_exists( 'scout_events_schedule_html' ) ) {
	/**
	 * The schedule as HTML, the same as [scout_schedule]. Already escaped.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $layout   'list' or 'grid'.
	 */
	function scout_events_schedule_html( int $event_id, string $layout = 'list' ): string {
		return Scout_Events_Front::schedule( $event_id, $layout );
	}
}
