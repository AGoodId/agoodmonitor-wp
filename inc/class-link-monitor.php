<?php
/**
 * AGoodMonitor — Link Monitor
 *
 * Passiv detection av länkfel under normal trafik — noll extra HTTP-anrop.
 *
 * Loggar:
 *   - 404:or med känd referrer (bots utan referrer filtreras bort)
 *   - Interna redirects (interna länkar som bör uppdateras)
 *
 * Data aggregeras per URL (upsert med hit-räknare) och skickas med i
 * den timvisa hälsorapporten till AGoodMember.
 *
 * Begränsning: server-nivå-redirects (.htaccess / Nginx) kringgår PHP
 * och loggas aldrig — wp_redirect-hooken fångar bara WP/plugin-redirects.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AGoodMonitor_Link_Monitor {

	const DB_VERSION    = '1.0';
	const CRON_HOOK     = 'agoodmonitor_cleanup_link_log';
	const CRON_INTERVAL = 'agoodmonitor_weekly';

	public function __construct() {
		add_filter( 'cron_schedules', [ $this, 'add_cron_interval' ] );
		add_action( 'init', [ $this, 'maybe_create_table' ], 1 );
		add_action( 'admin_init', [ $this, 'maybe_create_table' ] );
		add_action( 'init', [ $this, 'schedule_cleanup_cron' ] );
		add_action( self::CRON_HOOK, [ $this, 'cleanup_old_logs' ] );
		add_action( 'template_redirect', [ $this, 'log_404' ] );
		add_filter( 'wp_redirect', [ $this, 'log_redirect' ], 10, 2 );
		add_action( 'admin_menu', [ $this, 'add_admin_page' ] );

		// Health reporter hämtar data via detta filter — ingen hård koppling.
		add_filter( 'agoodmonitor_collect_link_errors', [ $this, 'get_recent_link_errors' ] );
	}

	// -------------------------------------------------------------------------
	// Databastabell
	// -------------------------------------------------------------------------

	/**
	 * Skapar tabellen via dbDelta om den inte redan finns eller är äldre version.
	 * Körs tidigt på init och admin_init så både frontend-loggning och
	 * befintliga installationer får tabellen utan ny aktivering.
	 */
	public function maybe_create_table(): void {
		$installed = get_option( 'agoodmonitor_link_monitor_db_version', '0' );
		if ( version_compare( $installed, self::DB_VERSION, '>=' ) ) {
			return;
		}

		global $wpdb;
		$table           = $wpdb->prefix . 'agoodmonitor_link_log';
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta kräver exakt formatering: varje kolumn på egen rad,
		// PRIMARY KEY separat, inga backticks runt tabellnamn.
		$sql = "CREATE TABLE $table (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  url varchar(2083) NOT NULL DEFAULT '',
  referrer varchar(2083) NOT NULL DEFAULT '',
  type varchar(10) NOT NULL DEFAULT '',
  redirect_to varchar(2083) NOT NULL DEFAULT '',
  status_code smallint(5) unsigned NOT NULL DEFAULT 0,
  hits int(10) unsigned NOT NULL DEFAULT 1,
  first_seen datetime NOT NULL,
  last_seen datetime NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY url_type (url(190),type),
  KEY type_last_seen (type,last_seen)
) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'agoodmonitor_link_monitor_db_version', self::DB_VERSION );
	}

	// -------------------------------------------------------------------------
	// Cron — veckovis rensning
	// -------------------------------------------------------------------------

	public function add_cron_interval( array $schedules ): array {
		if ( ! isset( $schedules[ self::CRON_INTERVAL ] ) ) {
			$schedules[ self::CRON_INTERVAL ] = [
				'interval' => WEEK_IN_SECONDS,
				'display'  => 'AGoodMonitor weekly',
			];
		}

		return $schedules;
	}

	public function schedule_cleanup_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), self::CRON_INTERVAL, self::CRON_HOOK );
		}
	}

	public function cleanup_old_logs(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'agoodmonitor_link_log';
		$days  = max( 1, absint( apply_filters( 'agoodmonitor_link_log_retention_days', 90 ) ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Egen tabell med dynamiskt prefix; ingen WP API-wrapper finns.
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE last_seen < %s",
			gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) )
		) );
	}

	// -------------------------------------------------------------------------
	// Loggning
	// -------------------------------------------------------------------------

	public function log_404(): void {
		if ( ! is_404() ) {
			return;
		}

		$url      = $this->get_current_url();
		$referrer = isset( $_SERVER['HTTP_REFERER'] )
			? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) )
			: '';

		if ( $this->should_ignore_404( $url, $referrer ) ) {
			return;
		}

		$this->upsert_log( $url, $referrer, '404', '', 404 );
	}

	/**
	 * Loggar interna redirects — interna länkar som bör uppdateras.
	 *
	 * Begränsning: fångar bara redirects som går via wp_redirect(). Server-nivå-
	 * redirects (.htaccess, Nginx) kringgår PHP och loggas aldrig.
	 *
	 * @return string $location oförändrad — hooken är transparant.
	 */
	public function log_redirect( string $location, int $status ): string {
		$statuses = array_map( 'absint', apply_filters( 'agoodmonitor_link_redirect_statuses', [ 301, 302, 307, 308 ] ) );
		if ( ! in_array( $status, $statuses, true ) ) {
			return $location;
		}

		$current_url  = $this->get_current_url();
		$redirect_url = $this->normalize_redirect_url( $location );

		if (
			$this->should_ignore_url( $current_url )
			|| ! $this->is_internal_url( $redirect_url )
			|| $this->same_url( $current_url, $redirect_url )
		) {
			return $location;
		}

		$this->upsert_log( $current_url, '', 'redirect', $redirect_url, $status );

		return $location;
	}

	private function should_ignore_404( string $url, string $referrer ): bool {
		if ( $this->should_ignore_url( $url ) ) {
			return true;
		}

		// Ignorera 404:or utan referrer (direkt-trafik, bots) — minskar brus.
		// Nackdel: broken bookmarks fångas inte. Stäng av med:
		// add_filter( 'agoodmonitor_ignore_direct_404', '__return_false' )
		if ( empty( $referrer ) && apply_filters( 'agoodmonitor_ignore_direct_404', true ) ) {
			return true;
		}

		return false;
	}

	private function should_ignore_url( string $url ): bool {
		$ignore_patterns = apply_filters( 'agoodmonitor_link_ignore_patterns', [
			'/wp-admin/',
			'/wp-json/',
			'/wp-cron',
			'.php',
			'wp-login.php',
			'/feed/',
			'/favicon',
			'/robots.txt',
			'/sitemap',
		] );

		foreach ( $ignore_patterns as $pattern ) {
			if ( strpos( $url, $pattern ) !== false ) {
				return true;
			}
		}

		return false;
	}

	private function upsert_log( string $url, string $referrer, string $type, string $redirect_to, int $status ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'agoodmonitor_link_log';
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Egen tabell med dynamiskt prefix; ingen WP API-wrapper finns.
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$table} (url, referrer, type, redirect_to, status_code, hits, first_seen, last_seen)
			 VALUES (%s, %s, %s, %s, %d, 1, %s, %s)
			 ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = %s",
			$url, $referrer, $type, $redirect_to, $status, $now, $now, $now
		) );
	}

	private function get_current_url(): string {
		$request_uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '/';

		if ( '' === $request_uri || '/' !== $request_uri[0] || str_starts_with( $request_uri, '//' ) ) {
			$request_uri = '/';
		}

		return esc_url_raw( home_url( $request_uri ) );
	}

	private function normalize_redirect_url( string $location ): string {
		$location = trim( $location );

		if ( str_starts_with( $location, '/' ) && ! str_starts_with( $location, '//' ) ) {
			return esc_url_raw( home_url( $location ) );
		}

		if ( str_starts_with( $location, '//' ) ) {
			$scheme = is_ssl() ? 'https:' : 'http:';
			return esc_url_raw( $scheme . $location );
		}

		return esc_url_raw( $location );
	}

	private function is_internal_url( string $url ): bool {
		$url_host  = wp_parse_url( $url, PHP_URL_HOST );
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( empty( $url_host ) || empty( $home_host ) ) {
			return false;
		}

		return strtolower( $url_host ) === strtolower( $home_host );
	}

	private function same_url( string $left, string $right ): bool {
		return untrailingslashit( $left ) === untrailingslashit( $right );
	}

	// -------------------------------------------------------------------------
	// Integration med health reporter
	// -------------------------------------------------------------------------

	/**
	 * Returnerar de 50 mest träffade länkfelen de senaste 7 dagarna.
	 * Anropas via filter 'agoodmonitor_collect_link_errors' från health reporter.
	 */
	public function get_recent_link_errors(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'agoodmonitor_link_log';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Egen tabell med dynamiskt prefix; ingen WP API-wrapper finns.
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT url, referrer, type, redirect_to, status_code, hits, last_seen
			 FROM {$table}
			 WHERE last_seen > %s
			 ORDER BY hits DESC
			 LIMIT 50",
			gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) )
		), ARRAY_A );

		return is_array( $results ) ? $results : [];
	}

	// -------------------------------------------------------------------------
	// Admin UI
	// -------------------------------------------------------------------------

	public function add_admin_page(): void {
		add_submenu_page(
			'options-general.php',
			'AGoodMonitor — Länkfel',
			'AGoodMonitor Länkfel',
			'manage_options',
			'agoodmonitor-links',
			[ $this, 'render_admin_page' ]
		);
	}

	public function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Rensa logg.
		if ( isset( $_GET['agoodmonitor_clear_links'] ) ) {
			check_admin_referer( 'agoodmonitor_clear_links' );
			global $wpdb;
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- TRUNCATE mot pluginets egen tabell; ingen användarinput.
			$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . 'agoodmonitor_link_log' );
			wp_safe_redirect( admin_url( 'options-general.php?page=agoodmonitor-links&cleared=1' ) );
			exit;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'agoodmonitor_link_log';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Egen tabell med dynamiskt prefix; ingen WP API-wrapper finns.
		$rows = $wpdb->get_results(
			"SELECT url, referrer, type, redirect_to, status_code, hits, last_seen
			 FROM {$table}
			 ORDER BY hits DESC, last_seen DESC
			 LIMIT 100",
			ARRAY_A
		);

		$home        = home_url();
		$clear_url   = wp_nonce_url(
			admin_url( 'options-general.php?page=agoodmonitor-links&agoodmonitor_clear_links=1' ),
			'agoodmonitor_clear_links'
		);
		?>
		<div class="wrap">
			<h1>AGoodMonitor — Länkfel</h1>
			<p class="description">
				Passivt loggade 404:or och interna redirects. Sorterat på träffar.
			</p>

			<?php if ( isset( $_GET['cleared'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Loggen är rensad.</p></div>
			<?php endif; ?>

			<?php if ( empty( $rows ) ) : ?>
				<p>Inga länkfel loggade ännu.</p>
			<?php else : ?>
				<p><?php echo esc_html( number_format_i18n( count( $rows ) ) ); ?> rader (max 100 visas).</p>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>URL</th>
							<th>Typ</th>
							<th>Träffar</th>
							<th>Redirect till</th>
							<th>Referrer</th>
							<th>Senast sedd</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $rows as $row ) :
						$edit_link = '';
						if ( ! empty( $row['referrer'] ) && strpos( $row['referrer'], $home ) === 0 ) {
							$post_id = url_to_postid( $row['referrer'] );
							if ( $post_id ) {
								$edit_link = get_edit_post_link( $post_id );
							}
						}
					?>
						<tr>
							<td><code><?php echo esc_html( $row['url'] ); ?></code></td>
							<td>
								<span style="color: <?php echo '404' === $row['type'] ? '#d63638' : '#996800'; ?>; font-weight: 600;">
									<?php echo esc_html( strtoupper( $row['type'] ) . ' (' . (int) $row['status_code'] . ')' ); ?>
								</span>
							</td>
							<td><?php echo esc_html( number_format_i18n( (int) $row['hits'] ) ); ?></td>
							<td>
								<?php if ( ! empty( $row['redirect_to'] ) ) : ?>
									<a href="<?php echo esc_url( $row['redirect_to'] ); ?>" target="_blank" rel="noreferrer">
										<?php echo esc_html( $row['redirect_to'] ); ?>
									</a>
								<?php else : ?>
									<em style="color: #999;">—</em>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $row['referrer'] ) : ?>
									<a href="<?php echo esc_url( $row['referrer'] ); ?>" target="_blank" rel="noreferrer">
										<?php echo esc_html( $row['referrer'] ); ?>
									</a>
									<?php if ( $edit_link ) : ?>
										&mdash; <a href="<?php echo esc_url( $edit_link ); ?>">Redigera inlägg</a>
									<?php endif; ?>
								<?php else : ?>
									<em style="color: #999;">—</em>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( wp_date( 'Y-m-d H:i', strtotime( $row['last_seen'] ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<p style="margin-top: 1.5em;">
				<a href="<?php echo esc_url( $clear_url ); ?>"
				   class="button button-secondary"
				   onclick="return confirm('Rensa hela länkloggen? Åtgärden kan inte ångras.');">
					Rensa logg
				</a>
			</p>
		</div>
		<?php
	}
}
