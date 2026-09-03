<?php
/**
 * Admin: settings page, order metabox, AJAX handlers.
 *
 * @package BM_Skroutz_Order_Import
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMSOI_Admin {

	const PAGE_SLUG = 'bm-skroutz-order-import';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_metabox' ), 10, 2 );

		// Show a Skroutz marker right before the buyer name on the orders list.
		// Uses WooCommerce's own buyer-name filter, so it renders server-side
		// on BOTH the legacy posts table and the HPOS table — no JS, no caches.
		add_filter( 'woocommerce_admin_order_buyer_name', array( __CLASS__, 'prefix_buyer_name' ), 10, 2 );

		add_action( 'admin_post_bmsoi_sync_now', array( __CLASS__, 'handle_sync_now' ) );
		add_action( 'wp_ajax_bmsoi_fetch_order', array( __CLASS__, 'ajax_fetch_order' ) );
		add_action( 'wp_ajax_bmsoi_accept_order', array( __CLASS__, 'ajax_accept_order' ) );
		add_action( 'wp_ajax_bmsoi_reject_order', array( __CLASS__, 'ajax_reject_order' ) );
	}

	public static function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Skroutz Order Import', 'bm-skroutz-order-import' ),
			__( 'Skroutz Import', 'bm-skroutz-order-import' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function register_settings() {
		$settings = array(
			'bmsoi_api_token',
			'bmsoi_webhook_secret',
			'bmsoi_unique_id',
			'bmsoi_variation_attributes',
			'bmsoi_payment_gateway',
			'bmsoi_shipping_method',
			'bmsoi_customer_user',
			'bmsoi_billing_email',
			'bmsoi_name_prefix',
			'bmsoi_merge_addresses',
			'bmsoi_manage_orders',
			'bmsoi_auto_accept',
			'bmsoi_pickup_window',
			'bmsoi_polling_enabled',
			'bmsoi_polling_interval',
			'bmsoi_disable_emails',
			'bmsoi_debug',
			'bmsoi_marketplace_icon',
			'bmsoi_list_marker',
		);
		foreach ( $settings as $setting ) {
			register_setting( 'bmsoi_settings', $setting );
		}

		register_setting( 'bmsoi_settings', 'bmsoi_import_since', array(
			'sanitize_callback' => array( __CLASS__, 'sanitize_import_since' ),
		) );
	}

	/**
	 * The "import from" field posts a datetime-local value in the site's
	 * timezone; store it as a unix timestamp. Empty = no cutoff.
	 */
	public static function sanitize_import_since( $value ) {
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 0;
		}
		$datetime = date_create_immutable( $value, wp_timezone() );
		return $datetime ? $datetime->getTimestamp() : (int) get_option( 'bmsoi_import_since', 0 );
	}

	public static function enqueue_assets( $hook ) {
		$screen    = get_current_screen();
		$is_order  = $screen && in_array( $screen->id, array( 'shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders' ), true );
		$is_plugin = false !== strpos( (string) $hook, self::PAGE_SLUG );

		if ( ! $is_order && ! $is_plugin ) {
			return;
		}

		wp_enqueue_style( 'bmsoi-admin', BMSOI_PLUGIN_URL . 'admin/css/bmsoi-admin.css', array(), BMSOI_VERSION );
		wp_enqueue_script( 'bmsoi-admin', BMSOI_PLUGIN_URL . 'admin/js/bmsoi-admin.js', array( 'jquery' ), BMSOI_VERSION, true );
		wp_localize_script( 'bmsoi-admin', 'bmsoi', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'bmsoi_ajax' ),
			'iconUrl' => self::icon_url(),
			'i18n'    => array(
				'working'      => __( 'Παρακαλώ περιμένετε…', 'bm-skroutz-order-import' ),
				'failed'       => __( 'Η ενέργεια απέτυχε.', 'bm-skroutz-order-import' ),
				'imported'     => __( 'Η παραγγελία ενημερώθηκε. Άνοιγμα σε νέα καρτέλα;', 'bm-skroutz-order-import' ),
				'copied'       => __( 'Αντιγράφηκε!', 'bm-skroutz-order-import' ),
				'confirmReject' => __( 'Σίγουρα θέλετε να απορρίψετε την παραγγελία στο Skroutz;', 'bm-skroutz-order-import' ),
				'skroutzOrder' => __( 'Παραγγελία Skroutz Marketplace', 'bm-skroutz-order-import' ),
				'express'      => __( 'Express', 'bm-skroutz-order-import' ),
				'fbs'          => __( 'Fulfilled by Skroutz', 'bm-skroutz-order-import' ),
			),
		) );
	}

	/* ---------------------------------------------------------------------
	 * Orders list: Skroutz marker right before the buyer name
	 * ------------------------------------------------------------------- */

	/**
	 * URL of the Skroutz badge icon (used in the order metabox / settings).
	 * Configurable; falls back to the bundled SVG.
	 */
	public static function icon_url() {
		$url = trim( (string) get_option( 'bmsoi_marketplace_icon', 'https://masiou.com/wp-content/uploads/2026/09/skroutz-icon.png' ) );
		if ( '' === $url ) {
			$url = BMSOI_PLUGIN_URL . 'admin/img/skroutz-icon.svg';
		}
		return apply_filters( 'bmsoi_marketplace_icon_url', $url );
	}

	/**
	 * Prepend a marker (default 🟠) to the buyer name of Skroutz orders on the
	 * orders list. WooCommerce escapes the buyer name as plain text, so the
	 * marker is an emoji/text — which is exactly what renders reliably on both
	 * the legacy and HPOS tables without any JavaScript.
	 *
	 * @param string   $buyer Buyer name.
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function prefix_buyer_name( $buyer, $order ) {
		if ( $order instanceof WC_Order && bmsoi_is_skroutz_order( $order ) ) {
			$marker = trim( (string) get_option( 'bmsoi_list_marker', '🟠' ) );
			if ( '' !== $marker ) {
				$buyer = $marker . ' ' . $buyer;
			}
		}
		return $buyer;
	}

	/* ---------------------------------------------------------------------
	 * Settings page
	 * ------------------------------------------------------------------- */

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$gateways = WC()->payment_gateways ? WC()->payment_gateways->payment_gateways() : array();
		$methods  = self::shipping_method_choices();
		$attrs    = wc_get_attribute_taxonomies();
		$selected_attrs = (array) get_option( 'bmsoi_variation_attributes', array() );
		?>
		<div class="wrap bmsoi-wrap">
			<h1><?php esc_html_e( 'Skroutz Order Import', 'bm-skroutz-order-import' ); ?></h1>

			<?php if ( isset( $_GET['bmsoi_synced'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					printf(
						/* translators: 1: imported count, 2: updated count, 3: error count */
						esc_html__( 'Ο συγχρονισμός ολοκληρώθηκε: %1$d νέες, %2$d ενημερώθηκαν, %3$d σφάλματα.', 'bm-skroutz-order-import' ),
						(int) ( $_GET['imported'] ?? 0 ),
						(int) ( $_GET['updated'] ?? 0 ),
						(int) ( $_GET['errors'] ?? 0 )
					);
					?>
				</p></div>
			<?php endif; ?>

			<div class="bmsoi-section">
				<h2><?php esc_html_e( 'Webhook', 'bm-skroutz-order-import' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="bmsoi_webhook_url"><?php esc_html_e( 'Webhook URL', 'bm-skroutz-order-import' ); ?></label></th>
						<td>
							<div class="bmsoi-inline">
								<input type="text" readonly id="bmsoi_webhook_url" class="regular-text code" value="<?php echo esc_attr( BMSOI_Webhook::url() ); ?>">
								<button type="button" class="button" id="bmsoi_copy_webhook"><?php esc_html_e( 'Αντιγραφή', 'bm-skroutz-order-import' ); ?></button>
							</div>
							<p class="description"><?php esc_html_e( 'Καταχωρήστε αυτό το URL στο merchant panel του Skroutz (Merchants > Services > Skroutz Marketplace) για να λαμβάνετε τις παραγγελίες σε πραγματικό χρόνο. Ακόμα και χωρίς webhook, το plugin εισάγει τις παραγγελίες μέσω API polling.', 'bm-skroutz-order-import' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Άμεσος συγχρονισμός', 'bm-skroutz-order-import' ); ?></th>
						<td>
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bmsoi_sync_now' ), 'bmsoi_sync_now' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Συγχρονισμός τώρα', 'bm-skroutz-order-import' ); ?></a>
							<?php $next = wp_next_scheduled( BMSOI_Poller::CRON_HOOK ); ?>
							<?php if ( $next ) : ?>
								<p class="description"><?php printf( esc_html__( 'Επόμενος προγραμματισμένος συγχρονισμός: %s', 'bm-skroutz-order-import' ), esc_html( wp_date( 'd/m/Y H:i:s', $next ) ) ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bmsoi_fetch_code"><?php esc_html_e( 'Εισαγωγή παραγγελίας', 'bm-skroutz-order-import' ); ?></label></th>
						<td>
							<div class="bmsoi-inline">
								<input type="text" id="bmsoi_fetch_code" placeholder="XXXXXX-XXXXXX" class="regular-text code">
								<button type="button" class="button" id="bmsoi_fetch_order"><?php esc_html_e( 'Εισαγωγή', 'bm-skroutz-order-import' ); ?></button>
							</div>
							<p class="description"><?php esc_html_e( 'Εισάγετε/ενημερώστε χειροκίνητα μια παραγγελία με τον κωδικό της από το Skroutz.', 'bm-skroutz-order-import' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'bmsoi_settings' ); ?>

				<div class="bmsoi-section">
					<h2><?php esc_html_e( 'Σύνδεση API', 'bm-skroutz-order-import' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="bmsoi_api_token"><?php esc_html_e( 'Skroutz API token', 'bm-skroutz-order-import' ); ?></label></th>
							<td>
								<input type="password" id="bmsoi_api_token" name="bmsoi_api_token" class="regular-text" value="<?php echo esc_attr( get_option( 'bmsoi_api_token', '' ) ); ?>" autocomplete="off">
								<p class="description"><?php esc_html_e( 'Το API token από το merchant panel του Skroutz.', 'bm-skroutz-order-import' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bmsoi_webhook_secret"><?php esc_html_e( 'Webhook secret', 'bm-skroutz-order-import' ); ?></label></th>
							<td>
								<input type="text" id="bmsoi_webhook_secret" name="bmsoi_webhook_secret" class="regular-text code" value="<?php echo esc_attr( get_option( 'bmsoi_webhook_secret', '' ) ); ?>">
								<p class="description"><?php esc_html_e( 'Προστατεύει το webhook endpoint. Αν το αλλάξετε, ενημερώστε και το URL στο Skroutz. Κενό = χωρίς έλεγχο.', 'bm-skroutz-order-import' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bmsoi_import_since"><?php esc_html_e( 'Εισαγωγή παραγγελιών από', 'bm-skroutz-order-import' ); ?></label></th>
							<td>
								<?php $since = (int) get_option( 'bmsoi_import_since', 0 ); ?>
								<input type="datetime-local" id="bmsoi_import_since" name="bmsoi_import_since" value="<?php echo esc_attr( $since ? wp_date( 'Y-m-d\TH:i', $since ) : '' ); ?>">
								<p class="description"><?php esc_html_e( 'Παραγγελίες Skroutz που δημιουργήθηκαν ΠΡΙΝ από αυτή τη στιγμή δεν εισάγονται αυτόματα (ούτε από webhook ούτε από polling) — μόνο οι νεότερες. Ορίζεται αυτόματα στην ενεργοποίηση του πρόσθετου. Η χειροκίνητη εισαγωγή με κωδικό παραγγελίας τις φέρνει κανονικά. Κενό = χωρίς όριο (εισαγωγή και παλαιότερων).', 'bm-skroutz-order-import' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Polling μέσω API', 'bm-skroutz-order-import' ); ?></th>
							<td>
								<label><input type="checkbox" name="bmsoi_polling_enabled" value="yes" <?php checked( get_option( 'bmsoi_polling_enabled', 'yes' ), 'yes' ); ?>> <?php esc_html_e( 'Περιοδική εισαγωγή/ενημέρωση παραγγελιών μέσω API', 'bm-skroutz-order-import' ); ?></label>
								<p class="description">
									<label><?php esc_html_e( 'Κάθε', 'bm-skroutz-order-import' ); ?>
										<input type="number" min="5" max="120" step="1" name="bmsoi_polling_interval" value="<?php echo esc_attr( max( 5, (int) get_option( 'bmsoi_polling_interval', 10 ) ) ); ?>" class="small-text">
										<?php esc_html_e( 'λεπτά (ελάχιστο 5).', 'bm-skroutz-order-import' ); ?></label>
								</p>
							</td>
						</tr>
					</table>
				</div>

				<div class="bmsoi-section">
					<h2><?php esc_html_e( 'Αντιστοίχιση παραγγελιών', 'bm-skroutz-order-import' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="bmsoi_unique_id"><?php esc_html_e( 'Μοναδικό αναγνωριστικό προϊόντος', 'bm-skroutz-order-import' ); ?></label></th>
							<td>
								<select id="bmsoi_unique_id" name="bmsoi_unique_id">
									<option value="sku" <?php selected( get_option( 'bmsoi_unique_id', 'sku' ), 'sku' ); ?>><?php esc_html_e( 'SKU', 'bm-skroutz-order-import' ); ?></option>
									<option value="id" <?php selected( get_option( 'bmsoi_unique_id', 'sku' ), 'id' ); ?>><?php esc_html_e( 'ID προϊόντος', 'bm-skroutz-order-import' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Πώς αντιστοιχίζεται το shop_uid του Skroutz XML με τα προϊόντα του WooCommerce.', 'bm-skroutz-order-import' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bmsoi_variation_attributes"><?php esc_html_e( 'Χαρακτηριστικά παραλλαγών', 'bm-skroutz-order-import' ); ?></label></th>
							<td>
								<select id="bmsoi_variation_attributes" name="bmsoi_variation_attributes[]" multiple size="4" style="min-width:220px">
									<?php foreach ( $attrs as $attr ) : ?>
										<option value="<?php echo esc_attr( $attr->attribute_name ); ?>" <?php echo in_array( $attr->attribute_name, $selected_attrs, true ) ? 'selected' : ''; ?>><?php echo esc_html( $attr->attribute_label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Χρησιμοποιούνται για την εύρεση της σωστής παραλλαγής όταν το Skroutz στέλνει μέγεθος/χρώμα.', 'bm-skroutz-order-import' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bmsoi_payment_gateway"><?php esc_html_e( 'Τρόπος πληρωμής', 'bm-skroutz-order-import' ); ?></label></th>
							<td>
								<select id="bmsoi_payment_gateway" name="bmsoi_payment_gateway">
									<?php foreach ( $gateways as $gateway ) : ?>
										<option value="<?php echo esc_attr( $gateway->id ); ?>" <?php selected( get_option( 'bmsoi_payment_gateway', BMSOI_Gateway::ID ), $gateway->id ); ?>><?php echo esc_html( $gateway->get_method_title() ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Ο τρόπος πληρωμής που θα αποδίδεται στις παραγγελίες Skroutz (χρήσιμο για γέφυρες ERP).', 'bm-skroutz-order-import' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bmsoi_shipping_method"><?php esc_html_e( 'Τρόπος αποστολής', 'bm-skroutz-order-import' ); ?></label></th>
							<td>
								<select id="bmsoi_shipping_method" name="bmsoi_shipping_method">
									<option value=""><?php esc_html_e( '— Γενικός (Αποστολή Skroutz) —', 'bm-skroutz-order-import' ); ?></option>
									<?php foreach ( $methods as $key => $label ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( get_option( 'bmsoi_shipping_method', '' ), $key ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bmsoi_customer_user"><?php esc_html_e( 'Χρήστης παραγγελιών', 'bm-skroutz-order-import' ); ?></label></th>
							<td>
								<input type="number" min="0" id="bmsoi_customer_user" name="bmsoi_customer_user" class="small-text" value="<?php echo esc_attr( (int) get_option( 'bmsoi_customer_user', 0 ) ); ?>">
								<p class="description"><?php esc_html_e( 'ID χρήστη WordPress κάτω από τον οποίο θα καταχωρούνται οι παραγγελίες. 0 = επισκέπτης (guest).', 'bm-skroutz-order-import' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bmsoi_billing_email"><?php esc_html_e( 'Email χρέωσης', 'bm-skroutz-order-import' ); ?></label></th>
							<td>
								<input type="text" id="bmsoi_billing_email" name="bmsoi_billing_email" class="regular-text" value="<?php echo esc_attr( get_option( 'bmsoi_billing_email', '' ) ); ?>">
								<p class="description"><?php esc_html_e( 'Σταθερό email για όλες τις παραγγελίες Skroutz. Κενό = XXXX@skroutz.gr ανά πελάτη.', 'bm-skroutz-order-import' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bmsoi_name_prefix"><?php esc_html_e( 'Πρόθεμα ονόματος', 'bm-skroutz-order-import' ); ?></label></th>
							<td>
								<input type="text" id="bmsoi_name_prefix" name="bmsoi_name_prefix" class="small-text" style="width:90px" value="<?php echo esc_attr( get_option( 'bmsoi_name_prefix', 'SKR' ) ); ?>">
								<p class="description"><?php esc_html_e( 'Προστίθεται μπροστά από το όνομα χρέωσης του πελάτη (π.χ. «SKR Κατερίνα Π.»), ώστε οι παραγγελίες Skroutz να ξεχωρίζουν παντού — και στο WooCommerce mobile app. Το όνομα παραλήπτη (αποστολή) μένει καθαρό για τα vouchers των courier. Κενό = απενεργοποίηση.', 'bm-skroutz-order-import' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Διεύθυνση', 'bm-skroutz-order-import' ); ?></th>
							<td>
								<label><input type="checkbox" name="bmsoi_merge_addresses" value="yes" <?php checked( get_option( 'bmsoi_merge_addresses', 'yes' ), 'yes' ); ?>> <?php esc_html_e( 'Συγχώνευση οδού και αριθμού στο πεδίο «Διεύθυνση 1»', 'bm-skroutz-order-import' ); ?></label>
							</td>
						</tr>
					</table>
				</div>

				<div class="bmsoi-section">
					<h2><?php esc_html_e( 'Διαχείριση παραγγελιών', 'bm-skroutz-order-import' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Αποδοχή μέσω αλλαγής κατάστασης', 'bm-skroutz-order-import' ); ?></th>
							<td>
								<label><input type="checkbox" name="bmsoi_manage_orders" value="yes" <?php checked( get_option( 'bmsoi_manage_orders', 'no' ), 'yes' ); ?>> <?php esc_html_e( 'Η αλλαγή από «Σε αναμονή» σε «Σε επεξεργασία» αποδέχεται την παραγγελία στο Skroutz', 'bm-skroutz-order-import' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Αυτόματη αποδοχή', 'bm-skroutz-order-import' ); ?></th>
							<td>
								<label><input type="checkbox" name="bmsoi_auto_accept" value="yes" <?php checked( get_option( 'bmsoi_auto_accept', 'no' ), 'yes' ); ?>> <?php esc_html_e( 'Αυτόματη αποδοχή κάθε νέας παραγγελίας Skroutz', 'bm-skroutz-order-import' ); ?></label>
								<p class="description">
									<select name="bmsoi_pickup_window">
										<option value="first_pickup_window" <?php selected( get_option( 'bmsoi_pickup_window', 'first_pickup_window' ), 'first_pickup_window' ); ?>><?php esc_html_e( 'Πρώτο διαθέσιμο παράθυρο παραλαβής', 'bm-skroutz-order-import' ); ?></option>
										<option value="last_pickup_window" <?php selected( get_option( 'bmsoi_pickup_window', 'first_pickup_window' ), 'last_pickup_window' ); ?>><?php esc_html_e( 'Τελευταίο διαθέσιμο παράθυρο παραλαβής', 'bm-skroutz-order-import' ); ?></option>
									</select>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Emails', 'bm-skroutz-order-import' ); ?></th>
							<td>
								<label><input type="checkbox" name="bmsoi_disable_emails" value="yes" <?php checked( get_option( 'bmsoi_disable_emails', 'yes' ), 'yes' ); ?>> <?php esc_html_e( 'Να μην αποστέλλονται emails WooCommerce στους πελάτες Skroutz', 'bm-skroutz-order-import' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bmsoi_list_marker"><?php esc_html_e( 'Δείκτης λίστας', 'bm-skroutz-order-import' ); ?></label></th>
							<td>
								<input type="text" id="bmsoi_list_marker" name="bmsoi_list_marker" class="small-text" style="width:90px" value="<?php echo esc_attr( get_option( 'bmsoi_list_marker', '🟠' ) ); ?>">
								<p class="description"><?php esc_html_e( 'Μπαίνει ακριβώς πριν το όνομα του πελάτη στη λίστα παραγγελιών (π.χ. «🟠 SKR Κατερίνα»). Εμφανίζεται πάντα, χωρίς εξάρτηση από JavaScript. Χρησιμοποιήστε ένα emoji. Κενό = απενεργοποίηση.', 'bm-skroutz-order-import' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bmsoi_marketplace_icon"><?php esc_html_e( 'Εικονίδιο Skroutz', 'bm-skroutz-order-import' ); ?></label></th>
							<td>
								<div class="bmsoi-inline">
									<img src="<?php echo esc_url( self::icon_url() ); ?>" alt="Skroutz" width="24" height="24" class="bmsoi-col-icon">
									<input type="url" id="bmsoi_marketplace_icon" name="bmsoi_marketplace_icon" class="regular-text code" value="<?php echo esc_attr( get_option( 'bmsoi_marketplace_icon', 'https://masiou.com/wp-content/uploads/2026/09/skroutz-icon.png' ) ); ?>">
								</div>
								<p class="description"><?php esc_html_e( 'Το εικονίδιο που εμφανίζεται στην καρτέλα (metabox) της παραγγελίας Skroutz. Κενό = ενσωματωμένο εικονίδιο του πρόσθετου.', 'bm-skroutz-order-import' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Καταγραφή (log)', 'bm-skroutz-order-import' ); ?></th>
							<td>
								<label><input type="checkbox" name="bmsoi_debug" value="yes" <?php checked( get_option( 'bmsoi_debug', 'yes' ), 'yes' ); ?>> <?php esc_html_e( 'Καταγραφή αιτημάτων στο WooCommerce > Κατάσταση > Αρχεία καταγραφής (πηγή: bm-skroutz)', 'bm-skroutz-order-import' ); ?></label>
							</td>
						</tr>
					</table>
				</div>

				<?php submit_button( __( 'Αποθήκευση ρυθμίσεων', 'bm-skroutz-order-import' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * All shipping methods across zones as "method_id:instance_id" => title.
	 */
	private static function shipping_method_choices() {
		$choices = array();

		$collect = function ( $zone ) use ( &$choices ) {
			foreach ( $zone->get_shipping_methods() as $method ) {
				$key = $method->id . ( $method->get_instance_id() ? ':' . $method->get_instance_id() : '' );
				$choices[ $key ] = $zone->get_zone_name() . ' — ' . $method->get_title();
			}
		};

		$collect( new WC_Shipping_Zone( 0 ) );
		foreach ( WC_Shipping_Zones::get_zones() as $zone_data ) {
			$collect( new WC_Shipping_Zone( $zone_data['id'] ) );
		}

		return $choices;
	}

	/* ---------------------------------------------------------------------
	 * Order metabox
	 * ------------------------------------------------------------------- */

	public static function register_metabox( $post_type, $post ) {
		if ( ! in_array( $post_type, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
			return;
		}

		$order = ( $post instanceof WP_Post ) ? wc_get_order( $post->ID ) : $post;
		if ( ! $order instanceof WC_Order || ! bmsoi_is_skroutz_order( $order ) ) {
			return;
		}

		$screen = 'shop_order';
		if ( class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' )
			&& wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled() ) {
			$screen = wc_get_page_screen_id( 'shop-order' );
		}

		add_meta_box(
			'bmsoi_metabox',
			__( 'Skroutz Marketplace', 'bm-skroutz-order-import' ),
			array( __CLASS__, 'render_metabox' ),
			$screen,
			'side',
			'high'
		);
	}

	public static function render_metabox( $post ) {
		$order = ( $post instanceof WP_Post ) ? wc_get_order( $post->ID ) : $post;
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$code     = bmsoi_order_code( $order );
		$response = BMSOI_API::get_order( $code );

		if ( is_wp_error( $response ) || empty( $response->order ) ) {
			echo '<p class="bmsoi-error">' . esc_html__( 'Αδυναμία ανάκτησης της παραγγελίας από το Skroutz API. Ελέγξτε το API token.', 'bm-skroutz-order-import' ) . '</p>';
			return;
		}

		$sc    = $response->order;
		$state = isset( $sc->state ) ? $sc->state : '';

		$labels = array(
			'open'                => __( 'Νέα', 'bm-skroutz-order-import' ),
			'accepted'            => __( 'Προς αποστολή', 'bm-skroutz-order-import' ),
			'rejected'            => __( 'Απορρίφθηκε', 'bm-skroutz-order-import' ),
			'dispatched'          => __( 'Απεστάλη', 'bm-skroutz-order-import' ),
			'delivered'           => __( 'Παραδόθηκε', 'bm-skroutz-order-import' ),
			'expired'             => __( 'Έληξε', 'bm-skroutz-order-import' ),
			'cancelled'           => __( 'Ακυρώθηκε', 'bm-skroutz-order-import' ),
			'returned'            => __( 'Επεστράφη', 'bm-skroutz-order-import' ),
			'partially_returned'  => __( 'Μερική επιστροφή', 'bm-skroutz-order-import' ),
			'for_return'          => __( 'Προς επιστροφή', 'bm-skroutz-order-import' ),
			'partially_delivered' => __( 'Μερική παράδοση', 'bm-skroutz-order-import' ),
		);
		?>
		<div class="bmsoi-mb" data-order="<?php echo esc_attr( $code ); ?>">
			<div class="bmsoi-mb-header">
				<img class="bmsoi-mb-icon" src="<?php echo esc_url( self::icon_url() ); ?>" alt="Skroutz" width="20" height="20">
				<span class="bmsoi-mb-code">#<?php echo esc_html( $code ); ?></span>
				<span class="bmsoi-mb-badge bmsoi-state-<?php echo esc_attr( $state ); ?>"><?php echo esc_html( $labels[ $state ] ?? $state ); ?></span>
			</div>

			<?php if ( ! empty( $sc->express ) ) : ?>
				<p class="bmsoi-mb-flag">⚡ <?php esc_html_e( 'Express παραγγελία', 'bm-skroutz-order-import' ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $sc->fulfilled_by_skroutz ) ) : ?>
				<p class="bmsoi-mb-flag">📦 <?php esc_html_e( 'Fulfilled by Skroutz', 'bm-skroutz-order-import' ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $sc->store_pickup ) ) : ?>
				<p class="bmsoi-mb-flag">🏬 <?php esc_html_e( 'Παραλαβή από κατάστημα', 'bm-skroutz-order-import' ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $sc->gift_wrap ) ) : ?>
				<p class="bmsoi-mb-flag">🎁 <?php esc_html_e( 'Συσκευασία δώρου', 'bm-skroutz-order-import' ); ?></p>
			<?php endif; ?>

			<?php if ( ! empty( $sc->courier_voucher ) ) :
				$tracking = (array) ( $sc->courier_tracking_codes ?? array() );
				?>
				<p>
					<strong><?php esc_html_e( 'Μεταφορέας:', 'bm-skroutz-order-import' ); ?></strong> <?php echo esc_html( $sc->courier ?? '' ); ?><br>
					<a href="<?php echo esc_url( $sc->courier_voucher ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $tracking ? reset( $tracking ) : __( 'Voucher', 'bm-skroutz-order-import' ) ); ?> ↗</a>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $sc->dispatch_until ) && 'accepted' === $state ) : ?>
				<p><strong><?php esc_html_e( 'Αποστολή έως:', 'bm-skroutz-order-import' ); ?></strong> <?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $sc->dispatch_until ) ) ); ?></p>
			<?php endif; ?>

			<?php if ( 'open' === $state && empty( $sc->express ) && empty( $sc->store_pickup ) && ! empty( $sc->accept_options ) ) : ?>
				<div class="bmsoi-mb-accept">
					<p>
						<label for="bmsoi_pickup_location"><?php esc_html_e( 'Σημείο παραλαβής', 'bm-skroutz-order-import' ); ?></label>
						<select id="bmsoi_pickup_location" class="widefat">
							<?php foreach ( (array) ( $sc->accept_options->pickup_location ?? array() ) as $location ) : ?>
								<option value="<?php echo esc_attr( $location->id ); ?>"><?php echo esc_html( $location->label ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p>
						<label for="bmsoi_pickup_window"><?php esc_html_e( 'Παράθυρο παραλαβής', 'bm-skroutz-order-import' ); ?></label>
						<select id="bmsoi_pickup_window" class="widefat">
							<?php foreach ( (array) ( $sc->accept_options->pickup_window ?? array() ) as $window ) : ?>
								<option value="<?php echo esc_attr( $window->id ); ?>"><?php echo esc_html( $window->label ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<input type="hidden" id="bmsoi_parcels" value="<?php echo esc_attr( (int) reset( (array) ( $sc->accept_options->number_of_parcels ?? array( 1 ) ) ) ); ?>">
					<button type="button" class="button button-primary bmsoi-mb-btn" id="bmsoi_accept"><?php esc_html_e( 'Αποδοχή παραγγελίας', 'bm-skroutz-order-import' ); ?></button>
				</div>
			<?php endif; ?>

			<?php if ( 'open' === $state && ! empty( $sc->reject_options->line_item_rejection_reasons ) ) : ?>
				<div class="bmsoi-mb-reject">
					<button type="button" class="button-link bmsoi-mb-danger" id="bmsoi_reject_toggle">✕ <?php esc_html_e( 'Απόρριψη παραγγελίας', 'bm-skroutz-order-import' ); ?></button>
					<div id="bmsoi_reject_form" hidden>
						<p>
							<label for="bmsoi_reject_reason"><?php esc_html_e( 'Αιτιολογία', 'bm-skroutz-order-import' ); ?></label>
							<select id="bmsoi_reject_reason" class="widefat">
								<?php foreach ( (array) $sc->reject_options->line_item_rejection_reasons as $reason ) : ?>
									<option value="<?php echo esc_attr( $reason->id ); ?>"><?php echo esc_html( $reason->label ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<p>
							<input type="number" id="bmsoi_reject_qty" class="widefat" min="1" placeholder="<?php esc_attr_e( 'Διαθέσιμη ποσότητα (για μερική διαθεσιμότητα)', 'bm-skroutz-order-import' ); ?>">
						</p>
						<p>
							<input type="text" id="bmsoi_reject_other" class="widefat" placeholder="<?php esc_attr_e( 'Άλλη αιτιολογία (προαιρετικό)', 'bm-skroutz-order-import' ); ?>">
						</p>
						<button type="button" class="button bmsoi-mb-btn" id="bmsoi_reject"><?php esc_html_e( 'Επιβεβαίωση απόρριψης', 'bm-skroutz-order-import' ); ?></button>
					</div>
				</div>
			<?php endif; ?>

			<p class="bmsoi-mb-sync">
				<button type="button" class="button bmsoi-mb-btn" id="bmsoi_sync_order"><?php esc_html_e( '↻ Συγχρονισμός από Skroutz', 'bm-skroutz-order-import' ); ?></button>
			</p>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * AJAX + admin-post handlers
	 * ------------------------------------------------------------------- */

	private static function verify_ajax() {
		check_ajax_referer( 'bmsoi_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Δεν έχετε δικαιώματα για αυτή την ενέργεια.', 'bm-skroutz-order-import' ) ), 403 );
		}
	}

	public static function ajax_fetch_order() {
		self::verify_ajax();

		$code = isset( $_POST['order_code'] ) ? sanitize_text_field( wp_unslash( $_POST['order_code'] ) ) : '';
		if ( '' === $code ) {
			wp_send_json_error( array( 'message' => __( 'Δώστε κωδικό παραγγελίας.', 'bm-skroutz-order-import' ) ) );
		}

		$response = BMSOI_API::get_order( $code );
		if ( is_wp_error( $response ) || empty( $response->order ) ) {
			$message = is_wp_error( $response ) ? $response->get_error_message() : __( 'Η παραγγελία δεν βρέθηκε στο Skroutz.', 'bm-skroutz-order-import' );
			wp_send_json_error( array( 'message' => $message ) );
		}

		$order_id = BMSOI_Importer::import( $response->order, true, true );
		if ( is_wp_error( $order_id ) || ! $order_id ) {
			wp_send_json_error( array( 'message' => is_wp_error( $order_id ) ? $order_id->get_error_message() : __( 'Η εισαγωγή απέτυχε.', 'bm-skroutz-order-import' ) ) );
		}

		wp_send_json_success( array(
			'order_id'  => $order_id,
			'order_url' => admin_url( 'post.php?post=' . $order_id . '&action=edit' ),
		) );
	}

	public static function ajax_accept_order() {
		self::verify_ajax();

		$code  = isset( $_POST['order_code'] ) ? sanitize_text_field( wp_unslash( $_POST['order_code'] ) ) : '';
		$order = self::order_by_code( $code );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Η παραγγελία δεν βρέθηκε.', 'bm-skroutz-order-import' ) ) );
		}

		$result = BMSOI_Actions::accept(
			$order,
			isset( $_POST['pickup_location'] ) ? sanitize_text_field( wp_unslash( $_POST['pickup_location'] ) ) : null,
			isset( $_POST['pickup_window'] ) ? (int) $_POST['pickup_window'] : null,
			isset( $_POST['parcels'] ) ? (int) $_POST['parcels'] : null
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$order->update_status( 'processing' );
		wp_send_json_success();
	}

	public static function ajax_reject_order() {
		self::verify_ajax();

		$code  = isset( $_POST['order_code'] ) ? sanitize_text_field( wp_unslash( $_POST['order_code'] ) ) : '';
		$order = self::order_by_code( $code );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Η παραγγελία δεν βρέθηκε.', 'bm-skroutz-order-import' ) ) );
		}

		$result = BMSOI_Actions::reject(
			$order,
			isset( $_POST['reason_id'] ) ? (int) $_POST['reason_id'] : 0,
			isset( $_POST['other_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['other_reason'] ) ) : '',
			isset( $_POST['available_quantity'] ) ? (int) $_POST['available_quantity'] : 0
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success();
	}

	public static function handle_sync_now() {
		check_admin_referer( 'bmsoi_sync_now' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Δεν έχετε δικαιώματα για αυτή την ενέργεια.', 'bm-skroutz-order-import' ) );
		}

		$summary = BMSOI_Poller::run();

		wp_safe_redirect( add_query_arg(
			array(
				'page'         => self::PAGE_SLUG,
				'bmsoi_synced' => 1,
				'imported'     => (int) $summary['imported'],
				'updated'      => (int) $summary['updated'],
				'errors'       => (int) $summary['errors'],
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	private static function order_by_code( $code ) {
		if ( '' === $code ) {
			return false;
		}
		$order_id = wc_get_order_id_by_order_key( 'SKZ-' . $code );
		if ( ! $order_id ) {
			$order_id = wc_get_order_id_by_order_key( 'SC-' . $code );
		}
		return $order_id ? wc_get_order( $order_id ) : false;
	}
}
