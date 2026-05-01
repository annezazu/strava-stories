<?php
/**
 * Uninstall: remove the OAuth app option and every per-user token.
 *
 * @package StravaStories
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'strava_stories_oauth_app' );
delete_metadata( 'user', 0, 'strava_stories_token', '', true );
delete_metadata( 'user', 0, '_strava_stories_ignored', '', true );
