<?php
/*
Plugin Name: BV Instagram Feed
Description: Lightweight Instagram grid + token refresh for Boardwalk Vintage.
Version: 0.3.1
Author: Boardwalk Vintage
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BV Instagram Feed. Settings → BV Instagram Feed for token + IG user ID.
 * Shortcode: [bv_instagram_grid limit="12" cols="4" size="m"] — images via proxy.php (same-origin).
 * Verify: GET /wp-json/bv/v1/instagram-verify | Filter: bv_instagram_media_cache_seconds
 */

// Option names.
const BV_IG_OPTION_TOKEN   = 'bv_ig_token';
const BV_IG_OPTION_IG_ID   = 'bv_ig_user_id';
const BV_IG_OPTION_CDN_URL = 'bv_ig_cdn_url';
const BV_IG_OPTION_STATUS  = 'bv_ig_status'; // { ok, checked, error, failing_since, notified }

/**
 * Get current token.
 * Priority: option -> BV_IG_TOKEN constant -> BV_IG_TOKEN env.
 */
function bv_instagram_get_token() {
	$token = get_option( BV_IG_OPTION_TOKEN );
	if ( ! $token && defined( 'BV_IG_TOKEN' ) && BV_IG_TOKEN ) {
		$token = BV_IG_TOKEN;
	} elseif ( ! $token && getenv( 'BV_IG_TOKEN' ) ) {
		$token = getenv( 'BV_IG_TOKEN' );
	}
	return apply_filters( 'bv_instagram_access_token', $token );
}

/**
 * Resolve Instagram Business/Creator user id.
 * Priority: BV_IG_USER_ID constant -> option -> transient -> /me/accounts.
 */
