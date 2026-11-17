<?php
namespace Splet99\Woo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ZVPot-1, 15. člen: a reduced price must show the lowest price from the 30 days before the reduction.
// WooCommerce keeps no price history, so every price change of a product or variation is logged in its meta.
final class Lowest_Price {

	const FEATURE = 'lowest_price';

	// Same key as the ergovision.si functions.php snippet, so its history carries over.
	const LOG_META = '_s99_price_log';

	const KEEP_DAYS = 120;

	const PRICE_KEYS = array( '_regular_price', '_sale_price', '_sale_price_dates_from', '_sale_price_dates_to' );

	// Product types whose price markup WooCommerce builds as a plain regular/sale pair.
	const SIMPLE_TYPES = array( 'simple', 'external', 'variation' );

	const TEXT = 'Najnižja cena v zadnjih 30 dneh pred znižanjem:';

	// Post ID => price state before this request changed it.
	private static $before = array();

	public static function init() {
		// After the theme loads, so the ergovision snippet in functions.php can be detected.
		add_action( 'init', array( __CLASS__, 'setup' ), 20 );
	}

	public static function enabled() {
		return Plugin::feature_enabled( self::FEATURE );
	}

	// The snippet already does the same job; both together would print the line twice.
	public static function snippet_active() {
		return function_exists( 's99_prior_price_incl_tax' );
	}

