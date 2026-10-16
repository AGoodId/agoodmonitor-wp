<?php
/**
 * Run only against a disposable WordPress install with Two Factor + WooCommerce.
 * AGOODMONITOR_TEST_WP=/absolute/wordpress php tests/turnstile-integration.php
 * Requires WP_ENVIRONMENT_TYPE=local and table prefix agm_test_. No real mail/HTTP.
 */
$test_wp_path = getenv( 'AGOODMONITOR_TEST_WP' );
if ( ! $test_wp_path ) {
	fwrite( STDERR, "Set AGOODMONITOR_TEST_WP to a disposable WordPress directory.\n" );
	exit( 1 );
}
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require $test_wp_path . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || 'agm_test_' !== $GLOBALS['table_prefix'] ) {
	fwrite( STDERR, "Refusing non-disposable environment (local + agm_test_ required).\n" );
	exit( 1 );
}
require dirname( __DIR__ ) . '/inc/class-turnstile.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

class AGM_Test_Blocked extends RuntimeException {}
class AGM_Test_Redirect extends RuntimeException {}
$passed = 0;
$http_calls = 0;
$mail_calls = 0;
$api_mode = 'success';
$expected_action = 'agm_login';
$used = [];
$config = [ 'enabled' => true, 'site_key' => 'test-site', 'secret_key' => 'secret-must-never-render', 'woocommerce' => true ];

function check( bool $ok, string $label ): void {
	if ( ! $ok ) {
		throw new RuntimeException( 'FAIL: ' . $label );
	}
	$GLOBALS['passed']++;
}
function blocked( callable $operation ): bool {
	try {
		$operation();
	} catch ( AGM_Test_Blocked $e ) {
		return true;
	}
	return false;
}
function boot( array $settings ): AGoodMonitor_Turnstile {
	foreach ( [ 'login_form_login', 'login_form_lostpassword', 'login_form_retrievepassword', 'login_form', 'lostpassword_form', 'woocommerce_login_form', 'woocommerce_lostpassword_form' ] as $hook ) {
		remove_all_actions( $hook );
	}
	// Remove only this module's wp_loaded hook, preserving real WC handlers.
	foreach ( $GLOBALS['wp_filter']['wp_loaded']->callbacks ?? [] as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof AGoodMonitor_Turnstile ) {
				remove_action( 'wp_loaded', $callback['function'], -1000 );
			}
		}
	}
	update_option( AGoodMonitor_Turnstile::OPTION, $settings, false );
	$module = new AGoodMonitor_Turnstile();
	$module->register_protection();
	return $module;
}
function submit_settings( AGoodMonitor_Turnstile $module, array $fields ): void {
	$_POST = array_merge( [ '_wpnonce' => wp_create_nonce( 'agoodmonitor_turnstile_save' ) ], $fields );
	$_REQUEST = $_POST;
	try { $module->save(); } catch ( AGM_Test_Redirect $e ) {}
}
add_filter( 'wp_die_handler', static fn() => static function ( $message ): void {
	throw new AGM_Test_Blocked( is_wp_error( $message ) ? $message->get_error_message() : (string) $message );
} );
add_filter( 'wp_redirect', static function (): void { throw new AGM_Test_Redirect(); } );
add_filter( 'pre_wp_mail', static function () { $GLOBALS['mail_calls']++; return true; } );
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) {
	if ( AGoodMonitor_Turnstile::VERIFY_URL !== $url ) {
		return new WP_Error( 'external_http_blocked' );
	}
	$GLOBALS['http_calls']++;
	check( 8 === $args['timeout'] && 0 === $args['redirection'], 'bounded HTTP request without secret-bearing redirect' );
	$mode = $GLOBALS['api_mode'];
	if ( 'timeout' === $mode ) { return new WP_Error( 'http_request_failed', 'sensitive upstream detail' ); }
	if ( 'http_error' === $mode ) { return [ 'response' => [ 'code' => 503 ], 'body' => 'secret-must-never-render' ]; }
	if ( 'json_error' === $mode ) { return [ 'response' => [ 'code' => 200 ], 'body' => '{bad' ]; }
	$token = $args['body']['response'];
	$success = 'success' === $mode && ! isset( $GLOBALS['used'][ $token ] );
	$GLOBALS['used'][ $token ] = true;
	$data = [ 'success' => $success, 'action' => $GLOBALS['expected_action'], 'hostname' => '127.0.0.1' ];
	if ( 'hostname' === $mode ) { $data['success'] = true; $data['hostname'] = 'attacker.invalid'; }
	if ( 'action' === $mode ) { $data['success'] = true; $data['action'] = 'other_form'; }
	if ( 'nonboolean' === $mode ) { $data['success'] = 'true'; }
	return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( $data ) ];
}, 10, 3 );

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_HOST'] = 'attacker.invalid'; // Must never influence accepted hostname.
$_POST = [];
$module = boot( [] );
do_action( 'login_form_login' );
do_action( 'login_form_lostpassword' );
check( 0 === $http_calls && ! has_action( 'login_form_login' ), 'default-off installs no protection hooks or HTTP' );
ob_start(); do_action( 'login_form' ); check( '' === ob_get_clean(), 'default-off emits no widget' );
check( ! wp_script_is( 'agoodmonitor-turnstile', 'enqueued' ), 'default-off loads no script' );