function bv_instagram_get_ig_user_id( $token ) {
	if ( empty( $token ) ) {
		return null;
	}

	// Constant (e.g. bv-secrets) overrides option so wrong saved id doesn't stick.
	if ( defined( 'BV_IG_USER_ID' ) && BV_IG_USER_ID ) {
		return BV_IG_USER_ID;
	}

	$manual = get_option( BV_IG_OPTION_IG_ID );
	if ( is_string( $manual ) && $manual !== '' ) {
		return $manual;
	}

	$cached = get_transient( 'bv_ig_user_id' );
	if ( is_string( $cached ) && $cached ) {
		return $cached;
	}

	$endpoint = add_query_arg(
		array(
			'fields'       => 'id,name,instagram_business_account',
			'limit'        => 50,
			'access_token' => $token,
		),
		'https://graph.facebook.com/v24.0/me/accounts'
	);

	$response = wp_remote_get( $endpoint, array( 'timeout' => 10 ) );
	if ( is_wp_error( $response ) ) {
		return null;
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( $code < 200 || $code >= 300 || ! is_array( $body ) || empty( $body['data'] ) ) {
		return null;
	}

	$ig_user_id = null;
	foreach ( (array) $body['data'] as $page ) {
		if ( ! empty( $page['instagram_business_account']['id'] ) ) {
			$ig_user_id = $page['instagram_business_account']['id'];
			break;
		}
	}

	if ( ! $ig_user_id ) {
		return null;
	}

	set_transient( 'bv_ig_user_id', $ig_user_id, 24 * HOUR_IN_SECONDS );
	return $ig_user_id;
}

/**
 * Base URL for proxy.php. Uses CDN when configured (constant BV_IG_CDN_URL or option bv_ig_cdn_url).
 *
 * @return string Full URL to proxy.php (no query args).
 */
function bv_instagram_proxy_base_url() {
	$fallback = set_url_scheme( plugins_url( 'proxy.php', __FILE__ ), is_ssl() ? 'https' : 'http' );
	$cdn = '';
	if ( defined( 'BV_IG_CDN_URL' ) && BV_IG_CDN_URL !== '' ) {
		$cdn = BV_IG_CDN_URL;
	} else {
		$saved = get_option( BV_IG_OPTION_CDN_URL, '' );
		if ( is_string( $saved ) && trim( $saved ) !== '' ) {
			$cdn = trim( $saved );
		}
	}
	if ( $cdn === '' ) {
		return $fallback;
	}
	$path = wp_parse_url( $fallback, PHP_URL_PATH );
	return rtrim( $cdn, '/' ) . ( $path !== null && $path !== '' ? $path : '/wp-content/plugins/bv-instagram-feed/proxy.php' );
}

/**
 * Build signed proxy URL for an image (no DB in proxy; shortcode passes URL + HMAC with AUTH_KEY).
 *
 * @param string $image_url Instagram CDN URL from API.
 * @param string $size      t|m|l|full.
 * @return string Full proxy.php URL or empty if AUTH_KEY missing.
 */
function bv_instagram_proxy_url( $image_url, $size = 'm' ) {
	if ( ! defined( 'AUTH_KEY' ) || AUTH_KEY === '' || $image_url === '' ) {
		return '';
	}
	$size = in_array( $size, array( 't', 'm', 'l', 'full' ), true ) ? $size : 'm';
	// URL-safe base64; strip padding so = in query string doesn't break parsing (e.g. 12th image).
	$url_b64 = rtrim( strtr( base64_encode( $image_url ), '+/', '-_' ), '=' );
	return add_query_arg(
		array(
			'bv_ig_url'  => $url_b64,
			'bv_ig_sig'  => hash_hmac( 'sha256', $image_url, AUTH_KEY ),
			'bv_ig_size' => $size,
		),
		bv_instagram_proxy_base_url()
	);
}

/**
 * Lasting record of whether the feed works, so a dead token doesn't go
 * unnoticed: errors used to live in a 5-minute transient and the home page
 * hides the grid when it fails (the token expired 2026-04-18 and nobody
 * knew until October).
 */
function bv_instagram_record_status( $ok, $error = '' ) {
	$prev = get_option( BV_IG_OPTION_STATUS, array() );
	$prev = is_array( $prev ) ? $prev : array();
	$now  = time();
	$status = array(
		'ok'            => (bool) $ok,
		'checked'       => $now,
		'error'         => $ok ? '' : (string) $error,
		'failing_since' => $ok ? 0 : ( ! empty( $prev['failing_since'] ) ? (int) $prev['failing_since'] : $now ),
		'notified'      => $ok ? 0 : ( isset( $prev['notified'] ) ? (int) $prev['notified'] : 0 ),
	);
	// Email the site admin when it starts failing, then weekly while it stays broken.
	if ( ! $ok && ( ! $status['notified'] || $now - $status['notified'] > WEEK_IN_SECONDS ) ) {
		$sent = wp_mail(
			get_option( 'admin_email' ),
			'[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] Instagram feed is not loading',
			"The Instagram grid on the home page is hidden because the feed can't load.\n\n"
			. 'Error: ' . $status['error'] . "\n"
			. 'Failing since: ' . gmdate( 'Y-m-d H:i', $status['failing_since'] ) . " UTC\n\n"
			. "Fix: Settings → BV Instagram Feed (status and a Test button are there).\n"
			. admin_url( 'options-general.php?page=bv-instagram-feed' ) . "\n"
		);
		if ( $sent ) {
			$status['notified'] = $now;
		}
	}
	update_option( BV_IG_OPTION_STATUS, $status, false );
	return $status;
}

function bv_instagram_fetch_media( $limit = 12, $size = 'm' ) {
	$limit = max( 1, min( 20, (int) $limit ) );
	$size  = in_array( $size, array( 't', 'm', 'l', 'full' ), true ) ? $size : 'm';
	$cache_key = 'bv_ig_media_' . $limit . '_' . $size;
	$cached = get_transient( $cache_key );
	if ( $cached !== false ) {
		return $cached;
	}

	$token = bv_instagram_get_token();
	if ( empty( $token ) ) {
		set_transient( 'bv_ig_last_error', 'no_token', 5 * MINUTE_IN_SECONDS );
		bv_instagram_record_status( false, 'no_token' );
		return array();
	}

	$ig_user_id = bv_instagram_get_ig_user_id( $token );
	if ( empty( $ig_user_id ) ) {
		set_transient( 'bv_ig_last_error', 'no_ig_user_id', 5 * MINUTE_IN_SECONDS );
		bv_instagram_record_status( false, 'no_ig_user_id' );
		return array();
	}

	$endpoint = add_query_arg(
		array(
			'fields'       => 'id,caption,media_url,permalink,thumbnail_url,media_type,media_product_type,timestamp',
			'access_token' => $token,
			'limit'        => $limit * 3,
		),
		'https://graph.facebook.com/v24.0/' . rawurlencode( $ig_user_id ) . '/media'
	);

	$response = wp_remote_get( $endpoint, array( 'timeout' => 8 ) );
	if ( is_wp_error( $response ) ) {
		set_transient( 'bv_ig_last_error', 'media_request_error', 5 * MINUTE_IN_SECONDS );
		bv_instagram_record_status( false, 'media_request_error: ' . $response->get_error_message() );
		set_transient( $cache_key, array(), 5 * MINUTE_IN_SECONDS ); // don't retry on every page view
		return array();
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$msg  = is_array( $body ) && ! empty( $body['error']['message'] ) ? $body['error']['message'] : 'media_api_' . $code;
		set_transient( 'bv_ig_last_error', $msg, 5 * MINUTE_IN_SECONDS );
		bv_instagram_record_status( false, $msg );
		set_transient( $cache_key, array(), 5 * MINUTE_IN_SECONDS ); // don't retry on every page view
		return array();
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $body ) || empty( $body['data'] ) ) {
		set_transient( 'bv_ig_last_error', 'no_media_data', 5 * MINUTE_IN_SECONDS );
		bv_instagram_record_status( false, 'no_media_data' );
		set_transient( $cache_key, array(), 5 * MINUTE_IN_SECONDS ); // don't retry on every page view
		return array();
	}

	// Feed posts only: /media also returns Reels (media_product_type REELS),
	// which aren't what the grid is for. Filter to change it, e.g. add 'REELS'.
	$product_types = (array) apply_filters( 'bv_instagram_media_product_types', array( 'FEED' ) );

	$items = array();
	foreach ( $body['data'] as $item ) {
		if ( ! empty( $item['media_product_type'] ) && ! in_array( $item['media_product_type'], $product_types, true ) ) {
			continue;
		}
		$type = isset( $item['media_type'] ) ? $item['media_type'] : '';
		if ( $type === 'VIDEO' ) {
			if ( empty( $item['thumbnail_url'] ) ) {
				continue;
			}
			$image_url = $item['thumbnail_url'];
		} else {
			$image_url = isset( $item['media_url'] ) ? $item['media_url'] : '';
		}
		if ( empty( $image_url ) || empty( $item['permalink'] ) ) {
			continue;
		}
		// Store API CDN URL so the proxy can fetch it (server-side). Public instagram.com/p/.../media URLs often block server requests.
		$items[] = array(
			'url'   => esc_url_raw( $image_url ),
			'link'  => esc_url_raw( $item['permalink'] ),
			'alt'   => isset( $item['caption'] ) ? wp_strip_all_tags( $item['caption'] ) : 'Instagram photo',
			'time'  => isset( $item['timestamp'] ) ? $item['timestamp'] : '',
			'type'  => $type,
		);
		if ( count( $items ) >= $limit ) {
			break;
		}
	}

	$ttl = (int) apply_filters( 'bv_instagram_media_cache_seconds', 30 * MINUTE_IN_SECONDS );
	set_transient( $cache_key, $items, $ttl > 0 ? $ttl : 30 * MINUTE_IN_SECONDS );
	delete_transient( 'bv_ig_last_error' );
	bv_instagram_record_status( true );
	return $items;
}

