<?php
namespace Splet99\Woo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Withdrawal from a distance contract (ZVPot-1A, 138.a člen): form page, popup, e-mail receipts, register of
// withdrawals, links in My Account and order e-mails. Ported from the standalone "Splet99 Odstop od pogodbe" 1.4.1
// plugin with the same option names, post type and page, so a site can switch over without losing anything.
final class Odstop {

	const FEATURE = 'odstop';

	const OPT      = 's99od_opts';
	const PAGE_OPT = 's99od_page_id';
	const CPT      = 's99_odstop';
	const SLUG     = 'odstop-od-pogodbe';
	const ENDPOINT = 'odstop-od-pogodbe';

	private static $inst = null;
	private $assets_needed = false;

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'boot' ) );
	}

	public static function enabled() {
		$settings = Plugin::settings();

		return in_array( self::FEATURE, (array) $settings['woo_features'], true );
	}

	// While the standalone plugin is active it does the job; running both would register everything twice.
	public static function standalone_active() {
		return class_exists( 'S99_Odstop', false );
	}

	public static function boot() {
		if ( null === self::$inst && self::enabled() && ! self::standalone_active() ) {
			self::$inst = new self();
		}
	}

	public static function form_page_url() {
		return self::page_url();
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_cpt' ) );
		add_shortcode( 'odstop_obrazec', array( $this, 'sc_form' ) );
		add_shortcode( 'odstop_link', array( $this, 'sc_link' ) );

		add_action( 'admin_post_nopriv_s99od_submit', array( $this, 'handle_submit' ) );
		add_action( 'admin_post_s99od_submit', array( $this, 'handle_submit' ) );

		add_action( 'wp_footer', array( $this, 'footer_output' ), 20 );
		add_filter( 'body_class', array( $this, 'body_class' ) );
		add_filter( 'wp_robots', array( $this, 'robots' ), 99 );
		add_filter( 'wpseo_robots', array( $this, 'robots_str' ), 99 );
		add_filter( 'rank_math/frontend/robots', array( $this, 'robots_arr' ), 99 );
		add_action( 'wp_head', array( $this, 'head_css' ), 99 );

		// WooCommerce: Moj račun + e-pošta o naročilu.
		add_filter( 'woocommerce_account_menu_items', array( $this, 'account_menu' ), 20 );
		add_filter( 'woocommerce_get_endpoint_url', array( $this, 'account_endpoint_url' ), 10, 2 );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'order_actions' ), 10, 2 );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'email_block' ), 20, 4 );

		// Admin.
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_filter( 'manage_' . self::CPT . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
	}

	/* ------------------------------------------------------------------ */
	/* Nastavitve in pomožne funkcije                                      */
	/* ------------------------------------------------------------------ */

	private static function defaults() {
		return array(
			'to_email'  => '',
			'link_text' => 'Odstop od pogodbe',
			'popup'     => 1,
			'footer'    => 0,
			'emails'    => 1,
			'sidebar'   => 1,
			'strict'    => 1,
			'langs'     => '',
		);
	}

	private static function opt( $key ) {
		$o = wp_parse_args( get_option( self::OPT, array() ), self::defaults() );
		return isset( $o[ $key ] ) ? $o[ $key ] : null;
	}

	private static function link_text() {
		$t = trim( (string) self::opt( 'link_text' ) );
		return '' !== $t ? __( $t, 's99-odstop' ) : __( 'Odstop od pogodbe', 's99-odstop' ); // phpcs:ignore
	}

	private static function intro_text() {
		return __( 'Za odstop od pogodbe izpolnite obrazec in ga potrdite. Po oddaji prejmete potrdilo o prejemu na navedeni e-poštni naslov.', 's99-odstop' );
	}

	private static function parse_emails( $raw ) {
		$out = array();
		foreach ( preg_split( '/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY ) as $e ) {
			$e = sanitize_email( $e );
			if ( is_email( $e ) ) {
				$out[] = $e;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function shop_recipients() {
		$list = self::parse_emails( self::opt( 'to_email' ) );
		return $list ? $list : array( self::shop_email() );
	}

	private static function clean_langs( $raw ) {
		$raw  = strtolower( preg_replace( '/[^a-zA-Z,\-_ ]/', '', (string) $raw ) );
		$list = array_values( array_unique( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) ) );
		return implode( ', ', $list );
	}

	private static function allowed_langs() {
		$list = array_values( array_filter( array_map( 'trim', explode( ',', (string) self::opt( 'langs' ) ) ) ) );
		if ( $list ) {
			return $list;
		}
		$def = apply_filters( 'wpml_default_language', null );
		return $def ? array( strtolower( (string) $def ) ) : array();
	}

	/**
	 * Ali se funkcija v danem jeziku prikaže (privzeto samo v privzetem jeziku WPML).
	 * Brez WPML-ja je vedno true.
	 */
	private static function lang_ok( $lang = '' ) {
		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return true;
		}
		$allowed = self::allowed_langs();
		if ( ! $allowed ) {
			return true;
		}
		if ( '' === $lang ) {
			$lang = apply_filters( 'wpml_current_language', null );
		}
		if ( ! $lang || 'all' === $lang ) {
			return true;
		}
		return in_array( strtolower( (string) $lang ), $allowed, true );
	}

	private static function shop_email() {
		$list = self::parse_emails( self::opt( 'to_email' ) );
		if ( $list ) {
			return $list[0];
		}
		$wc = (string) get_option( 'woocommerce_email_from_address' );
		if ( is_email( $wc ) ) {
			return $wc;
		}
		return get_option( 'admin_email' );
	}

	private static function from_header() {
		$addr = (string) get_option( 'woocommerce_email_from_address' );
		if ( ! is_email( $addr ) ) {
			$addr = self::shop_email();
		}
		$name = (string) get_option( 'woocommerce_email_from_name' );
		if ( '' === $name ) {
			$name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		}
		$name = str_replace( array( '"', "\r", "\n" ), '', $name );
		return 'From: "' . $name . '" <' . $addr . '>';
	}

	private static function page_url() {
		$id = (int) get_option( self::PAGE_OPT );
		if ( $id ) {
			$id  = (int) apply_filters( 'wpml_object_id', $id, 'page', true );
			$url = get_permalink( $id );
			if ( $url ) {
				return $url;
			}
		}
		return home_url( '/' . self::SLUG . '/' );
	}

	private static function fmt_time( $ts ) {
		return wp_date( 'j. n. Y \o\b H:i:s', (int) $ts );
	}

	private static function ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/* ------------------------------------------------------------------ */
	/* Aktivacija, CPT                                                     */
	/* ------------------------------------------------------------------ */

	private static function ensure_page() {
		$id = (int) get_option( self::PAGE_OPT );
		if ( $id && get_post_status( $id ) && 'trash' !== get_post_status( $id ) ) {
			return $id;
		}
		$existing = get_page_by_path( self::SLUG );
		if ( $existing && 'trash' !== $existing->post_status ) {
			update_option( self::PAGE_OPT, (int) $existing->ID );
			return (int) $existing->ID;
		}
		$new = wp_insert_post(
			array(
				'post_title'   => 'Odstop od pogodbe',
				'post_name'    => self::SLUG,
				'post_content' => '[odstop_obrazec]',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);
		if ( $new && ! is_wp_error( $new ) ) {
			update_option( self::PAGE_OPT, (int) $new );
			return (int) $new;
		}
		return 0;
	}

	public function register_cpt() {
		register_post_type(
			self::CPT,
			array(
				'labels'       => array(
					'name'          => 'Odstopi od pogodbe',
					'singular_name' => 'Odstop od pogodbe',
					'menu_name'     => 'Odstopi od pogodbe',
					'edit_item'     => 'Odstop od pogodbe',
					'search_items'  => 'Išči odstope',
					'not_found'     => 'Ni odstopov.',
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => true,
				'menu_icon'    => 'dashicons-undo',
				'supports'     => array( 'title' ),
				'capability_type' => 'post',
				'capabilities' => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap' => true,
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Obrazec                                                             */
	/* ------------------------------------------------------------------ */

	private function form_html( $uid ) {
		$name  = '';
		$email = '';
		$order = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( is_user_logged_in() ) {
			$u    = wp_get_current_user();
			$name = trim( $u->first_name . ' ' . $u->last_name );
			if ( '' === $name ) {
				$name = $u->display_name;
			}
			$email = $u->user_email;
		}
		$lang = apply_filters( 'wpml_current_language', '' );

		ob_start();
		?>
		<?php // Zapre morebiten nezačet form v temi/kodi strani: HTML parser sicer ignorira naš <form> (nested form), polja ostanejo brez obrazca. ?>
		</form>
		<form class="s99od-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="s99od_submit">
			<input type="hidden" name="back" value="<?php echo esc_url( self::page_url() ); ?>">
			<input type="hidden" name="lang" value="<?php echo esc_attr( is_string( $lang ) ? $lang : '' ); ?>">
			<p class="s99od-hp" aria-hidden="true"><label>Website <input type="text" name="website" value="" tabindex="-1" autocomplete="off"></label></p>
			<input type="hidden" name="s99_el" value="0" class="s99od-el">
			<p>
				<label for="<?php echo esc_attr( $uid ); ?>-name"><?php esc_html_e( 'Ime in priimek', 's99-odstop' ); ?> *</label>
				<input type="text" id="<?php echo esc_attr( $uid ); ?>-name" name="s99_name" value="<?php echo esc_attr( $name ); ?>" required maxlength="200" autocomplete="name">
			</p>
			<p>
				<label for="<?php echo esc_attr( $uid ); ?>-email"><?php esc_html_e( 'E-pošta (na ta naslov prejmete potrdilo)', 's99-odstop' ); ?> *</label>
				<input type="email" id="<?php echo esc_attr( $uid ); ?>-email" name="s99_email" value="<?php echo esc_attr( $email ); ?>" required maxlength="200" autocomplete="email">
			</p>
			<p>
				<label for="<?php echo esc_attr( $uid ); ?>-order"><?php esc_html_e( 'Številka naročila / referenca pogodbe', 's99-odstop' ); ?> *</label>
				<input type="text" id="<?php echo esc_attr( $uid ); ?>-order" name="order" value="<?php echo esc_attr( $order ); ?>" required maxlength="100">
			</p>
			<p>
				<label for="<?php echo esc_attr( $uid ); ?>-comment"><?php esc_html_e( 'Komentar (neobvezno)', 's99-odstop' ); ?></label>
				<textarea id="<?php echo esc_attr( $uid ); ?>-comment" name="s99_comment" rows="4" maxlength="3000"></textarea>
			</p>
			<p class="s99od-note"><?php esc_html_e( 'Če odstopate samo od določenih artiklov, jih navedite v komentarju. Brez navedbe velja odstop za celotno naročilo.', 's99-odstop' ); ?></p>
			<div class="s99od-actions"><button type="submit" class="s99od-btn"><?php esc_html_e( 'Potrdi odstop od pogodbe', 's99-odstop' ); ?></button></div>
		</form>
		<?php
		return ob_get_clean();
	}

	public function sc_form() {
		$this->assets_needed = true;

		$status = isset( $_GET['odstop'] ) ? sanitize_key( wp_unslash( $_GET['odstop'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' !== $status && ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		$out = '<div class="s99od-wrap">';

		if ( 'ok' === $status ) {
			$out .= $this->confirmation_html();
			return $out . '</div>';
		}

		if ( 'err' === $status ) {
			$code = isset( $_GET['e'] ) ? sanitize_key( wp_unslash( $_GET['e'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			$msgs = array(
				'req'  => __( 'Izpolnite vsa obvezna polja.', 's99-odstop' ),
				'mail' => __( 'Vpišite veljaven e-poštni naslov.', 's99-odstop' ),
				'rate' => __( 'Preveč poskusov. Poskusite znova čez nekaj časa.', 's99-odstop' ),
				'noorder' => __( 'To naročilo ne obstaja. Preverite številko naročila in e-naslov, ki ste ga uporabili pri nakupu.', 's99-odstop' ),
				'save' => __( 'Obvestila ni bilo mogoče shraniti. Poskusite znova.', 's99-odstop' ),
			);
			$msg  = isset( $msgs[ $code ] ) ? $msgs[ $code ] : $msgs['req'];
			$out .= '<div class="s99od-msg" role="alert">' . esc_html( $msg ) . '</div>';
		}

		$out .= '<p class="s99od-intro">' . esc_html( self::intro_text() ) . '</p>';
		$out .= $this->form_html( 's99od-p' );
		return $out . '</div>';
	}

	public function sc_link( $atts ) {
		$atts = shortcode_atts( array( 'text' => '' ), $atts );
		$text = '' !== $atts['text'] ? $atts['text'] : self::link_text();
		$this->assets_needed = true;
		return '<a class="s99od-link" href="' . esc_url( self::page_url() ) . '">' . esc_html( $text ) . '</a>';
	}

	private function confirmation_html() {
		$t   = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$ps  = array();
		if ( '' !== $t ) {
			$ps = get_posts(
				array(
					'post_type'   => self::CPT,
					'post_status' => 'any',
					'meta_key'    => 'token', // phpcs:ignore
					'meta_value'  => $t, // phpcs:ignore
					'numberposts' => 1,
				)
			);
		}
		$html = '<div class="s99od-msg s99od-ok" role="status"><strong>' . esc_html__( 'Obvestilo o odstopu od pogodbe je oddano.', 's99-odstop' ) . '</strong>';
		if ( empty( $ps ) ) {
			return $html . '</div>';
		}
		$id    = $ps[0]->ID;
		$name  = get_post_meta( $id, 'name', true );
		$email = get_post_meta( $id, 'email', true );
		$order = get_post_meta( $id, 'order', true );
		$ts    = (int) get_post_meta( $id, 'submitted_ts', true );
		$ok    = 'ok' === get_post_meta( $id, 'mail_customer', true );
		if ( get_post_meta( $id, 'spam', true ) ) {
			$html .= '<p>' . esc_html( sprintf( __( 'Obvestila ni bilo mogoče samodejno potrditi. Za potrdilo nas prosimo kontaktirajte na %s.', 's99-odstop' ), self::shop_email() ) ) . '</p>';
			return $html . '</div>';
		}

		$html .= '<dl class="s99od-dl">';
		$html .= '<dt>' . esc_html__( 'Ime in priimek', 's99-odstop' ) . '</dt><dd>' . esc_html( $name ) . '</dd>';
		$html .= '<dt>' . esc_html__( 'Številka naročila / referenca pogodbe', 's99-odstop' ) . '</dt><dd>' . esc_html( $order ) . '</dd>';
		$html .= '<dt>' . esc_html__( 'Datum in čas oddaje', 's99-odstop' ) . '</dt><dd>' . esc_html( self::fmt_time( $ts ) ) . '</dd>';
		$html .= '</dl>';
		if ( $ok ) {
			/* translators: %s: e-mail address */
			$html .= '<p>' . esc_html( sprintf( __( 'Potrdilo o prejemu smo poslali na %s.', 's99-odstop' ), $email ) ) . '</p>';
		} else {
			$html .= '<p>' . esc_html__( 'Potrdila po e-pošti nam ni uspelo poslati. Shranite ali natisnite to stran kot dokazilo o oddaji.', 's99-odstop' ) . '</p>';
		}
		return $html . '</div>';
	}

	/* ------------------------------------------------------------------ */
	/* Oddaja                                                              */
	/* ------------------------------------------------------------------ */

	public function handle_submit() {
		// Namenoma brez nonce: strani so pogosto v predpomnilniku (WP Rocket) in potekel nonce
		// bi kupcu onemogočil odstop. Zaščita: honeypot + omejitev oddaj na IP.
		$fallback = self::page_url();
		$back     = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : $fallback; // phpcs:ignore
		$back     = wp_validate_redirect( $back, $fallback );
		$back     = remove_query_arg( array( 'odstop', 't', 'e', 'order' ), $back );

		if ( ! empty( $_POST['website'] ) ) { // phpcs:ignore
			wp_safe_redirect( add_query_arg( 'odstop', 'ok', $back ) );
			exit;
		}

		$lang = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : ''; // phpcs:ignore
		if ( '' !== $lang ) {
			do_action( 'wpml_switch_language', $lang );
		}

		$name    = isset( $_POST['s99_name'] ) ? sanitize_text_field( wp_unslash( $_POST['s99_name'] ) ) : ''; // phpcs:ignore
		$email   = isset( $_POST['s99_email'] ) ? sanitize_email( wp_unslash( $_POST['s99_email'] ) ) : ''; // phpcs:ignore
		$order   = isset( $_POST['order'] ) ? sanitize_text_field( wp_unslash( $_POST['order'] ) ) : ''; // phpcs:ignore
		$comment = isset( $_POST['s99_comment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['s99_comment'] ) ) : ''; // phpcs:ignore

		$name    = mb_substr( $name, 0, 200 );
		$order   = mb_substr( $order, 0, 100 );
		$comment = mb_substr( $comment, 0, 3000 );

		if ( '' === $name || '' === $order || '' === $email ) {
			$this->back_err( $back, 'req' );
		}
		if ( ! is_email( $email ) ) {
			$this->back_err( $back, 'mail' );
		}

		$key = 's99od_rl_' . md5( self::ip() );
		$n   = (int) get_transient( $key );
		if ( $n >= 10 ) {
			$this->back_err( $back, 'rate' );
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );

		// Protispam: čas do oddaje (JS), povezave v besedilu, preverjanje naročila.
		$el       = isset( $_POST['s99_el'] ) ? absint( $_POST['s99_el'] ) : 0; // phpcs:ignore
		$human    = $el >= 1500 && $el <= 7 * DAY_IN_SECONDS * 1000;
		$has_link = (bool) preg_match( '~https?://|www\\.~i', $comment . ' ' . $name . ' ' . $order );
		$verified = self::verify_order( $order, $email );
		$spam     = ( ! $human && ! $verified ) || ( $has_link && ! $verified );
		if ( self::opt( 'strict' ) && function_exists( 'wc_get_order' ) && ! $verified ) {
			$this->back_err( $back, 'noorder' );
		}

		$ts    = time();
		$token = wp_generate_password( 20, false, false );
		$id    = wp_insert_post(
			array(
				'post_type'   => self::CPT,
				'post_status' => $spam ? 'pending' : 'publish',
				'post_title'  => sprintf( 'Naročilo %s – %s', $order, $name ),
				'meta_input'  => array(
					'name'          => $name,
					'email'         => $email,
					'order'         => $order,
					'comment'       => $comment,
					'submitted_ts'  => $ts,
					'token'         => $token,
					'lang'          => $lang,
					'mail_customer' => $spam ? 'skipped' : 'pending',
					'mail_shop'     => $spam ? 'skipped' : 'pending',
					'spam'          => $spam ? 1 : 0,
					'verified'      => $verified ? 1 : 0,
				),
			)
		);
		if ( ! $id || is_wp_error( $id ) ) {
			$this->back_err( $back, 'save' );
		}

		if ( ! $spam ) {
			// Največ 2 potrdili / 24 h na isti e-naslov (zaščita pred zlorabo za pošiljanje tujcem).
			$ck     = 's99od_ce_' . md5( strtolower( $email ) );
			$cn     = (int) get_transient( $ck );
			$notify = $cn < 2;
			set_transient( $ck, $cn + 1, DAY_IN_SECONDS );
			if ( ! $notify ) {
				update_post_meta( $id, 'limited', 1 );
			}
			$this->send_mails(
				$id,
				array(
					'name'    => $name,
					'email'   => $email,
					'order'   => $order,
					'comment' => $comment,
				),
				$ts,
				$verified,
				$notify
			);
		}

		wp_safe_redirect( add_query_arg( array( 'odstop' => 'ok', 't' => $token ), $back ) );
		exit;
	}

	private static function verify_order( $order, $email ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return false;
		}
		$num = absint( preg_replace( '~\\D+~', '', $order ) );
		if ( ! $num ) {
			return false;
		}
		$o = apply_filters( 's99od_find_order', wc_get_order( $num ), $order );
		if ( ! $o || ! is_object( $o ) || ! is_a( $o, 'WC_Order' ) || 'shop_order_refund' === $o->get_type() ) {
			return false;
		}
		return strtolower( trim( (string) $o->get_billing_email() ) ) === strtolower( trim( $email ) );
	}

	private function back_err( $back, $code ) {
		wp_safe_redirect( add_query_arg( array( 'odstop' => 'err', 'e' => $code ), $back ) );
		exit;
	}

	private function mail_html( $title, $intro, $d, $when, $note = '' ) {
		$rows = array(
			__( 'Ime in priimek', 's99-odstop' )                      => $d['name'],
			__( 'E-pošta', 's99-odstop' )                             => $d['email'],
			__( 'Številka naročila / referenca pogodbe', 's99-odstop' ) => $d['order'],
			__( 'Komentar', 's99-odstop' )                            => '' !== $d['comment'] ? $d['comment'] : '—',
			__( 'Datum in čas oddaje', 's99-odstop' )                 => $when,
		);
		if ( '' !== $note ) {
			$rows['Preverjanje'] = $note;
		}
		$h  = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#000;max-width:600px">';
		$h .= '<h2 style="margin:0 0 12px;font-size:20px">' . esc_html( $title ) . '</h2>';
		$h .= '<p style="margin:0 0 16px">' . esc_html( $intro ) . '</p>';
		$h .= '<table cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%">';
		foreach ( $rows as $label => $val ) {
			$h .= '<tr><td style="border:1px solid #000;font-weight:bold;width:38%;vertical-align:top">' . esc_html( $label ) . '</td>';
			$h .= '<td style="border:1px solid #000">' . nl2br( esc_html( $val ) ) . '</td></tr>';
		}
		$h .= '</table></div>';
		return $h;
	}

	private function send_mails( $id, $d, $ts, $verified = true, $notify = true ) {
		$when = self::fmt_time( $ts );
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$shop = self::shop_email();
		$from = self::from_header();

		$c_headers = array( 'Content-Type: text/html; charset=UTF-8', $from, 'Reply-To: ' . $shop );
		$c_subject = sprintf( __( 'Potrdilo o prejemu obvestila o odstopu od pogodbe – %s', 's99-odstop' ), $site );
		$c_body    = $this->mail_html(
			__( 'Potrdilo o prejemu obvestila o odstopu od pogodbe', 's99-odstop' ),
			__( 'Prejeli smo vaše obvestilo o odstopu od pogodbe. Spodaj je vsebina obvestila z datumom in uro oddaje.', 's99-odstop' ),
			$d,
			$when
		);
		if ( $notify ) {
			$ok_c = wp_mail( $d['email'], $c_subject, $c_body, $c_headers );
			update_post_meta( $id, 'mail_customer', $ok_c ? 'ok' : 'fail' );
		} else {
			update_post_meta( $id, 'mail_customer', 'skipped' );
		}

		$s_headers = array( 'Content-Type: text/html; charset=UTF-8', $from, 'Reply-To: ' . $d['email'] );
		$s_subject = sprintf( '%sNov odstop od pogodbe: naročilo %s', $verified ? '' : '[NEPREVERJENO] ', $d['order'] );
		$s_body    = $this->mail_html(
			'Nov odstop od pogodbe',
			'Kupec je prek spletne funkcije oddal obvestilo o odstopu od pogodbe.',
			$d,
			$when,
			$verified ? '' : 'Naročila s to številko in e-naslovom ni bilo mogoče samodejno preveriti.'
		);
		$ok_s = wp_mail( self::shop_recipients(), $s_subject, $s_body, $s_headers );
		update_post_meta( $id, 'mail_shop', $ok_s ? 'ok' : 'fail' );
	}

	/* ------------------------------------------------------------------ */
	/* Footer: CSS, popup, povezava                                        */
	/* ------------------------------------------------------------------ */

	private function is_form_page() {
		if ( is_admin() || ! is_singular() ) {
			return false;
		}
		$content = get_post_field( 'post_content', get_queried_object_id() );
		return $content && has_shortcode( $content, 'odstop_obrazec' );
	}

	/* noindex za stran z obrazcem (SEO vtičniki ga lahko prepišejo, zato filtri za Yoast in Rank Math). */
	public function robots( $robots ) {
		if ( $this->is_form_page() ) {
			unset( $robots['index'], $robots['follow'], $robots['max-image-preview'] );
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	public function robots_str( $r ) {
		return $this->is_form_page() ? 'noindex, nofollow' : $r;
	}

	public function robots_arr( $r ) {
		if ( $this->is_form_page() ) {
			$r = is_array( $r ) ? $r : array();
			$r['index']  = 'noindex';
			$r['follow'] = 'nofollow';
		}
		return $r;
	}

	public function body_class( $classes ) {
		if ( $this->is_form_page() ) {
			$classes[] = 's99od-page';
		}
		return $classes;
	}

	public function head_css() {
		if ( ! self::opt( 'sidebar' ) || ! $this->is_form_page() ) {
			return;
		}
		echo '<style id="s99od-nosidebar">'
			. 'body.s99od-page #secondary,body.s99od-page #sidebar,body.s99od-page .sidebar,body.s99od-page aside.widget-area,body.s99od-page .sidebar-primary,body.s99od-page .et_right_sidebar #sidebar,body.s99od-page .et_left_sidebar #sidebar{display:none!important}'
			. 'body.s99od-page #primary,body.s99od-page .content-area,body.s99od-page #left-area,body.s99od-page .site-main{width:100%!important;max-width:100%!important;float:none!important;margin-left:0!important;margin-right:0!important}'
			. 'body.s99od-page #main-content .container:before,body.s99od-page #sidebar:before{display:none!important}'
			. '</style>';
	}

	public function footer_output() {
		if ( is_admin() ) {
			return;
		}
		$popup  = (bool) self::opt( 'popup' );
		$footer = (bool) self::opt( 'footer' );
		if ( ! $popup && ! $footer && ! $this->assets_needed ) {
			return;
		}

		$on_form_page = $this->assets_needed;
		if ( ! $on_form_page && is_singular() ) {
			$content = get_post_field( 'post_content', get_queried_object_id() );
			if ( $content && has_shortcode( $content, 'odstop_obrazec' ) ) {
				$on_form_page = true;
			}
		}

		?>
		<style id="s99od-css">
		.s99od-wrap,.s99od-dialog{--s99od-text:#3f3f46;--s99od-muted:#71717a;--s99od-line:#e4e4e7;--s99od-field:#f4f4f5;--s99od-accent:#52525b;--s99od-accent-hover:#3f3f46;color:var(--s99od-text)}
		.s99od-wrap{max-width:520px}
		.s99od-dialog .s99od-wrap{max-width:none}
		.s99od-form{max-width:520px;font-size:14px;line-height:1.4}
		.s99od-form p{margin:0 0 12px;padding:0}
		.s99od-form label{display:block;font-size:13px;font-weight:600;line-height:1.3;margin:0 0 5px;padding:0;color:var(--s99od-text)}
		.s99od-form input[type=text],.s99od-form input[type=email],.s99od-form textarea{display:block;width:100%;box-sizing:border-box;margin:0;padding:9px 12px;min-height:0;height:auto;border:1px solid var(--s99od-line);border-radius:8px;background:var(--s99od-field);color:var(--s99od-text);font:inherit;font-size:14px;line-height:1.4;box-shadow:none}
		.s99od-form textarea{min-height:84px;resize:vertical}
		.s99od-form input:focus,.s99od-form textarea:focus{outline:0;border-color:#a1a1aa;background:#fff;box-shadow:0 0 0 3px rgba(113,113,122,.18)}
		.s99od-hp{position:absolute!important;left:-9999px!important;height:0!important;overflow:hidden!important}
		.s99od-note{margin:0 0 12px;font-size:12.5px;line-height:1.45;color:var(--s99od-muted)}
		.s99od-intro{margin:0 0 14px;font-size:13.5px;line-height:1.45;color:var(--s99od-muted)}
		.s99od-btn{display:block;width:100%;margin:0;padding:11px 16px;background:var(--s99od-accent);color:#fff;border:0;border-radius:8px;font:inherit;font-size:14px;font-weight:600;line-height:1.3;cursor:pointer;text-align:center;box-shadow:none;transition:background .15s}
		.s99od-btn:hover,.s99od-btn:focus{background:var(--s99od-accent-hover);color:#fff}
		.s99od-msg{margin:0 0 14px;padding:12px 14px;border:1px solid var(--s99od-line);border-radius:8px;background:var(--s99od-field);font-size:14px;line-height:1.45;color:var(--s99od-text)}
		.s99od-dl{margin:10px 0 0}
		.s99od-dl dt{margin:8px 0 0;font-size:12.5px;font-weight:600;color:var(--s99od-muted)}
		.s99od-dl dd{margin:0}
		.s99od-footer-link{text-align:center;margin:0;padding:10px 16px;font-size:13px}
		.s99od-footer-link a{color:inherit;text-decoration:underline}
		dialog.s99od-dialog{position:fixed;width:min(460px,calc(100% - 24px));max-height:calc(100vh - 24px);overflow:auto;margin:auto;padding:0;border:0;border-radius:14px;background:#fff;box-sizing:border-box;box-shadow:0 20px 50px rgba(0,0,0,.25)}
		dialog.s99od-dialog::backdrop{background:rgba(24,24,27,.55)}
		.s99od-head{position:relative;padding:14px 48px 14px 20px;border-bottom:1px solid var(--s99od-line)}
		.s99od-dialog h2{margin:0;padding:0;font-size:17px;font-weight:600;line-height:1.3;color:var(--s99od-text)}
		.s99od-body{padding:14px 20px 18px}
		.s99od-dialog .s99od-form{max-width:none}
		.s99od-dialog .s99od-actions{margin:14px -20px 0;padding:14px 20px 0;border-top:1px solid var(--s99od-line)}
		.s99od-close{position:absolute;top:50%;right:10px;transform:translateY(-50%);width:32px;height:32px;margin:0;padding:0;border:0;border-radius:8px;background:transparent;color:var(--s99od-muted);font-size:24px;line-height:1;cursor:pointer;box-shadow:none}
		.s99od-close:hover{background:var(--s99od-field);color:var(--s99od-text)}
		</style>
		<script>
		document.addEventListener('submit',function(e){var f=e.target;if(f&&f.classList&&f.classList.contains('s99od-form')){var i=f.querySelector('.s99od-el');if(i){i.value=Math.round(performance.now());}}},true);
		</script>
		<?php

		if ( $footer && self::lang_ok() ) {
			echo '<p class="s99od-footer-link"><a class="s99od-link" href="' . esc_url( self::page_url() ) . '">' . esc_html( self::link_text() ) . '</a></p>';
		}

		if ( $popup && ! $on_form_page ) {
			$path = wp_parse_url( self::page_url(), PHP_URL_PATH );
			$path = rtrim( (string) $path, '/' );
			?>
			<dialog id="s99od-dialog" class="s99od-dialog" aria-labelledby="s99od-title">
				<div class="s99od-head">
					<h2 id="s99od-title"><?php esc_html_e( 'Odstop od pogodbe', 's99-odstop' ); ?></h2>
					<button type="button" class="s99od-close" aria-label="<?php esc_attr_e( 'Zapri', 's99-odstop' ); ?>">&times;</button>
				</div>
				<div class="s99od-body">
					<p class="s99od-intro"><?php echo esc_html( self::intro_text() ); ?></p>
					<?php echo $this->form_html( 's99od-d' ); // phpcs:ignore ?>
				</div>
			</dialog>
			<script>
			(function(){
				var d=document.getElementById('s99od-dialog');
				if(!d||typeof d.showModal!=='function'){return;}
				var p=<?php echo wp_json_encode( $path ); ?>;
				document.addEventListener('click',function(e){
					if(e.defaultPrevented||e.button||e.metaKey||e.ctrlKey||e.shiftKey||e.altKey){return;}
					var a=e.target.closest?e.target.closest('a[href]'):null;
					if(!a){return;}
					var u;try{u=new URL(a.href,location.href);}catch(x){return;}
					if(u.origin!==location.origin||u.pathname.replace(/\/$/,'')!==p){return;}
					e.preventDefault();
					var o=u.searchParams.get('order');
					if(o){var f=d.querySelector('input[name="order"]');if(f){f.value=o;}}
					d.showModal();
				});
				d.addEventListener('click',function(e){if(e.target===d){d.close();}});
				var c=d.querySelector('.s99od-close');
				if(c){c.addEventListener('click',function(){d.close();});}
			})();
			</script>
			<?php
		}
	}

	/* ------------------------------------------------------------------ */
	/* WooCommerce                                                         */
	/* ------------------------------------------------------------------ */

	public function account_menu( $items ) {
		if ( ! self::lang_ok() ) {
			return $items;
		}
		$out = array();
		foreach ( $items as $k => $v ) {
			if ( 'customer-logout' === $k ) {
				$out[ self::ENDPOINT ] = self::link_text();
			}
			$out[ $k ] = $v;
		}
		if ( ! isset( $out[ self::ENDPOINT ] ) ) {
			$out[ self::ENDPOINT ] = self::link_text();
		}
		return $out;
	}

	public function account_endpoint_url( $url, $endpoint ) {
		return self::ENDPOINT === $endpoint ? self::page_url() : $url;
	}

	public function order_actions( $actions, $order ) {
		if ( ! self::lang_ok() ) {
			return $actions;
		}
		if ( $order->has_status( array( 'cancelled', 'refunded', 'failed' ) ) ) {
			return $actions;
		}
		$actions['odstop'] = array(
			'url'  => add_query_arg( 'order', $order->get_order_number(), self::page_url() ),
			'name' => self::link_text(),
		);
		return $actions;
	}

	public function email_block( $order, $sent_to_admin, $plain_text, $email ) {
		if ( $sent_to_admin || ! self::opt( 'emails' ) || ! is_object( $email ) ) {
			return;
		}
		$order_lang = is_object( $order ) && method_exists( $order, 'get_meta' ) ? $order->get_meta( 'wpml_language' ) : '';
		if ( ! self::lang_ok( is_string( $order_lang ) ? $order_lang : '' ) ) {
			return;
		}
		$ids = array( 'customer_completed_order', 'customer_processing_order', 'customer_on_hold_order', 'customer_invoice' );
		if ( ! in_array( $email->id, $ids, true ) ) {
			return;
		}
		$url  = add_query_arg( 'order', $order->get_order_number(), self::page_url() );
		$text = self::link_text();
		if ( $plain_text ) {
			echo "\n" . $text . ': ' . esc_url_raw( $url ) . "\n";
			return;
		}
		echo '<p style="margin:16px 0"><a href="' . esc_url( $url ) . '" style="color:#000;text-decoration:underline">' . esc_html( $text ) . '</a></p>';
	}

	/* ------------------------------------------------------------------ */
	/* Admin                                                               */
	/* ------------------------------------------------------------------ */

	public function admin_init() {
		register_setting( 's99od_group', self::OPT, array( 'sanitize_callback' => array( $this, 'sanitize_opts' ) ) );
		// Only an admin's page load creates the page, under a short lock, so parallel requests can't make two pages.
		if ( ! get_option( self::PAGE_OPT ) && ! wp_doing_ajax() && current_user_can( 'manage_options' ) ) {
			$lock = 's99od_page_lock';
			$held = (int) get_option( $lock );

			if ( $held && $held < time() - MINUTE_IN_SECONDS ) {
				delete_option( $lock );
			}

			if ( add_option( $lock, time(), '', 'no' ) ) {
				self::ensure_page();
				delete_option( $lock );
			}
		}
	}

	public function sanitize_opts( $in ) {
		$in    = is_array( $in ) ? $in : array();
		$emails = self::parse_emails( isset( $in['to_email'] ) ? $in['to_email'] : '' );
		return array(
			'to_email'  => implode( ', ', $emails ),
			'link_text' => isset( $in['link_text'] ) && '' !== trim( $in['link_text'] ) ? sanitize_text_field( $in['link_text'] ) : 'Odstop od pogodbe',
			'popup'     => empty( $in['popup'] ) ? 0 : 1,
			'footer'    => empty( $in['footer'] ) ? 0 : 1,
			'emails'    => empty( $in['emails'] ) ? 0 : 1,
			'sidebar'   => empty( $in['sidebar'] ) ? 0 : 1,
			'strict'    => empty( $in['strict'] ) ? 0 : 1,
			'langs'     => self::clean_langs( isset( $in['langs'] ) ? $in['langs'] : '' ),
		);
	}

	public function admin_menu() {
		add_options_page( 'Odstop od pogodbe', 'Odstop od pogodbe', 'manage_options', 's99od', array( $this, 'settings_page' ) );
		// Bližnjica do nastavitev tudi v meniju Odstopi od pogodbe.
		add_submenu_page( 'edit.php?post_type=' . self::CPT, 'Nastavitve', 'Nastavitve', 'manage_options', 'options-general.php?page=s99od' );
	}

	public function settings_page() {
		$o = wp_parse_args( get_option( self::OPT, array() ), self::defaults() );
		?>
		<div class="wrap">
			<h1>Odstop od pogodbe</h1>
			<p>Stran z obrazcem: <a href="<?php echo esc_url( self::page_url() ); ?>" target="_blank"><?php echo esc_html( self::page_url() ); ?></a><br>
			Shortcode za obrazec: <code>[odstop_obrazec]</code> · za povezavo: <code>[odstop_link]</code>. Vsaka povezava na to stran (npr. v meniju) odpre popup.</p>
			<form method="post" action="options.php">
				<?php settings_fields( 's99od_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="s99od_to">E-naslov za obvestila o odstopih</label></th>
						<td><input type="text" id="s99od_to" class="regular-text" name="<?php echo esc_attr( self::OPT ); ?>[to_email]" value="<?php echo esc_attr( $o['to_email'] ); ?>" placeholder="<?php echo esc_attr( self::shop_email() ); ?>">
						<p class="description">Sem pridejo obvestila o odstopih (lahko več naslovov ločiš z vejico). Prazno = e-pošta pošiljatelja iz WooCommerce.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="s99od_lt">Besedilo povezave</label></th>
						<td><input type="text" id="s99od_lt" class="regular-text" name="<?php echo esc_attr( self::OPT ); ?>[link_text]" value="<?php echo esc_attr( $o['link_text'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row">Popup</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPT ); ?>[popup]" value="1" <?php checked( $o['popup'], 1 ); ?>> Povezave na stran odpri v popupu (brez JS se odpre stran)</label></td>
					</tr>
					<tr>
						<th scope="row"><label for="s99od_langs">Jeziki (WPML)</label></th>
						<td><input type="text" id="s99od_langs" class="regular-text" name="<?php echo esc_attr( self::OPT ); ?>[langs]" value="<?php echo esc_attr( $o['langs'] ); ?>" placeholder="<?php echo esc_attr( implode( ', ', self::allowed_langs() ) ); ?>">
						<p class="description">V katerih jezikih se povezava prikaže v Mojem računu, e-pošti in nogi (kode ločene z vejico, npr. <code>sl</code>). Prazno = samo privzeti jezik WPML. Brez WPML-ja je povezava povsod.</p></td>
					</tr>
					<tr>
						<th scope="row">Strogo preverjanje naročil</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPT ); ?>[strict]" value="1" <?php checked( $o['strict'], 1 ); ?>> Zavrni odstop, če naročilo z vpisano številko in e-naslovom ne obstaja v WooCommerce</label>
						<p class="description">Številka z # ali brez je vseeno. Kupec dobi sporočilo, da naročilo ne obstaja. Deluje samo, če je WooCommerce aktiven.</p></td>
					</tr>
					<tr>
						<th scope="row">Stranska vrstica</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPT ); ?>[sidebar]" value="1" <?php checked( $o['sidebar'], 1 ); ?>> Na strani z obrazcem skrij stransko vrstico (sidebar)</label>
						<p class="description">Deluje na običajnih temah. Pri XStore/Divi raje nastavi stran brez sidebara v nastavitvah strani.</p></td>
					</tr>
					<tr>
						<th scope="row">Povezava v nogi</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPT ); ?>[footer]" value="1" <?php checked( $o['footer'], 1 ); ?>> Samodejno izpiši povezavo na dnu strani</label></td>
					</tr>
					<tr>
						<th scope="row">E-pošta o naročilu</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPT ); ?>[emails]" value="1" <?php checked( $o['emails'], 1 ); ?>> Dodaj povezavo v e-pošte kupcu</label></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public function add_meta_box() {
		add_meta_box( 's99od_details', 'Podrobnosti odstopa', array( $this, 'render_meta_box' ), self::CPT, 'normal', 'high' );
	}

	public function render_meta_box( $post ) {
		$rows = array(
			'Ime in priimek'      => get_post_meta( $post->ID, 'name', true ),
			'E-pošta'             => get_post_meta( $post->ID, 'email', true ),
			'Naročilo / pogodba'  => get_post_meta( $post->ID, 'order', true ),
			'Komentar'            => get_post_meta( $post->ID, 'comment', true ),
			'Oddano'              => self::fmt_time( (int) get_post_meta( $post->ID, 'submitted_ts', true ) ),
			'Potrdilo kupcu'      => get_post_meta( $post->ID, 'mail_customer', true ),
			'Obvestilo trgovcu'   => get_post_meta( $post->ID, 'mail_shop', true ),
			'Jezik'               => get_post_meta( $post->ID, 'lang', true ),
			'Spam (neprever.)'    => get_post_meta( $post->ID, 'spam', true ) ? 'DA – ni poslano' : 'ne',
			'Naročilo preverjeno' => get_post_meta( $post->ID, 'verified', true ) ? 'da' : 'ne',
		);
		echo '<table class="widefat striped"><tbody>';
		foreach ( $rows as $l => $v ) {
			echo '<tr><th style="width:200px">' . esc_html( $l ) . '</th><td>' . nl2br( esc_html( (string) $v ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public function columns( $c ) {
		return array(
			'cb'        => isset( $c['cb'] ) ? $c['cb'] : '',
			'title'     => 'Odstop',
			's99_email' => 'E-pošta',
			's99_time'  => 'Oddano',
			's99_mail'  => 'Potrdilo kupcu',
			's99_spam'  => 'Spam',
		);
	}

	public function column_content( $col, $id ) {
		if ( 's99_email' === $col ) {
			echo esc_html( (string) get_post_meta( $id, 'email', true ) );
		} elseif ( 's99_time' === $col ) {
			echo esc_html( self::fmt_time( (int) get_post_meta( $id, 'submitted_ts', true ) ) );
		} elseif ( 's99_spam' === $col ) {
			echo get_post_meta( $id, 'spam', true ) ? '<strong>DA</strong>' : '—';
		} elseif ( 's99_mail' === $col ) {
			$m = get_post_meta( $id, 'mail_customer', true );
			echo 'ok' === $m ? 'Poslano' : ( 'fail' === $m ? '<strong>NI poslano</strong>' : esc_html( (string) $m ) );
		}
	}
}
