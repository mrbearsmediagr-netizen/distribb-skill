<?php
/**
 * Periodic import through the Skroutz Merchant API (no webhook required).
 *
 * Every few minutes the poller:
 *  1. pulls the open orders from the API and imports any that are missing, and
 *  2. re-fetches recent local Skroutz orders that are still in a non-final
 *     status, so cancellations / dispatches at Skroutz are mirrored locally.
 *
 * @package BM_Skroutz_Order_Import
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMSOI_Poller {

	const CRON_HOOK = 'bmsoi_poll_orders';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_schedule' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
	}

	public static function register_schedule( $schedules ) {
		$minutes = max( 5, (int) get_option( 'bmsoi_polling_interval', 10 ) );
		$schedules['bmsoi_interval'] = array(
			'interval' => $minutes * MINUTE_IN_SECONDS,
			/* translators: %d: minutes */
			'display'  => sprintf( __( 'Κάθε %d λεπτά (BM Skroutz)', 'bm-skroutz-order-import' ), $minutes ),
		);
		return $schedules;
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'bmsoi_interval', self::CRON_HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/** Re-arm the schedule if it went missing (e.g. after an update). */
	public static function maybe_schedule() {
		if ( 'yes' === get_option( 'bmsoi_polling_enabled', 'yes' ) ) {
			self::schedule();
		} else {
			self::unschedule();
		}
	}

	/**
	 * One polling pass. Returns a summary array (used by the "sync now" button).
	 */
	public static function run() {
		$summary = array( 'imported' => 0, 'updated' => 0, 'errors' => 0 );

		if ( 'yes' !== get_option( 'bmsoi_polling_enabled', 'yes' ) || ! BMSOI_API::is_configured() ) {
			return $summary;
		}

		// 1. New (open) orders from the API.
		$response = BMSOI_API::list_orders( array( 'state' => 'open', 'per' => 25 ) );
		if ( ! is_wp_error( $response ) && ! empty( $response->orders ) && is_array( $response->orders ) ) {
			foreach ( $response->orders as $sc_order ) {
				if ( empty( $sc_order->code ) ) {
					continue;
				}
				// A listing row may be a summary — fetch the full order before importing.
				$full = self::full_order( $sc_order );
				if ( ! $full ) {
					$summary['errors']++;
					continue;
				}
				$result = BMSOI_Importer::import( $full, false );
				if ( is_wp_error( $result ) ) {
					$summary['errors']++;
				} elseif ( $result ) {
					$summary['imported']++;
				}
			}
		} elseif ( is_wp_error( $response ) ) {
			$summary['errors']++;
		}

		// 2. Refresh local orders that are still in-flight.
		$orders = wc_get_orders( array(
			'limit'   => 50,
			'status'  => array( 'wc-on-hold', 'wc-processing' ),
			'orderby' => 'date',
			'order'   => 'DESC',
			'return'  => 'objects',
		) );

		foreach ( $orders as $order ) {
			if ( ! bmsoi_is_skroutz_order( $order ) ) {
				continue;
			}
			$code     = bmsoi_order_code( $order );
			$response = BMSOI_API::get_order( $code );
			if ( is_wp_error( $response ) || empty( $response->order ) ) {
				continue;
			}
			$result = BMSOI_Importer::import( $response->order, true );
			if ( ! is_wp_error( $result ) && $result ) {
				$summary['updated']++;
			}
		}

		bmsoi_log( sprintf( 'Poll finished: %d imported, %d updated, %d errors.', $summary['imported'], $summary['updated'], $summary['errors'] ) );

		return $summary;
	}

	/**
	 * Make sure we hold a full order payload (with line items).
	 */
	private static function full_order( $sc_order ) {
		if ( ! empty( $sc_order->line_items ) ) {
			return $sc_order;
		}
		$response = BMSOI_API::get_order( $sc_order->code );
		if ( is_wp_error( $response ) || empty( $response->order ) ) {
			return null;
		}
		return $response->order;
	}
}