function bv_instagram_grid_shortcode( $atts = array() ) {
	$atts = shortcode_atts(
		array(
			'limit' => 12,
			'cols'  => 4,
			'size'  => 'm',
		),
		$atts,
		'bv_instagram_grid'
	);

	$limit = max( 1, min( 20, (int) $atts['limit'] ) );
	$cols  = max( 2, min( 6, (int) $atts['cols'] ) );
	$size  = in_array( $atts['size'], array( 't', 'm', 'l', 'full' ), true ) ? $atts['size'] : 'm';
	$items = bv_instagram_fetch_media( $limit, $size );

	if ( empty( $items ) ) {
		$reason = get_transient( 'bv_ig_last_error' ) ?: 'no items';
		return '<!-- Instagram feed: ' . esc_attr( $reason ) . ' -->';
	}

	ob_start();
	?>
	<style>
		.bv-ig-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
			gap: 8px;
		}
		@media (min-width: 900px) {
			.bv-ig-grid { grid-template-columns: repeat(<?php echo (int) $cols; ?>, 1fr); }
		}
		.bv-ig-grid a {
			position: relative;
			display: block;
			overflow: hidden;
			border-radius: 4px;
			background: #f2f2f2;
		}
		.bv-ig-grid img {
			width: 100%;
			height: 100%;
			object-fit: cover;
			display: block;
		}
	</style>
	<div class="bv-ig-grid" aria-label="Instagram feed">
		<?php
		foreach ( $items as $i => $item ) :
			$img_src = bv_instagram_proxy_url( $item['url'], $size );
			if ( $img_src === '' ) {
				$img_src = $item['url'];
			}
		?>
			<a href="<?php echo esc_url( $item['link'] ); ?>" target="_blank" rel="noopener nofollow">
				<img src="<?php echo esc_url( $img_src ); ?>"
				     alt="<?php echo esc_attr( mb_substr( $item['alt'], 0, 140 ) ); ?>"
				     loading="lazy"
				     decoding="async"
				/>
			</a>
		<?php endforeach; ?>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'bv_instagram_grid', 'bv_instagram_grid_shortcode' );