	public static function setup() {
		if ( ! self::enabled() || self::snippet_active() || ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		// "Before" hooks fire only when a value really changes, and still see the old values.
		add_action( 'add_post_meta', array( __CLASS__, 'before_add' ), 10, 2 );
		add_action( 'update_post_meta', array( __CLASS__, 'before_change' ), 10, 3 );
		add_action( 'delete_post_meta', array( __CLASS__, 'before_change' ), 10, 3 );
		add_action( 'shutdown', array( __CLASS__, 'write_log' ) );

		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'price_html' ), 20, 2 );
		add_filter( 'woocommerce_available_variation', array( __CLASS__, 'variation_data' ), 20, 3 );
		add_filter( 'woocommerce_duplicate_product_exclude_meta', array( __CLASS__, 'exclude_from_duplicate' ) );
	}

	/* ---------- price log ---------- */

	public static function before_add( $object_id, $meta_key ) {
		self::remember( $object_id, $meta_key );
	}

	// update_post_meta passes a meta ID first, delete_post_meta an array of them.
	public static function before_change( $meta_ids, $object_id, $meta_key ) {
		self::remember( $object_id, $meta_key );
	}

	private static function remember( $object_id, $meta_key ) {
		$object_id = (int) $object_id;

		if ( $object_id && in_array( $meta_key, self::PRICE_KEYS, true ) && ! isset( self::$before[ $object_id ] ) ) {
			self::$before[ $object_id ] = self::state( $object_id );
		}
	}

	private static function state( $id ) {
		return array(
			'r'  => (string) get_post_meta( $id, '_regular_price', true ),
			's'  => (string) get_post_meta( $id, '_sale_price', true ),
			'sf' => (int) get_post_meta( $id, '_sale_price_dates_from', true ),
			'st' => (int) get_post_meta( $id, '_sale_price_dates_to', true ),
		);
	}

	private static function same( $a, $b ) {
		foreach ( array( 'r', 's', 'sf', 'st' ) as $key ) {
			if ( ! isset( $a[ $key ], $b[ $key ] ) || $a[ $key ] !== $b[ $key ] ) {
				return false;
			}
		}

		return true;
	}

	public static function write_log() {
		$changed      = self::$before;
		self::$before = array();

		foreach ( $changed as $id => $before ) {
			if ( ! in_array( get_post_type( $id ), array( 'product', 'product_variation' ), true ) ) {
				continue;
			}

			$state = self::state( $id );
			$log   = get_post_meta( $id, self::LOG_META, true );
			$log   = is_array( $log ) ? array_values( $log ) : array();

			// First change since logging started: keep the old prices too, start time unknown.
			if ( ! $log && ( '' !== $before['r'] || '' !== $before['s'] ) && ! self::same( $before, $state ) ) {
				$before['t'] = 0;
				$log[]       = $before;
			}

			$last = end( $log );

			if ( $last && self::same( $last, $state ) ) {
				continue;
			}

			$state['t'] = time();
			$log[]      = $state;

			// Keep KEEP_DAYS of history plus the last state from before that.
			$cutoff = time() - self::KEEP_DAYS * DAY_IN_SECONDS;

			while ( count( $log ) > 1 && $log[1]['t'] < $cutoff ) {
				array_shift( $log );
			}

			update_post_meta( $id, self::LOG_META, $log );
		}
	}

	/* ---------- lowest price ---------- */

	private static function entry( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();

		return array(
			'r'  => isset( $raw['r'] ) ? (string) $raw['r'] : '',
			's'  => isset( $raw['s'] ) ? (string) $raw['s'] : '',
			'sf' => isset( $raw['sf'] ) ? (int) $raw['sf'] : 0,
			'st' => isset( $raw['st'] ) ? (int) $raw['st'] : 0,
			't'  => isset( $raw['t'] ) ? (int) $raw['t'] : 0,
		);
	}

	private static function push( &$segments, $from, $to, $price, $sale, $regular ) {
		if ( $to > $from ) {
			$segments[] = array(
				'from'    => $from,
				'to'      => $to,
				'price'   => $price,
				'sale'    => $sale,
				'regular' => $regular,
			);
		}
	}

	// The log as a timeline of the prices customers actually saw. Entry i is valid from its t until the next
	// entry's t; its sale counts only between the sale dates, since WooCommerce ends scheduled sales without
	// clearing the sale price.
	private static function segments( $log, $now ) {
		$segments = array();
		$count    = count( $log );

		for ( $i = 0; $i < $count; $i++ ) {
			$entry = self::entry( $log[ $i ] );
			$from  = $entry['t'];
			$to    = $i + 1 < $count ? self::entry( $log[ $i + 1 ] )['t'] : $now;

			if ( $to <= $from || '' === $entry['r'] ) {
				continue;
			}

			$regular   = (float) $entry['r'];
			$sale_from = $from;
			$sale_to   = $from;

			if ( '' !== $entry['s'] && (float) $entry['s'] < $regular ) {
				$sale_from = max( $from, $entry['sf'] );
				$sale_to   = $entry['st'] ? min( $to, $entry['st'] ) : $to;
			}

			if ( $sale_from < $sale_to ) {
				self::push( $segments, $from, $sale_from, $regular, false, $regular );
				self::push( $segments, $sale_from, $sale_to, (float) $entry['s'], true, $regular );
				self::push( $segments, $sale_to, $to, $regular, false, $regular );
			} else {
				self::push( $segments, $from, $to, $regular, false, $regular );
			}
		}

		return $segments;
	}

	// Lowest price in the 30 days before the current reduction began, without tax handling. Back-to-back
	// sale prices count as one step-by-step reduction, measured from its first step.
	public static function prior_price( $product ) {
		$regular = (float) $product->get_regular_price();
		$log     = get_post_meta( $product->get_id(), self::LOG_META, true );

		if ( ! is_array( $log ) || ! $log ) {
			return $regular;
		}

		$now      = time();
		$segments = self::segments( array_values( $log ), $now );

		if ( ! $segments ) {
			return $regular;
		}

		// If the log doesn't show a sale running right now, look at the last 30 days.
		$start         = $now;
		$start_regular = $regular;
		$k             = count( $segments ) - 1;

		if ( $segments[ $k ]['sale'] && $segments[ $k ]['to'] >= $now ) {
			while ( $k > 0 ) {
				$prev = $segments[ $k - 1 ];

				if ( $prev['sale'] && $prev['to'] === $segments[ $k ]['from'] ) {
					$k--;
					continue;
				}

				// Back-to-back scheduled sales: WooCommerce ends one at 23:59:59 and starts the next at 00:00:00.
				if ( $k > 1 && ! $prev['sale'] && $prev['to'] - $prev['from'] <= MINUTE_IN_SECONDS
					&& $segments[ $k - 2 ]['sale'] && $segments[ $k - 2 ]['to'] === $prev['from'] ) {
					$k -= 2;
					continue;
				}

				break;
			}

			$start         = $segments[ $k ]['from'];
			$start_regular = $segments[ $k ]['regular'];
		}

		$window = $start - 30 * DAY_IN_SECONDS;
		$prices = array();

		foreach ( $segments as $segment ) {
			if ( $segment['to'] > $window && $segment['from'] < $start ) {
				$prices[] = $segment['price'];
			}
		}

		// No history before the reduction: the regular price at its start.
		return $prices ? min( $prices ) : $start_regular;
	}

	/* ---------- display (languages ticked on the WooCommerce tab) ---------- */

	// Product IDs whose line was already printed on this page (sticky bars and builder modules repeat the price).
	private static $printed = array();

	private static function line( $price ) {
		return '<span class="s99-lowest-price" style="display:block;font-size:13px;margin-top:5px">' . esc_html( I18n::text( self::TEXT ) ) . ' ' . wc_price( $price ) . '</span>';
	}

	// Once per page, and only for the product the page is about.
	private static function main_product_line( $product, $price ) {
		$id = $product->get_id();

		// Not before or inside wp_head, where SEO plugins may read the price for meta tags.
		if ( ! did_action( 'wp_head' ) || doing_action( 'wp_head' ) ) {
			return '';
		}

		if ( isset( self::$printed[ $id ] ) || ! function_exists( 'is_product' ) || ! is_product() || get_queried_object_id() !== $id ) {
			return '';
		}

		self::$printed[ $id ] = true;

		return self::line( $price );
	}

	private static function shows_on_this_request() {
		return ( ! is_admin() || wp_doing_ajax() ) && self::base_currency() && I18n::lang_enabled( self::FEATURE );
	}

	// The price log holds shop-currency amounts; in another currency (WCML, CURCY, WOOCS…) they would be wrong.
	private static function base_currency() {
		return ! function_exists( 'get_woocommerce_currency' ) || get_woocommerce_currency() === get_option( 'woocommerce_currency' );
	}

	// Swaps WooCommerce's own sale markup for ours; markup changed by another plugin is left alone.
	private static function swap( $html, $core, $ours ) {
		return false !== strpos( $html, $core ) ? str_replace( $core, $ours, $html ) : $html;
	}

	public static function price_html( $html, $product ) {
		if ( '' === $html || ! is_object( $product ) || ! $product->is_on_sale() || ! self::shows_on_this_request() ) {
			return $html;
		}

		if ( $product->is_type( 'variable' ) ) {
			return self::variable_html( $html, $product );
		}

		if ( ! $product->is_type( self::SIMPLE_TYPES ) ) {
			return $html;
		}

		$current = wc_get_price_to_display( $product );
		$prior   = (float) wc_get_price_to_display( $product, array( 'price' => self::prior_price( $product ) ) );
		$core    = wc_format_sale_price( wc_get_price_to_display( $product, array( 'price' => $product->get_regular_price() ) ), $current );
		$ours    = $prior > (float) $current ? wc_format_sale_price( $prior, $current ) : wc_price( $current );
		$html    = self::swap( $html, $core, $ours );

		// Variations get the line through woocommerce_available_variation, which also covers AJAX-loaded ones.
		if ( ! $product->is_type( 'variation' ) ) {
			$html .= self::main_product_line( $product, $prior );
		}

		return $html;
	}

	// Same condition as WooCommerce's single struck-through price for a variable product.
	private static function is_uniform( $product ) {
		$prices = $product->get_variation_prices( true );

		return ! empty( $prices['price'] ) && ! empty( $prices['regular_price'] )
			&& (float) min( $prices['price'] ) === (float) max( $prices['price'] )
			&& (float) min( $prices['regular_price'] ) === (float) max( $prices['regular_price'] );
	}

	// A price range has no strike-through: each variation shows its own line once picked.
	private static function variable_html( $html, $product ) {
		if ( ! self::is_uniform( $product ) ) {
			return $html;
		}

		$prices  = $product->get_variation_prices( true );
		$current = min( $prices['price'] );
		$prior   = null;

		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );

			if ( $variation && $variation->is_on_sale() ) {
				$price = (float) wc_get_price_to_display( $variation, array( 'price' => self::prior_price( $variation ) ) );
				$prior = null === $prior ? $price : min( $prior, $price );
			}
		}

		if ( null === $prior ) {
			return $html;
		}

		$core = wc_format_sale_price( wc_price( max( $prices['regular_price'] ) ), wc_price( $current ) );
		$ours = $prior > (float) $current ? wc_format_sale_price( $prior, $current ) : wc_price( $current );

		// The variation price box usually stays hidden here, so the line goes under the main price.
		return self::swap( $html, $core, $ours ) . self::main_product_line( $product, $prior );
	}

	public static function variation_data( $data, $product, $variation ) {
		if ( empty( $data['price_html'] ) || ! is_object( $variation ) || ! $variation->is_on_sale() || ! self::shows_on_this_request() ) {
			return $data;
		}

		// A uniform parent already shows the line under its main price.
		if ( is_object( $product ) && $product->is_type( 'variable' ) && self::is_uniform( $product ) ) {
			return $data;
		}

		$data['price_html'] .= self::line( (float) wc_get_price_to_display( $variation, array( 'price' => self::prior_price( $variation ) ) ) );

		return $data;
	}

	// Duplicated products start without the original's price history.
	public static function exclude_from_duplicate( $exclude ) {
		$exclude   = (array) $exclude;
		$exclude[] = self::LOG_META;

		return $exclude;
	}
}