$module = boot( $config );
check( ! has_filter( 'authenticate', [ $module, 'verify' ] ), 'does not attach global authentication filters' );
check( blocked( static fn() => do_action( 'login_form_login' ) ), 'direct login POST missing token blocked' );
check( blocked( static fn() => do_action( 'login_form_lostpassword' ) ), 'direct lostpassword POST missing token blocked' );
check( blocked( static fn() => do_action( 'login_form_retrievepassword' ) ), 'legacy retrieval POST missing token blocked' );
foreach ( [ [], str_repeat( 'a', 2049 ), '' ] as $token ) {
	$_POST = [ 'cf-turnstile-response' => $token ];
	check( blocked( static fn() => do_action( 'login_form_login' ) ), 'malformed token fails closed' );
}
foreach ( [ 'invalid', 'expired', 'timeout', 'http_error', 'json_error', 'hostname', 'action', 'nonboolean' ] as $api_mode ) {
	$_POST = [ 'cf-turnstile-response' => uniqid( 'case_', true ) ];
	check( blocked( static fn() => do_action( 'login_form_login' ) ), $api_mode . ' fails closed' );
}
check( 0 === $mail_calls, 'failed challenges have no mail side effects' );
$api_mode = 'success';
$_POST = [ 'cf-turnstile-response' => 'once-only' ];
do_action( 'login_form_login' );
check( blocked( static fn() => do_action( 'login_form_login' ) ), 'replayed token rejected by Siteverify contract' );

$id = username_exists( 'agm_turnstile_test' );
if ( ! $id ) { $id = wp_create_user( 'agm_turnstile_test', 'test-only-disposable-password', 'turnstile@example.invalid' ); }
check( is_int( $id ), 'disposable test user exists' );
$_POST = [ 'cf-turnstile-response' => 'valid-login' ];
do_action( 'login_form_login' );
$user = wp_signon( [ 'user_login' => 'agm_turnstile_test', 'user_password' => 'test-only-disposable-password' ], false );
check( $user instanceof WP_User, 'verified request allows real WordPress password authentication' );
$expected_action = 'agm_lostpassword';
$_POST = [ 'cf-turnstile-response' => 'valid-reset', 'user_login' => 'agm_turnstile_test' ];
do_action( 'login_form_lostpassword' );
check( true === retrieve_password( 'agm_turnstile_test' ) && 1 === $mail_calls, 'verified reset reaches real core with mail intercepted' );
$key_before = get_userdata( $id )->user_activation_key;
$_POST = [ 'user_login' => 'agm_turnstile_test' ];
try { do_action( 'login_form_lostpassword' ); retrieve_password( 'agm_turnstile_test' ); } catch ( AGM_Test_Blocked $e ) {}
check( $key_before === get_userdata( $id )->user_activation_key && 1 === $mail_calls, 'blocked reset does not rotate key or send mail' );

