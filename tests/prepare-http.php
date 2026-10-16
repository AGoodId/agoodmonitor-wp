<?php
/** Disposable local fixture setup; run before starting the localhost HTTP tests. */
$test_wp_path = getenv( 'AGOODMONITOR_TEST_WP' );
$test_codes_path = getenv( 'AGOODMONITOR_TEST_CODES' );
if ( ! $test_wp_path || ! $test_codes_path ) {
	exit( "Set AGOODMONITOR_TEST_WP and AGOODMONITOR_TEST_CODES.\n" );
}
require $test_wp_path . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || 'agm_test_' !== $GLOBALS['table_prefix'] || ! defined( 'AGOODMONITOR_TEST_PLUGIN' ) ) {
	exit( "Refusing non-disposable install. See tests/README.md.\n" );
}
update_option( 'agoodmonitor_turnstile', [ 'enabled' => true, 'site_key' => 'test-site', 'secret_key' => 'test-only-secret', 'woocommerce' => true ], false );
foreach ( [ 'agm_turnstile_test', 'agm_2fa_test' ] as $login ) {
	if ( ! username_exists( $login ) ) {
		wp_create_user( $login, 'test-only-disposable-password', $login . '@example.invalid' );
	}
}
$test_user_id = username_exists( 'agm_2fa_test' );
$test_user = get_userdata( $test_user_id );
$secret = Two_Factor_Totp::generate_key();
update_user_meta( $test_user_id, Two_Factor_Totp::SECRET_META_KEY, $secret );
delete_user_meta( $test_user_id, Two_Factor_Totp::LAST_SUCCESSFUL_LOGIN_META_KEY );
update_user_meta( $test_user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, 'Two_Factor_Totp' );
update_user_meta( $test_user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, [ 'Two_Factor_Totp', 'Two_Factor_Backup_Codes' ] );
$codes = Two_Factor_Backup_Codes::get_instance()->generate_codes( $test_user );
$page_id = (int) get_option( 'agm_test_account_page', 0 );
if ( ! $page_id ) {
	$page_id = wp_insert_post( [ 'post_title' => 'Disposable test account', 'post_content' => '[woocommerce_my_account]', 'post_status' => 'publish', 'post_type' => 'page' ] );
	update_option( 'agm_test_account_page', $page_id );
}
update_option( 'woocommerce_myaccount_page_id', $page_id );
file_put_contents( $test_codes_path, wp_json_encode( [ 'totp_secret' => $secret, 'backup' => $codes[0], 'account_path' => '/?page_id=' . $page_id ] ) );
chmod( $test_codes_path, 0600 );
echo "Disposable HTTP fixtures prepared.\n";
