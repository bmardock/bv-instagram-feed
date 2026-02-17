<?php
/**
 * Remove options and transients when plugin is deleted (Plugins → Delete).
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'bv_ig_token' );
delete_option( 'bv_ig_user_id' );

$sizes = array( 't', 'm', 'l', 'full' );
for ( $n = 1; $n <= 20; $n++ ) {
	foreach ( $sizes as $size ) {
		delete_transient( 'bv_ig_media_' . $n . '_' . $size );
	}
}
delete_transient( 'bv_ig_user_id' );
delete_transient( 'bv_ig_last_error' );

$cache_dir = WP_CONTENT_DIR . '/cache/bv-instagram-feed';
if ( is_dir( $cache_dir ) ) {
	foreach ( glob( $cache_dir . '/*' ) as $file ) {
		if ( is_file( $file ) ) {
			@unlink( $file );
		}
	}
	@rmdir( $cache_dir );
}
