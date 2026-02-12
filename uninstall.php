<?php
/**
 * Remove options and transients when plugin is deleted (Plugins → Delete).
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'bv_ig_token' );
delete_option( 'bv_ig_user_id' );

for ( $n = 1; $n <= 20; $n++ ) {
	delete_transient( 'bv_ig_media_' . $n );
}
delete_transient( 'bv_ig_user_id' );
delete_transient( 'bv_ig_last_error' );
