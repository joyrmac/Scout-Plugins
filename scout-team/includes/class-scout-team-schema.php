<?php
/**
 * Person schema for each team member.
 *
 * With Scout Core active, the Person (and a ProfilePage wrapping it) joins
 * Scout Core's single @graph through its public `scout_schema_graph` filter,
 * and worksFor points at the business entity, home_url( '/#business' ). Blog
 * posts by a linked author get that Person as their author. Without Scout Core,
 * the plugin prints its own small graph on profile pages.
 *
 * Military service has no schema.org property, so it shows on the page only.
 * The Person is built from Scout_Team::public_profile(): card only contact
 * never goes in, and neither does a credential line without a source.
 *
 * @package Scout_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Team_Schema {

	public static function boot(): void {
		add_filter( 'scout_schema_graph', array( __CLASS__, 'extend_graph' ) );
		add_action( 'wp_head', array( __CLASS__, 'standalone' ), 21 );
	}

	/** Is Scout Core printing the graph? */
	private static function core_graph(): bool {
		return class_exists( 'Scout_Schema' ) && function_exists( 'scout_core_business' );
	}

	public static function person_id( int $post_id ): string {
		return get_permalink( $post_id ) . '#person';
	}

	/**
	 * The Person node.
	 *
	 * @param int $post_id Team member post ID.
	 */
	public static function person( int $post_id ): array {
		$p    = Scout_Team::public_profile( Scout_Team::profile( $post_id ) );
		$node = array(
			'@type' => 'Person',
			'@id'   => self::person_id( $post_id ),
			'name'  => html_entity_decode( wp_strip_all_tags( get_the_title( $post_id ) ), ENT_QUOTES, 'UTF-8' ),
		);
		// No profile page for visitors: no address to point at.
		if ( Scout_Team::shows( $p, 'bio' ) ) {
			$node['url'] = get_permalink( $post_id );
		}
		if ( '' !== $p['role'] ) {
			$node['jobTitle'] = $p['role'];
		}
		if ( '' !== $p['bio'] ) {
			$node['description'] = $p['bio'];
		}
		if ( has_post_thumbnail( $post_id ) ) {
			$image = wp_get_attachment_image_url( (int) get_post_thumbnail_id( $post_id ), 'full' );
			if ( $image ) {
				$node['image'] = $image;
			}
		}
		if ( '' !== $p['email'] ) {
			$node['email'] = $p['email'];
		}
		if ( '' !== $p['phone'] ) {
			$node['telephone'] = $p['phone'];
		}

		$node['worksFor'] = self::core_graph()
			? array( '@id' => home_url( '/#business' ) )
			: array(
				'@type' => 'Organization',
				'name'  => html_entity_decode( wp_strip_all_tags( get_bloginfo( 'name' ) ), ENT_QUOTES, 'UTF-8' ),
				'url'   => home_url( '/' ),
			);

		$credentials = array();
		$alumni      = array();
		foreach ( $p['education'] as $row ) {
			$school   = array(
				'@type' => 'EducationalOrganization',
				'name'  => $row['school'],
			);
			$alumni[] = $school;
			if ( '' !== $row['degree'] ) {
				$credential = array(
					'@type'              => 'EducationalOccupationalCredential',
					'name'               => $row['degree'],
					'credentialCategory' => 'degree',
					'recognizedBy'       => $school,
				);
				if ( '' !== $row['year'] ) {
					$credential['dateCreated'] = $row['year'];
				}
				$credentials[] = $credential;
			}
		}
		if ( $alumni ) {
			$node['alumniOf'] = $alumni;
		}

		if ( Scout_Team::is_law() && $p['attorney'] ) {
			foreach ( $p['bar'] as $row ) {
				$license = array(
					'@type'              => 'EducationalOccupationalCredential',
					/* translators: %s: state or court. */
					'name'               => sprintf( __( 'Bar admission, %s', 'scout-team' ), $row['state'] ),
					'credentialCategory' => 'license',
					'validIn'            => array(
						'@type' => 'AdministrativeArea',
						'name'  => $row['state'],
					),
				);
				if ( '' !== $row['year'] ) {
					$license['dateCreated'] = $row['year'];
				}
				if ( '' !== $row['number'] ) {
					$license['identifier'] = $row['number'];
				}
				if ( '' !== $row['url'] ) {
					$license['url'] = $row['url'];
				}
				$credentials[] = $license;
			}
		}
		if ( $credentials ) {
			$node['hasCredential'] = $credentials;
		}

		if ( $p['memberships'] ) {
			$node['memberOf'] = array_map(
				static function ( $row ) {
					return array(
						'@type' => 'Organization',
						'name'  => $row['name'],
					);
				},
				$p['memberships']
			);
		}

		$awards = array();
		foreach ( $p['awards'] as $row ) {
			$awards[] = implode( ', ', array_filter( array( $row['name'], $row['issuer'], $row['year'] ) ) );
		}
		if ( $awards ) {
			$node['award'] = $awards;
		}

		$langs = Scout_Team::languages( $p );
		if ( $langs ) {
			$node['knowsLanguage'] = $langs;
		}

		$same_as = $p['same_as'];
		foreach ( $p['bar'] as $row ) {
			if ( Scout_Team::is_law() && $p['attorney'] && '' !== $row['url'] ) {
				$same_as[] = $row['url'];
			}
		}
		if ( $same_as ) {
			$node['sameAs'] = array_values( array_unique( $same_as ) );
		}

		return (array) apply_filters( 'scout_team_person_schema', $node, $post_id );
	}

	private static function profile_page( int $post_id ): array {
		return array(
			'@type'        => 'ProfilePage',
			'@id'          => get_permalink( $post_id ) . '#profilepage',
			'url'          => get_permalink( $post_id ),
			'name'         => html_entity_decode( wp_strip_all_tags( get_the_title( $post_id ) ), ENT_QUOTES, 'UTF-8' ),
			'dateModified' => get_the_modified_date( 'c', $post_id ),
			'mainEntity'   => array( '@id' => self::person_id( $post_id ) ),
		);
	}

	/**
	 * Add nodes to Scout Core's graph.
	 *
	 * @param array $graph Scout Core graph, keyed.
	 */
	public static function extend_graph( $graph ) {
		if ( ! is_array( $graph ) ) {
			return $graph;
		}
		if ( is_singular( Scout_Team::POST_TYPE ) ) {
			$id                       = (int) get_queried_object_id();
			$graph[ 'person_' . $id ]  = self::person( $id );
			$graph['scout_team_page'] = self::profile_page( $id );
			return $graph;
		}
		// A post's byline: the people picked in "Written by", or else the
		// profile linked to its author. The author is the full Person, once.
		if ( isset( $graph['article'] ) && is_singular( Scout_Team::byline_post_types() ) && Scout_Team::settings()['bylines'] ) {
			$graph = self::with_authors( $graph, Scout_Team::byline_people( (int) get_queried_object_id() ) );
		}
		return $graph;
	}

	/**
	 * Point the article's author at the Person node of each person, and add
	 * each Person to the graph under the same key the profile page uses, so a
	 * person is one record however many times they are named.
	 *
	 * @param array $graph   Scout Core graph, keyed, with an 'article' node.
	 * @param int[] $members Person post IDs, in byline order.
	 */
	public static function with_authors( array $graph, array $members ): array {
		if ( ! $members || ! isset( $graph['article'] ) ) {
			return $graph;
		}
		$refs = array();
		foreach ( $members as $member ) {
			$member = (int) $member;
			$refs[] = array( '@id' => self::person_id( $member ) );
			if ( ! isset( $graph[ 'person_' . $member ] ) ) {
				$graph[ 'person_' . $member ] = self::person( $member );
			}
		}
		$graph['article']['author'] = 1 === count( $refs ) ? $refs[0] : $refs;
		return $graph;
	}

	/** Without Scout Core: print the profile page's own graph. */
	public static function standalone(): void {
		if ( self::core_graph() || is_admin() || ! is_singular( Scout_Team::POST_TYPE ) ) {
			return;
		}
		$id   = (int) get_queried_object_id();
		$data = array(
			'@context' => 'https://schema.org',
			'@graph'   => array( self::person( $id ), self::profile_page( $id ) ),
		);
		echo "\n<script type=\"application/ld+json\">" . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . "</script>\n";
	}
}
