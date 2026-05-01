<?php
/**
 * Strava Stories dashboard widget.
 *
 * Server-side renders only the shell (loading + empty states); activities
 * load via /strava-stories/v1/activities once the dashboard is interactive,
 * so the dashboard render itself never blocks on Strava.
 *
 * @package StravaStories
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class Strava_Stories_Widget {

	public const WIDGET_ID = 'strava_stories_widget';

	public function register(): void {
		wp_add_dashboard_widget(
			self::WIDGET_ID,
			__( 'Strava Stories', 'strava-stories' ),
			array( $this, 'render' ),
			null,
			null,
			'normal',
			'high'
		);
	}

	public function render(): void {
		$user_id   = get_current_user_id();
		$client    = strava_stories_client();
		$status    = $client->status( $user_id );
		$connected = ! empty( $status['connected'] );
		$admin_url = admin_url( 'admin.php?page=' . Strava_Stories_Admin::MENU_SLUG );
		$rest_root = esc_url_raw( rest_url( Strava_Stories_Rest::NAMESPACE . '/' ) );
		$nonce     = wp_create_nonce( 'wp_rest' );
		?>
		<div class="strava-stories-widget"
			data-rest-root="<?php echo esc_attr( $rest_root ); ?>"
			data-nonce="<?php echo esc_attr( $nonce ); ?>"
			data-connected="<?php echo $connected ? '1' : '0'; ?>"
			aria-labelledby="strava-stories-heading"
		>
			<h3 id="strava-stories-heading" class="screen-reader-text">
				<?php esc_html_e( 'Strava Stories', 'strava-stories' ); ?>
			</h3>

			<?php if ( ! $connected ) : ?>
				<div class="strava-stories-widget__empty">
					<p><?php esc_html_e( 'Connect Strava to see your latest activity here.', 'strava-stories' ); ?></p>
					<p>
						<a class="button button-primary" href="<?php echo esc_url( $admin_url ); ?>">
							<?php esc_html_e( 'Set up Strava Stories', 'strava-stories' ); ?>
						</a>
					</p>
				</div>
			<?php else : ?>
				<div class="strava-stories-widget__viewport" aria-live="polite">
					<button
						type="button"
						class="strava-stories-widget__nav strava-stories-widget__nav--prev"
						aria-label="<?php esc_attr_e( 'Previous activity', 'strava-stories' ); ?>"
						disabled
					>
						<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
					</button>

					<div class="strava-stories-widget__stage" data-state="loading">
						<div class="strava-stories-widget__loading">
							<span class="spinner is-active" aria-hidden="true"></span>
							<span class="screen-reader-text"><?php esc_html_e( 'Loading activities…', 'strava-stories' ); ?></span>
						</div>
					</div>

					<button
						type="button"
						class="strava-stories-widget__nav strava-stories-widget__nav--next"
						aria-label="<?php esc_attr_e( 'Next activity', 'strava-stories' ); ?>"
						disabled
					>
						<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
					</button>
				</div>

				<footer class="strava-stories-widget__footer">
					<span class="strava-stories-widget__pager" aria-live="polite"></span>
					<button type="button" class="button button-primary strava-stories-widget__blog" disabled>
						<?php esc_html_e( 'Create draft', 'strava-stories' ); ?>
					</button>
				</footer>
			<?php endif; ?>
		</div>
		<?php
	}
}
