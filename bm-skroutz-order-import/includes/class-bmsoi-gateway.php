<?php
/**
 * Virtual "Skroutz Marketplace" payment gateway.
 *
 * Groups the imported orders under their own payment method (handy for
 * reports and ERP bridges). Never shown on the customer-facing checkout.
 *
 * @package BM_Skroutz_Order_Import
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMSOI_Gateway {

	const ID = 'bmsoi_skroutz';

	public static function init() {
		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'register_gateway' ) );
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'hide_on_checkout' ) );
	}

	public static function register_gateway( $gateways ) {
		$gateways[] = 'BMSOI_WC_Gateway';
		return $gateways;
	}

	public static function hide_on_checkout( $gateways ) {
		if ( ! is_admin() && isset( $gateways[ self::ID ] ) ) {
			unset( $gateways[ self::ID ] );
		}
		return $gateways;
	}
}

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}
	if ( class_exists( 'BMSOI_WC_Gateway' ) ) {
		return;
	}

	class BMSOI_WC_Gateway extends WC_Payment_Gateway {

		public function __construct() {
			$this->id                 = BMSOI_Gateway::ID;
			$this->icon               = '';
			$this->has_fields         = false;
			$this->method_title       = __( 'Skroutz Marketplace', 'bm-skroutz-order-import' );
			$this->method_description = __( 'Εικονική πύλη πληρωμής για τις παραγγελίες που εισάγονται από το Skroutz Marketplace.', 'bm-skroutz-order-import' );

			$this->init_form_fields();
			$this->init_settings();

			$this->title       = $this->get_option( 'title' );
			$this->description = $this->get_option( 'description' );

			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		}

		public function init_form_fields() {
			$this->form_fields = array(
				'enabled' => array(
					'title'   => __( 'Ενεργοποίηση', 'bm-skroutz-order-import' ),
					'label'   => __( 'Ενεργοποίηση της πύλης Skroutz Marketplace', 'bm-skroutz-order-import' ),
					'type'    => 'checkbox',
					'default' => 'yes',
				),
				'title' => array(
					'title'   => __( 'Τίτλος', 'bm-skroutz-order-import' ),
					'type'    => 'text',
					'default' => __( 'Skroutz Marketplace', 'bm-skroutz-order-import' ),
				),
				'description' => array(
					'title'   => __( 'Περιγραφή', 'bm-skroutz-order-import' ),
					'type'    => 'textarea',
					'default' => __( 'Πληρωμή μέσω Skroutz Marketplace.', 'bm-skroutz-order-import' ),
				),
			);
		}
	}
}, 11 );
