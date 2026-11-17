<?php
namespace Splet99\Woo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// When free shipping is available, offer only that and hide the paid shipping methods.
final class Free_Shipping {

	const FEATURE = 'free_shipping_only';

	public static function init() {
		add_filter( 'woocommerce_package_rates', array( __CLASS__, 'filter_rates' ), 100, 2 );
	}

	public static function filter_rates( $rates, $package ) {
		if ( ! is_array( $rates ) || ! Plugin::feature_enabled( self::FEATURE ) ) {
			return $rates;
		}

		$free = array();

		foreach ( $rates as $id => $rate ) {
			if ( is_object( $rate ) && method_exists( $rate, 'get_method_id' ) && 'free_shipping' === $rate->get_method_id() ) {
				$free[ $id ] = $rate;
			}
		}

		return $free ? $free : $rates;
	}
}