// Verify endpoint: /wp-json/bv/v1/instagram-verify
function bv_register_instagram_verify_route_plugin() {
	register_rest_route( 'bv/v1', '/instagram-verify', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			if ( current_user_can( 'manage_options' ) ) {
				return true;
			}
			$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( $_SERVER['HTTP_HOST'] ) : '';
			$local_host = ( strpos( $host, 'localhost' ) !== false || strpos( $host, '127.0.0.1' ) !== false || strpos( $host, '.local' ) !== false || strpos( $host, '.test' ) !== false );
			if ( $local_host ) {
				return true;
			}
			if ( defined( 'BV_LOCAL_DEV' ) && BV_LOCAL_DEV ) {
				return true;
			}
			return false;
		},
		'callback'            => 'bv_instagram_verify_callback_plugin',
	) );
}
add_action( 'rest_api_init', 'bv_register_instagram_verify_route_plugin' );

function bv_instagram_verify_callback_plugin() {
	delete_transient( 'bv_ig_user_id' );

	$token = bv_instagram_get_token();
	if ( empty( $token ) ) {
		return new WP_REST_Response( array(
			'ok'       => false,
			'step'     => 'token',
			'message'  => 'No token. Set it in Settings → BV Instagram Feed.',
		), 200 );
	}

	$ig_user_id = bv_instagram_get_ig_user_id( $token );
	if ( empty( $ig_user_id ) ) {
		return new WP_REST_Response( array(
			'ok'       => false,
			'step'     => 'ig_user_id',
			'message'  => 'Could not resolve Instagram user id. Set it in Settings → BV Instagram Feed or ensure pages_show_list is granted.',
		), 200 );
	}

	$items = bv_instagram_fetch_media( 12, 'm' );
	if ( empty( $items ) ) {
		$reason = get_transient( 'bv_ig_last_error' ) ?: 'no media returned';
		return new WP_REST_Response( array(
			'ok'         => false,
			'step'       => 'media',
			'message'    => $reason,
			'ig_user_id' => $ig_user_id,
		), 200 );
	}

	$test_url = isset( $items[0]['url'] ) ? bv_instagram_proxy_url( $items[0]['url'], 'm' ) : '';
	$data = array(
		'ok'              => true,
		'ig_user_id'      => $ig_user_id,
		'media_count'     => count( $items ),
		'test_image_url'  => $test_url,
	);
	return new WP_REST_Response( $data, 200 );
}

