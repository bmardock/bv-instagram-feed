<?php
/**
 * Image proxy for BV Instagram Feed. No WordPress, no DB.
 * Expects signed image URL from shortcode. Resizes for Lighthouse; CDN caches via Cache-Control.
 * Query args: bv_ig_url (base64), bv_ig_sig (HMAC-SHA256 of URL with AUTH_KEY), bv_ig_size.
 */
if ( ! isset( $_GET['bv_ig_url'], $_GET['bv_ig_sig'] ) ) {
	header( 'HTTP/1.1 400 Bad Request' );
	header( 'Cache-Control: no-store' );
	exit;
}
$url_b64 = $_GET['bv_ig_url'];
$sig     = $_GET['bv_ig_sig'];
$size    = isset( $_GET['bv_ig_size'] ) ? preg_replace( '/[^a-z]/', '', $_GET['bv_ig_size'] ) : 'm';
if ( ! in_array( $size, array( 't', 'm', 'l', 'full' ), true ) ) {
	$size = 'm';
}

$max_dim = array( 't' => 150, 'm' => 306, 'l' => 640, 'full' => 1080 );
$max_dim = isset( $max_dim[ $size ] ) ? $max_dim[ $size ] : 306;

// URL-safe base64: restore +/ from -_, fix + if mangled, restore padding so decode is correct.
$url_b64 = str_replace( ' ', '+', $url_b64 );
$url_b64 = strtr( $url_b64, '-_', '+/' );
$url_b64 .= str_repeat( '=', ( 4 - strlen( $url_b64 ) % 4 ) % 4 );
$image_url = @base64_decode( $url_b64, true );
if ( $image_url === false || $image_url === '' || ! preg_match( '#^https?://([a-z0-9.-]+\.(cdninstagram\.com|fbcdn\.net|instagram\.com))/#i', $image_url ) ) {
	header( 'HTTP/1.1 400 Bad Request' );
	header( 'Cache-Control: no-store' );
	exit;
}

$dir = __DIR__;
for ( $i = 0; $i < 5; $i++ ) {
	$dir = dirname( $dir );
	$cfg = $dir . '/wp-config.php';
	if ( is_file( $cfg ) ) {
		break;
	}
}
if ( ! isset( $cfg ) || ! is_file( $cfg ) ) {
	$doc_root = isset( $_SERVER['DOCUMENT_ROOT'] ) ? $_SERVER['DOCUMENT_ROOT'] : '';
	$cfg = $doc_root && is_file( $doc_root . '/wp-config.php' ) ? $doc_root . '/wp-config.php' : '';
	if ( ! $cfg && $doc_root && is_file( $doc_root . '/../wp-config.php' ) ) {
		$cfg = realpath( $doc_root . '/../wp-config.php' );
	}
}
if ( ! $cfg || ! is_file( $cfg ) ) {
	header( 'HTTP/1.1 500 Internal Server Error' );
	header( 'Cache-Control: no-store' );
	exit;
}

$code = file_get_contents( $cfg );
$code = preg_replace( '#//.*$#m', '', $code );
$code = preg_replace( '#/\*.*?\*/#s', '', $code );
if ( ! preg_match( "#define\s*\(\s*['\"]AUTH_KEY['\"]\s*,\s*['\"]([^'\"]*)['\"]#", $code, $m ) ) {
	header( 'HTTP/1.1 500 Internal Server Error' );
	header( 'Cache-Control: no-store' );
	exit;
}
$auth_key = $m[1];
$expected = hash_hmac( 'sha256', $image_url, $auth_key );
if ( ! hash_equals( $expected, $sig ) ) {
	header( 'HTTP/1.1 403 Forbidden' );
	header( 'Cache-Control: no-store' );
	exit;
}

$body = null;
if ( function_exists( 'curl_init' ) ) {
	$ch = curl_init( $image_url );
	curl_setopt_array( $ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_TIMEOUT        => 15,
		CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; BV-IG-Proxy/1)',
		CURLOPT_HTTPHEADER     => array( 'Accept: image/*' ),
	) );
	$body = curl_exec( $ch );
	if ( $body === false || curl_getinfo( $ch, CURLINFO_HTTP_CODE ) >= 400 ) {
		$body = null;
	}
	curl_close( $ch );
}
if ( $body === null || strlen( $body ) === 0 ) {
	$body = @file_get_contents( $image_url, false, stream_context_create( array( 'http' => array( 'timeout' => 15, 'user_agent' => 'Mozilla/5.0' ) ) ) );
}
if ( $body === false || strlen( $body ) === 0 ) {
	header( 'HTTP/1.1 502 Bad Gateway' );
	header( 'Cache-Control: no-store' );
	exit;
}

$ct = 'image/jpeg';
if ( ! empty( $http_response_header ) ) {
	foreach ( (array) $http_response_header as $h ) {
		if ( stripos( $h, 'Content-Type:' ) === 0 ) {
			$ct = trim( explode( ';', substr( $h, strpos( $h, ':' ) + 1 ) )[0] );
			break;
		}
	}
}

if ( $max_dim > 0 && function_exists( 'imagecreatefromstring' ) && function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagecopyresampled' ) ) {
	$img = @imagecreatefromstring( $body );
	if ( $img ) {
		$sw = imagesx( $img );
		$sh = imagesy( $img );
		if ( $sw > 0 && $sh > 0 ) {
			$scale = min( 1.0, $max_dim / max( $sw, $sh ) );
			$dw = (int) max( 1, round( $sw * $scale ) );
			$dh = (int) max( 1, round( $sh * $scale ) );
			$out = imagecreatetruecolor( $dw, $dh );
			if ( $out ) {
				if ( function_exists( 'imagealphablending' ) && function_exists( 'imagesavealpha' ) ) {
					imagealphablending( $out, false );
					imagesavealpha( $out, true );
					$trans = imagecolorallocatealpha( $out, 0, 0, 0, 127 );
					imagefilledrectangle( $out, 0, 0, $dw, $dh, $trans );
				}
				imagecopyresampled( $out, $img, 0, 0, 0, 0, $dw, $dh, $sw, $sh );
				imagedestroy( $img );
				$img = $out;
			}
		}
		ob_start();
		if ( function_exists( 'imagewebp' ) ) {
			@imagewebp( $img, null, 82 );
		} else {
			@imagejpeg( $img, null, 82 );
		}
		$out_body = ob_get_clean();
		if ( $out_body !== '' ) {
			$body = $out_body;
			$ct = function_exists( 'imagewebp' ) ? 'image/webp' : 'image/jpeg';
		}
		imagedestroy( $img );
	}
}

header( 'Content-Type: ' . $ct );
header( 'Cache-Control: public, max-age=86400' );
header( 'Cross-Origin-Resource-Policy: cross-origin' );
header( 'X-Content-Type-Options: nosniff' );
echo $body;
exit;
