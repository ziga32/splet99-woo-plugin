<?php
namespace Splet99\Woo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	const OWNER = 'ziga32';
	const PAGE  = 'splet99-woo-settings';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'hide_php_errors' ), 1 );
		add_action( 'in_admin_header', array( __CLASS__, 'hide_admin_notices' ), PHP_INT_MAX );
		add_action( 'admin_head', array( __CLASS__, 'hide_admin_notices_css' ) );
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
			</table>
		</div>
		<?php
	}
}
