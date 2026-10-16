<?php
/**
 * Optional, site-local Turnstile protection for native login/reset requests.
 * No authenticate filter: application passwords, REST and 2FA actions stay separate.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AGoodMonitor_Turnstile {
	const OPTION = 'agoodmonitor_turnstile';
	const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
	const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'admin_menu' ] );
		add_action( 'admin_post_agoodmonitor_turnstile_save', [ $this, 'save' ] );
		add_action( 'admin_post_agoodmonitor_turnstile_probe', [ $this, 'probe' ] );
		add_action( 'admin_notices', [ $this, 'recovery_notice' ] );
		add_action( 'plugins_loaded', [ $this, 'register_protection' ], 100 );
	}

	private function settings(): array {
		$value = get_option( self::OPTION, [] );
		return is_array( $value ) ? $value : [];
	}

	private function bypassed(): bool {
		return defined( 'AGOODMONITOR_TURNSTILE_DISABLE' ) && true === AGOODMONITOR_TURNSTILE_DISABLE;
	}

	/** Exact configured origin, never the caller-controlled Host header. */
	private function hostname( string $action ): string {
		$url = str_starts_with( $action, 'agm_wc_' ) ? home_url() : site_url();
		return strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	}

	/** Known providers; deployments can extend this for custom/MU integrations. */
	public function conflicts(): array {
		$plugins = array_merge( (array) get_option( 'active_plugins', [] ), array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
		$found = [];
		foreach ( $plugins as $plugin ) {
			if ( preg_match( '~^(simple-cloudflare-turnstile|wp-cloudflare-turnstile|simple-turnstile)/~', $plugin ) ) {
				$found[] = $plugin;
			}
		}
		return (array) apply_filters( 'agoodmonitor_turnstile_conflicts', $found );
	}

	public function register_protection(): void {
		if ( empty( $this->settings()['enabled'] ) || $this->bypassed() ) {
			return;
		}
		// Early action gates also cover POSTs without a rendered widget or nonce.
		add_action( 'login_form_login', [ $this, 'guard_login' ], -1000 );
		add_action( 'login_form_lostpassword', [ $this, 'guard_lostpassword' ], -1000 );
		add_action( 'login_form_retrievepassword', [ $this, 'guard_lostpassword' ], -1000 );
		add_action( 'login_form', [ $this, 'render_login' ] );
		add_action( 'lostpassword_form', [ $this, 'render_lostpassword' ] );
		if ( ! empty( $this->settings()['woocommerce'] ) && class_exists( 'WooCommerce' ) ) {
			add_action( 'wp_loaded', [ $this, 'guard_woocommerce' ], -1000 );
			add_action( 'woocommerce_login_form', [ $this, 'render_wc_login' ] );
			add_action( 'woocommerce_lostpassword_form', [ $this, 'render_wc_lostpassword' ] );
		}
	}

	public function guard_login(): void {
		$this->guard( 'agm_login' );
	}

	public function guard_lostpassword(): void {
		$this->guard( 'agm_lostpassword' );
	}

	public function guard_woocommerce(): void {
		// Same markers used by WC_Form_Handler; no URL/referrer dependency.
		if ( isset( $_POST['login'], $_POST['username'], $_POST['password'] ) ) {
			$this->guard( 'agm_wc_login' );
		} elseif ( isset( $_POST['wc_reset_password'], $_POST['user_login'] ) ) {
			$this->guard( 'agm_wc_lostpassword' );
		}
	}

	private function guard( string $action ): void {
		// Scope comes from the native handler, not AJAX/REST flags an attacker
		// can add while still reaching WC_Form_Handler on wp_loaded.
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}
		$result = $this->verify( $action );
		if ( is_wp_error( $result ) ) {
			wp_die( $result, esc_html__( 'Botskydd', 'agoodmonitor' ), [ 'response' => 403, 'back_link' => true ] );
		}
	}

	private function failure(): WP_Error {
		return new WP_Error( 'agoodmonitor_turnstile', __( 'Botskyddet kunde inte verifieras. Ladda om formuläret och försök igen. Kontakta sajtadministratören om felet kvarstår.', 'agoodmonitor' ) );
	}

	/** Never return upstream bodies, tokens or keys in errors, telemetry or logs. */
	public function verify( string $action ): bool|WP_Error {
		$settings = $this->settings();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Public forms are protected by server-side Siteverify, not a WP nonce.
		$token = isset( $_POST['cf-turnstile-response'] ) && is_string( $_POST['cf-turnstile-response'] ) ? trim( wp_unslash( $_POST['cf-turnstile-response'] ) ) : '';
		if ( '' === $token || strlen( $token ) > 2048 || empty( $settings['site_key'] ) || empty( $settings['secret_key'] ) || $this->conflicts() ) {
			return $this->failure();
		}
		$response = wp_remote_post( self::VERIFY_URL, [
			'timeout' => 8,
			'redirection' => 0,
			'limit_response_size' => 16384,
			'body' => [ 'secret' => $settings['secret_key'], 'response' => $token ],
		] );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return $this->failure();
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$hostname = $this->hostname( $action );
		if ( ! is_array( $data ) || true !== ( $data['success'] ?? false ) || '' === $hostname
			|| $hostname !== ( $data['hostname'] ?? null ) || $action !== ( $data['action'] ?? null ) ) {
			return $this->failure();
		}
		return true;
	}

	public function render_login(): void {
		$this->widget( 'agm_login' );
	}

	public function render_lostpassword(): void {
		$this->widget( 'agm_lostpassword' );
	}

	public function render_wc_login(): void {
		$this->widget( 'agm_wc_login' );
	}

	public function render_wc_lostpassword(): void {
		$this->widget( 'agm_wc_lostpassword' );
	}

	private function widget( string $action ): void {
		$settings = $this->settings();
		if ( empty( $settings['site_key'] ) || $this->conflicts() ) {
			echo '<p>' . esc_html__( 'Botskyddet är inte tillgängligt. Kontakta sajtadministratören.', 'agoodmonitor' ) . '</p>';
			return;
		}
		wp_enqueue_script( 'agoodmonitor-turnstile', self::SCRIPT_URL, [], null, true );
		echo '<div class="cf-turnstile" data-sitekey="' . esc_attr( $settings['site_key'] ) . '" data-action="' . esc_attr( $action ) . '" data-size="flexible"></div>';
		echo '<noscript><p>' . esc_html__( 'JavaScript krävs för botskyddet. Kontakta sajtadministratören om du behöver hjälp.', 'agoodmonitor' ) . '</p></noscript>';
	}

	public function admin_menu(): void {
		add_options_page( 'AGoodMonitor Turnstile', 'AGoodMonitor Turnstile', 'manage_options', 'agoodmonitor-turnstile', [ $this, 'render_settings' ] );
	}

	public function recovery_notice(): void {
		if ( $this->bypassed() && current_user_can( 'manage_options' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'AGoodMonitor Turnstile är avstängt via AGOODMONITOR_TURNSTILE_DISABLE i serverkonfigurationen.', 'agoodmonitor' ) . '</p></div>';
		}
	}

	private function authorize( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'Ej behörig.', 'agoodmonitor' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( $action );
	}

	private function fingerprint( array $settings ): string {
		return hash_hmac( 'sha256', wp_json_encode( [ $settings['site_key'] ?? '', $settings['secret_key'] ?? '', ! empty( $settings['woocommerce'] ), site_url(), home_url() ] ), wp_salt() );
	}

	/** Saving changed credentials always turns protection OFF until re-probed. */
	public function save(): void {
		$this->authorize( 'agoodmonitor_turnstile_save' );
		$old = $this->settings();
		$secret_submitted = isset( $_POST['secret_key'] ) && ( ! is_string( $_POST['secret_key'] ) || '' !== trim( wp_unslash( $_POST['secret_key'] ) ) );
		$settings = [
			'site_key' => $this->posted_key( 'site_key' ),
			'secret_key' => $secret_submitted ? $this->posted_key( 'secret_key' ) : ( $old['secret_key'] ?? '' ),
			'woocommerce' => ! empty( $_POST['woocommerce'] ),
			'enabled' => false,
		];
		if ( ! empty( $_POST['clear_secret'] ) ) {
			$settings['secret_key'] = '';
		}
		$receipt = get_transient( 'agoodmonitor_ts_probe_' . get_current_user_id() );
		$unchanged = hash_equals( $this->fingerprint( $old ), $this->fingerprint( $settings ) );
		$verified = is_string( $receipt ) && hash_equals( $this->fingerprint( $settings ), $receipt );
		// The setup widget is on the WP admin origin. Separate WC origins need a
		// separate probe implementation before they can be enabled safely.
		$supported_origin = empty( $settings['woocommerce'] ) || $this->hostname( 'agm_login' ) === $this->hostname( 'agm_wc_login' );
		if ( ! empty( $_POST['enabled'] ) && $unchanged && ( $verified || ! empty( $old['enabled'] ) )
			&& $supported_origin && ! empty( $settings['site_key'] ) && ! empty( $settings['secret_key'] ) && ! $this->conflicts() && ! $this->bypassed() ) {
			$settings['enabled'] = true;
		}
		update_option( self::OPTION, $settings, false );
		delete_transient( 'agoodmonitor_ts_probe_' . get_current_user_id() );
		$this->redirect( $settings['enabled'] ? 'enabled' : 'saved' );
	}

	private function posted_key( string $name ): string {
		$value = isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ? trim( wp_unslash( $_POST[ $name ] ) ) : '';
		return preg_match( '/^[A-Za-z0-9_-]{1,256}$/D', $value ) ? $value : '';
	}

	public function probe(): void {
		$this->authorize( 'agoodmonitor_turnstile_probe' );
		delete_transient( 'agoodmonitor_ts_probe_' . get_current_user_id() );
		$result = $this->verify( 'agm_setup' );
		if ( true === $result ) {
			set_transient( 'agoodmonitor_ts_probe_' . get_current_user_id(), $this->fingerprint( $this->settings() ), 10 * MINUTE_IN_SECONDS );
		}
		$this->redirect( true === $result ? 'verified' : 'failed' );
	}

	private function redirect( string $status ): void {
		wp_safe_redirect( add_query_arg( 'ts_status', $status, admin_url( 'options-general.php?page=agoodmonitor-turnstile' ) ) );
		exit;
	}

	public function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = $this->settings();
		$conflicts = $this->conflicts();
		$status = empty( $settings['site_key'] ) || empty( $settings['secret_key'] ) ? 'Inte konfigurerat' : 'Avstängt';
		if ( ! empty( $settings['enabled'] ) && ! $this->bypassed() ) {
			$status = $conflicts ? 'Aktiverat – konflikt, formulär blockeras' : 'Aktiverat';
		}
		$messages = [
			'saved' => 'Sparat. Skyddet är avstängt. Gör konfigurationsprovet innan aktivering.',
			'enabled' => 'Skyddet är aktiverat. Prova nu i ett privat fönster med ett testkonto och behåll denna administratörssession öppen.',
			'verified' => 'Konfigurationsprovet lyckades. Aktivera inom tio minuter. WooCommerce kräver samma värdnamn i home_url och site_url i denna version.',
			'failed' => 'Konfigurationsprovet misslyckades. Kontrollera nyckelpar, värdnamn, konflikt och nätverksåtkomst. Skyddets inställning har inte ändrats.',
		];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Allowlisted display-only message; no state changes.
		$message = isset( $_GET['ts_status'] ) && is_string( $_GET['ts_status'] ) ? ( $messages[ $_GET['ts_status'] ] ?? '' ) : '';
		?>
		<div class="wrap">
			<h1>AGoodMonitor Turnstile</h1>
			<p><strong>Status: <?php echo esc_html( $this->bypassed() ? 'Avstängt via serverkonfiguration' : $status ); ?></strong></p>
			<?php if ( $message ) : ?><div class="notice notice-info"><p><?php echo esc_html( $message ); ?></p></div><?php endif; ?>
			<?php if ( $conflicts ) : ?><div class="notice notice-error"><p><?php echo esc_html( 'Annan Turnstile-hantering upptäckt: ' . implode( ', ', $conflicts ) . '. Välj en ansvarig leverantör före aktivering. Befintligt aktiverat skydd blockerar vid konflikt.' ); ?></p></div><?php endif; ?>
			<p>Avstängt som standard. Skyddar WordPress inloggning och begäran om återställningsmejl. WooCommerce är ett separat tillval.</p>
			<p>1. Säkerställ serveråtkomst och lägg vid behov <code>define( 'AGOODMONITOR_TURNSTILE_DISABLE', true );</code> i <code>wp-config.php</code> före WordPress-laddningen för att återfå åtkomst.</p>
			<p>2. Spara nycklarna för denna sajt, gör provet nedan och aktivera därefter. Ändrade nycklar eller WooCommerce-val stänger av skyddet och kräver ett nytt prov.</p>
			<p>Vid timeout, ogiltig verifiering eller Cloudflare-avbrott blockeras formulären. Inget automatiskt undantag finns. Behåll en inloggad administratörssession under införandet.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="agoodmonitor_turnstile_save">
				<?php wp_nonce_field( 'agoodmonitor_turnstile_save' ); ?>
				<p><label>Site key <input class="regular-text" name="site_key" value="<?php echo esc_attr( $settings['site_key'] ?? '' ); ?>" autocomplete="off"></label></p>
				<p><label>Secret key <input type="password" class="regular-text" name="secret_key" value="" autocomplete="new-password"></label></p>
				<p><?php echo esc_html( empty( $settings['secret_key'] ) ? 'Ingen secret key sparad.' : 'Secret key finns sparad. Lämna tomt för att behålla; den visas aldrig här.' ); ?></p>
				<p><label><input type="checkbox" name="clear_secret" value="1"> Radera sparad secret key</label></p>
				<p><label><input type="checkbox" name="woocommerce" value="1" <?php checked( ! empty( $settings['woocommerce'] ) ); ?>> Skydda även WooCommerce standardformulär för inloggning och glömt lösenord</label></p>
				<p><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>> Aktivera skydd efter godkänt konfigurationsprov</label></p>
				<?php submit_button( 'Spara inställningar' ); ?>
			</form>
			<?php if ( ! empty( $settings['site_key'] ) && ! empty( $settings['secret_key'] ) && ! $conflicts && ! $this->bypassed() ) : ?>
				<h2>Konfigurationsprov</h2>
				<p>Provet verifierar widget, nyckelpar, förväntad åtgärd och värdnamn utan att logga in eller skicka mejl. Värdnamn: <?php echo esc_html( $this->hostname( 'agm_setup' ) ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="agoodmonitor_turnstile_probe">
					<?php wp_nonce_field( 'agoodmonitor_turnstile_probe' ); $this->widget( 'agm_setup' ); submit_button( 'Verifiera konfiguration' ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
