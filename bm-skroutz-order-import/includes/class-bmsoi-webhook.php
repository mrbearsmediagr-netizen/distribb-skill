<?php
/**
 * REST webhook endpoint that Skroutz Marketplace pushes order events to.
 *
 * Register the URL shown on the settings page in the Skroutz merchant panel
 * (Merchants > Services > Skroutz Marketplace).
 *
 * @package BM_Skroutz_Order_Import
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMSOI_Webhook {

	const REST_NAMESPACE = 'bm-skroutz/v1';
	const REST_ROUTE     = '/orders';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route( self::REST_NAMESPACE, self::REST_ROUTE, array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle' ),
			'permission_callback' => array( __CLASS__, 'verify_request' ),
		) );
	}

	/**
	 * The webhook URL to register at Skroutz (includes the shared secret).
	 */
	public static function url() {
		$url    = get_rest_url( null, self::REST_NAMESPACE . self::REST_ROUTE );
		$secret = get_option( 'bmsoi_webhook_secret', '' );
		return $secret ? add_query_arg( 'secret', rawurlencode( $secret ), $url ) : $url;
	}

	/**
	 * Validate the shared secret (query arg or X-BMSOI-Secret header).
	 * If no secret is configured the endpoint is open, like the original plugin.
	 */
	public static function verify_request( $request ) {
		$secret = (string) get_option( 'bmsoi_webhook_secret', '' );
		if ( '' === $secret ) {
			return true;
		}
		$provided = $request->get_param( 'secret' );
		if ( empty( $provided ) ) {
			$provided = $request->get_header( 'x-bmsoi-secret' );
		}
		return is_string( $provided ) && hash_equals( $secret, $provided );
	}

	/**
	 * Handle an incoming Skroutz webhook payload.
	 */
	public static function handle( WP_REST_Request $request ) {
		$payload = json_decode( $request->get_body() );

		bmsoi_log( 'Webhook received: ' . wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ) );

		/**
		 * Fires for every webhook delivery, before any processing.
		 *
		 * @param object|null $payload Decoded payload.
		 */
		do_action( 'bmsoi_webhook_received', $payload );

		if ( empty( $payload->order ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Missing order payload.' ), 400 );
		}

		// A brand-new open order must not overwrite an order that already exists
		// (e.g. duplicate delivery); every other event updates the existing order.
		$is_new_event = isset( $payload->event_type, $payload->order->state )
			&& 'new_order' === $payload->event_type
			&& 'open' === $payload->order->state;

		$result = BMSOI_Importer::import( $payload->order, ! $is_new_event );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => $result->get_error_message() ), 500 );
		}

		return new WP_REST_Response( array( 'success' => true, 'order_id' => $result ), 200 );
	}
}
