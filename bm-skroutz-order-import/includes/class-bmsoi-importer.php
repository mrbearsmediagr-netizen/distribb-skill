<?php
/**
 * Maps a Skroutz Marketplace order payload to a WooCommerce order.
 *
 * @package BM_Skroutz_Order_Import
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMSOI_Importer {

	/**
	 * Import (create or update) a WooCommerce order from a Skroutz order object.
	 *
	 * @param object $sc_order    Decoded Skroutz order (the `order` member of the API/webhook payload).
	 * @param bool   $update      Update the order if it already exists.
	 * @param bool   $bypass_lock Ignore the concurrency lock (manual re-fetch).
	 * @return int|null|WP_Error  Order ID, null when skipped, WP_Error on failure.
	 */
	public static function import( $sc_order, $update = true, $bypass_lock = false ) {
		if ( empty( $sc_order->code ) ) {
			return new WP_Error( 'bmsoi_no_code', __( 'Το payload δεν περιέχει κωδικό παραγγελίας.', 'bm-skroutz-order-import' ) );
		}

		$code     = sanitize_text_field( $sc_order->code );
		$lock_key = 'bmsoi_lock_' . md5( $code );

		if ( get_transient( $lock_key ) && ! $bypass_lock ) {
			bmsoi_log( "Order {$code}: skipped, another import is in progress." );
			return null;
		}
		set_transient( $lock_key, 1, MINUTE_IN_SECONDS );

		try {
			$order_id = wc_get_order_id_by_order_key( 'SKZ-' . $code );
			if ( ! $order_id ) {
				// Compatibility with orders imported by the WebExpert Smart Cart plugin.
				$order_id = wc_get_order_id_by_order_key( 'SC-' . $code );
			}
			$is_new = ! $order_id;

			if ( ! $is_new && ! $update ) {
				return null;
			}

			// "From now on" cutoff: never auto-import orders created before the
			// plugin was activated. Manual imports ($bypass_lock) go through.
			if ( $is_new && ! $bypass_lock ) {
				$since = bmsoi_import_since();
				if ( $since && ! empty( $sc_order->created_at ) && strtotime( $sc_order->created_at ) < $since ) {
					bmsoi_log( "Order {$code}: skipped, created before the import-start date." );
					return null;
				}
			}

			$order = $is_new ? new WC_Order() : wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return new WP_Error( 'bmsoi_order_load', sprintf( __( 'Αποτυχία φόρτωσης της παραγγελίας #%d.', 'bm-skroutz-order-import' ), $order_id ) );
			}

			if ( $is_new ) {
				$order->set_order_key( 'SKZ-' . $code );
				$order->set_created_via( 'skroutz_api_import' );
				$order->set_currency( get_woocommerce_currency() );
				$order->set_prices_include_tax( 'yes' === get_option( 'woocommerce_prices_include_tax' ) );
				if ( ! empty( $sc_order->created_at ) ) {
					$order->set_date_created( strtotime( $sc_order->created_at ) );
					$order->set_date_paid( strtotime( $sc_order->created_at ) );
				}
			}

			self::apply_status( $order, $sc_order, ! $is_new );
			self::apply_customer( $order, $sc_order );
			self::apply_payment( $order );
			self::apply_meta( $order, $sc_order );
			self::apply_line_items( $order, $sc_order );
			self::apply_invoice_details( $order, $sc_order );
			self::apply_shipping( $order, $sc_order );

			$order->set_customer_note( isset( $sc_order->comments ) ? (string) $sc_order->comments : '' );
			$order->calculate_totals();

			$order_id = $order->save();

			bmsoi_log( sprintf( 'Order %s %s as WooCommerce order #%d (state: %s).', $code, $is_new ? 'imported' : 'updated', $order_id, $sc_order->state ?? '-' ) );

			/**
			 * Fires after a Skroutz order has been imported or updated.
			 *
			 * @param int    $order_id WooCommerce order ID.
			 * @param object $sc_order Raw Skroutz order payload.
			 * @param bool   $is_new   True on first import.
			 */
			do_action( 'bmsoi_order_imported', $order_id, $sc_order, $is_new );

			return $order_id;
		} catch ( Exception $e ) {
			bmsoi_log( "Order {$code}: import failed - " . $e->getMessage(), 'error' );
			return new WP_Error( 'bmsoi_import_failed', $e->getMessage() );
		} finally {
			delete_transient( $lock_key );
		}
	}

	/**
	 * Map the Skroutz order state to a WooCommerce status.
	 */
	private static function apply_status( $order, $sc_order, $order_exists ) {
		$state = isset( $sc_order->state ) ? $sc_order->state : 'open';

		switch ( $state ) {
			case 'open':
				$status = 'on-hold';
				break;
			case 'accepted':
			case 'partially_delivered':
				$status = 'processing';
				break;
			case 'dispatched':
			case 'delivered':
				$status = 'completed';
				break;
			case 'cancelled':
				// A cancellation after dispatch means the parcel is on its way back — keep stock out.
				$status = ( $order_exists && 'completed' === $order->get_status() ) ? 'for-return' : 'cancelled';
				break;
			case 'rejected':
			case 'expired':
				$status = 'cancelled';
				break;
			case 'returned':
			case 'partially_returned':
				$status = 'refunded';
				break;
			case 'for_return':
				$status = 'for-return';
				break;
			default:
				$status = 'processing';
		}

		/**
		 * Filter the WooCommerce status applied for a Skroutz state.
		 *
		 * @param string   $status Target status (without wc- prefix).
		 * @param string   $state  Skroutz order state.
		 * @param WC_Order $order  Order being written.
		 */
		$status = apply_filters( 'bmsoi_status_for_state', $status, $state, $order );
		$order->set_status( $status );
	}

	/**
	 * Billing/shipping details, e-mail and customer user.
	 */
	private static function apply_customer( $order, $sc_order ) {
		$customer = isset( $sc_order->customer ) ? $sc_order->customer : new stdClass();
		$address  = isset( $customer->address ) ? $customer->address : new stdClass();

		$email = get_option( 'bmsoi_billing_email', '' );
		if ( '' === $email && isset( $customer->id ) ) {
			$email = $customer->id . '@skroutz.gr';
		}

		$user_id = (int) get_option( 'bmsoi_customer_user', 0 );
		if ( $user_id > 0 ) {
			$order->set_customer_id( $user_id );
			$user = get_userdata( $user_id );
			if ( $user && '' === get_option( 'bmsoi_billing_email', '' ) ) {
				$email = $user->user_email;
			}
		}

		$first_name = isset( $customer->first_name ) ? $customer->first_name : '';
		$last_name  = isset( $customer->last_name ) ? $customer->last_name : '';
		$country    = isset( $address->country_code ) ? $address->country_code : 'GR';
		$state      = ( 'GR' === $country && isset( $address->region ) ) ? wc_strtoupper( $address->region ) : '';

		list( $address_1, $address_2 ) = self::format_address( $address );

		// Recognition prefix (e.g. "SKR") on the billing first name, so Skroutz
		// orders stand out everywhere the buyer name shows — WooCommerce app
		// included. Shipping name stays clean for courier vouchers/labels.
		$billing_first_name = $first_name;
		$prefix             = trim( (string) get_option( 'bmsoi_name_prefix', 'SKR' ) );
		if ( '' !== $prefix ) {
			$billing_first_name = trim( $prefix . ' ' . $first_name );
		}

		$order->set_billing_first_name( $billing_first_name );
		$order->set_billing_last_name( $last_name );
		$order->set_billing_address_1( $address_1 );
		$order->set_billing_address_2( $address_2 );
		$order->set_billing_city( isset( $address->city ) ? $address->city : '' );
		$order->set_billing_postcode( isset( $address->zip ) ? $address->zip : '' );
		$order->set_billing_state( $state );
		$order->set_billing_country( $country );
		$order->set_billing_email( sanitize_email( str_replace( '{customer}', isset( $customer->id ) ? $customer->id : '', $email ) ) );
		if ( ! empty( $customer->phone ) ) {
			$order->set_billing_phone( $customer->phone );
		}
		if ( ! empty( $customer->mobile ) ) {
			$order->update_meta_data( apply_filters( 'bmsoi_cellphone_meta_key', '_billing_cellphone' ), $customer->mobile );
		}

		$order->set_shipping_first_name( $first_name );
		$order->set_shipping_last_name( $last_name );
		$order->set_shipping_address_1( $address_1 );
		$order->set_shipping_address_2( $address_2 );
		$order->set_shipping_city( isset( $address->city ) ? $address->city : '' );
		$order->set_shipping_postcode( isset( $address->zip ) ? $address->zip : '' );
		$order->set_shipping_state( $state );
		$order->set_shipping_country( $country );
	}

	/**
	 * Resolve street / collection-point address to address_1 + address_2.
	 */
	private static function format_address( $address ) {
		if ( ! empty( $address->pickup_from_collection_point ) ) {
			$cp = isset( $address->collection_point_address ) ? (string) $address->collection_point_address : '';
			// "Point label: Street 12, City, 12345" → address_1 = street, address_2 = point label.
			if ( preg_match( '/^(.+?):\s*(.+),\s*(.+?),\s*(\d{4,5})\s*$/u', $cp, $m ) ) {
				return array( $m[2], $m[1] );
			}
			return array( $cp, '' );
		}

		$street = isset( $address->street_name ) ? (string) $address->street_name : '';
		$number = isset( $address->street_number ) ? (string) $address->street_number : '';

		if ( 'yes' === get_option( 'bmsoi_merge_addresses', 'yes' ) ) {
			return array( trim( $street . ' ' . $number ), '' );
		}
		return array( $street, $number );
	}

	/**
	 * Payment method: the configured gateway (defaults to the virtual Skroutz gateway).
	 */
	private static function apply_payment( $order ) {
		$gateway_id = get_option( 'bmsoi_payment_gateway', BMSOI_Gateway::ID );
		$order->set_payment_method( $gateway_id );

		if ( isset( WC()->payment_gateways ) ) {
			foreach ( WC()->payment_gateways->payment_gateways() as $gateway ) {
				if ( $gateway && isset( $gateway->id ) && $gateway->id === $gateway_id ) {
					$order->set_payment_method_title( $gateway->get_method_title() );
					break;
				}
			}
		}
	}

	/**
	 * Store the Skroutz payload details as order meta (same keys as the
	 * WebExpert plugin, so ERP bridges keep working).
	 */
	private static function apply_meta( $order, $sc_order ) {
		$customer = isset( $sc_order->customer ) ? $sc_order->customer : new stdClass();
		$address  = isset( $customer->address ) ? $customer->address : new stdClass();

		$order->update_meta_data( 'skroutz_order_code', $sc_order->code );
		$order->update_meta_data( '_skroutz_customer_id', isset( $customer->id ) ? $customer->id : '' );
		$order->update_meta_data( '_skroutz_pickup_from_collection_point', isset( $address->pickup_from_collection_point ) ? $address->pickup_from_collection_point : '' );
		$order->update_meta_data( '_skroutz_collection_point_address', isset( $address->collection_point_address ) ? $address->collection_point_address : '' );
		$order->update_meta_data( '_skroutz_delivery_comments', isset( $address->comments ) ? $address->comments : '' );
		$order->update_meta_data( '_skroutz_courier', isset( $sc_order->courier ) ? $sc_order->courier : '' );
		$order->update_meta_data( '_skroutz_courier_voucher', isset( $sc_order->courier_voucher ) ? $sc_order->courier_voucher : '' );
		$order->update_meta_data( '_skroutz_courier_tracking_codes', isset( $sc_order->courier_tracking_codes ) ? $sc_order->courier_tracking_codes : '' );
		$order->update_meta_data( '_skroutz_express', isset( $sc_order->express ) ? $sc_order->express : '' );
		$order->update_meta_data( '_skroutz_gift_wrap', isset( $sc_order->gift_wrap ) ? $sc_order->gift_wrap : '' );
		$order->update_meta_data( '_skroutz_store_pickup', isset( $sc_order->store_pickup ) ? $sc_order->store_pickup : '' );
		$order->update_meta_data( '_skroutz_fulfilled', isset( $sc_order->fulfilled_by_skroutz ) ? $sc_order->fulfilled_by_skroutz : '' );
		$order->update_meta_data( '_skroutz_dispatch_until', isset( $sc_order->dispatch_until ) ? $sc_order->dispatch_until : '' );
		$order->update_meta_data( '_skroutz_commission', isset( $sc_order->commission ) ? $sc_order->commission : '' );
		$order->update_meta_data( '_skroutz_payment_method', isset( $sc_order->payment_method ) ? $sc_order->payment_method : '' );
		$order->update_meta_data( '_skroutz_plus_user', ! empty( $customer->skroutz_plus_user ) ? '1' : '' );
		$order->update_meta_data( '_skroutz_shipping_cost', isset( $sc_order->shipping_cost ) ? $sc_order->shipping_cost : '' );
		$order->update_meta_data( '_skroutz_fbs_delivery_note', isset( $sc_order->fbs_delivery_note ) ? $sc_order->fbs_delivery_note : '' );
		$order->update_meta_data( '_skroutz_fbs_delivery_note_url', isset( $sc_order->fbs_delivery_note_url ) ? $sc_order->fbs_delivery_note_url : '' );
		$order->update_meta_data( '_skroutz_expires_at', isset( $sc_order->expires_at ) ? $sc_order->expires_at : '' );
		$order->update_meta_data( '_skroutz_rejection_reason', isset( $sc_order->rejection_info->reason ) ? $sc_order->rejection_info->reason : '' );
		$order->update_meta_data( '_skroutz_rejection_actor', isset( $sc_order->rejection_info->actor ) ? $sc_order->rejection_info->actor : '' );

		$pickup_locations = isset( $sc_order->accept_options->pickup_location ) ? (array) $sc_order->accept_options->pickup_location : array();
		$first_pickup     = ! empty( $pickup_locations ) ? reset( $pickup_locations ) : null;
		$order->update_meta_data( '_skroutz_pickup_location_id', isset( $first_pickup->id ) ? $first_pickup->id : '' );
		$order->update_meta_data( '_skroutz_pickup_location_label', isset( $first_pickup->label ) ? $first_pickup->label : '' );

		if ( ! empty( $sc_order->invoice_details->vat_exclusion_requested ) ) {
			$order->update_meta_data( 'is_vat_exempt', 'yes' );
		}
	}

	/**
	 * Line items: resolve products (SKU or ID, variations included) and add them
	 * with VAT-aware amounts. Existing items (matched by Skroutz item_id) are kept.
	 */
	private static function apply_line_items( $order, $sc_order ) {
		if ( empty( $sc_order->line_items ) || ! is_array( $sc_order->line_items ) ) {
			return;
		}

		foreach ( $sc_order->line_items as $line_item ) {
			// Skip items that are already on the order (webhook updates re-send everything).
			foreach ( $order->get_items() as $existing ) {
				if ( isset( $line_item->id ) && (string) $existing->get_meta( 'item_id' ) === (string) $line_item->id ) {
					continue 2;
				}
			}

			$quantity = max( 1, (int) ( $line_item->quantity ?? 1 ) );
			$unit     = (float) ( $line_item->unit_price ?? 0 );
			$total    = (float) ( $line_item->total_price ?? ( $unit * $quantity ) );

			// The Skroutz payload carries gross prices; WooCommerce line totals are net.
			if ( wc_tax_enabled() && ! empty( $line_item->price_includes_vat ) && ! empty( $line_item->vat_ratio ) ) {
				$divisor = 1 + ( (float) $line_item->vat_ratio / 100 );
				$unit    = $unit / $divisor;
				$total   = $total / $divisor;
			}

			$args = array(
				'subtotal' => wc_format_decimal( $unit * $quantity, 4 ),
				'total'    => wc_format_decimal( $total, 4 ),
				'quantity' => $quantity,
			);

			$product = self::resolve_product( $line_item );

			if ( $product instanceof WC_Product ) {
				$item_id = $order->add_product( $product, $quantity, $args );
			} else {
				// Unknown product: still import the line so the order is complete.
				$item = new WC_Order_Item_Product();
				$item->set_name( isset( $line_item->product_name ) ? $line_item->product_name : ( $line_item->shop_uid ?? __( 'Άγνωστο προϊόν', 'bm-skroutz-order-import' ) ) );
				$item->set_quantity( $quantity );
				$item->set_subtotal( $args['subtotal'] );
				$item->set_total( $args['total'] );
				$item_id = $order->add_item( $item );
				$order->add_order_note( sprintf(
					/* translators: 1: shop uid, 2: product name */
					__( 'Δεν βρέθηκε προϊόν στο WooCommerce για το Skroutz uid «%1$s» (%2$s). Το είδος προστέθηκε χωρίς σύνδεση προϊόντος.', 'bm-skroutz-order-import' ),
					$line_item->shop_uid ?? '-',
					$line_item->product_name ?? '-'
				) );
			}

			$order_item = $order->get_item( $item_id );
			if ( $order_item instanceof WC_Order_Item_Product ) {
				if ( isset( $line_item->id ) ) {
					$order_item->update_meta_data( 'item_id', $line_item->id );
				}
				if ( ! empty( $line_item->extra_info ) ) {
					$order_item->update_meta_data( apply_filters( 'bmsoi_extra_info_meta_key', 'extra_info' ), $line_item->extra_info );
				}
				if ( ! empty( $line_item->size->value ) || ! empty( $line_item->size->shop_value ) ) {
					$order_item->update_meta_data( 'skroutz_size', ! empty( $line_item->size->shop_value ) ? $line_item->size->shop_value : $line_item->size->value );
				}
				if ( ! empty( $line_item->tags ) ) {
					$order_item->update_meta_data( 'skroutz_tags', implode( ', ', (array) $line_item->tags ) );
				}
				$order_item->save();
			}
		}
	}

	/**
	 * Find the WooCommerce product for a Skroutz line item.
	 *
	 * With unique id = "sku" the shop_variation_uid / shop_uid is matched against
	 * product SKUs (variation first). With "id" they are treated as product IDs.
	 *
	 * @return WC_Product|false
	 */
	public static function resolve_product( $line_item ) {
		$product = apply_filters( 'bmsoi_pre_resolve_product', null, $line_item );
		if ( $product instanceof WC_Product ) {
			return $product;
		}

		$mode = get_option( 'bmsoi_unique_id', 'sku' );
		$uids = array_filter( array(
			isset( $line_item->shop_variation_uid ) ? (string) $line_item->shop_variation_uid : '',
			isset( $line_item->shop_uid ) ? (string) $line_item->shop_uid : '',
		) );

		foreach ( $uids as $uid ) {
			if ( 'sku' === $mode ) {
				$product_id = wc_get_product_id_by_sku( $uid );
				$found      = $product_id ? wc_get_product( $product_id ) : false;
			} else {
				$found = wc_get_product( absint( $uid ) );
			}

			if ( $found instanceof WC_Product ) {
				// A variable parent without a resolved variation can't be added directly —
				// keep looking (the next uid may be the parent's variation) unless it's all we have.
				if ( $found->is_type( 'variable' ) ) {
					$variation = self::resolve_variation( $found, $line_item );
					if ( $variation instanceof WC_Product ) {
						return $variation;
					}
					continue;
				}
				return $found;
			}
		}

		return apply_filters( 'bmsoi_resolve_product_fallback', false, $line_item );
	}

	/**
	 * Try to find a matching variation of a variable product using the size
	 * attribute value from the payload and the configured variation attributes.
	 */
	private static function resolve_variation( $product, $line_item ) {
		$value = '';
		if ( ! empty( $line_item->size->shop_value ) ) {
			$value = $line_item->size->shop_value;
		} elseif ( ! empty( $line_item->size->value ) ) {
			$value = $line_item->size->value;
		}
		if ( '' === $value ) {
			return false;
		}

		$attributes = (array) get_option( 'bmsoi_variation_attributes', array() );
		foreach ( $attributes as $attribute ) {
			$term = get_term_by( 'name', $value, wc_attribute_taxonomy_name( $attribute ) );
			if ( $term && ! is_wp_error( $term ) ) {
				$variation_id = wc_find_matching_product_variation( $product, array( 'attribute_pa_' . $attribute => $term->slug ) );
				if ( $variation_id ) {
					return wc_get_product( $variation_id );
				}
			}
		}
		return false;
	}

	/**
	 * Invoice details (τιμολόγιο) — company, VAT number, tax office, profession.
	 */
	private static function apply_invoice_details( $order, $sc_order ) {
		$wants_invoice = isset( $sc_order->invoice ) && ( true === $sc_order->invoice || 'true' === $sc_order->invoice );

		if ( ! $wants_invoice || empty( $sc_order->invoice_details ) ) {
			$order->update_meta_data( apply_filters( 'bmsoi_invoice_toggle_meta_key', '_billing_invoice' ), 'n' );
			return;
		}

		$details = $sc_order->invoice_details;
		$order->update_meta_data( apply_filters( 'bmsoi_invoice_toggle_meta_key', '_billing_invoice' ), apply_filters( 'bmsoi_invoice_toggle_value', 'y' ) );
		$order->update_meta_data( apply_filters( 'bmsoi_billing_activity_meta_key', '_billing_activity' ), isset( $details->profession ) ? $details->profession : '' );
		$order->update_meta_data( apply_filters( 'bmsoi_billing_vat_id_meta_key', '_billing_vat_id' ), isset( $details->vat_number ) ? $details->vat_number : '' );
		$order->update_meta_data( apply_filters( 'bmsoi_billing_tax_office_meta_key', '_billing_tax_office' ), isset( $details->doy ) ? $details->doy : '' );
		$order->set_billing_company( isset( $details->company ) ? $details->company : '' );

		if ( ! empty( $details->address ) ) {
			list( $address_1, $address_2 ) = self::format_address( $details->address );
			$order->set_billing_address_1( $address_1 );
			$order->set_billing_address_2( $address_2 );
			$order->set_billing_city( isset( $details->address->city ) ? $details->address->city : '' );
			$order->set_billing_postcode( isset( $details->address->zip ) ? $details->address->zip : '' );
		}
	}

	/**
	 * Shipping line: the configured method with the payload's shipping cost.
	 */
	private static function apply_shipping( $order, $sc_order ) {
		if ( count( $order->get_items( 'shipping' ) ) > 0 ) {
			return;
		}

		$item       = new WC_Order_Item_Shipping();
		$configured = (string) get_option( 'bmsoi_shipping_method', '' );
		$title      = __( 'Αποστολή Skroutz', 'bm-skroutz-order-import' );

		if ( '' !== $configured ) {
			$parts = explode( ':', $configured );
			$item->set_method_id( $parts[0] );
			if ( isset( $parts[1] ) ) {
				$item->set_instance_id( (int) $parts[1] );
				$method = WC_Shipping_Zones::get_shipping_method( (int) $parts[1] );
				if ( $method ) {
					$title = $method->get_title();
				}
			}
		} else {
			$item->set_method_id( 'flat_rate' );
		}

		$item->set_method_title( $title );

		$cost = isset( $sc_order->shipping_cost ) ? (float) $sc_order->shipping_cost : 0;
		if ( $cost > 0 ) {
			$item->set_total( $cost );
		}

		$order->add_item( $item );
	}
}