/**
 * Daily job: refresh an Instagram-Login token, then check the feed works.
 *
 * Only "IG…" tokens (Instagram API with Instagram Login) can be refreshed at
 * graph.instagram.com. Facebook Graph tokens ("EAA…", what this site uses)
 * can't: the old code called that endpoint for them every day, failed
 * silently, and the 60-day token expired. Use a token that doesn't expire
 * instead (a Business Manager system user token); the health check below
 * emails the admin if it ever stops working.
 */
function bv_instagram_daily() {
	bv_instagram_refresh_token_option();
	foreach ( array( 'bv_ig_media_12_m' ) as $key ) {
		delete_transient( $key ); // force a real API call for the check
	}
	bv_instagram_fetch_media( 12, 'm' );
}

function bv_instagram_refresh_token_option() {
	$token = get_option( BV_IG_OPTION_TOKEN );
	if ( empty( $token ) || 0 !== strpos( $token, 'IG' ) ) {
		return;
	}

	$url = add_query_arg(
		array(
			'grant_type'   => 'ig_refresh_token',
			'access_token' => $token,
		),
		'https://graph.instagram.com/refresh_access_token'
	);

	$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
	if ( is_wp_error( $response ) ) {
		return;
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		return;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $body['access_token'] ) ) {
		return;
	}

	update_option( BV_IG_OPTION_TOKEN, sanitize_text_field( $body['access_token'] ) );
}
add_action( 'bv_instagram_refresh_token', 'bv_instagram_daily' );

function bv_instagram_schedule_refresh_plugin() {
	if ( ! wp_next_scheduled( 'bv_instagram_refresh_token' ) ) {
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'bv_instagram_refresh_token' );
	}
}
add_action( 'init', 'bv_instagram_schedule_refresh_plugin' );

// Dashboard warning while the feed is failing (admins only).
function bv_instagram_admin_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$status = get_option( BV_IG_OPTION_STATUS, array() );
	if ( empty( $status ) || ! empty( $status['ok'] ) ) {
		return;
	}
	printf(
		'<div class="notice notice-error"><p><strong>Instagram feed is not loading</strong> (since %1$s): %2$s. <a href="%3$s">Settings → BV Instagram Feed</a></p></div>',
		esc_html( gmdate( 'M j, Y', (int) $status['failing_since'] ) ),
		esc_html( mb_substr( (string) $status['error'], 0, 200 ) ),
		esc_url( admin_url( 'options-general.php?page=bv-instagram-feed' ) )
	);
}
add_action( 'admin_notices', 'bv_instagram_admin_notice' );

// Admin settings page.
function bv_instagram_settings_menu() {
	add_options_page(
		'BV Instagram Feed',
		'BV Instagram Feed',
		'manage_options',
		'bv-instagram-feed',
		'bv_instagram_settings_page'
	);
}
add_action( 'admin_menu', 'bv_instagram_settings_menu' );