check( class_exists( 'WooCommerce' ), 'real WooCommerce loaded' );
$_POST = [ 'login' => '1', 'username' => 'agm_turnstile_test', 'password' => 'test-only-disposable-password' ];
check( blocked( static fn() => $module->guard_woocommerce() ), 'WC direct login without token blocked' );
$_POST = [ 'wc_reset_password' => '1', 'user_login' => 'agm_turnstile_test' ];
check( blocked( static fn() => $module->guard_woocommerce() ), 'WC direct retrieval without token blocked' );
$expected_action = 'agm_wc_login';
$_POST = [ 'login' => '1', 'username' => 'agm_turnstile_test', 'password' => 'test-only-disposable-password', 'cf-turnstile-response' => 'wc-valid' ];
$module->guard_woocommerce();
check( wp_signon( [ 'user_login' => 'agm_turnstile_test', 'user_password' => 'test-only-disposable-password' ], false ) instanceof WP_User, 'verified WC credentials authenticate' );
$expected_action = 'agm_wc_lostpassword';
$_POST = [ 'wc_reset_password' => '1', 'user_login' => 'agm_turnstile_test', 'cf-turnstile-response' => 'wc-reset-valid' ];
$module->guard_woocommerce();
check( WC_Shortcode_My_Account::retrieve_password() && 2 === $mail_calls, 'verified real WC retrieval with mail intercepted' );

$before = $http_calls;
$_POST = [ 'authcode' => '123456' ];
try { do_action( 'login_form_revalidate_2fa' ); } catch ( AGM_Test_Redirect $e ) {}
check( $before === $http_calls, '2FA action does not require a second Turnstile token' );
$totp = Two_Factor_Totp::get_instance();
$secret = Two_Factor_Totp::generate_key();
update_user_meta( $id, Two_Factor_Totp::SECRET_META_KEY, $secret );
$_REQUEST = [ 'authcode' => Two_Factor_Totp::calc_totp( $secret ) ];
check( $totp->validate_authentication( $user ), 'real Two Factor TOTP provider accepts test code' );
$backup = Two_Factor_Backup_Codes::get_instance();
$codes = $backup->generate_codes( $user );
$_REQUEST = [ 'two-factor-backup-code' => $codes[0] ];
check( $backup->validate_authentication( $user ), 'real Two Factor backup provider accepts test code' );
check( ! $backup->validate_authentication( $user ), 'backup code cannot be reused' );

wp_set_current_user( 1 );
$_POST = []; $_GET = [];
ob_start(); $module->render_settings(); $html = ob_get_clean();
check( ! str_contains( $html, $config['secret_key'] ), 'stored secret absent from admin HTML' );
ob_start(); $module->render_login(); $html = ob_get_clean();
check( ! str_contains( $html, $config['secret_key'] ) && str_contains( $html, 'agm_login' ), 'widget exposes only public key and action' );
submit_settings( $module, [ 'site_key' => 'test-site', 'woocommerce' => '1' ] );
check( ! get_option( AGoodMonitor_Turnstile::OPTION )['enabled'], 'save can disable protection' );
submit_settings( $module, [ 'site_key' => 'test-site', 'woocommerce' => '1', 'enabled' => '1' ] );
check( ! get_option( AGoodMonitor_Turnstile::OPTION )['enabled'], 'cannot enable without successful configuration probe' );
$expected_action = 'agm_setup';
$_POST = [ '_wpnonce' => wp_create_nonce( 'agoodmonitor_turnstile_probe' ), 'cf-turnstile-response' => 'probe-valid' ]; $_REQUEST = $_POST;
try { $module->probe(); } catch ( AGM_Test_Redirect $e ) {}
submit_settings( $module, [ 'site_key' => 'test-site', 'woocommerce' => '1', 'enabled' => '1' ] );
check( get_option( AGoodMonitor_Turnstile::OPTION )['enabled'], 'successful probe enables matching configuration' );
submit_settings( $module, [ 'site_key' => 'changed-site', 'woocommerce' => '1', 'enabled' => '1' ] );
check( ! get_option( AGoodMonitor_Turnstile::OPTION )['enabled'], 'key rotation disables protection until fresh probe' );
submit_settings( $module, [ 'site_key' => 'changed-site', 'secret_key' => 'malformed secret!', 'woocommerce' => '1', 'enabled' => '1' ] );
check( '' === get_option( AGoodMonitor_Turnstile::OPTION )['secret_key'] && ! get_option( AGoodMonitor_Turnstile::OPTION )['enabled'], 'invalid replacement secret disables protection rather than silently retaining old key' );
wp_set_current_user( 0 );
check( blocked( static fn() => $module->save() ), 'anonymous settings save forbidden' );
wp_set_current_user( 1 ); $_REQUEST = [ '_wpnonce' => 'bad' ];
check( blocked( static fn() => $module->save() ), 'invalid settings nonce forbidden' );
wp_set_current_user( 0 );

