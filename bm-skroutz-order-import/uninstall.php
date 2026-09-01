<?php
/**
 * Uninstall: remove plugin options and scheduled events.
 *
 * @package BM_Skroutz_Order_Import
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$bmsoi_options = array(
	'bmsoi_api_token',
	'bmsoi_webhook_secret',
	'bmsoi_unique_id',
	'bmsoi_variation_attributes',
	'bmsoi_payment_gateway',
	'bmsoi_shipping_method',
	'bmsoi_customer_user',
	'bmsoi_billing_email',
	'bmsoi_merge_addresses',
	'bmsoi_manage_orders',
	'bmsoi_auto_accept',
	'bmsoi_pickup_window',
	'bmsoi_polling_enabled',
	'bmsoi_polling_interval',
	'bmsoi_disable_emails',
	'bmsoi_debug',
);

foreach ( $bmsoi_options as $bmsoi_option ) {
	delete_option( $bmsoi_option );
}

wp_clear_scheduled_hook( 'bmsoi_poll_orders' );
