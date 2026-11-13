<?php
namespace Splet99\Woo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	const OWNER = 'ziga32';
	const PAGE  = 'splet99-woo-settings';

	const DASHBOARD_KEEP = array( 'woocommerce_dashboard_status' );

	public static function init() {
		add_action( 'init', array( __CLASS__, 'hide_php_errors' ), 1 );
		add_action( 'in_admin_header', array( __CLASS__, 'hide_admin_notices' ), PHP_INT_MAX );
		add_action( 'admin_head', array( __CLASS__, 'hide_admin_notices_css' ) );
		add_action( 'admin_init', array( __CLASS__, 'hide_welcome_panel' ) );
		add_action( 'do_meta_boxes', array( __CLASS__, 'hide_dashboard_widgets' ), PHP_INT_MAX );
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
	}

	// Requiring manage_options too means a stranger who registers "ziga32" on a site gets nothing.
	public static function is_owner() {
		$user = wp_get_current_user();

		return $user->exists()
			&& self::OWNER === $user->user_login
			&& current_user_can( 'manage_options' );
	}

	public static function hide_php_errors() {
		if ( ! self::is_owner() ) {
			@ini_set( 'display_errors', '0' ); // phpcs:ignore
		}
	}

	public static function hide_admin_notices() {
		if ( self::is_owner() ) {
			return;
		}

		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'network_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
	}

	// Catches notices that plugins print directly instead of through the notice hooks.
	public static function hide_admin_notices_css() {
		if ( self::is_owner() ) {
			return;
		}

		echo '<style>#wpbody-content .notice:not(.inline),#wpbody-content .update-nag,#wpbody-content div.updated,#wpbody-content div.error{display:none!important}</style>';
	}

	public static function hide_welcome_panel() {
		if ( ! self::is_owner() ) {
			remove_all_actions( 'welcome_panel' );
		}
	}

	// do_meta_boxes runs after wp_dashboard_setup, so widgets added late by plugins are caught too.
	public static function hide_dashboard_widgets( $screen_id ) {
		global $wp_meta_boxes;

		if ( 'dashboard' !== $screen_id || self::is_owner() || empty( $wp_meta_boxes['dashboard'] ) ) {
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

	public static function add_settings_page() {
		if ( ! self::is_owner() ) {
			return;
		}

		add_options_page(
			'Splet99 settings',
			'Splet99 settings',
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function render_settings_page() {
		?>
		<div class="wrap">
			<h1>Splet99 settings</h1>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Vtičnik</th>
					<td>Splet99 Woo <?php echo esc_html( SPLET99_WOO_VERSION ); ?></td>
				</tr>
				<tr>
					<th scope="row">Obvestila in napake</th>
					<td>Skrita za vse uporabnike razen <code><?php echo esc_html( self::OWNER ); ?></code>.</td>
				</tr>
				<tr>
					<th scope="row">Nadzorna plošča</th>
					<td>
						Widgeti skriti za vse uporabnike razen <code><?php echo esc_html( self::OWNER ); ?></code>.
						<?php if ( self::DASHBOARD_KEEP ) : ?>
							Ostanejo: <code><?php echo esc_html( implode( ', ', self::DASHBOARD_KEEP ) ); ?></code>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}
}
