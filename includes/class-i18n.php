<?php
namespace Splet99\Woo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Languages of the shop (WPML / Polylang / site locale) and built-in translations of the customer-facing texts.
// Texts are keyed by their Slovenian original, so the code keeps plain __( '…', 's99-odstop' ) calls.
final class I18n {

	const FALLBACK = 'en';

	public static function init() {
		add_filter( 'gettext_s99-odstop', array( __CLASS__, 'gettext' ), 10, 2 );
	}

	// Language forced for a moment (e.g. the shop's own notification e-mail), '' = none.
	private static $override = '';

	public static function set_override( $code ) {
		self::$override = (string) $code;
	}

	// Code of the language the visitor (or a WPML-switched e-mail) is in, exactly as WPML / Polylang name it
	// (e.g. "si", "pt-br"). Use lang_key() for the two-letter dictionary key.
	public static function current_code() {
		if ( '' !== self::$override ) {
			return self::$override;
		}

		if ( function_exists( 'pll_current_language' ) ) {
			$lang = pll_current_language( 'slug' );

			if ( $lang ) {
				return strtolower( $lang );
			}
		}

		$wpml = apply_filters( 'wpml_current_language', null );

		if ( is_string( $wpml ) && '' !== $wpml && 'all' !== $wpml ) {
			return strtolower( $wpml );
		}

		return self::single_lang();
	}

	public static function default_code() {
		if ( function_exists( 'pll_default_language' ) ) {
			$lang = pll_default_language( 'slug' );

			if ( $lang ) {
				return strtolower( $lang );
			}
		}

		$wpml = apply_filters( 'wpml_default_language', null );

		return is_string( $wpml ) && '' !== $wpml ? strtolower( $wpml ) : self::single_lang();
	}

