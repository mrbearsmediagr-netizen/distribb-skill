<?php
/**
 * Thin client for the Skroutz Merchant e-commerce API.
 *
 * Docs: https://developer.skroutz.gr/merchants/
 * Base: https://api.skroutz.gr/merchants/ecommerce/
 *
 * @package BM_Skroutz_Order_Import
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMSOI_API {

	const BASE_URL = 'https://api.skroutz.gr/merchants/ecommerce/';

	/**
	 * Whether an API token is configured.
	 */
	public static function is_configured() {
		return '' !== (string) get_option( 'bmsoi_api_token', '' );
	}

	/**
	 * Perform a request against the Skroutz Merchant API.
	 *
	 * @param string     $path   Relative path, e.g. "orders/ABCD12-EFGH34".
	 * @param string     $method GET|POST|PUT.
	 * @param array|null $body   For POST/PUT: JSON body. For GET: query args.
	 * @return object|WP_Error   Decoded JSON body or WP_Error.
	 */
	public static function request( $path, $method = 'GET', $body = null ) {
		$token = get_option( 'bmsoi_api_token', '' );
		if ( empty( $token ) ) {
			return new WP_Error( 'bmsoi_no_token', __( 'Δεν έχει οριστεί Skroutz API token.', 'bm-skroutz-order-import' ) );
		}

		$url  = self::BASE_URL . ltrim( $path, '/' );
		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/vnd.skroutz+json; version=3.0',
				'Content-Type'  => 'application/json',
				'User-Agent'    => 'BM-Skroutz-Order-Import/' . BMSOI_VERSION . ' (WooCommerce; +' . home_url() . ')',
			),
		);

		if ( in_array( $method, array( 'POST', 'PUT' ), true ) ) {
			$args['body'] = null === $body ? '' : wp_json_encode( $body, JSON_UNESCAPED_UNICODE );
		} elseif ( ! empty( $body ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', $body ), $url );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			bmsoi_log( sprintf( 'API %s %s failed: %s', $method, $path, $response->get_error_message() ), 'error' );
			return $response;
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ) );

		if ( $code >= 400 ) {
			$message = self::error_messages( $decoded );
			bmsoi_log( sprintf( 'API %s %s -> HTTP %d: %s', $method, $path, $code, $message ), 'error' );
			return new WP_Error( 'bmsoi_http_' . $code, $message ? $message : sprintf( __( 'Σφάλμα API (HTTP %d).', 'bm-skroutz-order-import' ), $code ), $decoded );
		}

		if ( null === $decoded ) {
			return new WP_Error( 'bmsoi_bad_json', __( 'Μη έγκυρη απάντηση από το Skroutz API.', 'bm-skroutz-order-import' ) );
		}

		return $decoded;
	}

	/**
	 * Flatten the Skroutz error structure to a single readable string.
	 */
	public static function error_messages( $decoded ) {
		if ( empty( $decoded->errors ) || ! is_array( $decoded->errors ) ) {
			return '';
		}
		$messages = array();
		foreach ( $decoded->errors as $error ) {
			if ( ! empty( $error->messages ) ) {
				$messages[] = implode( ', ', (array) $error->messages );
			}
		}
		return implode( ' / ', $messages );
	}

	/** Fetch a single order (full payload). */
	public static function get_order( $code ) {
		return self::request( 'orders/' . rawurlencode( $code ) );
	}

	/** List orders. $params e.g. ['state' => 'open', 'page' => 1, 'per' => 25]. */
	public static function list_orders( $params = array() ) {
		return self::request( 'orders', 'GET', $params );
	}

	/** Accept an order. $args: number_of_parcels, pickup_location, pickup_window. */
	public static function accept_order( $code, $args ) {
		return self::request( 'orders/' . rawurlencode( $code ) . '/accept', 'POST', $args );
	}

	/** Reject an order. $args: line_items[] with id/reason_id, or rejection_reason_other. */
	public static function reject_order( $code, $args ) {
		return self::request( 'orders/' . rawurlencode( $code ) . '/reject', 'POST', $args );
	}
}
