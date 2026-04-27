<?php
/**
 * REST API for the Strava Stories widget.
 *
 * Namespace: strava-stories/v1
 *   GET  /activities — list recent Strava activities (presented for the widget).
 *   POST /blog       — create a WP draft from a chosen activity.
 *
 * @package StravaStories
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class Strava_Stories_Rest {

	public const NAMESPACE = 'strava-stories/v1';

	public static function boot(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/activities',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_activities' ),
				'permission_callback' => array( __CLASS__, 'can_use' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/blog',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_blog_draft' ),
				'permission_callback' => array( __CLASS__, 'can_use' ),
				'args'                => array(
					'activity_id' => array(
						'required'          => true,
						'sanitize_callback' => static function ( $value ) {
							$s = is_scalar( $value ) ? (string) $value : '';
							return ctype_digit( $s ) ? $s : '';
						},
					),
				),
			)
		);
	}

	public static function can_use(): bool {
		return current_user_can( 'edit_posts' );
	}

	public static function list_activities( WP_REST_Request $request ): WP_REST_Response {
		$client  = strava_stories_client();
		$user_id = get_current_user_id();
		$status  = $client->status( $user_id );
		if ( empty( $status['connected'] ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'not_connected' ), 403 );
		}

		$activities = $client->get_recent_activities( $user_id, 10 );
		if ( is_wp_error( $activities ) ) {
			return new WP_REST_Response(
				array( 'ok' => false, 'error' => $activities->get_error_message() ),
				502
			);
		}

		// Strava's listing endpoint sometimes omits `embed_token`. Backfill via
		// the detail endpoint for activities without one, capped to avoid a
		// burst of API calls — the rest fall back to the styled card.
		$backfill_budget = 5;
		foreach ( $activities as &$activity ) {
			if ( $backfill_budget <= 0 ) {
				break;
			}
			if ( ! empty( $activity['embed_token'] ) ) {
				continue;
			}
			$detail = $client->get_activity( $user_id, (string) $activity['id'] );
			$backfill_budget--;
			if ( is_wp_error( $detail ) ) {
				continue;
			}
			if ( ! empty( $detail['embed_token'] ) ) {
				$activity['embed_token'] = $detail['embed_token'];
			}
		}
		unset( $activity );

		$presented = array_map( array( __CLASS__, 'present_activity' ), $activities );
		return new WP_REST_Response( array( 'ok' => true, 'activities' => $presented ), 200 );
	}

	public static function create_blog_draft( WP_REST_Request $request ): WP_REST_Response {
		$activity_id = (string) $request->get_param( 'activity_id' );
		if ( $activity_id === '' || ! ctype_digit( $activity_id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'invalid_activity' ), 400 );
		}

		$client  = strava_stories_client();
		$user_id = get_current_user_id();

		// Prefer the detail endpoint — it reliably includes `embed_token`,
		// which the listing sometimes omits. But it 404s ("Record Not Found")
		// for "Only Me" activities when the connection's scope is the older
		// `activity:read`, so fall back to the listing payload in that case
		// — the draft still gets created, just without the embed.
		$activity        = $client->get_activity( $user_id, $activity_id );
		$detail_failed   = is_wp_error( $activity );

		if ( $detail_failed ) {
			$listing = $client->get_recent_activities( $user_id, 30 );
			if ( is_wp_error( $listing ) ) {
				return new WP_REST_Response( array( 'ok' => false, 'error' => $listing->get_error_message() ), 502 );
			}
			$activity = null;
			foreach ( $listing as $a ) {
				if ( (string) ( $a['id'] ?? '' ) === $activity_id ) {
					$activity = $a;
					break;
				}
			}
			if ( ! $activity ) {
				return new WP_REST_Response( array( 'ok' => false, 'error' => 'unknown_activity' ), 404 );
			}
		}

		$presented = self::present_activity( $activity );
		$title     = $presented['name'] !== ''
			? $presented['name']
			: sprintf( /* translators: %s: sport label, e.g. "Run". */ __( 'A recent %s', 'strava-stories' ), $presented['sport_label'] );

		$body = "<!-- wp:heading -->\n<h2>" . esc_html( $title ) . "</h2>\n<!-- /wp:heading -->\n\n";

		// Strava embed via their official placeholder + embed.js. This is the
		// pattern Strava's "Share → Embed" dialog produces; it works on any
		// domain because the actual iframe is served from strava-embeds.com.
		// Direct www.strava.com iframes are blocked by X-Frame-Options.

		$lines = array();
		foreach ( $presented['stats'] as $stat ) {
			$lines[] = '<li><strong>' . esc_html( $stat['label'] ) . ':</strong> ' . esc_html( $stat['value'] ) . '</li>';
		}
		if ( ! empty( $lines ) ) {
			$body .= "<!-- wp:list -->\n<ul>\n" . implode( "\n", $lines ) . "\n</ul>\n<!-- /wp:list -->\n\n";
		}

		if ( $presented['description'] !== '' ) {
			$body .= "<!-- wp:paragraph -->\n<p>" . esc_html( $presented['description'] ) . "</p>\n<!-- /wp:paragraph -->\n\n";
		}

		$body .= "<!-- wp:paragraph -->\n<p><a href=\"" . esc_url( $presented['url'] ) . "\">"
			. esc_html__( 'View on Strava', 'strava-stories' )
			. "</a></p>\n<!-- /wp:paragraph -->\n";

		// Lift kses for this single insert so the iframe survives for authors
		// without `unfiltered_html`. We re-attach immediately after.
		$kses_was_active = has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $kses_was_active ) {
			kses_remove_filters();
		}
		$post_id = wp_insert_post(
			array(
				'post_status'  => 'draft',
				'post_author'  => $user_id,
				'post_title'   => $title,
				'post_content' => $body,
				'meta_input'   => array(
					'_strava_stories_activity' => $activity_id,
				),
			),
			true
		);
		if ( $kses_was_active ) {
			kses_init_filters();
		}
		if ( is_wp_error( $post_id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => $post_id->get_error_message() ), 500 );
		}

		return new WP_REST_Response(
			array(
				'ok'       => true,
				'post_id'  => (int) $post_id,
				'edit_url' => get_edit_post_link( (int) $post_id, 'raw' ),
			),
			201
		);
	}

	/**
	 * Reshape a raw activity into a render-ready payload.
	 *
	 * @param array<string, mixed> $a
	 * @return array<string, mixed>
	 */
	public static function present_activity( array $a ): array {
		$raw_id      = $a['id'] ?? '';
		$id          = is_scalar( $raw_id ) ? (string) $raw_id : '';
		$type        = (string) ( $a['type'] ?? '' );
		$sport_label = self::sport_label( $type );

		$distance_m   = (float) ( $a['distance_m'] ?? 0 );
		$moving_s     = (int) ( $a['moving_time_s'] ?? 0 );
		$elev_m       = (float) ( $a['total_elevation_m'] ?? 0 );
		$avg_speed_ms = (float) ( $a['avg_speed_ms'] ?? 0 );
		$is_metric    = self::is_metric_locale();

		$stats = array();
		if ( $distance_m > 0 ) {
			$stats[] = array( 'label' => __( 'Distance', 'strava-stories' ), 'value' => self::format_distance( $distance_m, $is_metric ) );
		}
		if ( $moving_s > 0 ) {
			$stats[] = array( 'label' => __( 'Moving time', 'strava-stories' ), 'value' => self::format_duration( $moving_s ) );
		}
		if ( $avg_speed_ms > 0 && $distance_m > 0 ) {
			$stats[] = array(
				'label' => self::is_pace_sport( $type ) ? __( 'Avg pace', 'strava-stories' ) : __( 'Avg speed', 'strava-stories' ),
				'value' => self::is_pace_sport( $type )
					? self::format_pace( $avg_speed_ms, $is_metric )
					: self::format_speed( $avg_speed_ms, $is_metric ),
			);
		}
		if ( $elev_m > 0 ) {
			$stats[] = array( 'label' => __( 'Elevation gain', 'strava-stories' ), 'value' => self::format_elevation( $elev_m, $is_metric ) );
		}

		$description = trim( (string) ( $a['description'] ?? '' ) );
		$excerpt     = $description;
		if ( mb_strlen( $excerpt ) > 280 ) {
			$excerpt = mb_substr( $excerpt, 0, 277 ) . '…';
		}

		$embed_token = (string) ( $a['embed_token'] ?? '' );
		$polyline    = (string) ( $a['polyline'] ?? '' );
		$visibility  = (string) ( $a['visibility'] ?? '' );
		// Strava's embed system refuses "Only Me" activities with error 9CF
		// even when an embed_token is present; gate the iframe accordingly.
		$embeddable = $visibility !== 'only_me' && $id !== '';

		return array(
			'id'                => $id,
			'name'              => (string) ( $a['name'] ?? '' ),
			'sport_label'       => $sport_label,
			'description'       => $description,
			'description_short' => $excerpt,
			'url'               => (string) ( $a['url'] ?? ( $id !== '' ? 'https://www.strava.com/activities/' . $id : '' ) ),
			'embed_token'       => $embed_token,
			'embeddable'        => $embeddable,
			'visibility'        => $visibility,
			'polyline'          => $polyline,
			'start_date'        => (string) ( $a['start_date'] ?? '' ),
			'at'                => (int) ( $a['at'] ?? 0 ),
			'stats'             => $stats,
		);
	}

	private static function sport_label( string $type ): string {
		$map = array(
			'Run'              => __( 'Run', 'strava-stories' ),
			'TrailRun'         => __( 'Trail run', 'strava-stories' ),
			'Walk'             => __( 'Walk', 'strava-stories' ),
			'Hike'             => __( 'Hike', 'strava-stories' ),
			'Ride'             => __( 'Ride', 'strava-stories' ),
			'VirtualRide'      => __( 'Virtual ride', 'strava-stories' ),
			'GravelRide'       => __( 'Gravel ride', 'strava-stories' ),
			'MountainBikeRide' => __( 'MTB ride', 'strava-stories' ),
			'Swim'             => __( 'Swim', 'strava-stories' ),
			'WeightTraining'   => __( 'Strength', 'strava-stories' ),
			'Workout'          => __( 'Workout', 'strava-stories' ),
			'Yoga'             => __( 'Yoga', 'strava-stories' ),
		);
		return $map[ $type ] ?? ( $type !== '' ? $type : __( 'Activity', 'strava-stories' ) );
	}

	private static function is_pace_sport( string $type ): bool {
		return in_array( $type, array( 'Run', 'TrailRun', 'Walk', 'Hike' ), true );
	}

	private static function is_metric_locale(): bool {
		$locale = (string) get_locale();
		return ! in_array( $locale, array( 'en_US', 'en_LR', 'my_MM' ), true );
	}

	private static function format_distance( float $meters, bool $metric ): string {
		return $metric
			? sprintf( '%.2f km', $meters / 1000 )
			: sprintf( '%.2f mi', $meters / 1609.344 );
	}

	private static function format_elevation( float $meters, bool $metric ): string {
		return $metric
			? sprintf( '%d m', (int) round( $meters ) )
			: sprintf( '%d ft', (int) round( $meters * 3.28084 ) );
	}

	private static function format_speed( float $ms, bool $metric ): string {
		return $metric
			? sprintf( '%.1f km/h', $ms * 3.6 )
			: sprintf( '%.1f mph', $ms * 2.236936 );
	}

	private static function format_pace( float $ms, bool $metric ): string {
		if ( $ms <= 0 ) {
			return '—';
		}
		$seconds_per_unit = $metric ? ( 1000 / $ms ) : ( 1609.344 / $ms );
		$min = (int) floor( $seconds_per_unit / 60 );
		$sec = (int) round( $seconds_per_unit - ( $min * 60 ) );
		if ( $sec === 60 ) {
			$min += 1;
			$sec = 0;
		}
		return sprintf( '%d:%02d /%s', $min, $sec, $metric ? 'km' : 'mi' );
	}

	private static function format_duration( int $seconds ): string {
		$h = (int) floor( $seconds / 3600 );
		$m = (int) floor( ( $seconds % 3600 ) / 60 );
		$s = $seconds % 60;
		return $h > 0
			? sprintf( '%d:%02d:%02d', $h, $m, $s )
			: sprintf( '%d:%02d', $m, $s );
	}
}