	// Without WPML / Polylang: Slovenian for a shop based in Slovenia (even on an English admin locale),
	// otherwise the site language.
	private static function single_lang() {
		if ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) && is_object( WC()->countries )
			&& 'SI' === WC()->countries->get_base_country() ) {
			return 'sl';
		}

		return self::normalize( get_locale() );
	}

	// Code => name of every language the shop runs in (codes as WPML / Polylang name them).
	public static function active_languages() {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$out = array();

		if ( function_exists( 'pll_languages_list' ) ) {
			$slugs = (array) pll_languages_list( array( 'fields' => 'slug' ) );
			$names = (array) pll_languages_list( array( 'fields' => 'name' ) );

			foreach ( $slugs as $i => $slug ) {
				$out[ strtolower( $slug ) ] = isset( $names[ $i ] ) ? $names[ $i ] : $slug;
			}
		} elseif ( isset( $GLOBALS['sitepress'] ) && is_object( $GLOBALS['sitepress'] ) && method_exists( $GLOBALS['sitepress'], 'get_active_languages' ) ) {
			// Straight from WPML's settings; the wpml_active_languages filter builds language-switcher URLs.
			foreach ( (array) $GLOBALS['sitepress']->get_active_languages() as $code => $lang ) {
				$name                       = is_array( $lang ) && ! empty( $lang['display_name'] ) ? $lang['display_name'] : $code;
				$out[ strtolower( $code ) ] = $name;
			}
		}

		if ( ! $out ) {
			$code         = self::default_code();
			$out[ $code ] = $code;
		}

		if ( did_action( 'plugins_loaded' ) ) {
			$cache = $out;
		}

		return $out;
	}

	public static function is_multilingual() {
		return count( self::active_languages() ) > 1;
	}

	// Two-letter dictionary key: "sl_SI", "sl-si", "si" → "sl"; "pt-br" → "pt".
	public static function normalize( $code ) {
		$code = strtolower( substr( (string) $code, 0, 2 ) );

		return 'si' === $code ? 'sl' : $code;
	}

	public static function lang_key( $code = '' ) {
		return self::normalize( '' !== $code ? $code : self::current_code() );
	}

	// Feature switched on for this language? Languages unticked on the WooCommerce tab are stored per feature;
	// a language added to the shop later is on. Single-language shops: always on.
	public static function lang_enabled( $feature, $code = '' ) {
		if ( ! self::is_multilingual() ) {
			return true;
		}

		$code = strtolower( '' !== $code ? $code : self::current_code() );

		return ! in_array( $code, self::langs_off( $feature ), true );
	}

	public static function langs_off( $feature ) {
		$stored = get_option( Plugin::OPTION, array() );

		if ( is_array( $stored ) && isset( $stored['woo_lang_off'][ $feature ] ) ) {
			return array_map( 'strtolower', array_map( 'strval', (array) $stored['woo_lang_off'][ $feature ] ) );
		}

		// Withdrawal, until the tab is saved: languages listed in the standalone plugin's "langs" field.
		// An empty field means all languages (the law applies to every language version of the shop).
		if ( 'odstop' === $feature ) {
			$opts = get_option( 's99od_opts', array() );

			if ( is_array( $opts ) && ! empty( $opts['langs'] ) ) {
				$on  = array_map( array( __CLASS__, 'normalize' ), array_filter( array_map( 'trim', explode( ',', (string) $opts['langs'] ) ) ) );
				$off = array();

				foreach ( array_keys( self::active_languages() ) as $code ) {
					if ( ! in_array( self::normalize( $code ), $on, true ) ) {
						$off[] = $code;
					}
				}

				return $off;
			}
		}

		return array();
	}

	public static function text( $sl, $code = '' ) {
		$lang = self::lang_key( $code );

		if ( 'sl' === $lang ) {
			return $sl;
		}

		$dict = self::dictionary();

		if ( ! isset( $dict[ $sl ] ) ) {
			return $sl;
		}

		if ( isset( $dict[ $sl ][ $lang ] ) ) {
			return $dict[ $sl ][ $lang ];
		}

		return isset( $dict[ $sl ][ self::FALLBACK ] ) ? $dict[ $sl ][ self::FALLBACK ] : $sl;
	}

	public static function gettext( $translation, $text ) {
		return $translation === $text ? self::text( $text ) : $translation;
	}

	// Slovenian original => translations (sl, hr, bs, sr, en, de, it, es; other languages get English).
	private static function dictionary() {
		static $dict = null;

		if ( null !== $dict ) {
			return $dict;
		}

		$dict = array(
			'Najnižja cena v zadnjih 30 dneh pred znižanjem:' => array(
				'hr' => 'Najniža cijena u posljednjih 30 dana prije sniženja:',
				'bs' => 'Najniža cijena u posljednjih 30 dana prije sniženja:',
				'sr' => 'Najniža cena u poslednjih 30 dana pre sniženja:',
				'en' => 'Lowest price in the 30 days before the discount:',
				'de' => 'Niedrigster Preis der letzten 30 Tage vor der Preisermäßigung:',
				'it' => 'Prezzo più basso nei 30 giorni precedenti lo sconto:',
				'es' => 'Precio más bajo en los 30 días anteriores a la rebaja:',
			),
			'Odstop od pogodbe' => array(
				'hr' => 'Odustanak od ugovora',
				'bs' => 'Odustanak od ugovora',
				'sr' => 'Odustanak od ugovora',
				'en' => 'Withdrawal from the contract',
				'de' => 'Widerruf des Vertrags',
				'it' => 'Recesso dal contratto',
				'es' => 'Desistimiento del contrato',
			),
			'Za odstop od pogodbe izpolnite obrazec in ga potrdite. Po oddaji prejmete potrdilo o prejemu na navedeni e-poštni naslov.' => array(
				'hr' => 'Za odustanak od ugovora ispunite obrazac i potvrdite ga. Nakon slanja primit ćete potvrdu o primitku na navedenu e-adresu.',
				'bs' => 'Za odustanak od ugovora popunite obrazac i potvrdite ga. Nakon slanja dobit ćete potvrdu o prijemu na navedenu e-mail adresu.',
				'sr' => 'Za odustanak od ugovora popunite obrazac i potvrdite ga. Nakon slanja dobićete potvrdu o prijemu na navedenu imejl adresu.',
				'en' => 'To withdraw from the contract, fill in and confirm the form. After submitting it you will receive a confirmation of receipt at the e-mail address you entered.',
				'de' => 'Um den Vertrag zu widerrufen, füllen Sie das Formular aus und bestätigen Sie es. Nach dem Absenden erhalten Sie eine Eingangsbestätigung an die angegebene E-Mail-Adresse.',
				'it' => 'Per recedere dal contratto compila il modulo e confermalo. Dopo l’invio riceverai una conferma di ricezione all’indirizzo e-mail indicato.',
				'es' => 'Para desistir del contrato, rellene el formulario y confírmelo. Tras el envío recibirá un acuse de recibo en la dirección de correo electrónico indicada.',
			),
			'Ime in priimek' => array(
				'hr' => 'Ime i prezime',
				'bs' => 'Ime i prezime',
				'sr' => 'Ime i prezime',
				'en' => 'Full name',
				'de' => 'Vor- und Nachname',
				'it' => 'Nome e cognome',
				'es' => 'Nombre y apellidos',
			),
			'E-pošta (na ta naslov prejmete potrdilo)' => array(
				'hr' => 'E-pošta (na ovu adresu primit ćete potvrdu)',
				'bs' => 'E-mail (na ovu adresu dobit ćete potvrdu)',
				'sr' => 'Imejl (na ovu adresu dobićete potvrdu)',
				'en' => 'E-mail (the confirmation will be sent here)',
				'de' => 'E-Mail (an diese Adresse senden wir die Bestätigung)',
				'it' => 'E-mail (riceverai la conferma a questo indirizzo)',
				'es' => 'Correo electrónico (aquí recibirá el acuse de recibo)',
			),
			'Številka naročila / referenca pogodbe' => array(
				'hr' => 'Broj narudžbe / referenca ugovora',
				'bs' => 'Broj narudžbe / referenca ugovora',
				'sr' => 'Broj porudžbine / referenca ugovora',
				'en' => 'Order number / contract reference',
				'de' => 'Bestellnummer / Vertragsreferenz',
				'it' => 'Numero d’ordine / riferimento del contratto',
				'es' => 'Número de pedido / referencia del contrato',
			),
			'Komentar (neobvezno)' => array(
				'hr' => 'Komentar (neobavezno)',
				'bs' => 'Komentar (neobavezno)',
				'sr' => 'Komentar (neobavezno)',
				'en' => 'Comment (optional)',
				'de' => 'Kommentar (optional)',
				'it' => 'Commento (facoltativo)',
				'es' => 'Comentario (opcional)',
			),
			'Če odstopate samo od določenih artiklov, jih navedite v komentarju. Brez navedbe velja odstop za celotno naročilo.' => array(
				'hr' => 'Ako odustajete samo od određenih artikala, navedite ih u komentaru. Ako ih ne navedete, odustanak vrijedi za cijelu narudžbu.',
				'bs' => 'Ako odustajete samo od određenih artikala, navedite ih u komentaru. Bez navoda odustanak važi za cijelu narudžbu.',
				'sr' => 'Ako odustajete samo od određenih artikala, navedite ih u komentaru. Bez navoda odustanak važi za celu porudžbinu.',
				'en' => 'If you are withdrawing only from certain items, list them in the comment. Otherwise the withdrawal applies to the whole order.',
				'de' => 'Wenn Sie nur einzelne Artikel widerrufen, nennen Sie diese im Kommentar. Ohne Angabe gilt der Widerruf für die gesamte Bestellung.',
				'it' => 'Se recedi solo da alcuni articoli, indicali nel commento. Altrimenti il recesso vale per l’intero ordine.',
				'es' => 'Si solo desiste de algunos artículos, indíquelos en el comentario. Si no lo indica, el desistimiento se aplica a todo el pedido.',
			),
			'Potrdi odstop od pogodbe' => array(
				'hr' => 'Potvrdi odustanak od ugovora',
				'bs' => 'Potvrdi odustanak od ugovora',
				'sr' => 'Potvrdi odustanak od ugovora',
				'en' => 'Confirm withdrawal from the contract',
				'de' => 'Widerruf bestätigen',
				'it' => 'Conferma il recesso dal contratto',
				'es' => 'Confirmar el desistimiento del contrato',
			),
			'Izpolnite vsa obvezna polja.' => array(
				'hr' => 'Ispunite sva obavezna polja.',
				'bs' => 'Popunite sva obavezna polja.',
				'sr' => 'Popunite sva obavezna polja.',
				'en' => 'Please fill in all required fields.',
				'de' => 'Bitte füllen Sie alle Pflichtfelder aus.',
				'it' => 'Compila tutti i campi obbligatori.',
				'es' => 'Rellene todos los campos obligatorios.',
			),
			'Vpišite veljaven e-poštni naslov.' => array(
				'hr' => 'Upišite valjanu e-adresu.',
				'bs' => 'Unesite ispravnu e-mail adresu.',
				'sr' => 'Unesite ispravnu imejl adresu.',
				'en' => 'Please enter a valid e-mail address.',
				'de' => 'Bitte geben Sie eine gültige E-Mail-Adresse ein.',
				'it' => 'Inserisci un indirizzo e-mail valido.',
				'es' => 'Introduzca una dirección de correo electrónico válida.',
			),
			'Preveč poskusov. Poskusite znova čez nekaj časa.' => array(
				'hr' => 'Previše pokušaja. Pokušajte ponovno kasnije.',
				'bs' => 'Previše pokušaja. Pokušajte ponovo za neko vrijeme.',
				'sr' => 'Previše pokušaja. Pokušajte ponovo za neko vreme.',
				'en' => 'Too many attempts. Please try again later.',
				'de' => 'Zu viele Versuche. Bitte versuchen Sie es später erneut.',
				'it' => 'Troppi tentativi. Riprova più tardi.',
				'es' => 'Demasiados intentos. Vuelva a intentarlo más tarde.',
			),
			'To naročilo ne obstaja. Preverite številko naročila in e-naslov, ki ste ga uporabili pri nakupu.' => array(
				'hr' => 'Ova narudžba ne postoji. Provjerite broj narudžbe i e-adresu koju ste koristili pri kupnji.',
				'bs' => 'Ova narudžba ne postoji. Provjerite broj narudžbe i e-mail adresu koju ste koristili pri kupovini.',
				'sr' => 'Ova porudžbina ne postoji. Proverite broj porudžbine i imejl adresu koju ste koristili pri kupovini.',
				'en' => 'This order does not exist. Please check the order number and the e-mail address you used for the purchase.',
				'de' => 'Diese Bestellung existiert nicht. Bitte prüfen Sie die Bestellnummer und die beim Kauf verwendete E-Mail-Adresse.',
				'it' => 'Questo ordine non esiste. Verifica il numero d’ordine e l’indirizzo e-mail usato per l’acquisto.',
				'es' => 'Este pedido no existe. Compruebe el número de pedido y el correo electrónico que utilizó en la compra.',
			),
			'Obvestila ni bilo mogoče shraniti. Poskusite znova.' => array(
				'hr' => 'Obavijest nije bilo moguće spremiti. Pokušajte ponovno.',
				'bs' => 'Obavještenje nije bilo moguće sačuvati. Pokušajte ponovo.',
				'sr' => 'Obaveštenje nije bilo moguće sačuvati. Pokušajte ponovo.',
				'en' => 'The notice could not be saved. Please try again.',
				'de' => 'Die Mitteilung konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.',
				'it' => 'Non è stato possibile salvare la comunicazione. Riprova.',
				'es' => 'No se ha podido guardar la notificación. Vuelva a intentarlo.',
			),
			'Obvestilo o odstopu od pogodbe je oddano.' => array(
				'hr' => 'Obavijest o odustanku od ugovora je poslana.',
				'bs' => 'Obavještenje o odustanku od ugovora je poslano.',
				'sr' => 'Obaveštenje o odustanku od ugovora je poslato.',
				'en' => 'Your notice of withdrawal from the contract has been submitted.',
				'de' => 'Ihre Widerrufserklärung wurde übermittelt.',
				'it' => 'La comunicazione di recesso dal contratto è stata inviata.',
				'es' => 'Su notificación de desistimiento del contrato se ha enviado.',
			),
			'Obvestila ni bilo mogoče samodejno potrditi. Za potrdilo nas prosimo kontaktirajte na %s.' => array(
				'hr' => 'Obavijest nije bilo moguće automatski potvrditi. Za potvrdu nas kontaktirajte na %s.',
				'bs' => 'Obavještenje nije bilo moguće automatski potvrditi. Za potvrdu nas kontaktirajte na %s.',
				'sr' => 'Obaveštenje nije bilo moguće automatski potvrditi. Za potvrdu nas kontaktirajte na %s.',
				'en' => 'The notice could not be confirmed automatically. Please contact us at %s for a confirmation.',
				'de' => 'Die Mitteilung konnte nicht automatisch bestätigt werden. Für eine Bestätigung kontaktieren Sie uns bitte unter %s.',
				'it' => 'Non è stato possibile confermare automaticamente la comunicazione. Per una conferma contattaci a %s.',
				'es' => 'No se ha podido confirmar la notificación automáticamente. Para obtener una confirmación, contáctenos en %s.',
			),
			'Datum in čas oddaje' => array(
				'hr' => 'Datum i vrijeme slanja',
				'bs' => 'Datum i vrijeme slanja',
				'sr' => 'Datum i vreme slanja',
				'en' => 'Date and time submitted',
				'de' => 'Datum und Uhrzeit der Übermittlung',
				'it' => 'Data e ora di invio',
				'es' => 'Fecha y hora de envío',
			),
			'Potrdilo o prejemu smo poslali na %s.' => array(
				'hr' => 'Potvrdu o primitku poslali smo na %s.',
				'bs' => 'Potvrdu o prijemu poslali smo na %s.',
				'sr' => 'Potvrdu o prijemu poslali smo na %s.',
				'en' => 'We have sent a confirmation of receipt to %s.',
				'de' => 'Wir haben eine Eingangsbestätigung an %s gesendet.',
				'it' => 'Abbiamo inviato una conferma di ricezione a %s.',
				'es' => 'Hemos enviado un acuse de recibo a %s.',
			),
			'Potrdila po e-pošti nam ni uspelo poslati. Shranite ali natisnite to stran kot dokazilo o oddaji.' => array(
				'hr' => 'Potvrdu e-poštom nismo uspjeli poslati. Spremite ili ispišite ovu stranicu kao dokaz o slanju.',
				'bs' => 'Potvrdu e-mailom nismo uspjeli poslati. Sačuvajte ili odštampajte ovu stranicu kao dokaz o slanju.',
				'sr' => 'Potvrdu imejlom nismo uspeli da pošaljemo. Sačuvajte ili odštampajte ovu stranicu kao dokaz o slanju.',
				'en' => 'We could not send the confirmation by e-mail. Please save or print this page as proof of submission.',
				'de' => 'Die Bestätigung konnte nicht per E-Mail gesendet werden. Bitte speichern oder drucken Sie diese Seite als Nachweis.',
				'it' => 'Non è stato possibile inviare la conferma via e-mail. Salva o stampa questa pagina come prova dell’invio.',
				'es' => 'No hemos podido enviar la confirmación por correo electrónico. Guarde o imprima esta página como prueba del envío.',
			),
			'E-pošta' => array(
				'hr' => 'E-pošta',
				'bs' => 'E-mail',
				'sr' => 'Imejl',
				'en' => 'E-mail',
				'de' => 'E-Mail',
				'it' => 'E-mail',
				'es' => 'Correo electrónico',
			),
			'Komentar' => array(
				'hr' => 'Komentar',
				'bs' => 'Komentar',
				'sr' => 'Komentar',
				'en' => 'Comment',
				'de' => 'Kommentar',
				'it' => 'Commento',
				'es' => 'Comentario',
			),
			'Potrdilo o prejemu obvestila o odstopu od pogodbe – %s' => array(
				'hr' => 'Potvrda o primitku obavijesti o odustanku od ugovora – %s',
				'bs' => 'Potvrda o prijemu obavještenja o odustanku od ugovora – %s',
				'sr' => 'Potvrda o prijemu obaveštenja o odustanku od ugovora – %s',
				'en' => 'Confirmation of receipt of your notice of withdrawal – %s',
				'de' => 'Eingangsbestätigung Ihres Widerrufs – %s',
				'it' => 'Conferma di ricezione della comunicazione di recesso – %s',
				'es' => 'Acuse de recibo de su notificación de desistimiento – %s',
			),
			'Potrdilo o prejemu obvestila o odstopu od pogodbe' => array(
				'hr' => 'Potvrda o primitku obavijesti o odustanku od ugovora',
				'bs' => 'Potvrda o prijemu obavještenja o odustanku od ugovora',
				'sr' => 'Potvrda o prijemu obaveštenja o odustanku od ugovora',
				'en' => 'Confirmation of receipt of your notice of withdrawal',
				'de' => 'Eingangsbestätigung Ihres Widerrufs',
				'it' => 'Conferma di ricezione della comunicazione di recesso',
				'es' => 'Acuse de recibo de su notificación de desistimiento',
			),
			'Prejeli smo vaše obvestilo o odstopu od pogodbe. Spodaj je vsebina obvestila z datumom in uro oddaje.' => array(
				'hr' => 'Primili smo vašu obavijest o odustanku od ugovora. U nastavku je sadržaj obavijesti s datumom i vremenom slanja.',
				'bs' => 'Primili smo vaše obavještenje o odustanku od ugovora. U nastavku je sadržaj obavještenja s datumom i vremenom slanja.',
				'sr' => 'Primili smo vaše obaveštenje o odustanku od ugovora. U nastavku je sadržaj obaveštenja sa datumom i vremenom slanja.',
				'en' => 'We have received your notice of withdrawal from the contract. Its content, with the date and time of submission, is below.',
				'de' => 'Wir haben Ihre Widerrufserklärung erhalten. Unten finden Sie deren Inhalt mit Datum und Uhrzeit der Übermittlung.',
				'it' => 'Abbiamo ricevuto la tua comunicazione di recesso dal contratto. Di seguito il contenuto con data e ora di invio.',
				'es' => 'Hemos recibido su notificación de desistimiento del contrato. A continuación figura su contenido con la fecha y hora de envío.',
			),
			'Zapri' => array(
				'hr' => 'Zatvori',
				'bs' => 'Zatvori',
				'sr' => 'Zatvori',
				'en' => 'Close',
				'de' => 'Schließen',
				'it' => 'Chiudi',
				'es' => 'Cerrar',
			),
			// Date format of the submission time (PHP date characters; backslash-escaped letters print as text).
			'j. n. Y \o\b H:i:s' => array(
				'hr' => 'j. n. Y. \u H:i:s',
				'bs' => 'j. n. Y. \u H:i:s',
				'sr' => 'j. n. Y. \u H:i:s',
				'en' => 'd/m/Y, H:i:s',
				'de' => 'j.n.Y, H:i:s',
				'it' => 'j/n/Y, H:i:s',
				'es' => 'j/n/Y, H:i:s',
			),
		);

		return $dict;
	}

	// Page title and slug of the withdrawal page per language.
	public static function page_slug( $code ) {
		$slugs = array(
			'sl' => 'odstop-od-pogodbe',
			'hr' => 'odustanak-od-ugovora',
			'bs' => 'odustanak-od-ugovora',
			'sr' => 'odustanak-od-ugovora',
			'en' => 'withdrawal-from-contract',
			'de' => 'widerruf',
			'it' => 'recesso',
			'es' => 'desistimiento',
		);

		$lang = self::normalize( $code );

		return isset( $slugs[ $lang ] ) ? $slugs[ $lang ] : $slugs[ self::FALLBACK ];
	}
}
