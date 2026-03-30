<?php
/**
 * GitHub-based plugin auto-updater for AGoodMonitor.
 *
 * Checks GitHub Releases for new versions and integrates with
 * the WordPress plugin update system.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AGoodMonitor_GitHub_Updater {

	private string $slug;
	private string $plugin_file;
	private string $github_repo;
	private ?object $github_response = null;

	public function __construct( string $plugin_file, string $github_repo ) {
		$this->plugin_file = $plugin_file;
		$this->slug        = plugin_basename( $plugin_file );
		$this->github_repo = $github_repo;

		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugin_info' ], 20, 3 );
		add_filter( 'upgrader_post_install', [ $this, 'after_install' ], 10, 3 );
	}

	private function get_github_release(): ?object {
		if ( null !== $this->github_response ) {
			return $this->github_response;
		}

		$url  = "https://api.github.com/repos/{$this->github_repo}/releases/latest";
		$args = [ 'headers' => [ 'Accept' => 'application/vnd.github.v3+json' ] ];

		$token = defined( 'AGOODMONITOR_GITHUB_TOKEN' ) ? AGOODMONITOR_GITHUB_TOKEN : '';
		if ( $token ) {
			$args['headers']['Authorization'] = "token {$token}";
		}

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$this->github_response = json_decode( wp_remote_retrieve_body( $response ) );

		return $this->github_response;
	}

	public function check_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_github_release();
		if ( ! $release ) {
			return $transient;
		}

		$remote_version  = ltrim( $release->tag_name, 'v' );
		$current_version = $transient->checked[ $this->slug ] ?? '0.0.0';

		if ( version_compare( $remote_version, $current_version, '>' ) ) {
			$zip_url = $this->get_zip_url( $release );

			if ( $zip_url ) {
				$transient->response[ $this->slug ] = (object) [
					'slug'        => dirname( $this->slug ),
					'plugin'      => $this->slug,
					'new_version' => $remote_version,
					'url'         => $release->html_url,
					'package'     => $zip_url,
				];
			}
		}

		return $transient;
	}

	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( dirname( $this->slug ) !== ( $args->slug ?? '' ) ) {
			return $result;
		}

		$release = $this->get_github_release();
		if ( ! $release ) {
			return $result;
		}

		$plugin_data = get_plugin_data( $this->plugin_file );

		return (object) [
			'name'          => $plugin_data['Name'],
			'slug'          => dirname( $this->slug ),
			'version'       => ltrim( $release->tag_name, 'v' ),
			'author'        => $plugin_data['AuthorName'],
			'homepage'      => $plugin_data['PluginURI'],
			'sections'      => [
				'description'  => $plugin_data['Description'],
				'changelog'    => nl2br( esc_html( $release->body ?? '' ) ),
			],
			'download_link' => $this->get_zip_url( $release ),
		];
	}

	public function after_install( $response, $hook_extra, $result ) {
		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->slug ) {
			return $result;
		}

		global $wp_filesystem;

		$proper_destination = WP_PLUGIN_DIR . '/' . dirname( $this->slug );
		$wp_filesystem->move( $result['destination'], $proper_destination );
		$result['destination'] = $proper_destination;

		// Återaktivera bara om pluginet var aktivt innan uppdateringen.
		if ( is_plugin_active( $this->slug ) ) {
			activate_plugin( $this->slug );
		}

		return $result;
	}

	private function get_zip_url( object $release ): string {
		if ( ! empty( $release->assets ) ) {
			foreach ( $release->assets as $asset ) {
				if ( str_ends_with( $asset->name, '.zip' ) ) {
					// Privata repos: använd API-URL med Authorization-header (via filter
					// på http_request_args) istället för den deprecerade access_token-query-param.
					$token = defined( 'AGOODMONITOR_GITHUB_TOKEN' ) ? AGOODMONITOR_GITHUB_TOKEN : '';
					if ( $token ) {
						add_filter( 'http_request_args', function( $args, $url ) use ( $asset, $token ) {
							if ( strpos( $url, 'api.github.com/repos/' . $this->github_repo ) !== false ) {
								$args['headers']['Authorization'] = 'token ' . $token;
							}
							return $args;
						}, 10, 2 );
						return $asset->url; // API-URL, kräver Authorization-header
					}
					return $asset->browser_download_url;
				}
			}
		}

		return $release->zipball_url ?? '';
	}
}
