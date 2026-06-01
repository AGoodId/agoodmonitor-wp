<?php
/**
 * AGoodMonitor — WordPress Health Reporter
 *
 * Samlar in WordPress-hälsodata och skickar till AGoodMember API.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AGoodMonitor_Health_Reporter {

	const OPTION_API_KEY      = 'agoodmonitor_api_key';
	const OPTION_API_URL      = 'agoodmonitor_api_url';
	const OPTION_LAST_REPORT  = 'agoodmonitor_last_report';
	const CRON_HOOK           = 'agoodmonitor_send_health_report';
	const TRANSIENT_HEALTH    = 'agoodmonitor_health_issues';
	const TRANSIENT_HEALTH_TTL = 6 * HOUR_IN_SECONDS;
	const REPORT_SCHEMA       = 2;

	public function __construct() {
		add_action( 'init', [ $this, 'schedule_cron' ] );
		add_action( self::CRON_HOOK, [ $this, 'send_health_report' ] );
		add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ $this, 'refresh_health_cache' ] );
		add_action( 'wp_ajax_agoodmonitor_send_report', [ $this, 'ajax_send_report' ] );
	}

	/**
	 * Kör Site Health-tester och cacha resultaten i ett transient (6h).
	 * Körs på admin_init så att cron-rapporter kan använda cachad data
	 * istället för att köra alla tester synkront varje timme.
	 */
	public function refresh_health_cache(): void {
		if ( null !== $this->get_cached_health_report() ) {
			return;
		}

		$this->run_health_tests( true );
	}

	public function schedule_cron(): void {
		if ( ! get_option( self::OPTION_API_KEY ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
		}
	}

	public function send_health_report(): bool {
		$api_key = get_option( self::OPTION_API_KEY );
		$api_url = get_option( self::OPTION_API_URL, 'https://www.agoodsport.se' );

		if ( ! $api_key ) {
			return false;
		}

		$data = $this->collect_health_data();

		$response = wp_remote_post( rtrim( $api_url, '/' ) . '/api/monitoring/wp-health', [
			'headers' => [
				'Content-Type'       => 'application/json',
				'X-AGoodMonitor-Key' => $api_key,
			],
			'body'    => wp_json_encode( $data ),
			'timeout' => 30,
		] );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		// 200 = OK, 429 = redan rapporterat (räknas som OK)
		$success = $code >= 200 && $code < 500;

		if ( $success ) {
			update_option( self::OPTION_LAST_REPORT, current_time( 'mysql' ) );
		}

		return $success;
	}

	private function collect_health_data(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'get_plugin_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		// Core
		global $wp_version;
		$core_update  = null;
		$core_updates = get_site_transient( 'update_core' );
		if ( ! empty( $core_updates->updates ) ) {
			foreach ( $core_updates->updates as $update ) {
				if ( 'upgrade' === $update->response ) {
					$core_update = $update->current;
					break;
				}
			}
		}

		// Plugins
		$all_plugins    = get_plugins();
		$plugin_updates = get_site_transient( 'update_plugins' );
		$active_plugins = get_option( 'active_plugins', [] );
		$plugins_data   = [];
		$update_count   = 0;
		$active_count   = 0;

		foreach ( $all_plugins as $slug => $plugin ) {
			$is_active = in_array( $slug, $active_plugins, true );
			$update_to = null;

			if ( isset( $plugin_updates->response[ $slug ] ) ) {
				$update_to = $plugin_updates->response[ $slug ]->new_version;
				$update_count++;
			}

			if ( $is_active ) {
				$active_count++;
			}

			$plugins_data[] = [
				'slug'      => $slug,
				'name'      => $plugin['Name'],
				'version'   => $plugin['Version'],
				'update_to' => $update_to,
				'active'    => $is_active,
			];
		}

		// Tema
		$theme         = wp_get_theme();
		$theme_updates = get_site_transient( 'update_themes' );
		$theme_update  = null;

		if ( isset( $theme_updates->response[ $theme->get_stylesheet() ] ) ) {
			$theme_update = $theme_updates->response[ $theme->get_stylesheet() ]['new_version'];
		}

		// Site Health — hämta från cache (fylls på av admin_init-hook).
		// Om cachen saknas körs bara direkta tester synkront för att cron inte
		// ska bli beroende av att någon besöker wp-admin.
		$health_report = $this->get_cached_health_report();

		if ( null === $health_report ) {
			$this->run_health_tests( false );
			$health_report = $this->get_cached_health_report();
		}

		$health_issues     = $health_report['issues'] ?? [];
		$critical_count    = (int) ( $health_report['critical_count'] ?? 0 );
		$recommended_count = (int) ( $health_report['recommended_count'] ?? 0 );
		$good_count        = (int) ( $health_report['good_count'] ?? 0 );
		$test_count        = (int) ( $health_report['test_count'] ?? count( $health_issues ) );
		$checked_at        = $health_report['checked_at'] ?? null;

		return [
			'report_schema'             => self::REPORT_SCHEMA,
			'plugin_version'           => AGOODMONITOR_VERSION,
			'wp_version'               => $wp_version,
			'wp_major_version'         => $this->get_major_version( $wp_version ),
			'wp_70_ready'              => version_compare( $wp_version, '7.0', '>=' ),
			'wp_update_available'      => $core_update,
			'php_version'              => phpversion(),
			'php_debug_mode'           => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'plugins'                  => $plugins_data,
			'plugins_update_count'     => $update_count,
			'plugins_active_count'     => $active_count,
			'active_theme'             => $theme->get( 'Name' ),
			'active_theme_version'     => $theme->get( 'Version' ),
			'active_theme_update'      => $theme_update,
			'health_critical_count'    => $critical_count,
			'health_recommended_count' => $recommended_count,
			'health_good_count'        => $good_count,
			'health_test_count'        => $test_count,
			'health_last_checked_at'   => $checked_at,
			'health_issues'            => $health_issues,
			'link_errors'              => apply_filters( 'agoodmonitor_collect_link_errors', [] ),
		];
	}

	/**
	 * Kör Site Health-tester och lagra resultaten i ett transient (6h).
	 * Async-tester körs bara i wp-admin eller vid manuell sändning.
	 */
	private function run_health_tests( bool $include_async ): void {
		if ( ! class_exists( 'WP_Site_Health' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		}

		if ( ! class_exists( 'WP_Site_Health' ) ) {
			return;
		}

		$health = WP_Site_Health::get_instance();
		$tests  = $health->get_tests();
		$issues = [];
		$counts = [
			'good'        => 0,
			'recommended' => 0,
			'critical'    => 0,
		];

		$test_groups = [
			'direct' => $tests['direct'] ?? [],
		];

		if ( $include_async && apply_filters( 'agoodmonitor_include_async_site_health_tests', true ) ) {
			$test_groups['async'] = $tests['async'] ?? [];
		}

		foreach ( $test_groups as $source => $group_tests ) {
			foreach ( $group_tests as $test_id => $test ) {
				$result = $this->run_single_health_test( $test, (string) $test_id );

				if ( ! is_array( $result ) || empty( $result['status'] ) ) {
					continue;
				}

				$status = (string) $result['status'];
				if ( isset( $counts[ $status ] ) ) {
					$counts[ $status ]++;
				}

				if ( in_array( $status, [ 'critical', 'recommended' ], true ) ) {
					$issues[] = [
						'test'        => (string) $test_id,
						'source'      => $source,
						'category'    => $this->get_health_category( $result ),
						'label'       => wp_strip_all_tags( $result['label'] ?? '' ),
						'description' => wp_strip_all_tags( $result['description'] ?? '' ),
						'status'      => $status,
					];
				}
			}
		}

		set_transient(
			self::TRANSIENT_HEALTH,
			[
				'schema'            => self::REPORT_SCHEMA,
				'checked_at'        => gmdate( 'c' ),
				'include_async'     => $include_async,
				'issues'            => $issues,
				'critical_count'    => $counts['critical'],
				'recommended_count' => $counts['recommended'],
				'good_count'        => $counts['good'],
				'test_count'        => array_sum( $counts ),
			],
			self::TRANSIENT_HEALTH_TTL
		);
	}

	private function get_cached_health_report(): ?array {
		$cached = get_transient( self::TRANSIENT_HEALTH );

		if ( ! is_array( $cached ) ) {
			return null;
		}

		if ( isset( $cached['issues'] ) && is_array( $cached['issues'] ) ) {
			$schema     = (int) ( $cached['schema'] ?? 1 );
			$checked_at = $cached['checked_at'] ?? null;

			if ( $schema < self::REPORT_SCHEMA || empty( $checked_at ) ) {
				delete_transient( self::TRANSIENT_HEALTH );
				return null;
			}

			return $cached;
		}

		// v1-cache var en ren issue-lista utan metadata. Rensa den så nästa
		// rapport får riktiga V2-fält som checked_at och test_count.
		delete_transient( self::TRANSIENT_HEALTH );
		return null;
	}

	private function run_single_health_test( array $test, string $test_id ): ?array {
		if ( isset( $test['test'] ) && is_callable( $test['test'] ) ) {
			try {
				$result = call_user_func( $test['test'] );
				return is_array( $result ) ? $result : null;
			} catch ( \Throwable $e ) {
				return null;
			}
		}

		if ( empty( $test['test'] ) || ! is_string( $test['test'] ) ) {
			return null;
		}

		$method = 'get_test_' . $test['test'];
		if ( ! method_exists( WP_Site_Health::class, $method ) ) {
			return null;
		}

		try {
			$health = WP_Site_Health::get_instance();
			$result = call_user_func( [ $health, $method ] );
			return is_array( $result ) ? $result : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	private function get_health_category( array $result ): ?string {
		if ( empty( $result['badge']['label'] ) ) {
			return null;
		}

		return sanitize_title( wp_strip_all_tags( $result['badge']['label'] ) );
	}

	private function get_major_version( string $version ): ?int {
		if ( preg_match( '/^(\d+)/', $version, $matches ) ) {
			return (int) $matches[1];
		}

		return null;
	}

	// =========================================================================
	// Admin UI
	// =========================================================================

	public function add_admin_menu(): void {
		add_options_page(
			'AGoodMonitor',
			'AGoodMonitor',
			'manage_options',
			'agoodmonitor',
			[ $this, 'render_settings_page' ]
		);
	}

	public function register_settings(): void {
		register_setting( 'agoodmonitor_settings', self::OPTION_API_KEY, [
			'sanitize_callback' => 'sanitize_text_field',
		] );
		register_setting( 'agoodmonitor_settings', self::OPTION_API_URL, [
			'sanitize_callback' => 'esc_url_raw',
			'default'           => 'https://www.agoodsport.se',
		] );
	}

	public function render_settings_page(): void {
		$api_key     = get_option( self::OPTION_API_KEY, '' );
		$api_url     = get_option( self::OPTION_API_URL, 'https://www.agoodsport.se' );
		$last_report = get_option( self::OPTION_LAST_REPORT, '' );
		$next_cron   = wp_next_scheduled( self::CRON_HOOK );
		?>
		<div class="wrap">
			<h1>AGoodMonitor</h1>
			<p class="description">
				Skickar WordPress-hälsodata till AGoodMember automatiskt varje timme.
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'agoodmonitor_settings' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="agoodmonitor_api_key">API-nyckel</label></th>
						<td>
							<input
								type="text"
								id="agoodmonitor_api_key"
								name="<?php echo esc_attr( self::OPTION_API_KEY ); ?>"
								value="<?php echo esc_attr( $api_key ); ?>"
								class="regular-text"
								placeholder="Klistra in nyckeln från AGoodMonitor"
							/>
							<p class="description">
								Hämta nyckeln i AGoodMonitor → din sajt → WordPress-fliken.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="agoodmonitor_api_url">API-URL</label></th>
						<td>
							<input
								type="url"
								id="agoodmonitor_api_url"
								name="<?php echo esc_attr( self::OPTION_API_URL ); ?>"
								value="<?php echo esc_attr( $api_url ); ?>"
								class="regular-text"
							/>
							<p class="description">Ändra bara om du kör en egen instans.</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Spara inställningar' ); ?>
			</form>

			<hr />

			<h2>Status</h2>
			<table class="widefat striped" style="max-width: 500px;">
				<tr>
					<td><strong>Plugin-version</strong></td>
					<td><?php echo esc_html( AGOODMONITOR_VERSION ); ?></td>
				</tr>
				<tr>
					<td><strong>Senaste rapport</strong></td>
					<td><?php echo $last_report ? esc_html( $last_report ) : '<em>Ingen rapport skickad ännu</em>'; ?></td>
				</tr>
				<tr>
					<td><strong>Nästa schemalagd</strong></td>
					<td>
						<?php
						if ( $next_cron ) {
							echo esc_html( wp_date( 'Y-m-d H:i:s', $next_cron ) );
						} elseif ( ! $api_key ) {
							echo '<em>Ingen API-nyckel konfigurerad</em>';
						} else {
							echo '<em>Ej schemalagd</em>';
						}
						?>
					</td>
				</tr>
			</table>

			<?php if ( $api_key ) : ?>
				<p style="margin-top: 1em;">
					<button type="button" id="agoodmonitor-send-now" class="button button-secondary">
						Skicka rapport nu
					</button>
					<span id="agoodmonitor-send-status" style="margin-left: 10px;"></span>
				</p>

				<script>
				document.getElementById('agoodmonitor-send-now').addEventListener('click', function() {
					const btn = this;
					const status = document.getElementById('agoodmonitor-send-status');
					btn.disabled = true;
					status.textContent = 'Skickar...';

					fetch(ajaxurl + '?action=agoodmonitor_send_report&_wpnonce=<?php echo wp_create_nonce( 'agoodmonitor_send' ); ?>', {
						method: 'POST',
					})
					.then(r => r.json())
					.then(data => {
						status.textContent = data.success ? '✓ Rapport skickad!' : '✗ ' + (data.data?.message || 'Fel vid sändning');
						status.style.color = data.success ? 'green' : 'red';
						btn.disabled = false;
					})
					.catch(() => {
						status.textContent = '✗ Nätverksfel';
						status.style.color = 'red';
						btn.disabled = false;
					});
				});
				</script>
			<?php endif; ?>
		</div>
		<?php
	}

	public function ajax_send_report(): void {
		check_ajax_referer( 'agoodmonitor_send', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Ej behörig' ] );
		}

		$this->run_health_tests( true );
		$success = $this->send_health_report();

		if ( $success ) {
			wp_send_json_success( [ 'message' => 'Rapport skickad' ] );
		} else {
			wp_send_json_error( [ 'message' => 'Kunde inte skicka rapport. Kontrollera API-nyckeln.' ] );
		}
	}
}
