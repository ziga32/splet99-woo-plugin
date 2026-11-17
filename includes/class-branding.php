<?php
namespace Splet99\Woo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Login page header "<site name> by Splet99", shown to everyone.
final class Branding {

	public static function init() {
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'login_styles' ) );
		add_filter( 'login_headerurl', array( __CLASS__, 'login_logo_url' ) );
		add_filter( 'login_headertext', array( __CLASS__, 'login_logo_text' ) );
	}

	public static function login_styles() {
		wp_enqueue_style( 'splet99-woo-login', SPLET99_WOO_URL . 'assets/login.css', array( 'login' ), SPLET99_WOO_VERSION );
	}

	public static function login_logo_url() {
		return home_url( '/' );
	}

	// wp-login.php prints this unescaped; the "by Splet99" line under it comes from login.css.
	public static function login_logo_text() {
		return esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
	}
}
