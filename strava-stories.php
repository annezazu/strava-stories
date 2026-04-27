<?php
/**
 * Plugin Name:       Strava Stories
 * Plugin URI:        https://github.com/annezazu/strava-stories
 * Description:       A WordPress dashboard widget that surfaces your latest Strava activity with stats, embed, and a one-click "Let's blog it" draft.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Anne McCarthy
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       strava-stories
 *
 * @package StravaStories
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'STRAVA_STORIES_VERSION', '0.1.0' );
define( 'STRAVA_STORIES_FILE', __FILE__ );
define( 'STRAVA_STORIES_DIR', plugin_dir_path( __FILE__ ) );
define( 'STRAVA_STORIES_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		if ( strpos( $class, 'Strava_Stories_' ) !== 0 ) {
			return;
		}
		$file = 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
		$path = STRAVA_STORIES_DIR . 'includes/' . $file;
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

add_action( 'plugins_loaded', 'strava_stories_load_textdomain' );
function strava_stories_load_textdomain(): void {
	load_plugin_textdomain( 'strava-stories', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

add_action( 'plugins_loaded', 'strava_stories_boot' );
function strava_stories_boot(): void {
	Strava_Stories_Rest::boot();
	Strava_Stories_Admin::boot();
}

/** Singleton accessor for the Strava client. */
function strava_stories_client(): Strava_Stories_Client {
	static $client = null;
	if ( $client === null ) {
		$client = new Strava_Stories_Client();
	}
	return $client;
}

add_action( 'admin_menu', 'strava_stories_register_admin_page' );
function strava_stories_register_admin_page(): void {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	( new Strava_Stories_Admin() )->register();
}

add_action( 'wp_dashboard_setup', 'strava_stories_register_widget' );
function strava_stories_register_widget(): void {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	( new Strava_Stories_Widget() )->register();
}

add_action( 'admin_init', 'strava_stories_dispatch_oauth' );
function strava_stories_dispatch_oauth(): void {
	if ( empty( $_GET['page'] ) || $_GET['page'] !== Strava_Stories_Admin::MENU_SLUG ) {
		return;
	}
	if ( ! is_user_logged_in() ) {
		return;
	}

	if ( ! empty( $_GET['strava_stories_connect'] ) ) {
		strava_stories_client()->connect();
		return;
	}
	if ( ! empty( $_GET['strava_stories_callback'] ) ) {
		strava_stories_client()->handle_callback();
	}
}

add_action( 'admin_enqueue_scripts', 'strava_stories_enqueue_assets' );
function strava_stories_enqueue_assets( string $hook ): void {
	$is_dashboard = $hook === 'index.php';
	$is_settings  = ! empty( $_GET['page'] ) && $_GET['page'] === Strava_Stories_Admin::MENU_SLUG;
	if ( ! $is_dashboard && ! $is_settings ) {
		return;
	}

	wp_enqueue_style(
		'strava-stories',
		STRAVA_STORIES_URL . 'assets/strava-stories.css',
		array(),
		STRAVA_STORIES_VERSION
	);

	if ( $is_dashboard ) {
		wp_enqueue_script(
			'strava-stories',
			STRAVA_STORIES_URL . 'assets/strava-stories.js',
			array( 'wp-i18n' ),
			STRAVA_STORIES_VERSION,
			true
		);
	}
}
