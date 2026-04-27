<?php
/**
 * Strava OAuth2 + API client.
 *
 * Per-user tokens stored in user meta (`strava_stories_token`); site-wide
 * client_id / client_secret in the option `strava_stories_oauth_app`.
 *
 * Modeled on the Keyring OAuth2 pattern (https://github.com/beaulebens/keyring),
 * trimmed to a single service.
 *
 * @package StravaStories
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class Strava_Stories_Client {

	public const TOKEN_META_KEY = 'strava_stories_token';
	public const APP_OPTION_KEY = 'strava_stories_oauth_app';

	private const AUTHORIZE_URL    = 'https://www.strava.com/oauth/authorize';
	private const ACCESS_TOKEN_URL = 'https://www.strava.com/oauth/token';
	// `activity:read_all` is required to read activities with visibility set to
	// "Only Me" via the detail endpoint (the listing endpoint works on either
	// scope). Existing users with the older `activity:read` token must
	// disconnect + reconnect to pick up the broader scope.
	private const SCOPE            = 'read,activity:read_all';

	public function status( int $user_id ): array {
		$token = $this->get_token( $user_id );
		if ( ! $token ) {
			return array( 'connected' => false );
		}
		return array(
			'connected' => true,
			'user'      => (string) ( $token['meta']['username'] ?? '' ),
		);
	}

	public function disconnect( int $user_id ): void {
		delete_user_meta( $user_id, self::TOKEN_META_KEY );
	}

	public function callback_url(): string {
		return add_query_arg(
			array(
				'page'                    => Strava_Stories_Admin::MENU_SLUG,
				'strava_stories_callback' => '1',
			),
			admin_url( 'admin.php' )
		);
	}

	public function connect(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to connect Strava.', 'strava-stories' ) );
		}
		check_admin_referer( 'strava-stories-connect' );

		$credentials = $this->get_app_credentials();
		if ( ! $credentials ) {
			wp_die( esc_html__( 'Strava OAuth app credentials are not configured yet.', 'strava-stories' ) );
		}

		$state = wp_generate_password( 32, false, false );
		set_transient( $this->state_transient_key( $state ), get_current_user_id(), 10 * MINUTE_IN_SECONDS );

		// Strava expects literal commas in `scope` (http_build_query encodes them,
		// which Strava silently rejects). Build the URL manually.
		$params = array(
			'response_type'   => 'code',
			'client_id'       => $credentials['client_id'],
			'redirect_uri'    => $this->callback_url(),
			'state'           => $state,
			'approval_prompt' => 'auto',
		);
		$url = self::AUTHORIZE_URL . '?' . http_build_query( $params ) . '&scope=' . self::SCOPE;
		wp_redirect( $url );
		exit;
	}

	public function handle_callback(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'You must be logged in to complete this connection.', 'strava-stories' ) );
		}

		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['code'] ) ) : '';
		if ( $state === '' || $code === '' ) {
			wp_die( esc_html__( 'Missing state or authorization code.', 'strava-stories' ) );
		}

		$expected_user = get_transient( $this->state_transient_key( $state ) );
		if ( ! $expected_user || (int) $expected_user !== get_current_user_id() ) {
			wp_die( esc_html__( 'Invalid or expired authorization state.', 'strava-stories' ) );
		}
		delete_transient( $this->state_transient_key( $state ) );

		$credentials = $this->get_app_credentials();
		if ( ! $credentials ) {
			wp_die( esc_html__( 'Strava OAuth app credentials are missing.', 'strava-stories' ) );
		}

		$response = wp_remote_post(
			self::ACCESS_TOKEN_URL,
			array(
				'headers' => array( 'Accept' => 'application/json' ),
				'body'    => array(
					'client_id'     => $credentials['client_id'],
					'client_secret' => $credentials['client_secret'],
					'code'          => $code,
					'grant_type'    => 'authorization_code',
					'redirect_uri'  => $this->callback_url(),
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_die( esc_html( $response->get_error_message() ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			wp_die( esc_html__( 'Token exchange failed.', 'strava-stories' ) );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['access_token'] ) ) {
			wp_die( esc_html__( 'Token response was malformed.', 'strava-stories' ) );
		}

		$meta = $this->derive_athlete_meta( $body );
		$meta = array_merge( $meta, $this->expiry_meta( $body ) );
		if ( ! empty( $body['refresh_token'] ) ) {
			$meta['refresh_token'] = (string) $body['refresh_token'];
		}

		// If the token-exchange response didn't include athlete info, hit /athlete.
		if ( empty( $meta['username'] ) ) {
			$meta = array_merge( $meta, $this->fetch_athlete( (string) $body['access_token'] ) );
		}

		$this->store_token( get_current_user_id(), (string) $body['access_token'], $meta );

		wp_safe_redirect( add_query_arg( 'connected', '1', admin_url( 'admin.php?page=' . Strava_Stories_Admin::MENU_SLUG ) ) );
		exit;
	}

	/**
	 * Fetch recent activities with rich fields for the widget.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function get_recent_activities( int $user_id, int $limit = 10 ) {
		$url = add_query_arg(
			array(
				'per_page' => max( 1, min( 30, $limit ) ),
				'page'     => 1,
			),
			'https://www.strava.com/api/v3/athlete/activities'
		);

		$activities = $this->authed_get( $url, $user_id );
		if ( is_wp_error( $activities ) ) {
			return $activities;
		}
		if ( ! is_array( $activities ) ) {
			return array();
		}

		$out = array();
		foreach ( $activities as $a ) {
			if ( ! is_array( $a ) || empty( $a['id'] ) || empty( $a['start_date'] ) ) {
				continue;
			}
			// Strava IDs now exceed 2^31, which truncates on 32-bit PHP. Keep
			// them as opaque strings everywhere downstream.
			$id = is_scalar( $a['id'] ) ? (string) $a['id'] : '';
			if ( $id === '' || ! ctype_digit( $id ) ) {
				continue;
			}
			$out[] = array(
				'id'                => $id,
				'name'              => (string) ( $a['name'] ?? '' ),
				'description'       => (string) ( $a['description'] ?? '' ),
				'type'              => (string) ( $a['type'] ?? ( $a['sport_type'] ?? '' ) ),
				'sport_type'        => (string) ( $a['sport_type'] ?? '' ),
				'distance_m'        => (float) ( $a['distance'] ?? 0 ),
				'moving_time_s'     => (int) ( $a['moving_time'] ?? 0 ),
				'elapsed_time_s'    => (int) ( $a['elapsed_time'] ?? 0 ),
				'total_elevation_m' => (float) ( $a['total_elevation_gain'] ?? 0 ),
				'avg_speed_ms'      => (float) ( $a['average_speed'] ?? 0 ),
				'max_speed_ms'      => (float) ( $a['max_speed'] ?? 0 ),
				'avg_heartrate'     => isset( $a['average_heartrate'] ) ? (float) $a['average_heartrate'] : 0.0,
				'kudos'             => (int) ( $a['kudos_count'] ?? 0 ),
				'embed_token'       => (string) ( $a['embed_token'] ?? '' ),
				'visibility'        => (string) ( $a['visibility'] ?? '' ),
				'polyline'          => isset( $a['map']['summary_polyline'] ) ? (string) $a['map']['summary_polyline'] : '',
				'start_date'        => (string) $a['start_date'],
				'at'                => (int) strtotime( (string) $a['start_date'] ),
				'url'               => 'https://www.strava.com/activities/' . $id,
			);
		}
		return $out;
	}

	/**
	 * Fetch a single activity by ID. The detail endpoint includes
	 * `embed_token` reliably, even when the listing endpoint elides it.
	 *
	 * Activity IDs are passed as strings so they don't truncate on 32-bit PHP.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_activity( int $user_id, string $activity_id ) {
		if ( ! ctype_digit( $activity_id ) ) {
			return new WP_Error( 'strava_stories_invalid_activity', __( 'Invalid activity ID.', 'strava-stories' ) );
		}
		$url      = 'https://www.strava.com/api/v3/activities/' . $activity_id;
		$activity = $this->authed_get( $url, $user_id );
		if ( is_wp_error( $activity ) ) {
			return $activity;
		}
		if ( ! is_array( $activity ) || empty( $activity['id'] ) ) {
			return new WP_Error( 'strava_stories_unknown_activity', __( 'Activity not found.', 'strava-stories' ) );
		}
		$id = is_scalar( $activity['id'] ) ? (string) $activity['id'] : $activity_id;
		return array(
			'id'                => $id,
			'name'              => (string) ( $activity['name'] ?? '' ),
			'description'       => (string) ( $activity['description'] ?? '' ),
			'type'              => (string) ( $activity['type'] ?? ( $activity['sport_type'] ?? '' ) ),
			'sport_type'        => (string) ( $activity['sport_type'] ?? '' ),
			'distance_m'        => (float) ( $activity['distance'] ?? 0 ),
			'moving_time_s'     => (int) ( $activity['moving_time'] ?? 0 ),
			'elapsed_time_s'    => (int) ( $activity['elapsed_time'] ?? 0 ),
			'total_elevation_m' => (float) ( $activity['total_elevation_gain'] ?? 0 ),
			'avg_speed_ms'      => (float) ( $activity['average_speed'] ?? 0 ),
			'max_speed_ms'      => (float) ( $activity['max_speed'] ?? 0 ),
			'avg_heartrate'     => isset( $activity['average_heartrate'] ) ? (float) $activity['average_heartrate'] : 0.0,
			'kudos'             => (int) ( $activity['kudos_count'] ?? 0 ),
			'embed_token'       => (string) ( $activity['embed_token'] ?? '' ),
			'visibility'        => (string) ( $activity['visibility'] ?? '' ),
			'polyline'          => isset( $activity['map']['summary_polyline'] ) ? (string) $activity['map']['summary_polyline'] : '',
			'start_date'        => (string) ( $activity['start_date'] ?? '' ),
			'at'                => isset( $activity['start_date'] ) ? (int) strtotime( (string) $activity['start_date'] ) : 0,
			'url'               => 'https://www.strava.com/activities/' . $id,
		);
	}

	/**
	 * @return array{client_id:string, client_secret:string}|null
	 */
	public function get_app_credentials(): ?array {
		$app = get_option( self::APP_OPTION_KEY, array() );
		if ( ! is_array( $app ) || empty( $app['client_id'] ) || empty( $app['client_secret'] ) ) {
			return null;
		}
		return array(
			'client_id'     => (string) $app['client_id'],
			'client_secret' => (string) $app['client_secret'],
		);
	}

	/**
	 * @return array<mixed>|WP_Error
	 */
	private function authed_get( string $url, int $user_id ) {
		$token = $this->get_token( $user_id );
		if ( ! $token ) {
			return new WP_Error( 'strava_stories_not_connected', __( 'Strava is not connected.', 'strava-stories' ) );
		}

		$expires_at = (int) ( $token['meta']['expires_at'] ?? 0 );
		if ( $expires_at > 0 && $expires_at <= time() + 60 ) {
			$refreshed = $this->refresh_access_token( $user_id, $token );
			if ( is_wp_error( $refreshed ) ) {
				return $refreshed;
			}
			$token = $refreshed;
		}

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token['access_token'],
					'Accept'        => 'application/json',
					'User-Agent'    => 'StravaStories/' . STRAVA_STORIES_VERSION . '; ' . home_url(),
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error( 'strava_stories_http_' . $status, (string) wp_remote_retrieve_body( $response ) );
		}
		// JSON_BIGINT_AS_STRING keeps Strava's 11-digit activity IDs intact on
		// 32-bit PHP (where they'd otherwise be truncated to 32-bit ints).
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true, 512, JSON_BIGINT_AS_STRING );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * @param array{access_token:string, meta:array<string,mixed>} $token
	 * @return array{access_token:string, meta:array<string,mixed>}|WP_Error
	 */
	private function refresh_access_token( int $user_id, array $token ) {
		$refresh = (string) ( $token['meta']['refresh_token'] ?? '' );
		if ( $refresh === '' ) {
			return new WP_Error( 'strava_stories_no_refresh_token', __( 'Token expired and no refresh token is available. Reconnect Strava.', 'strava-stories' ) );
		}
		$credentials = $this->get_app_credentials();
		if ( ! $credentials ) {
			return new WP_Error( 'strava_stories_app_missing', __( 'Strava OAuth app credentials are missing.', 'strava-stories' ) );
		}

		$response = wp_remote_post(
			self::ACCESS_TOKEN_URL,
			array(
				'headers' => array( 'Accept' => 'application/json' ),
				'body'    => array(
					'client_id'     => $credentials['client_id'],
					'client_secret' => $credentials['client_secret'],
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh,
				),
				'timeout' => 15,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error( 'strava_stories_refresh_failed', (string) wp_remote_retrieve_body( $response ) );
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['access_token'] ) ) {
			return new WP_Error( 'strava_stories_refresh_malformed', __( 'Refresh response was malformed.', 'strava-stories' ) );
		}

		$meta = $token['meta'];
		$meta = array_merge( $meta, $this->expiry_meta( $body ) );
		if ( ! empty( $body['refresh_token'] ) ) {
			$meta['refresh_token'] = (string) $body['refresh_token'];
		}
		$this->store_token( $user_id, (string) $body['access_token'], $meta );
		return array(
			'access_token' => (string) $body['access_token'],
			'meta'         => $meta,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function fetch_athlete( string $access_token ): array {
		$response = wp_remote_get(
			'https://www.strava.com/api/v3/athlete',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => 'application/json',
					'User-Agent'    => 'StravaStories/' . STRAVA_STORIES_VERSION . '; ' . home_url(),
				),
				'timeout' => 10,
			)
		);
		if ( is_wp_error( $response ) ) {
			return array();
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $body ) ? $this->derive_athlete_meta( array( 'athlete' => $body ) ) : array();
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	private function derive_athlete_meta( array $body ): array {
		$athlete = isset( $body['athlete'] ) && is_array( $body['athlete'] ) ? $body['athlete'] : array();
		if ( empty( $athlete ) ) {
			return array();
		}
		$display = trim( (string) ( $athlete['firstname'] ?? '' ) . ' ' . (string) ( $athlete['lastname'] ?? '' ) );
		return array(
			'user_id'  => isset( $athlete['id'] ) ? (int) $athlete['id'] : 0,
			'username' => $display !== '' ? $display : (string) ( $athlete['username'] ?? '' ),
			'name'     => $display,
		);
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, int>
	 */
	private function expiry_meta( array $body ): array {
		if ( ! empty( $body['expires_at'] ) ) {
			return array( 'expires_at' => (int) $body['expires_at'] );
		}
		if ( ! empty( $body['expires_in'] ) ) {
			return array( 'expires_at' => time() + (int) $body['expires_in'] );
		}
		return array();
	}

	/**
	 * @return array{access_token:string, meta:array<string,mixed>}|null
	 */
	private function get_token( int $user_id ): ?array {
		$stored = get_user_meta( $user_id, self::TOKEN_META_KEY, true );
		if ( ! is_array( $stored ) || empty( $stored['access_token'] ) ) {
			return null;
		}
		return $stored;
	}

	/**
	 * @param array<string, mixed> $meta
	 */
	private function store_token( int $user_id, string $access_token, array $meta ): void {
		update_user_meta(
			$user_id,
			self::TOKEN_META_KEY,
			array(
				'access_token' => $access_token,
				'meta'         => $meta,
				'stored_at'    => time(),
			)
		);
	}

	private function state_transient_key( string $state ): string {
		return 'strava_stories_oauth_state_' . $state;
	}
}
