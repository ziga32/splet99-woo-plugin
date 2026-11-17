<?php
namespace Splet99\Woo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {

	const TABS = array(
		'notifications' => 'Hide notifications',
		'plugins'       => 'Hide plugins',
		'menu'          => 'Hide menu items',
		'woo'           => 'WooCommerce',
	);

	const TAB_FIELDS = array(
		'notifications' => 'exempt_users',
		'plugins'       => 'hidden_plugins',
		'menu'          => 'hidden_menu',
		'woo'           => 'woo_features',
	);

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_post_' . Plugin::OPTION, array( __CLASS__, 'save' ) );
	}

	public static function add_page() {
		if ( ! Plugin::is_owner() ) {
			return;
		}

		add_options_page( 'Splet99 settings', 'Splet99 settings', 'manage_options', Plugin::PAGE, array( __CLASS__, 'render' ) );
	}

	private static function tab_url( $tab, $args = array() ) {
		return add_query_arg(
			array_merge( array( 'page' => Plugin::PAGE, 'tab' => $tab ), $args ),
			admin_url( 'options-general.php' )
		);
	}

	private static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		return array_key_exists( $tab, self::TABS ) ? $tab : 'notifications';
	}

	public static function save() {
		if ( ! Plugin::is_owner() ) {
			wp_die( 'Ni dovoljenja.', 403 );
		}

		check_admin_referer( Plugin::OPTION );

		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';

		if ( array_key_exists( $tab, self::TAB_FIELDS ) ) {
			$fields   = self::TAB_FIELDS;
			$field    = $fields[ $tab ];
			$values   = isset( $_POST[ $field ] ) ? (array) wp_unslash( $_POST[ $field ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$stored   = get_option( Plugin::OPTION, array() );
			$stored   = is_array( $stored ) ? $stored : array();

			if ( 'exempt_users' === $field ) {
				$stored[ $field ] = array_values( array_filter( array_map( 'absint', $values ) ) );
			} else {
				// Not sanitize_text_field(): it strips %XX sequences that some menu slugs contain.
				$stored[ $field ] = array_values( array_filter( array_map( 'trim', array_map( 'wp_strip_all_tags', $values ) ) ) );
			}

			$saved_tabs           = isset( $stored['saved_tabs'] ) ? (array) $stored['saved_tabs'] : array();
			$stored['saved_tabs'] = array_values( array_unique( array_merge( $saved_tabs, array( $field ) ) ) );

			update_option( Plugin::OPTION, $stored );
		}

		wp_safe_redirect( self::tab_url( $tab ? $tab : 'notifications', array( 'updated' => 1 ) ) );
		exit;
	}

	public static function render() {
		$tab      = self::current_tab();
		$settings = Plugin::settings();
		?>
		<div class="wrap">
			<h1>Splet99 settings <small style="font-size:13px;color:#646970">Splet99 Woo <?php echo esc_html( SPLET99_WOO_VERSION ); ?></small></h1>

			<?php if ( ! empty( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p>Nastavitve so shranjene.</p></div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper">
				<?php foreach ( self::TABS as $key => $label ) : ?>
					<a href="<?php echo esc_url( self::tab_url( $key ) ); ?>" class="nav-tab<?php echo $key === $tab ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Plugin::OPTION ); ?>">
				<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
				<?php wp_nonce_field( Plugin::OPTION ); ?>

				<?php
				if ( 'plugins' === $tab ) {
					self::render_plugins( $settings );
				} elseif ( 'menu' === $tab ) {
					self::render_menu( $settings );
				} elseif ( 'woo' === $tab ) {
					self::render_woo( $settings );
				} else {
					self::render_notifications( $settings );
				}

				submit_button( 'Shrani' );
				?>
			</form>
		</div>
		<?php
	}

	private static function render_notifications( $settings ) {
		$exempt = array_map( 'intval', (array) $settings['exempt_users'] );
		$users  = get_users(
			array(
				'role__not_in' => array( 'subscriber', 'customer' ),
				'orderby'      => 'login',
			)
		);
		?>
		<p>Za vse uporabnike razen <code><?php echo esc_html( Plugin::OWNER ); ?></code> in spodaj izbranih se skrije:</p>
		<ul style="list-style:disc;padding-left:20px">
			<li>obvestila in napake v wp-adminu</li>
			<li>PHP napake na strani</li>
			<li>widgeti na nadzorni plošči in pozdravna plošča
				<?php if ( Plugin::DASHBOARD_KEEP ) : ?>
					(ostane: <code><?php echo esc_html( implode( ', ', Plugin::DASHBOARD_KEEP ) ); ?></code>)
				<?php endif; ?>
			</li>
			<li>elementi v admin vrstici: <code><?php echo esc_html( implode( ', ', Plugin::ADMIN_BAR_HIDE ) ); ?></code></li>
		</ul>

		<h2>Uporabniki, ki vidijo vse</h2>
		<table class="widefat striped" style="max-width:760px">
			<thead>
				<tr>
					<td class="check-column"></td>
					<th>Uporabnik</th>
					<th>Ime</th>
					<th>Vloga</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $users as $user ) : ?>
					<?php
					if ( Plugin::OWNER === $user->user_login ) {
						continue;
					}
					$field_id = 'splet99-user-' . $user->ID;
					?>
					<tr>
						<th scope="row" class="check-column">
							<input type="checkbox" id="<?php echo esc_attr( $field_id ); ?>" name="exempt_users[]" value="<?php echo esc_attr( $user->ID ); ?>" <?php checked( in_array( (int) $user->ID, $exempt, true ) ); ?>>
						</th>
						<td><label for="<?php echo esc_attr( $field_id ); ?>"><strong><?php echo esc_html( $user->user_login ); ?></strong></label></td>
						<td><?php echo esc_html( $user->display_name ); ?></td>
						<td><?php echo esc_html( implode( ', ', $user->roles ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_plugins( $settings ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$hidden = Plugin::hidden_plugins();
		$self   = Plugin::own_basename();
		?>
		<p>Označeni vtičniki so skriti s seznama Vtičniki za vse uporabnike razen <code><?php echo esc_html( Plugin::OWNER ); ?></code>. Še naprej delujejo in se posodabljajo.</p>
		<?php self::render_default_note( 'hidden_plugins' ); ?>
		<table class="widefat striped" style="max-width:760px">
			<thead>
				<tr>
					<td class="check-column"></td>
					<th>Vtičnik</th>
					<th>Različica</th>
					<th>Stanje</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( get_plugins() as $file => $data ) : ?>
					<?php
					$is_self  = $file === $self;
					$field_id = 'splet99-plugin-' . md5( $file );
					?>
					<tr>
						<th scope="row" class="check-column">
							<input type="checkbox" id="<?php echo esc_attr( $field_id ); ?>" name="hidden_plugins[]" value="<?php echo esc_attr( $file ); ?>" <?php checked( $is_self || in_array( $file, $hidden, true ) ); ?> <?php disabled( $is_self ); ?>>
						</th>
						<td>
							<label for="<?php echo esc_attr( $field_id ); ?>"><strong><?php echo esc_html( $data['Name'] ); ?></strong></label>
							<?php if ( $is_self ) : ?>
								<br><em>vedno skrit</em>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $data['Version'] ); ?></td>
						<td><?php echo is_plugin_active( $file ) ? 'aktiven' : 'neaktiven'; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_default_note( $key ) {
		if ( Plugin::is_saved( $key ) ) {
			return;
		}
		?>
		<p><em>Privzeto so označeni elementi, ki vsebujejo: <code><?php echo esc_html( implode( ', ', Plugin::DEFAULT_HIDDEN ) ); ?></code>. Po prvem shranjevanju velja tvoja izbira.</em></p>
		<?php
	}

	private static function menu_title( $title ) {
		// Drop counter bubbles such as "Komentarji <span class="awaiting-mod">3</span>".
		$title = preg_replace( '#<span[^>]*>.*?</span>#s', '', (string) $title );

		return trim( wp_strip_all_tags( $title ) );
	}

	private static function render_menu( $settings ) {
		global $menu, $submenu;

		$hidden      = (array) $settings['hidden_menu'];
		$use_default = ! Plugin::is_saved( 'hidden_menu' );
		?>
		<p>Označene postavke levega menija so skrite za vse uporabnike razen <code><?php echo esc_html( Plugin::OWNER ); ?></code>. Skrije se le povezava v meniju, stran ostane dosegljiva z neposrednim naslovom.</p>
		<?php self::render_default_note( 'hidden_menu' ); ?>
		<ul style="max-width:760px">
			<?php foreach ( (array) $menu as $item ) : ?>
				<?php
				if ( empty( $item[2] ) || ( isset( $item[4] ) && false !== strpos( $item[4], 'wp-menu-separator' ) ) ) {
					continue;
				}

				$slug  = $item[2];
				$title = self::menu_title( $item[0] );
				?>
				<li style="margin:10px 0 4px">
					<label>
						<input type="checkbox" name="hidden_menu[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $hidden, true ) || ( $use_default && Plugin::matches_default( $slug . ' ' . $title ) ) ); ?>>
						<strong><?php echo esc_html( $title ? $title : $slug ); ?></strong>
					</label>

					<?php if ( ! empty( $submenu[ $slug ] ) ) : ?>
						<ul style="margin:4px 0 0 28px">
							<?php foreach ( $submenu[ $slug ] as $sub ) : ?>
								<?php
								if ( empty( $sub[2] ) ) {
									continue;
								}

								$value     = $slug . Plugin::MENU_SEPARATOR . $sub[2];
								$sub_title = self::menu_title( $sub[0] );
								?>
								<li style="margin:2px 0">
									<label>
										<input type="checkbox" name="hidden_menu[]" value="<?php echo esc_attr( $value ); ?>" <?php checked( in_array( $value, $hidden, true ) ); ?>>
										<?php echo esc_html( $sub_title ? $sub_title : $sub[2] ); ?>
									</label>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	private static function render_woo( $settings ) {
		$features = (array) $settings['woo_features'];
		?>
		<p>Dodatne funkcije za trgovino. Vse so privzeto izklopljene.</p>

		<?php if ( Lowest_Price::snippet_active() ) : ?>
			<div class="notice notice-warning inline"><p>V <code>functions.php</code> je že snippet za najnižjo ceno (<code>s99_prior_price_incl_tax</code>). Dokler je tam, ta funkcija vtičnika miruje, da se cena ne izpiše dvakrat. Zgodovina cen je skupna, zato se ob odstranitvi snippeta nič ne izgubi.</p></div>
		<?php endif; ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Najnižja cena v 30 dneh</th>
				<td>
					<label>
						<input type="checkbox" name="woo_features[]" value="<?php echo esc_attr( Lowest_Price::FEATURE ); ?>" <?php checked( in_array( Lowest_Price::FEATURE, $features, true ) ); ?>>
						Pri znižanih izdelkih prikaži najnižjo ceno v zadnjih 30 dneh pred znižanjem (ZVPot-1, 15. člen)
					</label>
					<p class="description">
						Samo v slovenskem jeziku. Prečrtana cena postane najnižja cena pred znižanjem, na strani izdelka se pod ceno izpiše
						&raquo;<?php echo esc_html( Lowest_Price::TEXT ); ?> …&laquo;.<br>
						Spremembe cen se beležijo od vklopa naprej (zgodovina 120 dni). Za izdelke brez zgodovine se uporabi redna cena.
					</p>
				</td>
			</tr>
		</table>
		<?php
	}
}
