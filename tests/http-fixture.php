<?php
/** TEST ONLY: copy into a disposable install's wp-content/mu-plugins directory.
 * Set AGOODMONITOR_TEST_PLUGIN to the repo path in that install's wp-config.php.
 * Never install this fixture on a reachable site. It deliberately simulates CF.
 */
if ( ! defined( 'AGOODMONITOR_TEST_PLUGIN' ) || 'local' !== wp_get_environment_type() || 'agm_test_' !== $GLOBALS['table_prefix'] ) {
	return;
}
add_filter( 'pre_wp_mail', static function () { update_option( 'agm_test_mail_count', (int) get_option( 'agm_test_mail_count', 0 ) + 1 ); return true; } );
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) {
	if ( 'https://challenges.cloudflare.com/turnstile/v0/siteverify' !== $url ) {
		return new WP_Error( 'test_external_http_blocked' );
	}
	$token = $args['body']['response'];
	$action = explode( ':', $token )[0];
	$valid = str_starts_with( $action, 'agm_' ) && add_option( 'agm_test_used_' . hash( 'sha256', $token ), true, '', false );
	return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [ 'success' => $valid, 'action' => $action, 'hostname' => '127.0.0.1' ] ) ];
}, 10, 3 );
require AGOODMONITOR_TEST_PLUGIN . '/inc/class-turnstile.php';
new AGoodMonitor_Turnstile();
