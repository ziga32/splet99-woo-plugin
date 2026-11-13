<?php
namespace Splet99\Woo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Splet99 branding on the login page, shown to everyone.
final class Branding {

	const URL  = 'https://splet99.net';
	const NAME = 'Splet99 d.o.o.';

	public static function init() {
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'login_styles' ) );
		add_filter( 'login_headerurl', array( __CLASS__, 'login_logo_url' ) );
		add_filter( 'login_headertext', array( __CLASS__, 'login_logo_text' ) );
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
}
