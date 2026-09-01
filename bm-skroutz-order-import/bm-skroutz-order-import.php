<?php
/**
 * Plugin Name:       BM Skroutz Order Import for WooCommerce
 * Plugin URI:        https://github.com/mrbearsmediagr-netizen/distribb-skill
 * Description:       Εισάγει αυτόματα τις παραγγελίες του Skroutz Marketplace στο WooCommerce μέσω του Skroutz Merchant API (webhook + περιοδικό polling), με δυνατότητα αποδοχής/απόρριψης παραγγελιών.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            BEARSMEDIA
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bm-skroutz-order-import
 * WC requires at least: 6.0
 * WC tested up to:   10.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BMSOI_VERSION', '1.1.0' );
define( 'BMSOI_PLUGIN_FILE', __FILE__ );
define( 'BMSOI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BMSOI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// HPOS (High-Performance Order Storage) compatibility.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

require_once BMSOI_PLUGIN_DIR . 'includes/class-bmsoi-api.php';
require_once BMSOI_PLUGIN_DIR . 'includes/class-bmsoi-importer.php';
require_once BMSOI_PLUGIN_DIR . 'includes/class-bmsoi-webhook.php';
require_once BMSOI_PLUGIN_DIR . 'includes/class-bmsoi-poller.php';
require_once BMSOI_PLUGIN_DIR . 'includes/class-bmsoi-actions.php';
require_once BMSOI_PLUGIN_DIR . 'includes/class-bmsoi-admin.php';
require_once BMSOI_PLUGIN_DIR . 'includes/class-bmsoi-gateway.php';

/**
 * Bootstrap — everything hooks in only when WooCommerce is active.
 */
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'Το BM Skroutz Order Import απαιτεί ενεργό WooCommerce.', 'bm-skroutz-order-import' ) .
				'</p></div>';
		} );
		return;
	}

	BMSOI_Webhook::init();
	BMSOI_Poller::init();
	BMSOI_Actions::init();
	BMSOI_Admin::init();
	BMSOI_Gateway::init();
	bmsoi_register_order_hooks();
} );

/**
 * Is this WooCommerce order a Skroutz Marketplace order (ours or from the
 * WebExpert Smart Cart plugin, so migrated shops keep working)?
 */
function bmsoi_is_skroutz_order( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return false;
	}
	if ( in_array( $order->get_created_via(), array( 'skroutz_api_import', 'skroutz_smart_cart' ), true ) ) {
		return true;
	}
	$key = (string) $order->get_order_key();
	return 0 === strpos( $key, 'SKZ-' ) || 0 === strpos( $key, 'SC-' );
}

/**
 * Skroutz order code (e.g. ABCD12-EFGH34) from a WooCommerce order.
 */
function bmsoi_order_code( $order ) {
	return preg_replace( '/^(SKZ-|SC-)/', '', (string) $order->get_order_key() );
}

/**
 * Import-start cutoff (unix timestamp). Skroutz orders created before this
 * moment are never imported automatically — only from the activation of the
 * plugin onwards. 0 = no cutoff. Initialised lazily so upgrades without
 * re-activation are covered too.
 */
function bmsoi_import_since() {
	$since = get_option( 'bmsoi_import_since', false );
	if ( false === $since ) {
		$since = time();
		update_option( 'bmsoi_import_since', $since );
	}
	return (int) $since;
}

/**
 * Debug logger (WooCommerce > Status > Logs, source: bm-skroutz).
 */
function bmsoi_log( $message, $level = 'info' ) {
	if ( 'yes' !== get_option( 'bmsoi_debug', 'yes' ) || ! function_exists( 'wc_get_logger' ) ) {
		return;
	}
	wc_get_logger()->log( $level, is_scalar( $message ) ? $message : wc_print_r( $message, true ), array( 'source' => 'bm-skroutz' ) );
}

/**
 * Order-level hooks: custom status, e-mail suppression, FBS stock handling.
 */
