<?php
namespace Splet99\Woo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Splet99 branding on the login page and in the wp-admin footer, shown to everyone.
final class Branding {

	const URL  = 'https://splet99.net';
	const NAME = 'Splet99 d.o.o.';

	public static function init() {
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'login_styles' ) );
		add_filter( 'login_headerurl', array( __CLASS__, 'login_logo_url' ) );
		add_filter( 'login_headertext', array( __CLASS__, 'login_logo_text' ) );
		add_action( 'login_footer', array( __CLASS__, 'login_credit' ) );
		add_filter( 'admin_footer_text', array( __CLASS__, 'admin_footer' ), PHP_INT_MAX );
	}

	public static function login_styles() {
		wp_enqueue_style( 'splet99-woo-login', SPLET99_WOO_URL . 'assets/login.css', array( 'login' ), SPLET99_WOO_VERSION );
	}

	public static function login_logo_url() {
		return self::URL;
	}

	public static function login_logo_text() {
		return self::NAME;
	}

	public static function credit_html() {
		return 'Vzdrževanje: <a href="' . esc_url( self::URL ) . '" target="_blank" rel="noopener">' . esc_html( self::NAME ) . '</a>';
	}

	public static function login_credit() {
		echo '<p class="splet99-login-credit">' . self::credit_html() . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function admin_footer() {
		return self::credit_html();
	}
}
