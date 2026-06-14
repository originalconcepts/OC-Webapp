<?php
/**
 * Adds an "app only" restriction to WooCommerce coupons: a coupon flagged this
 * way is valid only when the shopper is browsing inside the app's WebView.
 *
 * @package OC_Webapp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

class OC_Coupon_Restrictions_Admin {

	const META_KEY = '_is_restrict_to_app';

	/**
	 * Register hooks.
	 */
	public function init() {
		add_filter( 'woocommerce_coupon_data_tabs', array( $this, 'add_tab' ), 20, 1 );
		add_action( 'woocommerce_coupon_data_panels', array( $this, 'add_panel' ), 10, 0 );
		add_action( 'woocommerce_process_shop_coupon_meta', array( $this, 'save_meta' ), 10, 2 );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate' ), 10, 2 );
	}

	/**
	 * Add the "Web Application" tab to the coupon editor.
	 *
	 * @param array $tabs
	 * @return array
	 */
	public function add_tab( $tabs ) {
		$tabs['oc_application'] = array(
			'label'  => __( 'Web Application', 'oc_webapp' ),
			'target' => 'oc_coupondata_application',
			'class'  => 'oc_coupondata_application',
		);

		return $tabs;
	}

	/**
	 * Render the tab panel.
	 */
	public function add_panel() {
		echo '<div id="oc_coupondata_application" class="panel woocommerce_options_panel">';
		echo '<div class="options_group">';

		woocommerce_wp_checkbox(
			array(
				'id'          => self::META_KEY,
				'label'       => __( 'Restrict to app users only', 'oc_webapp' ),
				'description' => __( 'When checked, this coupon works only for shoppers browsing inside the app.', 'oc_webapp' ),
			)
		);

		echo '</div>';
		echo '</div>';
	}

	/**
	 * Persist the checkbox.
	 *
	 * @param int     $post_id
	 * @param WC_Coupon $coupon
	 */
	public function save_meta( $post_id, $coupon ) {
		// WooCommerce verifies the coupon save nonce before this fires.
		$value = isset( $_POST[ self::META_KEY ] ) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification

		if ( $coupon instanceof WC_Coupon ) {
			$coupon->update_meta_data( self::META_KEY, $value );
			$coupon->save();
		} else {
			update_post_meta( $post_id, self::META_KEY, $value );
		}
	}

	/**
	 * Invalidate an app-only coupon when the request isn't from the app.
	 *
	 * @param bool      $valid
	 * @param WC_Coupon $coupon
	 * @return bool
	 */
	public function validate( $valid, $coupon ) {
		if ( ! $valid ) {
			return false;
		}

		if ( 'yes' !== $coupon->get_meta( self::META_KEY ) ) {
			return true; // not restricted.
		}

		if ( function_exists( 'oc_webapp_is_in_app' ) && oc_webapp_is_in_app() ) {
			return true;
		}

		add_filter( 'woocommerce_coupon_error', array( $this, 'error_message' ), 10, 3 );
		return false;
	}

	/**
	 * App-only validation message.
	 *
	 * @param string $err
	 * @param int    $err_code
	 * @param mixed  $coupon
	 * @return string
	 */
	public function error_message( $err, $err_code, $coupon ) {
		return __( 'This coupon can only be used in the app.', 'oc_webapp' );
	}
}
