<?php
/**
 * AGoodMonitor — Security Hardening
 *
 * Site-wide WordPress-härdning i tre lager:
 *   1. HTTP security headers
 *   2. WordPress fingerprinting (generator, version-strängar)
 *   3. Attackyta (XML-RPC, user enumeration, uploads PHP-exekvering)
 *
 * Varje åtgärd är avstängbar via apply_filters().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AGoodMonitor_Hardening {

	public function __construct() {
		add_action( 'send_headers', array( $this, 'send_security_headers' ) );
		add_action( 'init', array( $this, 'remove_fingerprinting' ) );
		add_action( 'admin_init', array( $this, 'protect_uploads_directory' ) );

		if ( apply_filters( 'agoodmonitor_disable_xmlrpc', true ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
		}

		if ( apply_filters( 'agoodmonitor_block_user_enumeration', true ) ) {
			add_filter( 'rest_endpoints', array( $this, 'block_user_endpoints' ) );
		}
	}

	// -------------------------------------------------------------------------
	// Fas 1 — HTTP Security Headers
	// -------------------------------------------------------------------------

	public function send_security_headers(): void {
		$defaults = array(
			'X-Content-Type-Options' => 'nosniff',
			'X-Frame-Options'        => 'SAMEORIGIN',
			'Referrer-Policy'        => 'strict-origin-when-cross-origin',
			'Permissions-Policy'     => 'camera=(), microphone=(), geolocation=(), payment=()',
		);

		/**
		 * Filter security headers.
		 *
		 * Return false for an individual header value to skip it.
		 *
		 * @param array $headers Header name => value pairs.
		 */
		$headers = apply_filters( 'agoodmonitor_security_headers', $defaults );

		foreach ( $headers as $name => $value ) {
			if ( false !== $value ) {
				header( sanitize_text_field( $name ) . ': ' . sanitize_text_field( $value ) );
			}
		}

		// X-Powered-By sätts av PHP direkt, inte via WordPress — måste tas bort
		// via header_remove(), inte wp_headers-filtret.
		header_remove( 'X-Powered-By' );
	}

	// -------------------------------------------------------------------------
	// Fas 2 — WordPress Fingerprinting
	// -------------------------------------------------------------------------

	public function remove_fingerprinting(): void {
		// Generator-tagg i <head> och RSS-feeds.
		remove_action( 'wp_head', 'wp_generator' );
		add_filter( 'the_generator', '__return_empty_string' );

		// Sällan använda discovery-länkar.
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'rsd_link' );

		// Version-query (?ver=X.Y) på CSS- och JS-URL:er.
		if ( apply_filters( 'agoodmonitor_strip_version_query', true ) ) {
			add_filter( 'style_loader_src', array( $this, 'strip_version_query' ), 10, 2 );
			add_filter( 'script_loader_src', array( $this, 'strip_version_query' ), 10, 2 );
		}
	}

	/**
	 * Ta bort ?ver= från asset-URL:er för att dölja exakta plugin-versioner.
	 * Cache-busting hanteras internt av WordPress och påverkas inte.
	 */
	public function strip_version_query( string $src ): string {
		if ( strpos( $src, 'ver=' ) !== false ) {
			$src = remove_query_arg( 'ver', $src );
		}
		return $src;
	}

	// -------------------------------------------------------------------------
	// Fas 3 — Attackyta
	// -------------------------------------------------------------------------

	/**
	 * Blockera publikt åtkomliga user-endpoints i REST API.
	 * Inloggade användare med list_users-capability behåller åtkomst.
	 *
	 * Känd lucka: /?author=N redirectar fortfarande till /author/slug/ och
	 * exponerar användarnamn. Den vektorn täcks inte i denna sprint.
	 */
	public function block_user_endpoints( array $endpoints ): array {
		if ( ! current_user_can( 'list_users' ) ) {
			unset( $endpoints['/wp/v2/users'] );
			unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
		}
		return $endpoints;
	}

	/**
	 * Lägg till .htaccess i uploads-mappen för att förhindra PHP-exekvering.
	 *
	 * Fungerar bara på Apache — Nginx ignorerar .htaccess tyst.
	 * Körs på admin_init (en gång) och skriver bara filen om den saknas.
	 */
	public function protect_uploads_directory(): void {
		if ( ! apply_filters( 'agoodmonitor_protect_uploads', true ) ) {
			return;
		}

		// Skippa på Nginx — .htaccess har ingen effekt.
		$server_software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? $_SERVER['SERVER_SOFTWARE'] : '';
		if ( stripos( $server_software, 'nginx' ) !== false ) {
			return;
		}

		$upload_dir = wp_upload_dir();
		$htaccess   = trailingslashit( $upload_dir['basedir'] ) . '.htaccess';

		if ( file_exists( $htaccess ) ) {
			return;
		}

		$content  = "# Skapad av AGoodMonitor — förhindrar PHP-exekvering i uploads\n";
		$content .= "<FilesMatch \"\\.php$\">\n";
		$content .= "    Deny from all\n";
		$content .= "</FilesMatch>\n";

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $htaccess, $content ) ) {
			error_log( 'AGoodMonitor: kunde inte skriva .htaccess i uploads-mappen.' );
		}
	}
}
