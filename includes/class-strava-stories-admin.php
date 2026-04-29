<?php
/**
 * Admin settings page: site-wide Strava OAuth app credentials + per-user connect.
 *
 * @package StravaStories
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class Strava_Stories_Admin {

	public const MENU_SLUG = 'strava-stories';

	public static function boot(): void {
		$instance = new self();
		add_action( 'admin_post_strava_stories_save_app', array( $instance, 'handle_save_app' ) );
		add_action( 'admin_post_strava_stories_disconnect', array( $instance, 'handle_disconnect' ) );
	}

	public function register(): void {
		add_menu_page(
			__( 'Strava Stories', 'strava-stories' ),
			__( 'Strava Stories', 'strava-stories' ),
			'edit_posts',
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-chart-line',
			71
		);
	}

	public function handle_save_app(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'strava-stories' ) );
		}
		check_admin_referer( 'strava_stories_save_app' );

		$existing = get_option( Strava_Stories_Client::APP_OPTION_KEY, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$client_id     = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['client_id'] ) ) : '';
		$client_secret = isset( $_POST['client_secret'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['client_secret'] ) ) : '';

		$saved = array(
			'client_id'     => $client_id,
			'client_secret' => $client_secret !== '' ? $client_secret : ( $existing['client_secret'] ?? '' ),
		);
		update_option( Strava_Stories_Client::APP_OPTION_KEY, $saved, false );

		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) );
		exit;
	}

	public function handle_disconnect(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'strava-stories' ) );
		}
		check_admin_referer( 'strava-stories-disconnect' );
		strava_stories_client()->disconnect( get_current_user_id() );
		wp_safe_redirect( add_query_arg( 'disconnected', '1', admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) );
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$client     = strava_stories_client();
		$user_id    = get_current_user_id();
		$status     = $client->status( $user_id );
		$app        = $client->get_app_credentials();
		$callback   = $client->callback_url();
		$is_admin   = current_user_can( 'manage_options' );
		$configured = (bool) $app;

		$saved_app = get_option( Strava_Stories_Client::APP_OPTION_KEY, array() );
		if ( ! is_array( $saved_app ) ) {
			$saved_app = array();
		}

		$connect_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'                   => self::MENU_SLUG,
					'strava_stories_connect' => '1',
				),
				admin_url( 'admin.php' )
			),
			'strava-stories-connect'
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Strava Stories', 'strava-stories' ); ?></h1>

			<?php if ( ! empty( $_GET['connected'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Connected to Strava.', 'strava-stories' ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! empty( $_GET['disconnected'] ) ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'Disconnected from Strava.', 'strava-stories' ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! empty( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Credentials saved.', 'strava-stories' ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Your Strava connection', 'strava-stories' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Each user connects their own Strava account. Tokens are never shared with other users on this site.', 'strava-stories' ); ?></p>

			<table class="widefat striped" style="max-width:720px;">
				<tbody>
					<tr data-strava-stories-row="connection">
						<td><strong><?php esc_html_e( 'Status', 'strava-stories' ); ?></strong></td>
						<td>
							<?php if ( ! empty( $status['connected'] ) ) : ?>
								<span class="strava-stories-status strava-stories-status--connected">
									<?php
									printf(
										/* translators: %s: athlete name on Strava. */
										esc_html__( 'Connected as %s', 'strava-stories' ),
										esc_html( $status['user'] !== '' ? $status['user'] : __( '(unknown)', 'strava-stories' ) )
									);
									?>
								</span>
							<?php elseif ( ! $configured ) : ?>
								<span class="strava-stories-status strava-stories-status--disconnected">
									<?php esc_html_e( 'Not connected — OAuth app not configured yet.', 'strava-stories' ); ?>
								</span>
							<?php else : ?>
								<span class="strava-stories-status strava-stories-status--disconnected"><?php esc_html_e( 'Not connected', 'strava-stories' ); ?></span>
							<?php endif; ?>
						</td>
						<td style="text-align:right;">
							<?php if ( ! empty( $status['connected'] ) ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
									<input type="hidden" name="action" value="strava_stories_disconnect" />
									<?php wp_nonce_field( 'strava-stories-disconnect' ); ?>
									<button type="submit" class="button"><?php esc_html_e( 'Disconnect', 'strava-stories' ); ?></button>
								</form>
							<?php else : ?>
								<a class="button button-primary"
									href="<?php echo esc_url( $connect_url ); ?>"
									data-connecting-label="<?php esc_attr_e( 'Connecting…', 'strava-stories' ); ?>"
									<?php if ( ! $configured ) echo 'aria-disabled="true" style="pointer-events:none;opacity:.5"'; ?>
								>
									<?php esc_html_e( 'Connect Strava', 'strava-stories' ); ?>
								</a>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<?php if ( $is_admin ) : ?>
				<hr style="margin:32px 0;" />
				<h2><?php esc_html_e( 'Strava OAuth app', 'strava-stories' ); ?></h2>
				<p class="description">
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: link to Strava API settings. */
							__( 'Administrators only. Create an API application at %s and paste its credentials below. The Authorization Callback Domain on Strava\'s side should be just this site\'s host (no scheme or path).', 'strava-stories' ),
							'<a href="https://www.strava.com/settings/api" target="_blank" rel="noopener noreferrer">strava.com/settings/api</a>'
						),
						array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) )
					);
					?>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="strava_stories_save_app" />
					<?php wp_nonce_field( 'strava_stories_save_app' ); ?>

					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><label for="strava-stories-client-id"><?php esc_html_e( 'Client ID', 'strava-stories' ); ?></label></th>
								<td>
									<input
										type="text"
										id="strava-stories-client-id"
										class="regular-text"
										name="client_id"
										value="<?php echo esc_attr( (string) ( $saved_app['client_id'] ?? '' ) ); ?>"
										autocomplete="off"
									/>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="strava-stories-client-secret"><?php esc_html_e( 'Client Secret', 'strava-stories' ); ?></label></th>
								<td>
									<input
										type="password"
										id="strava-stories-client-secret"
										class="regular-text"
										name="client_secret"
										value=""
										placeholder="<?php echo ! empty( $saved_app['client_secret'] ) ? esc_attr__( '(saved — leave blank to keep)', 'strava-stories' ) : ''; ?>"
										autocomplete="off"
									/>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Callback URL', 'strava-stories' ); ?></th>
								<td>
									<code><?php echo esc_html( $callback ); ?></code>
									<p class="description"><?php esc_html_e( "Strava only asks for a callback domain, not a full URL. This is the address it will redirect users back to after they approve.", 'strava-stories' ); ?></p>
								</td>
							</tr>
						</tbody>
					</table>

					<?php submit_button( __( 'Save credentials', 'strava-stories' ) ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
