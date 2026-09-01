<?php
/**
 * Order actions against the Skroutz Merchant API: accept / reject / auto-accept.
 *
 * @package BM_Skroutz_Order_Import
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMSOI_Actions {

	public static function init() {
		add_action( 'bmsoi_order_imported', array( __CLASS__, 'maybe_auto_accept' ), 10, 3 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 3 );
	}

	/**
	 * Accept an order at Skroutz, choosing the first (or last) pickup
	 * location/window offered by accept_options.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return true|WP_Error
	 */
	public static function accept( $order, $pickup_location = null, $pickup_window = null, $parcels = null ) {
		$code     = bmsoi_order_code( $order );
		$response = BMSOI_API::get_order( $code );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( empty( $response->order ) || 'open' !== $response->order->state ) {
			return new WP_Error( 'bmsoi_not_open', __( 'Η παραγγελία δεν είναι πλέον σε κατάσταση «Νέα» στο Skroutz.', 'bm-skroutz-order-import' ) );
		}
		if ( ! empty( $response->order->express ) ) {
			return new WP_Error( 'bmsoi_express', __( 'Οι Express παραγγελίες δεν γίνονται αποδεκτές μέσω API.', 'bm-skroutz-order-import' ) );
		}

		$options = isset( $response->order->accept_options ) ? $response->order->accept_options : null;
		if ( ! $options ) {
			return new WP_Error( 'bmsoi_no_options', __( 'Δεν βρέθηκαν επιλογές αποδοχής για την παραγγελία.', 'bm-skroutz-order-import' ) );
		}

		$use_last  = 'last_pickup_window' === get_option( 'bmsoi_pickup_window', 'first_pickup_window' );
		$locations = (array) ( $options->pickup_location ?? array() );
		$windows   = (array) ( $options->pickup_window ?? array() );
		$parcelno  = (array) ( $options->number_of_parcels ?? array( 1 ) );

		$location = null !== $pickup_location ? $pickup_location : self::pick( $locations, $use_last, 'id' );
		$window   = null !== $pickup_window ? (int) $pickup_window : (int) self::pick( $windows, $use_last, 'id' );
		$parcels  = null !== $parcels ? (int) $parcels : (int) reset( $parcelno );

		$result = BMSOI_API::accept_order( $code, array(
			'number_of_parcels' => max( 1, $parcels ),
			'pickup_location'   => $location,
			'pickup_window'     => $window,
		) );

		if ( is_wp_error( $result ) ) {
			$order->add_order_note( sprintf( __( 'Αποτυχία αποδοχής της παραγγελίας %1$s στο Skroutz: %2$s', 'bm-skroutz-order-import' ), $code, $result->get_error_message() ) );
			$order->save();
			return $result;
		}

		$errors = BMSOI_API::error_messages( $result );
		if ( $errors ) {
			$order->add_order_note( sprintf( __( 'Αποτυχία αποδοχής της παραγγελίας %1$s στο Skroutz: %2$s', 'bm-skroutz-order-import' ), $code, $errors ) );
			$order->save();
			return new WP_Error( 'bmsoi_accept_failed', $errors );
		}

		$order->update_meta_data( '_skroutz_pickup_location_id', $location );
		$order->add_order_note( sprintf( __( 'Η παραγγελία %s έγινε αποδεκτή στο Skroutz Marketplace.', 'bm-skroutz-order-import' ), $code ) );
		$order->save();

		return true;
	}

	/**
	 * Reject an order at Skroutz.
	 *
	 * @param WC_Order $order        WooCommerce order.
	 * @param int      $reason_id    Rejection reason ID from reject_options.
	 * @param string   $other_reason Free-text reason (used instead of line items when set).
	 * @param int      $available_quantity For "partial availability" reasons.
	 * @return true|WP_Error
	 */
	public static function reject( $order, $reason_id, $other_reason = '', $available_quantity = 0 ) {
		$code = bmsoi_order_code( $order );

		if ( '' !== $other_reason ) {
			$args = array( 'rejection_reason_other' => $other_reason );
		} else {
			$args = array( 'line_items' => array() );
			foreach ( $order->get_items() as $item ) {
				$skroutz_item_id = $item->get_meta( 'item_id' );
				if ( ! $skroutz_item_id ) {
					continue;
				}
				$line = array( 'id' => $skroutz_item_id, 'reason_id' => (int) $reason_id );
				if ( 4 === (int) $reason_id && $available_quantity > 0 ) {
					$line['available_quantity'] = (int) $available_quantity;
				}
				$args['line_items'][] = $line;
			}
			if ( empty( $args['line_items'] ) ) {
				return new WP_Error( 'bmsoi_no_items', __( 'Δεν βρέθηκαν είδη παραγγελίας για απόρριψη.', 'bm-skroutz-order-import' ) );
			}
		}

		$result = BMSOI_API::reject_order( $code, $args );

		if ( is_wp_error( $result ) ) {
			$order->add_order_note( sprintf( __( 'Αποτυχία απόρριψης της παραγγελίας %1$s στο Skroutz: %2$s', 'bm-skroutz-order-import' ), $code, $result->get_error_message() ) );
			$order->save();
			return $result;
		}

		$errors = BMSOI_API::error_messages( $result );
		if ( $errors ) {
			$order->add_order_note( sprintf( __( 'Αποτυχία απόρριψης της παραγγελίας %1$s στο Skroutz: %2$s', 'bm-skroutz-order-import' ), $code, $errors ) );
			$order->save();
			return new WP_Error( 'bmsoi_reject_failed', $errors );
		}

		$order->add_order_note( sprintf( __( 'Η παραγγελία %s απορρίφθηκε στο Skroutz Marketplace.', 'bm-skroutz-order-import' ), $code ) );
		$order->save();
		$order->update_status( 'cancelled' );

		return true;
	}

	/**
	 * Auto-accept newly imported open orders when the option is enabled.
	 */
	public static function maybe_auto_accept( $order_id, $sc_order, $is_new ) {
		if ( ! $is_new || 'yes' !== get_option( 'bmsoi_auto_accept', 'no' ) ) {
			return;
		}
		if ( ! isset( $sc_order->state ) || 'open' !== $sc_order->state ) {
			return;
		}
		if ( ! empty( $sc_order->express ) || ! empty( $sc_order->store_pickup ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! bmsoi_is_skroutz_order( $order ) ) {
			return;
		}

		$result = self::accept( $order );
		if ( ! is_wp_error( $result ) ) {
			$order->update_status( 'processing' );
		}
	}

	/**
	 * Manual acceptance flow: moving a Skroutz order from on-hold to processing
	 * accepts it at Skroutz (when "manage orders" is enabled).
	 */
	public static function on_status_changed( $order_id, $old_status, $new_status ) {
		if ( 'yes' !== get_option( 'bmsoi_manage_orders', 'no' ) ) {
			return;
		}
		if ( 'on-hold' !== $old_status || 'processing' !== $new_status ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! bmsoi_is_skroutz_order( $order ) ) {
			return;
		}

		$result = self::accept( $order );
		if ( is_wp_error( $result ) && 'bmsoi_not_open' !== $result->get_error_code() ) {
			// Acceptance failed — put the order back on hold so it isn't shipped by mistake.
			$order->update_status( 'on-hold' );
		}
	}

	/**
	 * Pick the first or last element (or its property) from a list.
	 */
	private static function pick( $list, $use_last, $prop = null ) {
		if ( empty( $list ) ) {
			return null;
		}
		$element = $use_last ? end( $list ) : reset( $list );
		if ( null !== $prop && is_object( $element ) && isset( $element->{$prop} ) ) {
			return $element->{$prop};
		}
		return $element;
	}
}