$conflict = static fn() => [ 'custom-turnstile/provider.php' ];
$module = boot( $config );
add_filter( 'agoodmonitor_turnstile_conflicts', $conflict );
$before = $http_calls;
$_POST = [ 'cf-turnstile-response' => 'conflict-valid' ];
check( is_wp_error( $module->verify( 'agm_login' ) ) && $before === $http_calls, 'conflict fails closed without consuming token twice' );
remove_filter( 'agoodmonitor_turnstile_conflicts', $conflict );

$configured = array_merge( $config, [ 'enabled' => false ] );
$module = boot( $configured );
wp_set_current_user( 1 );
$different_home = static fn() => 'https://separate-wc.invalid';
add_filter( 'home_url', $different_home );
$fingerprint = new ReflectionMethod( $module, 'fingerprint' );
$fingerprint->setAccessible( true );
set_transient( 'agoodmonitor_ts_probe_1', $fingerprint->invoke( $module, $configured ), 600 );
submit_settings( $module, [ 'site_key' => 'test-site', 'woocommerce' => '1', 'enabled' => '1' ] );
check( ! get_option( AGoodMonitor_Turnstile::OPTION )['enabled'], 'separate WC origin cannot be activated without supported setup flow' );
remove_filter( 'home_url', $different_home );
wp_set_current_user( 0 );

$module = boot( array_merge( $config, [ 'woocommerce' => false ] ) );
check( ! has_action( 'woocommerce_login_form' ), 'WC opt-out attaches no widget' );
$before = $http_calls;
check( wp_authenticate( 'agm_turnstile_test', 'test-only-disposable-password' ) instanceof WP_User && $before === $http_calls, 'unrelated authentication has no Turnstile requirement' );
define( 'DOING_AJAX', true );
add_filter( 'wp_die_ajax_handler', static fn() => static function (): void { throw new AGM_Test_Blocked(); } );
$module = boot( $config );
$_POST = [ 'login' => '1', 'username' => 'test', 'password' => 'test' ];
check( blocked( static fn() => $module->guard_woocommerce() ), 'AJAX flag cannot bypass a reachable native WC handler' );
$_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = [];
$before = $http_calls; do_action( 'login_form_login' );
check( $before === $http_calls, 'GET does not validate' );
define( 'AGOODMONITOR_TURNSTILE_DISABLE', true );
$module = boot( $config );
check( ! has_action( 'login_form_login' ) && ! has_action( 'lostpassword_form' ), 'server recovery override removes protection hooks' );
global $wpdb;
check( 'off' === $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", AGoodMonitor_Turnstile::OPTION ) ), 'credentials are not autoloaded' );
echo "PASS: {$passed} assertions. External HTTP mocked; all mail intercepted.\n";
