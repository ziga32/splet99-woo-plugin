<?php
namespace Splet99\Woo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	const OWNER  = 'ziga32';
	const PAGE   = 'splet99-woo-settings';
	const OPTION = 'splet99_woo_settings';

	const DASHBOARD_KEEP = array( 'woocommerce_dashboard_status' );

	// Admin bar items whose ID or title contains any of these words are hidden.
	const ADMIN_BAR_HIDE = array( 'novamira' );

	// Plugins and top-level menu items matching these words are hidden until that tab is saved for the first time.
	const DEFAULT_HIDDEN = array( 'novamira' );

	// Submenu items in a hidden menu are stored as "parent|child".
	const MENU_SEPARATOR = '|';

	// Features on the WooCommerce tab and whether each is on until the owner decides on that tab.
	const WOO_FEATURE_DEFAULTS = array(
		'lowest_price' => false,
		'odstop'       => true,
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'hide_php_errors' ), 1 );
		add_action( 'in_admin_header', array( __CLASS__, 'hide_admin_notices' ), PHP_INT_MAX );
		add_action( 'admin_head', array( __CLASS__, 'hide_admin_notices_css' ) );
		add_action( 'admin_init', array( __CLASS__, 'hide_welcome_panel' ) );
		add_action( 'do_meta_boxes', array( __CLASS__, 'hide_dashboard_widgets' ), PHP_INT_MAX );
		add_action( 'admin_bar_menu', array( __CLASS__, 'hide_admin_bar_items' ), PHP_INT_MAX );
		add_filter( 'all_plugins', array( __CLASS__, 'hide_plugins' ) );
		add_filter( 'pre_update_site_option_auto_update_plugins', array( __CLASS__, 'keep_hidden_auto_updates' ), 10, 2 );
		add_action( 'admin_menu', array( __CLASS__, 'hide_menu_items' ), PHP_INT_MAX );

		Settings::init();
	}

	public static function settings() {
		$saved = get_option( self::OPTION, array() );

		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'exempt_users'   => array(),
				'hidden_plugins' => array(),
				'hidden_menu'    => array(),
				'woo_features'   => array(),
			)
		);
	}

	// Tracked per tab: 0.6.0 stored every key on any save, so key presence alone can't be trusted.
	public static function is_saved( $key ) {
		$saved = get_option( self::OPTION, array() );

		return is_array( $saved )
			&& isset( $saved['saved_tabs'] )
			&& in_array( $key, (array) $saved['saved_tabs'], true );
	}

	public static function feature_enabled( $feature ) {
		$stored = get_option( self::OPTION, array() );

		if ( is_array( $stored ) && isset( $stored['woo_decided'] ) ) {
			$decided = (array) $stored['woo_decided'];
		} else {
			// Saves from 0.10.x / 0.11.0 only decided lowest_price; odstop was off by default there.
			$decided = self::is_saved( 'woo_features' ) ? array( 'lowest_price' ) : array();
		}

		if ( in_array( $feature, $decided, true ) ) {
			$settings = self::settings();

			return in_array( $feature, (array) $settings['woo_features'], true );
		}

		return ! empty( self::WOO_FEATURE_DEFAULTS[ $feature ] );
	}

	public static function matches_default( $text ) {
		foreach ( self::DEFAULT_HIDDEN as $needle ) {
			if ( false !== stripos( (string) $text, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	// Requiring manage_options too means a stranger who registers "ziga32" on a site gets nothing.
	public static function is_owner() {
		$user = wp_get_current_user();

		return $user->exists()
			&& self::OWNER === $user->user_login
			&& current_user_can( 'manage_options' );
	}

	// Owner plus users picked on the "Hide notifications" tab.
	public static function sees_everything() {
		if ( self::is_owner() ) {
			return true;
		}

		$user_id  = get_current_user_id();
		$settings = self::settings();

		return $user_id && in_array( $user_id, array_map( 'intval', (array) $settings['exempt_users'] ), true );
	}

	public static function own_basename() {
		return plugin_basename( SPLET99_WOO_FILE );
	}

	public static function hide_php_errors() {
		if ( ! self::sees_everything() ) {
			@ini_set( 'display_errors', '0' ); // phpcs:ignore
		}
	}

	public static function hide_admin_notices() {
		if ( self::sees_everything() ) {
			return;
		}

		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'network_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
	}

	// Catches notices that plugins print directly instead of through the notice hooks.
	public static function hide_admin_notices_css() {
		if ( self::sees_everything() ) {
			return;
		}

		echo '<style>#wpbody-content .notice:not(.inline),#wpbody-content .update-nag,#wpbody-content div.updated,#wpbody-content div.error{display:none!important}</style>';
	}

	public static function hide_welcome_panel() {
		if ( ! self::sees_everything() ) {
			remove_all_actions( 'welcome_panel' );
		}
	}

	// do_meta_boxes runs after wp_dashboard_setup, so widgets added late by plugins are caught too.
	public static function hide_dashboard_widgets( $screen_id ) {
		global $wp_meta_boxes;

		if ( 'dashboard' !== $screen_id || self::sees_everything() || empty( $wp_meta_boxes['dashboard'] ) ) {
			return;
		}

		foreach ( $wp_meta_boxes['dashboard'] as $context => $priorities ) {
			foreach ( (array) $priorities as $priority => $boxes ) {
				foreach ( array_keys( (array) $boxes ) as $id ) {
					if ( ! in_array( $id, self::DASHBOARD_KEEP, true ) ) {
						$wp_meta_boxes['dashboard'][ $context ][ $priority ][ $id ] = false;
					}
				}
			}
		}
	}

	public static function hide_admin_bar_items( $wp_admin_bar ) {
		if ( self::sees_everything() ) {
			return;
		}

		foreach ( (array) $wp_admin_bar->get_nodes() as $node ) {
			$haystack = $node->id . ' ' . wp_strip_all_tags( (string) $node->title );

			foreach ( self::ADMIN_BAR_HIDE as $needle ) {
				if ( false !== stripos( $haystack, $needle ) ) {
					$wp_admin_bar->remove_node( $node->id );
					break;
				}
			}
		}
	}

	// This plugin is always hidden so clients can't deactivate it.
	public static function hidden_plugins() {
		$settings = self::settings();
		$hidden   = (array) $settings['hidden_plugins'];

		if ( ! self::is_saved( 'hidden_plugins' ) ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			foreach ( get_plugins() as $file => $data ) {
				if ( self::matches_default( $file . ' ' . $data['Name'] ) ) {
					$hidden[] = $file;
				}
			}
		}

		$hidden[] = self::own_basename();

		return array_values( array_unique( $hidden ) );
	}

	public static function hide_plugins( $plugins ) {
		if ( self::is_owner() ) {
			return $plugins;
		}

		foreach ( self::hidden_plugins() as $basename ) {
			unset( $plugins[ $basename ] );
		}

		return $plugins;
	}

	// Core intersects auto_update_plugins with the all_plugins list when anyone toggles auto-updates,
	// which would silently switch off auto-updates for every plugin hidden above.
	public static function keep_hidden_auto_updates( $value, $old_value ) {
		if ( self::is_owner() ) {
			return $value;
		}

		$kept = array_intersect( (array) $old_value, self::hidden_plugins() );

		return array_values( array_unique( array_merge( (array) $value, $kept ) ) );
	}

	// Customize links carry the current URL in "return=", so compare without it.
	public static function normalize_menu_slug( $slug ) {
		return remove_query_arg( 'return', html_entity_decode( (string) $slug ) );
	}

	public static function hide_menu_items() {
		global $menu, $submenu;

		if ( self::is_owner() ) {
			return;
		}

		if ( ! self::is_saved( 'hidden_menu' ) ) {
			foreach ( (array) $menu as $item ) {
				if ( ! empty( $item[2] ) && self::matches_default( $item[2] . ' ' . wp_strip_all_tags( (string) $item[0] ) ) ) {
					remove_menu_page( $item[2] );
				}
			}
		}

		$settings = self::settings();

		foreach ( (array) $settings['hidden_menu'] as $item ) {
			if ( false === strpos( $item, self::MENU_SEPARATOR ) ) {
				remove_menu_page( $item );
				continue;
			}

			list( $parent, $child ) = explode( self::MENU_SEPARATOR, $item, 2 );

			if ( empty( $submenu[ $parent ] ) ) {
				continue;
			}

			$target = self::normalize_menu_slug( $child );

			foreach ( $submenu[ $parent ] as $index => $sub ) {
				if ( isset( $sub[2] ) && self::normalize_menu_slug( $sub[2] ) === $target ) {
					unset( $submenu[ $parent ][ $index ] );
				}
			}
		}
	}
}
