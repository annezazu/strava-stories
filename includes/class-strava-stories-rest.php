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

	private const IGNORED_META_KEY = '_strava_stories_ignored';

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
			'/ignore',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'ignore_activity' ),
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
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'unignore_activity' ),
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
				),
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

		// Fetch a larger window so that as activities get drafted (and filtered
		// out below), more recent ones backfill the visible list rather than
		// shrinking it toward empty. We display up to DISPLAY_LIMIT after the
		// drafted filter.
		$activities = $client->get_recent_activities( $user_id, 15 );
		if ( is_wp_error( $activities ) ) {
			return new WP_REST_Response(
				array( 'ok' => false, 'error' => $activities->get_error_message() ),
				502
			);
		}

		$drafted = self::drafted_activity_ids();
		$ignored = self::ignored_activity_ids( $user_id );
		$excluded = $drafted + $ignored;
		if ( ! empty( $excluded ) ) {
			$activities = array_values(
				array_filter(
					$activities,
					static function ( $a ) use ( $excluded ) {
						$id = isset( $a['id'] ) && is_scalar( $a['id'] ) ? (string) $a['id'] : '';
						return $id === '' || ! isset( $excluded[ $id ] );
					}
				)
			);
		}

		$activities = array_slice( $activities, 0, 5 );

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

		$body = self::photo_blocks_for_activity( $client, $user_id, $activity_id, $title );

		if ( $presented['description'] !== '' ) {
			$body .= "<!-- wp:paragraph -->\n<p>" . esc_html( $presented['description'] ) . "</p>\n<!-- /wp:paragraph -->\n\n";
		}

		$draft_stats = self::draft_stats( $activity );
		if ( ! empty( $draft_stats ) ) {
			$items = '';
			foreach ( $draft_stats as $line ) {
				$items .= '<li>' . esc_html( $line ) . "</li>\n";
			}
			$body .= "<!-- wp:list -->\n<ul>\n" . $items . "</ul>\n<!-- /wp:list -->\n\n";
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

	public static function ignore_activity( WP_REST_Request $request ): WP_REST_Response {
		$activity_id = (string) $request->get_param( 'activity_id' );
		if ( $activity_id === '' || ! ctype_digit( $activity_id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'invalid_activity' ), 400 );
		}
		$user_id = get_current_user_id();
		$ids     = self::ignored_activity_ids( $user_id );
		$ids[ $activity_id ] = true;
		update_user_meta( $user_id, self::IGNORED_META_KEY, array_keys( $ids ) );
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	public static function unignore_activity( WP_REST_Request $request ): WP_REST_Response {
		$activity_id = (string) $request->get_param( 'activity_id' );
		if ( $activity_id === '' || ! ctype_digit( $activity_id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'invalid_activity' ), 400 );
		}
		$user_id = get_current_user_id();
		$ids     = self::ignored_activity_ids( $user_id );
		unset( $ids[ $activity_id ] );
		if ( empty( $ids ) ) {
			delete_user_meta( $user_id, self::IGNORED_META_KEY );
		} else {
			update_user_meta( $user_id, self::IGNORED_META_KEY, array_keys( $ids ) );
		}
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * @return array<string, true>
	 */
	private static function ignored_activity_ids( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::IGNORED_META_KEY, true );
		$ids = array();
		foreach ( (array) $raw as $value ) {
			$id = is_scalar( $value ) ? (string) $value : '';
			if ( $id !== '' && ctype_digit( $id ) ) {
				$ids[ $id ] = true;
			}
		}
		return $ids;
	}

	/**
	 * Map of Strava activity IDs that already have an associated post.
	 *
	 * Posts in 'auto-draft' and 'trash' don't count: auto-drafts are
	 * speculative (WP creates them on Add New), and trashing is the user
	 * explicitly releasing the activity for re-drafting.
	 *
	 * @return array<string, true>
	 */
	private static function drafted_activity_ids(): array {
		global $wpdb;
		$rows = $wpdb->get_col(
			"SELECT DISTINCT pm.meta_value
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_strava_stories_activity'
			   AND p.post_status NOT IN ( 'auto-draft', 'trash' )"
		);
		$ids = array();
		foreach ( (array) $rows as $row ) {
			$id = is_scalar( $row ) ? (string) $row : '';
			if ( $id !== '' ) {
				$ids[ $id ] = true;
			}
		}
		return $ids;
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

	/**
	 * Stat lines for the draft post — bare values, no labels except for
	 * elevation which carries its own suffix.
	 *
	 * @param array<string, mixed> $a
	 * @return array<int, string>
	 */
	private static function draft_stats( array $a ): array {
		$is_metric  = self::is_metric_locale();
		$distance_m = (float) ( $a['distance_m'] ?? 0 );
		$moving_s   = (int) ( $a['moving_time_s'] ?? 0 );
		$elev_m     = (float) ( $a['total_elevation_m'] ?? 0 );

		$out = array();
		if ( $distance_m > 0 ) {
			$out[] = self::format_distance( $distance_m, $is_metric );
		}
		if ( $moving_s > 0 ) {
			$out[] = self::format_duration_human( $moving_s );
		}
		if ( $elev_m > 0 ) {
			$out[] = sprintf(
				/* translators: %s: elevation with units, e.g. "2259 ft". */
				__( '%s elev gain', 'strava-stories' ),
				self::format_elevation( $elev_m, $is_metric )
			);
		}
		return $out;
	}

	// Human-friendly duration: largest two non-zero units, or just seconds for
	// sub-minute durations. "2 hr 22 mins", "22 mins 4 secs", "47 secs".
	private static function format_duration_human( int $seconds ): string {
		$h = intdiv( $seconds, 3600 );
		$m = intdiv( $seconds % 3600, 60 );
		$s = $seconds % 60;

		$units = array();
		if ( $h > 0 ) { $units[] = $h . ' hr'; }
		if ( $m > 0 ) { $units[] = $m . ( $m === 1 ? ' min' : ' mins' ); }
		if ( $s > 0 || empty( $units ) ) { $units[] = $s . ( $s === 1 ? ' sec' : ' secs' ); }

		return implode( ' ', array_slice( $units, 0, 2 ) );
	}

	private static function format_duration( int $seconds ): string {
		$h = (int) floor( $seconds / 3600 );
		$m = (int) floor( ( $seconds % 3600 ) / 60 );
		$s = $seconds % 60;
		return $h > 0
			? sprintf( '%d:%02d:%02d', $h, $m, $s )
			: sprintf( '%d:%02d', $m, $s );
	}

	/**
	 * Fetch and sideload activity photos, returning serialized image / gallery
	 * block markup ready for splicing into post_content. Empty string when the
	 * activity has no photos or all sideloads fail.
	 */
	private static function photo_blocks_for_activity( Strava_Stories_Client $client, int $user_id, string $activity_id, string $alt_text ): string {
		$photos = $client->get_activity_photos( $user_id, $activity_id );
		if ( is_wp_error( $photos ) || ! is_array( $photos ) || empty( $photos ) ) {
			return '';
		}

		// Strava returns up to 100 photos; cap to keep the click responsive.
		$photos = array_slice( $photos, 0, 12 );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachments = array();
		foreach ( $photos as $p ) {
			$id = self::sideload_photo( (string) $p['url'], (string) ( $p['caption'] ?? '' ), $alt_text );
			if ( is_wp_error( $id ) || $id === 0 ) {
				continue;
			}
			$attachments[] = array(
				'id'      => $id,
				'url'     => (string) wp_get_attachment_url( $id ),
				'caption' => (string) ( $p['caption'] ?? '' ),
			);
		}

		if ( empty( $attachments ) ) {
			return '';
		}

		if ( count( $attachments ) === 1 ) {
			return self::image_block( $attachments[0] ) . "\n";
		}

		$inner = '';
		$ids   = array();
		foreach ( $attachments as $a ) {
			$inner .= self::image_block( $a );
			$ids[] = (int) $a['id'];
		}
		return sprintf(
			"<!-- wp:gallery {\"linkTo\":\"none\",\"ids\":[%s]} -->\n<figure class=\"wp-block-gallery has-nested-images columns-default is-cropped\">%s</figure>\n<!-- /wp:gallery -->\n\n",
			implode( ',', $ids ),
			$inner
		);
	}

	/**
	 * @param array{id:int, url:string, caption:string} $a
	 */
	private static function image_block( array $a ): string {
		$caption_html = $a['caption'] !== ''
			? '<figcaption class="wp-element-caption">' . esc_html( $a['caption'] ) . '</figcaption>'
			: '';
		return sprintf(
			"<!-- wp:image {\"id\":%d,\"sizeSlug\":\"large\",\"linkDestination\":\"none\"} -->\n<figure class=\"wp-block-image size-large\"><img src=\"%s\" alt=\"%s\" class=\"wp-image-%d\"/>%s</figure>\n<!-- /wp:image -->\n\n",
			$a['id'],
			esc_url( $a['url'] ),
			esc_attr( $a['caption'] ),
			$a['id'],
			$caption_html
		);
	}

	/**
	 * Download a remote photo and attach it to the media library.
	 *
	 * @return int|WP_Error Attachment ID on success.
	 */
	private static function sideload_photo( string $url, string $caption, string $alt_text ) {
		if ( $url === '' ) {
			return 0;
		}
		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$name = basename( $path );
		// Strava CloudFront URLs sometimes lack an extension; sniff and append.
		if ( ! preg_match( '/\.(jpe?g|png|gif|webp|heic|heif)$/i', $name ) ) {
			$mime = mime_content_type( $tmp ) ?: 'image/jpeg';
			$ext  = array(
				'image/jpeg' => '.jpg',
				'image/png'  => '.png',
				'image/gif'  => '.gif',
				'image/webp' => '.webp',
			)[ $mime ] ?? '.jpg';
			$name = ( $name !== '' ? $name : 'strava-photo' ) . $ext;
		}

		$file_array = array( 'name' => $name, 'tmp_name' => $tmp );
		$id         = media_handle_sideload( $file_array, 0 );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp );
			return $id;
		}

		if ( $caption !== '' ) {
			wp_update_post( array( 'ID' => $id, 'post_excerpt' => $caption ) );
		}
		update_post_meta( $id, '_wp_attachment_image_alt', $alt_text );
		return (int) $id;
	}

}
