<?php
namespace Splet99\Woo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {

	const TABS = array(
		'woo'           => 'WooCommerce',
		'notifications' => 'Hide notifications',
		'plugins'       => 'Hide plugins',
		'menu'          => 'Hide menu items',
	);

	const TAB_FIELDS = array(
		'notifications' => 'exempt_users',
		'plugins'       => 'hidden_plugins',
		'menu'          => 'hidden_menu',
		'woo'           => 'woo_features',
	);

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_filter( 'plugin_action_links_' . Plugin::own_basename(), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_post_' . Plugin::OPTION, array( __CLASS__, 'save' ) );
	}

	// "Nastavitve" link in the plugin's row on the Plugins page (the row is visible to the owner only).
	public static function action_links( $links ) {
		$links = (array) $links;

		if ( Plugin::is_owner() ) {
			array_unshift( $links, '<a href="' . esc_url( self::tab_url( 'woo' ) ) . '">Nastavitve</a>' );
		}

		return $links;
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

		return array_key_exists( $tab, self::TABS ) ? $tab : 'woo';
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

			// Every feature shown on the form is now an explicit choice, so its default no longer applies.
			if ( 'woo_features' === $field ) {
				$stored['woo_decided'] = array_keys( Plugin::WOO_FEATURE_DEFAULTS );

				// Languages are stored as the unticked ones, so a language added to the shop later is on.
				if ( I18n::is_multilingual() ) {
					$posted = isset( $_POST['woo_lang_on'] ) ? (array) wp_unslash( $_POST['woo_lang_on'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					$active = array_keys( I18n::active_languages() );
					$off    = isset( $stored['woo_lang_off'] ) ? (array) $stored['woo_lang_off'] : array();

					foreach ( array( Lowest_Price::FEATURE, Odstop::FEATURE ) as $feature ) {
						$on              = isset( $posted[ $feature ] ) ? array_map( 'strtolower', array_map( 'sanitize_text_field', (array) $posted[ $feature ] ) ) : array();
						$off[ $feature ] = array_values( array_diff( $active, $on ) );
					}

					$stored['woo_lang_off'] = $off;
				}

				// Carts keep their calculated shipping rates until the shipping cache version changes.
				if ( class_exists( '\\WC_Cache_Helper' ) ) {
					\WC_Cache_Helper::get_transient_version( 'shipping', true );
				}
			}

			$saved_tabs           = isset( $stored['saved_tabs'] ) ? (array) $stored['saved_tabs'] : array();
			$stored['saved_tabs'] = array_values( array_unique( array_merge( $saved_tabs, array( $field ) ) ) );

			update_option( Plugin::OPTION, $stored );
		}

		wp_safe_redirect( self::tab_url( $tab ? $tab : 'woo', array( 'updated' => 1 ) ) );
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
				} elseif ( 'notifications' === $tab ) {
					self::render_notifications( $settings );
				} else {
					self::render_woo( $settings );
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
		<?php if ( Plugin::ghost_mode_on() ) : ?>
			<p><em>Privzeto ni označeno nič: Novamiro pred ostalimi administratorji skriva njen Ghost Mode. Po prvem shranjevanju velja tvoja izbira.</em></p>
		<?php else : ?>
			<p><em>Privzeto so označeni elementi, ki vsebujejo: <code><?php echo esc_html( implode( ', ', Plugin::DEFAULT_HIDDEN ) ); ?></code>. Po prvem shranjevanju velja tvoja izbira.</em></p>
		<?php endif; ?>
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
		$lowest_on   = Plugin::feature_enabled( Lowest_Price::FEATURE );
		$free_on     = Plugin::feature_enabled( Free_Shipping::FEATURE );
		$odstop_on   = Plugin::feature_enabled( Odstop::FEATURE );
		$standalone  = Odstop::standalone_active() && ! Odstop::replaced_now();
		$replaced    = get_option( Odstop::REPLACED_OPT );
		$left_file   = is_array( $replaced ) && ! empty( $replaced['file'] ) ? $replaced['file'] : Odstop::STANDALONE_FILE;
		$left_files  = file_exists( WP_PLUGIN_DIR . '/' . $left_file );
		?>
		<p>Dodatne funkcije za trgovino. Privzeto sta vklopljena odstop od pogodbe in samo brezplačna dostava.</p>

		<?php if ( Lowest_Price::snippet_active() ) : ?>
			<div class="notice notice-warning inline"><p>V <code>functions.php</code> je že snippet za najnižjo ceno (<code>s99_prior_price_incl_tax</code>). Dokler je tam, ta funkcija vtičnika miruje, da se cena ne izpiše dvakrat. Zgodovina cen je skupna, zato se ob odstranitvi snippeta nič ne izgubi.</p></div>
		<?php endif; ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Najnižja cena v 30 dneh</th>
				<td>
					<label>
						<input type="checkbox" name="woo_features[]" value="<?php echo esc_attr( Lowest_Price::FEATURE ); ?>" <?php checked( $lowest_on ); ?>>
						Pri znižanih izdelkih prikaži najnižjo ceno v zadnjih 30 dneh pred znižanjem (ZVPot-1, 15. člen)
					</label>
					<?php self::render_langs( Lowest_Price::FEATURE ); ?>
					<p class="description">
						Prečrtana cena postane najnižja cena pred znižanjem, na strani izdelka se pod ceno izpiše
						&raquo;<?php echo esc_html( Lowest_Price::TEXT ); ?> …&laquo; (v jeziku obiskovalca).<br>
						Spremembe cen se beležijo od vklopa naprej (zgodovina 120 dni). Za izdelke brez zgodovine se uporabi redna cena.
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Odstop od pogodbe</th>
				<td>
					<?php if ( $standalone && $odstop_on ) : ?>
						<div class="notice notice-warning inline"><p>Samostojni vtičnik <strong>Splet99 Odstop od pogodbe</strong> je še aktiven, ker ga ni bilo mogoče samodejno zamenjati (večstranska namestitev, druga različica ali ga nalaga nekaj drugega). Dokler je aktiven, ta funkcija miruje. Deaktiviraj ga ročno; nastavitve, stran in oddani odstopi ostanejo.</p></div>
					<?php elseif ( $standalone ) : ?>
						<div class="notice notice-warning inline"><p>Aktiven je samostojni vtičnik <strong>Splet99 Odstop od pogodbe</strong>, ta funkcija pa je izklopljena. Ko jo vklopiš, se samostojni vtičnik samodejno deaktivira in izbriše; nastavitve, stran in vsi oddani odstopi ostanejo.</p></div>
					<?php elseif ( ! $odstop_on && 'publish' === get_post_status( (int) get_option( 's99od_page_id' ) ) ) : ?>
						<div class="notice notice-error inline"><p>Stran za odstop od pogodbe obstaja, a funkcija ni vklopljena: obrazec na strani in povezave v Mojem računu ter e-pošti ne delujejo. Vklopi funkcijo ali stran umakni.</p></div>
					<?php endif; ?>
					<?php if ( is_array( $replaced ) && isset( $replaced['time'] ) ) : ?>
						<div class="notice notice-info inline"><p>
							Samostojni vtičnik Splet99 Odstop od pogodbe <?php echo esc_html( isset( $replaced['version'] ) ? $replaced['version'] : '' ); ?> je bil
							<?php echo esc_html( wp_date( 'j. n. Y H:i', (int) $replaced['time'] ) ); ?> samodejno zamenjan s to funkcijo<?php
							if ( ! empty( $replaced['deleted'] ) ) {
								echo ' in izbrisan.';
							} elseif ( ! $left_files ) {
								echo ' in deaktiviran.';
							} elseif ( ! empty( $replaced['kept'] ) ) {
								echo ' in deaktiviran. Datoteke so namenoma ostale, ker ima vtičnik rutino za odstranitev, ki bi lahko pobrisala nastavitve in evidenco odstopov. Mapo <code>' . esc_html( dirname( $left_file ) ) . '</code> odstrani prek FTP ali upravitelja datotek, <strong>ne</strong> z Vtičniki → Izbriši.';
							} else {
								echo ' in deaktiviran. Datotek ni bilo mogoče izbrisati, zato ga izbriši ročno med vtičniki.';
							}
							?>
						</p></div>
					<?php endif; ?>
					<label>
						<input type="checkbox" name="woo_features[]" value="<?php echo esc_attr( Odstop::FEATURE ); ?>" <?php checked( $odstop_on ); ?>>
						Funkcija za odstop od pogodbe na daljavo (ZVPot-1A, 138.a člen)
					</label>
					<?php self::render_langs( Odstop::FEATURE ); ?>
					<p class="description">
						Stran z obrazcem (ustvari se sama ali prevzame obstoječo), popup, potrdilo kupcu po e-pošti, evidenca v meniju <em>Odstopi od pogodbe</em>, povezava v Mojem računu in v e-pošti o naročilu.
						<?php if ( $odstop_on && ! $standalone ) : ?>
							<br>Nastavitve: <a href="<?php echo esc_url( admin_url( 'options-general.php?page=s99od' ) ); ?>">Nastavitve → Odstop od pogodbe</a> · stran: <a href="<?php echo esc_url( Odstop::form_page_url() ); ?>" target="_blank"><?php echo esc_html( Odstop::form_page_url() ); ?></a>
						<?php endif; ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Samo brezplačna dostava</th>
				<td>
					<label>
						<input type="checkbox" name="woo_features[]" value="<?php echo esc_attr( Free_Shipping::FEATURE ); ?>" <?php checked( $free_on ); ?>>
						Ko je na voljo brezplačna dostava, skrij vse ostale načine dostave
					</label>
					<p class="description">Velja za način dostave &raquo;Brezplačna dostava&laquo; (free_shipping) v WooCommerce. Skrije tudi osebni prevzem, če je nastavljen kot način dostave.</p>
				</td>
			</tr>
		</table>
		<?php
	}

	// Language checkboxes under a feature (multilingual shops only).
	private static function render_langs( $feature ) {
		if ( ! I18n::is_multilingual() ) {
			return;
		}
		?>
		<p style="margin:8px 0 0 24px">
			Jeziki:
			<?php foreach ( I18n::active_languages() as $code => $name ) : ?>
				<label style="margin-right:12px">
					<input type="checkbox" name="woo_lang_on[<?php echo esc_attr( $feature ); ?>][]" value="<?php echo esc_attr( $code ); ?>" <?php checked( I18n::lang_enabled( $feature, $code ) ); ?>>
					<?php echo esc_html( $name ); ?> <code><?php echo esc_html( $code ); ?></code>
				</label>
			<?php endforeach; ?>
		</p>
		<?php
	}
}