function bmsoi_register_order_hooks() {

	// "Προς επιστροφή" order status (used for post-dispatch cancellations/returns).
	add_filter( 'woocommerce_register_shop_order_post_statuses', function ( $statuses ) {
		$statuses['wc-for-return'] = array(
			'label'                     => _x( 'Προς επιστροφή', 'Order status', 'bm-skroutz-order-import' ),
			'public'                    => false,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: number of orders */
			'label_count'               => _n_noop( 'Προς επιστροφή <span class="count">(%s)</span>', 'Προς επιστροφή <span class="count">(%s)</span>', 'bm-skroutz-order-import' ),
		);
		return $statuses;
	} );

	add_filter( 'wc_order_statuses', function ( $statuses ) {
		if ( ! isset( $statuses['wc-for-return'] ) ) {
			$statuses['wc-for-return'] = _x( 'Προς επιστροφή', 'Order status', 'bm-skroutz-order-import' );
		}
		return $statuses;
	} );

	// Don't reduce stock for orders fulfilled by Skroutz (FBS) — the stock lives in their warehouse.
	add_filter( 'woocommerce_can_reduce_order_stock', function ( $reduce, $order ) {
		if ( bmsoi_is_skroutz_order( $order ) && '' !== (string) $order->get_meta( '_skroutz_fulfilled' ) && $order->get_meta( '_skroutz_fulfilled' ) ) {
			$reduce = false;
		}
		return $reduce;
	}, 10, 2 );

	// Skroutz customers use anonymised addresses — never e-mail them from the shop.
	if ( 'yes' === get_option( 'bmsoi_disable_emails', 'yes' ) ) {
		$suppress = function ( $recipient, $order = null ) {
			if ( $order instanceof WC_Order && bmsoi_is_skroutz_order( $order ) ) {
				return '';
			}
			return $recipient;
		};
		add_filter( 'woocommerce_email_recipient_customer_on_hold_order', $suppress, 9999, 2 );
		add_filter( 'woocommerce_email_recipient_customer_processing_order', $suppress, 9999, 2 );
		add_filter( 'woocommerce_email_recipient_customer_completed_order', $suppress, 9999, 2 );
		add_filter( 'woocommerce_email_recipient_customer_refunded_order', $suppress, 9999, 2 );
		add_filter( 'woocommerce_email_recipient_customer_invoice', $suppress, 9999, 2 );

		// Safety net: strip @skroutz.gr recipients from any outgoing mail.
		add_filter( 'wp_mail', function ( $args ) {
			$strip = function ( $email ) {
				return 'skroutz.gr' !== substr( strrchr( (string) $email, '@' ), 1 );
			};
			if ( is_array( $args['to'] ) ) {
				$args['to'] = array_filter( $args['to'], $strip );
			} elseif ( ! $strip( $args['to'] ) ) {
				$args['to'] = '';
			}
			return $args;
		} );
	}
}

/**
 * Activation: webhook secret, cron schedule.
 */
register_activation_hook( __FILE__, function () {
	if ( ! get_option( 'bmsoi_webhook_secret' ) ) {
		update_option( 'bmsoi_webhook_secret', wp_generate_password( 24, false, false ) );
	}
	// Only import orders created from this moment on.
	if ( false === get_option( 'bmsoi_import_since', false ) ) {
		update_option( 'bmsoi_import_since', time() );
	}
	// The plugin is included mid-request on activation, so the custom cron
	// interval isn't registered yet — add it before scheduling.
	add_filter( 'cron_schedules', array( 'BMSOI_Poller', 'register_schedule' ) );
	BMSOI_Poller::schedule();
} );

register_deactivation_hook( __FILE__, function () {
	BMSOI_Poller::unschedule();
} );

// Settings shortcut on the Plugins screen.
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	array_unshift(
		$links,
		'<a href="' . esc_url( admin_url( 'admin.php?page=bm-skroutz-order-import' ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'bm-skroutz-order-import' ) . '</a>'
	);
	return $links;
} );
