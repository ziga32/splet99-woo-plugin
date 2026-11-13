<?php
namespace Splet99\Woo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Offers updates from the newest vX.Y.Z tag in the public GitHub repo. Hooks into the plugin's "Update URI" header.
final class Updater {

	const REPO  = 'ziga32/splet99-woo-plugin';
	const CACHE = 'splet99_woo_latest_tag';

	private static $basename;
	private static $version;

	public static function init( $file, $version ) {
		self::$basename = plugin_basename( $file );
		self::$version  = $version;

		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check_update' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder_name' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_cache' ) );

		// Core's own wp_update_plugins runs on load-plugins.php at priority 10.
		add_action( 'load-plugins.php', array( __CLASS__, 'owner_fresh_check_start' ), 1 );
		add_action( 'load-plugins.php', array( __CLASS__, 'owner_fresh_check_end' ), 11 );
	}

	// Core re-checks at most hourly on the Plugins screen; give the owner a fresh check, at most once a minute.
	public static function owner_fresh_check_start() {
		if ( ! Plugin::is_owner() ) {
			return;
		}

		$current = get_site_transient( 'update_plugins' );

		if ( is_object( $current ) && isset( $current->last_checked ) && time() - $current->last_checked < MINUTE_IN_SECONDS ) {
			return;
		}

		delete_site_transient( self::CACHE );
		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'expire_last_checked' ) );
	}

	public static function expire_last_checked( $value ) {
		if ( is_object( $value ) ) {
			$value               = clone $value;
			$value->last_checked = 0;
		}

		return $value;
	}

	public static function owner_fresh_check_end() {
		remove_filter( 'site_transient_update_plugins', array( __CLASS__, 'expire_last_checked' ) );
	}

	private static function latest() {
		if ( empty( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$cached = get_site_transient( self::CACHE );
			if ( false !== $cached ) {
				return $cached ? $cached : null;
			}
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/tags?per_page=100',
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);

		$latest = null;

		if ( 200 === wp_remote_retrieve_response_code( $response ) ) {
			$tags = json_decode( wp_remote_retrieve_body( $response ), true );

			foreach ( (array) $tags as $tag ) {
				$name    = isset( $tag['name'] ) ? $tag['name'] : '';
				$version = ltrim( $name, 'vV' );

				if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
					continue;
				}

				if ( ! $latest || version_compare( $version, $latest['version'], '>' ) ) {
					$latest = array(
						'version' => $version,
						'package' => 'https://github.com/' . self::REPO . '/archive/refs/tags/' . rawurlencode( $name ) . '.zip',
					);
				}
			}
		}

		// Cache failures as an empty array so a GitHub outage doesn't trigger a request on every page load.
		set_site_transient( self::CACHE, $latest ? $latest : array(), HOUR_IN_SECONDS );

		return $latest;
	}

	public static function check_update( $update, $plugin_data, $plugin_file ) {
		if ( self::$basename !== $plugin_file ) {
			return $update;
		}

		$latest = self::latest();

		return array(
			'slug'    => dirname( self::$basename ),
			'version' => $latest ? $latest['version'] : self::$version,
			'package' => $latest ? $latest['package'] : '',
			'url'     => 'https://github.com/' . self::REPO,
		);
	}

	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || dirname( self::$basename ) !== $args->slug ) {
			return $result;
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$latest = self::latest();
		$data   = get_plugin_data( WP_PLUGIN_DIR . '/' . self::$basename, false, false );

		return (object) array(
			'name'          => $data['Name'],
			'slug'          => $args->slug,
			'external'      => true,
			'version'       => $latest ? $latest['version'] : self::$version,
			'author'        => $data['Author'],
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => $data['RequiresWP'],
			'requires_php'  => $data['RequiresPHP'],
			'download_link' => $latest ? $latest['package'] : '',
			'sections'      => array(
				'description' => esc_html( $data['Description'] ),
				'changelog'   => '<a href="https://github.com/' . self::REPO . '/commits/main" target="_blank" rel="noopener">Seznam sprememb na GitHubu</a>',
			),
		);
	}

	// GitHub archives unpack to "repo-name-1.2.3/"; WordPress must find the plugin in its existing folder.
	public static function fix_folder_name( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;

		if ( empty( $hook_extra['plugin'] ) || self::$basename !== $hook_extra['plugin'] ) {
			return $source;
		}

		$target = trailingslashit( $remote_source ) . dirname( self::$basename ) . '/';

		if ( trailingslashit( $source ) === $target ) {
			return $source;
		}

		if ( $wp_filesystem->move( $source, $target, true ) ) {
			return $target;
		}

		return new \WP_Error( 'splet99_folder_rename', 'Mape posodobitve ni bilo mogoče preimenovati.' );
	}

	public static function clear_cache() {
		delete_site_transient( self::CACHE );
	}
}