function bv_instagram_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( isset( $_POST['bv_ig_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bv_ig_settings_nonce'] ) ), 'bv_ig_settings_save' ) ) {
		$token  = isset( $_POST['bv_ig_token'] ) ? trim( (string) wp_unslash( $_POST['bv_ig_token'] ) ) : '';
		$ig_id  = isset( $_POST['bv_ig_user_id'] ) ? preg_replace( '/\D/', '', (string) wp_unslash( $_POST['bv_ig_user_id'] ) ) : '';
		$cdn_url = isset( $_POST['bv_ig_cdn_url'] ) ? trim( esc_url_raw( wp_unslash( $_POST['bv_ig_cdn_url'] ) ) ) : '';
		// The field never shows the saved token, so blank means "keep it";
		// clearing takes the checkbox (saving any other setting used to wipe it).
		if ( $token !== '' ) {
			update_option( BV_IG_OPTION_TOKEN, $token );
		} elseif ( ! empty( $_POST['bv_ig_clear_token'] ) ) {
			update_option( BV_IG_OPTION_TOKEN, '' );
		}
		update_option( BV_IG_OPTION_IG_ID, $ig_id );
		update_option( BV_IG_OPTION_CDN_URL, $cdn_url );
		echo '<div class="updated"><p>Settings saved.</p></div>';
	}
	if ( isset( $_POST['bv_ig_test_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bv_ig_test_nonce'] ) ), 'bv_ig_test' ) ) {
		delete_transient( 'bv_ig_media_12_m' );
		delete_transient( 'bv_ig_user_id' );
		$items = bv_instagram_fetch_media( 12, 'm' );
		echo $items
			? '<div class="updated"><p>Feed works: ' . count( $items ) . ' posts loaded.</p></div>'
			: '<div class="error"><p>Feed failed: ' . esc_html( (string) get_transient( 'bv_ig_last_error' ) ) . '</p></div>';
	}
	$status = get_option( BV_IG_OPTION_STATUS, array() );

	$token = get_option( BV_IG_OPTION_TOKEN, '' );
	$ig_id = get_option( BV_IG_OPTION_IG_ID, '' );
	$cdn_url = get_option( BV_IG_OPTION_CDN_URL, '' );
	$token_placeholder = $token !== '' ? '••••••••' : '';
	?>
	<div class="wrap">
		<h1>BV Instagram Feed</h1>
		<p>
			<strong>Status:</strong>
			<?php if ( empty( $status ) ) : ?>
				not checked yet
			<?php elseif ( ! empty( $status['ok'] ) ) : ?>
				<span style="color:#008a20">working</span> (checked <?php echo esc_html( human_time_diff( (int) $status['checked'] ) ); ?> ago)
			<?php else : ?>
				<span style="color:#d63638">failing since <?php echo esc_html( gmdate( 'M j, Y', (int) $status['failing_since'] ) ); ?></span>: <?php echo esc_html( (string) $status['error'] ); ?>
			<?php endif; ?>
		</p>
		<form method="post" style="margin-bottom:1em">
			<?php wp_nonce_field( 'bv_ig_test', 'bv_ig_test_nonce' ); ?>
			<?php submit_button( 'Test now', 'secondary', 'bv_ig_test', false ); ?>
		</form>
		<form method="post">
			<?php wp_nonce_field( 'bv_ig_settings_save', 'bv_ig_settings_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="bv_ig_token">Access token</label></th>
					<td>
						<input type="password" id="bv_ig_token" name="bv_ig_token" class="regular-text" value="" placeholder="<?php echo esc_attr( $token_placeholder ); ?>" autocomplete="off" />
						<p class="description">Use a token that doesn't expire: a system user token from Meta Business settings (permissions instagram_basic, pages_show_list, pages_read_engagement). Leave blank to keep the saved token. Constant BV_IG_USER_ID overrides the ID below.</p>
							<label><input type="checkbox" name="bv_ig_clear_token" value="1" /> Clear saved token</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bv_ig_user_id">Instagram Business Account ID</label></th>
					<td>
						<input type="text" id="bv_ig_user_id" name="bv_ig_user_id" class="regular-text" value="<?php echo esc_attr( $ig_id ); ?>" placeholder="e.g. 17841401718325671" />
						<p class="description">Numeric ID from Meta app → Instagram API setup.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bv_ig_cdn_url">CDN URL (proxy)</label></th>
					<td>
						<input type="url" id="bv_ig_cdn_url" name="bv_ig_cdn_url" class="regular-text" value="<?php echo esc_attr( $cdn_url ); ?>" placeholder="https://cdn.example.com" />
						<p class="description">When set, image proxy URLs use this origin (e.g. <code>https://cdn.example.com</code>). Leave blank to use the current site. Constant <code>BV_IG_CDN_URL</code> overrides when set.</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
