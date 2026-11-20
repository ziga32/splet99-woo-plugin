<?php
namespace Splet99\Woo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Columns hidden by default in the Pages, Posts and Products lists. WordPress applies these only to users who
// haven't saved their own Screen Options, so anyone can show them again there and keep that choice.
final class Columns {

	// Screen id => column ids (author, WP Rocket, SEOPress, WooCommerce brands).
	const HIDDEN = array(
		'edit-page'    => array( 'author', 'rocket_insights', 'seopress_noindex', 'seopress_nofollow', 'seopress_score' ),
		'edit-post'    => array( 'author', 'rocket_insights', 'seopress_noindex', 'seopress_nofollow', 'seopress_score' ),
		'edit-product' => array( 'rocket_insights', 'seopress_title', 'seopress_desc', 'seopress_noindex', 'seopress_nofollow', 'seopress_score', 'taxonomy-product_brand' ),
	);

	public static function init() {
		add_filter( 'default_hidden_columns', array( __CLASS__, 'hide' ), 20, 2 );
	}

	public static function hide( $hidden, $screen ) {
		if ( ! $screen instanceof \WP_Screen || ! isset( self::HIDDEN[ $screen->id ] ) ) {
			return $hidden;
		}

		return array_values( array_unique( array_merge( (array) $hidden, self::HIDDEN[ $screen->id ] ) ) );
	}
}
