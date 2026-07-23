<?php
/**
 * Plugin Name: WooCommerce Subscriptions to Sublium Migration Bridge
 * Plugin URI: https://betatech.co
 * Description: Export subscriptions from WooCommerce Subscriptions and import them into Sublium Subscriptions, enabling seamless migration between stores.
 * Version: 0.8.9
 * Author: BetaTech
 * Author URI: https://betatech.co
 * Requires Plugins: woocommerce
 * Requires at least: 6.7
 * Requires PHP: 8.0
 * WC requires at least: 9.0
 * WC tested up to: 10.0
 * Text Domain: wc-subscriptions-sublium-migration-bridge
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

final class WSMB_Migration_Bridge {
	const OPTION_KEY = 'wsmb_migration_bridge_settings';
	const REST_NS    = 'wsmb/v1';
	const META_OLD_ID = '_wsmb_old_wcs_subscription_id';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'save_settings' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// Fix missing Authorize.net customer profile ID on renewal.
		// When Sublium tries to charge a migrated subscription, it may not have
		// the customer profile ID. We hook in and supply it from wp_woocommerce_payment_tokenmeta.
		add_filter( 'woocommerce_order_get_meta', array( $this, 'fix_authnet_missing_profile_id' ), 10, 4 );
		add_action( 'woocommerce_before_pay_action', array( $this, 'ensure_authnet_profile_on_order' ) );
		// Hook into Sublium's pre-renewal to inject the profile ID into subscription meta.
		add_action( 'sublium_wcs_before_process_renewal', array( $this, 'inject_authnet_profile_before_renewal' ), 10, 2 );
		add_filter( 'sublium_wcs_subscription_payment_meta', array( $this, 'inject_authnet_profile_payment_meta' ), 10, 2 );
	}

	/**
	 * Get the Authorize.net customer profile ID for a user from their payment token meta.
	 * This is used as fallback when the profile ID is missing from order/subscription meta.
	 */
	/**
	 * Get Authorize.net API credentials from WooCommerce gateway settings.
	 */
	private function get_authnet_api_credentials() {
		$gateway_settings = get_option( 'woocommerce_authorize_net_cim_credit_card_settings', array() );
		$api_login_id     = $gateway_settings['api_login_id'] ?? '';
		$transaction_key  = $gateway_settings['transaction_key'] ?? '';
		$environment      = $gateway_settings['environment'] ?? 'production';

		if ( empty( $api_login_id ) || empty( $transaction_key ) ) {
			return null;
		}

		return array(
			'api_login_id'    => $api_login_id,
			'transaction_key' => $transaction_key,
			'endpoint'        => $environment === 'test'
				? 'https://apitest.authorize.net/xml/v1/request.api'
				: 'https://api2.authorize.net/xml/v1/request.api',
		);
	}

	/**
	 * Look up Authorize.net customer profile ID using a payment profile token.
	 * Uses getCustomerProfileId API call.
	 */
	private function get_authnet_customer_profile_from_api( $payment_token, $credentials ) {
		if ( empty( $payment_token ) || empty( $credentials ) ) return null;

		// Try getCustomerProfileIdFromTransaction first.
		$request = array(
			'getTransactionDetailsRequest' => array(
				'merchantAuthentication' => array(
					'name'           => $credentials['api_login_id'],
					'transactionKey' => $credentials['transaction_key'],
				),
				'transId' => $payment_token,
			),
		);

		$response = wp_remote_post( $credentials['endpoint'], array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $request ),
		) );

		if ( is_wp_error( $response ) ) return null;

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$profile_id = $body['transaction']['customer']['id'] ?? null;
		if ( $profile_id ) return $profile_id;

		// Try getCustomerPaymentProfile using the payment profile ID directly.
		// The token in our DB is actually the payment profile ID.
		// We need to search by it — try getCustomerProfileIdFromPaymentProfile.
		$request2 = array(
			'getCustomerPaymentProfileListRequest' => array(
				'merchantAuthentication' => array(
					'name'           => $credentials['api_login_id'],
					'transactionKey' => $credentials['transaction_key'],
				),
				'searchType' => 'cardsExpiringInMonth',
				'month'      => date( 'Y-12' ),
				'sorting'    => array( 'orderBy' => 'id', 'orderDescending' => false ),
				'paging'     => array( 'limit' => 10, 'offset' => 1 ),
			),
		);

		return null; // Will use token meta fallback
	}

	/**
	 * Get customer profile ID — tries token meta first, then Authorize.net API.
	 */
	private function get_authnet_customer_profile_id( $user_id, $payment_token = '' ) {
		global $wpdb;

		// First try token meta — most reliable when available.
		$profile_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT tm.meta_value
			 FROM {$wpdb->prefix}woocommerce_payment_tokens t
			 INNER JOIN {$wpdb->prefix}woocommerce_payment_tokenmeta tm ON tm.payment_token_id = t.token_id
			 WHERE t.user_id = %d
			   AND t.gateway_id = 'authorize_net_cim_credit_card'
			   AND tm.meta_key = 'customer_profile_id'
			   AND tm.meta_value != ''
			 ORDER BY t.is_default DESC, t.token_id DESC
			 LIMIT 1",
			$user_id
		) );

		if ( $profile_id ) return $profile_id;

		// Try user meta.
		$profile_id = get_user_meta( $user_id, '_wc_authorize_net_cim_credit_card_customer_id', true );
		if ( $profile_id ) return $profile_id;

		// Last resort — call Authorize.net API using the payment token.
		if ( $payment_token ) {
			$credentials = $this->get_authnet_api_credentials();
			if ( $credentials ) {
				$profile_id = $this->get_authnet_customer_profile_from_api( $payment_token, $credentials );
				if ( $profile_id ) {
					// Cache it.
					update_user_meta( $user_id, '_wc_authorize_net_cim_credit_card_customer_id', $profile_id );
					return $profile_id;
				}
			}
		}

		return null;
	}

	/**
	 * Filter: when order meta for _wc_authorize_net_cim_credit_card_customer_id is empty,
	 * supply it from payment token meta.
	 */
	public function fix_authnet_missing_profile_id( $value, $object, $key, $single ) {
		if ( $key !== '_wc_authorize_net_cim_credit_card_customer_id' ) return $value;
		if ( ! empty( $value ) ) return $value;
		if ( ! is_a( $object, 'WC_Order' ) && ! is_a( $object, 'WC_Abstract_Order' ) ) return $value;

		$user_id    = $object->get_customer_id();
		$profile_id = $this->get_authnet_profile_from_token( $user_id );
		if ( $profile_id ) {
			// Write it to the order so future lookups are instant.
			$object->update_meta_data( '_wc_authorize_net_cim_credit_card_customer_id', $profile_id );
			$object->save_meta_data();
			return $single ? $profile_id : array( $profile_id );
		}

		return $value;
	}

	/**
	 * Action: before Sublium processes a renewal, ensure the subscription meta
	 * has the Authorize.net customer profile ID.
	 */
	public function inject_authnet_profile_before_renewal( $subscription_id, $order = null ) {
		global $wpdb;
		$meta_table = $wpdb->prefix . 'sublium_wcs_subscription_meta';
		$sub_table  = $wpdb->prefix . 'sublium_wcs_subscriptions';

		// Get user_id from subscription.
		$user_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT user_id FROM {$sub_table} WHERE id = %d",
			$subscription_id
		) );
		if ( ! $user_id ) return;

		$profile_id = $this->get_authnet_profile_from_token( $user_id );
		if ( ! $profile_id ) return;

		// Check if customer_id already in subscription meta.
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$meta_table} WHERE subscription_id = %d AND meta_key = '_wc_authorize_net_cim_credit_card_customer_id' LIMIT 1",
			$subscription_id
		) );

		if ( ! $existing ) {
			$wpdb->insert( $meta_table, array(
				'subscription_id' => $subscription_id,
				'meta_key'        => '_wc_authorize_net_cim_credit_card_customer_id',
				'meta_value'      => $profile_id,
			) );
		}
	}

	/**
	 * Filter: inject Authorize.net customer profile ID into Sublium payment meta.
	 */
	public function inject_authnet_profile_payment_meta( $payment_meta, $subscription ) {
		if ( empty( $payment_meta['_wc_authorize_net_cim_credit_card_customer_id'] ) ) {
			$sub_id  = is_array( $subscription ) ? ( $subscription['id'] ?? 0 ) : ( method_exists( $subscription, 'get_id' ) ? $subscription->get_id() : 0 );
			$user_id = is_array( $subscription ) ? ( $subscription['user_id'] ?? 0 ) : ( method_exists( $subscription, 'get_customer_id' ) ? $subscription->get_customer_id() : 0 );
			if ( ! $user_id && $sub_id ) {
				global $wpdb;
				$user_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}sublium_wcs_subscriptions WHERE id = %d", $sub_id ) );
			}
			$profile_id = $this->get_authnet_profile_from_token( $user_id );
			if ( $profile_id ) {
				$payment_meta['_wc_authorize_net_cim_credit_card_customer_id'] = $profile_id;
			}
		}
		return $payment_meta;
	}

	/**
	 * Action: ensure order has Authorize.net profile ID before payment.
	 */
	public function ensure_authnet_profile_on_order( $order ) {
		if ( ! is_a( $order, 'WC_Order' ) ) return;
		if ( $order->get_payment_method() !== 'authorize_net_cim_credit_card' ) return;

		$existing = $order->get_meta( '_wc_authorize_net_cim_credit_card_customer_id' );
		if ( $existing ) return;

		$profile_id = $this->get_authnet_profile_from_token( $order->get_customer_id() );
		if ( $profile_id ) {
			$order->update_meta_data( '_wc_authorize_net_cim_credit_card_customer_id', $profile_id );
			$order->save_meta_data();
		}
	}

	public function enqueue_admin_assets( $hook ) {
		if ( 'woocommerce_page_wsmb-migration' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'jquery' );
		wp_add_inline_style( 'wp-admin', '
			.wsmb-tab { display:none }
			.wsmb-tab.wsmb-active { display:block }
			#wsmb-tabs .nav-tab { cursor:pointer; text-decoration:none }
			.wsmb-log { background:#1e1e1e;color:#d4d4d4;font-family:monospace;font-size:12px;padding:12px;border-radius:4px;max-height:400px;overflow-y:auto;display:none;margin-top:12px }
			.wsmb-log-ok { color:#b5cea8 }
			.wsmb-log-warn { color:#ffd700 }
			.wsmb-log-error { color:#f48771 }
			.wsmb-log-info { color:#9cdcfe }
			.wsmb-log-done { color:#4ec9b0 }
			.wsmb-progress-wrap { margin:16px 0 }
			.wsmb-progress-track { background:#e0e0e0;border-radius:4px;height:24px;overflow:hidden;max-width:640px }
			.wsmb-progress-bar { background:#2271b1;height:100%;width:0%;transition:width 0.3s;display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px }
			.wsmb-instruction-box { background:#f6f7f7;border-left:4px solid #2271b1;padding:14px 18px;margin:12px 0;border-radius:0 4px 4px 0 }
			.wsmb-instruction-box h3 { margin:0 0 8px;font-size:14px }
			.wsmb-step { display:flex;gap:12px;margin:10px 0;align-items:flex-start }
			.wsmb-step-num { background:#2271b1;color:#fff;border-radius:50%;width:24px;height:24px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:12px;font-weight:bold }
			.wsmb-result-box { background:#f0f6fc;border-left:4px solid #2271b1;padding:12px 16px;margin-top:8px;border-radius:0 4px 4px 0 }
			.wsmb-curl-block { background:#1e1e1e;color:#d4d4d4;font-family:monospace;font-size:12px;padding:12px;border-radius:4px;margin:8px 0;white-space:pre-wrap;word-break:break-all }
			 table.wsmb-map-table { border-collapse:collapse;max-width:860px }
			 table.wsmb-map-table th { background:#f0f0f1;padding:8px 10px;text-align:left;font-size:13px }
			 table.wsmb-map-table td { padding:6px 8px;border-bottom:1px solid #e0e0e0 }
		' );
	}

	public function admin_menu() {
		add_submenu_page(
			'woocommerce',
			'Sublium Migration',
			'Sublium Migration',
			'manage_woocommerce',
			'wsmb-migration',
			array( $this, 'render_admin_page' )
		);
	}

	public function save_settings() {
		if ( empty( $_POST['wsmb_action'] ) || 'save_settings' !== $_POST['wsmb_action'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) || empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'wsmb_save_settings' ) ) {
			return;
		}

		$settings = array(
			'bridge_token'      => isset( $_POST['bridge_token'] ) ? sanitize_text_field( wp_unslash( $_POST['bridge_token'] ) ) : '',
			'source_url'        => isset( $_POST['source_url'] ) ? esc_url_raw( wp_unslash( $_POST['source_url'] ) ) : '',
			'source_token'      => isset( $_POST['source_token'] ) ? sanitize_text_field( wp_unslash( $_POST['source_token'] ) ) : '',
			'default_gateway'   => isset( $_POST['default_gateway'] ) ? sanitize_text_field( wp_unslash( $_POST['default_gateway'] ) ) : '',
			'target_product_id' => isset( $_POST['target_product_id'] ) ? absint( $_POST['target_product_id'] ) : 0,
			'target_variation_id' => isset( $_POST['target_variation_id'] ) ? absint( $_POST['target_variation_id'] ) : 0,
			'test_customer_emails' => isset( $_POST['test_customer_emails'] ) ? sanitize_textarea_field( wp_unslash( $_POST['test_customer_emails'] ) ) : '',
			'test_customer_ids' => isset( $_POST['test_customer_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['test_customer_ids'] ) ) : '',
			'test_subscription_ids' => isset( $_POST['test_subscription_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['test_subscription_ids'] ) ) : '',
			'gateway_mode'      => isset( $_POST['gateway_mode'] ) ? absint( $_POST['gateway_mode'] ) : 1,
			'order_status'      => isset( $_POST['order_status'] ) ? sanitize_text_field( wp_unslash( $_POST['order_status'] ) ) : 'completed',
			'copy_token_rows'   => ! empty( $_POST['copy_token_rows'] ) ? 'yes' : 'no',
			'pause_after_import' => ! empty( $_POST['pause_after_import'] ) ? 'yes' : 'no',
		'product_map'        => isset( $_POST['product_map'] ) ? sanitize_textarea_field( wp_unslash( $_POST['product_map'] ) ) : '',
		'interval_map'       => isset( $_POST['interval_map'] ) ? sanitize_textarea_field( wp_unslash( $_POST['interval_map'] ) ) : '',
		);

		if ( empty( $settings['bridge_token'] ) ) {
			$settings['bridge_token'] = wp_generate_password( 40, false, false );
		}

		update_option( self::OPTION_KEY, $settings, false );
		add_settings_error( 'wsmb', 'saved', 'Migration bridge settings saved.', 'updated' );
	}

	private function get_settings() {
		$settings = get_option( self::OPTION_KEY, array() );

		return wp_parse_args(
			is_array( $settings ) ? $settings : array(),
			array(
				'bridge_token'       => wp_generate_password( 40, false, false ),
				'source_url'         => '',
				'source_token'       => '',
				'default_gateway'    => '',
				'target_product_id'  => 0,
				'target_variation_id' => 0,
				'test_customer_emails' => '',
				'test_customer_ids'  => '',
				'test_subscription_ids' => '',
				'gateway_mode'       => 1,
				'order_status'       => 'completed',
				'copy_token_rows'    => 'no',
				'pause_after_import' => 'no',
				'product_map'        => '',
				'interval_map'       => '',
			)
		);
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) return;
		$settings   = $this->get_settings();
		settings_errors( 'wsmb' );
		$token      = $settings['bridge_token'];
		$source_url = rtrim( $settings['source_url'], '/' );
		$push_url   = rest_url( self::REST_NS . '/destination/push' );
		$src_ep     = rest_url( self::REST_NS . '/source/subscriptions' );
		?>
		<div class="wrap" style="max-width:1100px">
		<h1>WooCommerce → Sublium Migration Bridge</h1>

		<nav class="nav-tab-wrapper" id="wsmb-nav" style="margin-bottom:0">
			<a class="nav-tab nav-tab-active" data-tab="tab-instructions">📖 Instructions</a>
			<a class="nav-tab" data-tab="tab-settings">⚙️ Settings</a>
			<a class="nav-tab" data-tab="tab-mapping">🔁 Mapping</a>
			<a class="nav-tab" data-tab="tab-migrate">🚀 Batch Migrate</a>
			<a class="nav-tab" data-tab="tab-curl">💻 cURL Reference</a>
		</nav>

		<!-- ===== INSTRUCTIONS ===== -->
		<div id="tab-instructions" class="wsmb-tab wsmb-active" style="padding-top:16px">
			<h2>How to Use This Plugin</h2>
			<p>This plugin migrates WooCommerce Subscriptions data from your <strong>old store</strong> into <strong>Sublium</strong> on your new store. Install it on <em>both</em> stores.</p>

			<div class="wsmb-instruction-box">
				<h3>🏪 On the OLD store (tikvadrink.com)</h3>
				<div class="wsmb-step"><span class="wsmb-step-num">1</span><div>Install &amp; activate this plugin. Go to <strong>WooCommerce → Sublium Migration</strong>.</div></div>
				<div class="wsmb-step"><span class="wsmb-step-num">2</span><div>Copy the <strong>This Site Bridge Token</strong> shown on the Settings tab. You will paste it into the new store.</div></div>
				<div class="wsmb-step"><span class="wsmb-step-num">3</span><div>Click <strong>Save Settings</strong> once to lock the token into the database.</div></div>
				<div class="wsmb-step"><span class="wsmb-step-num">4</span><div>That's all you need to do on the old store. It now exposes a secure export API.</div></div>
			</div>

			<div class="wsmb-instruction-box">
				<h3>🆕 On the NEW store (beta.tikvadrink.com) — this page</h3>
				<div class="wsmb-step"><span class="wsmb-step-num">1</span><div>Go to the <strong>Settings</strong> tab. Enter the old store URL and paste the old store's bridge token. Save.</div></div>
				<div class="wsmb-step"><span class="wsmb-step-num">2</span><div>Go to the <strong>Mapping</strong> tab. Add rows to map old product IDs → new product IDs and old billing intervals → Sublium plan IDs. Save Mappings.</div></div>
				<div class="wsmb-step"><span class="wsmb-step-num">3</span><div>Go to the <strong>Batch Migrate</strong> tab. Select statuses, set batch size to <strong>10</strong> for your first test.</div></div>
				<div class="wsmb-step"><span class="wsmb-step-num">4</span><div>Enter a specific <strong>Subscription ID</strong> in the filter box (e.g. a test customer). Click <strong>🔍 Dry Run</strong> — this previews the import without writing any data.</div></div>
				<div class="wsmb-step"><span class="wsmb-step-num">5</span><div>Review the log output. Fix any warnings (missing product mappings, plan mismatches). Run dry run again until clean.</div></div>
				<div class="wsmb-step"><span class="wsmb-step-num">6</span><div>Clear the filter, set batch size to <strong>25 or 50</strong>, click <strong>🚀 Live Import</strong>. The migration runs batch by batch automatically.</div></div>
				<div class="wsmb-step"><span class="wsmb-step-num">7</span><div>If the run is interrupted, note the <strong>current offset</strong> shown and enter it in Start Offset to resume.</div></div>
			</div>

			<div class="wsmb-instruction-box" style="border-color:#d63638">
				<h3>⚠️ Safety Checklist</h3>
				<ul style="margin:0;padding-left:18px">
					<li>Always do a <strong>Dry Run</strong> before any live import</li>
					<li>Enable <strong>Safety Mode</strong> (Settings tab) to import active subscriptions as Paused — review before activating</li>
					<li>Never migrate the same subscriptions twice — the plugin skips existing records but double-check</li>
					<li>Take a full database backup of both stores before the live migration</li>
					<li>Test with 1–2 specific subscription IDs first, verify them in Sublium, then run the full batch</li>
				</ul>
			</div>

			<div class="wsmb-instruction-box" style="border-color:#00a32a">
				<h3>📋 Mapping Quick Reference (Tikva Drink Mix → Tikva Heart)</h3>
				<p style="margin:0 0 8px"><strong>Product ID Map</strong> (old variation → new product : new variation)</p>
				<code>2802:643:5435</code> Chocolate &nbsp;
				<code>2803:643:5437</code> Mango Fusion &nbsp;
				<code>2804:643:5436</code> Sweet Raspberry &nbsp;
				<code>38975:643:5438</code> Strawberry Watermelon &nbsp;
				<code>2468:643</code> Bundle fallback
				<p style="margin:8px 0 4px"><strong>Billing Interval Map</strong></p>
				<code>1:1</code> 1 month → Plan 1 &nbsp;
				<code>4:2</code> 4 months → Plan 2 &nbsp;
				<code>6:2</code> 6 months → Plan 2
			</div>
		</div>

		<!-- ===== SETTINGS ===== -->
		<div id="tab-settings" class="wsmb-tab" style="padding-top:16px">
		<form method="post">
			<?php wp_nonce_field( 'wsmb_save_settings' ); ?>
			<input type="hidden" name="wsmb_action" value="save_settings" />
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="bridge_token">This Site Bridge Token</label></th>
					<td>
						<input class="regular-text" id="bridge_token" name="bridge_token" value="<?php echo esc_attr( $token ); ?>" />
						<p class="description">Paste this into the old store's Settings tab too. Protects all REST endpoints.</p>
					</td>
				</tr>
				<tr>
					<th><label for="source_url">Old Store URL</label></th>
					<td><input class="regular-text" id="source_url" name="source_url" placeholder="https://tikvadrink.com" value="<?php echo esc_attr( $settings['source_url'] ); ?>" /></td>
				</tr>
				<tr>
					<th><label for="source_token">Old Store Bridge Token</label></th>
					<td><input class="regular-text" id="source_token" name="source_token" value="<?php echo esc_attr( $settings['source_token'] ); ?>" /></td>
				</tr>
				<tr>
					<th><label for="default_gateway">Fallback Gateway ID</label></th>
					<td>
						<input class="regular-text" id="default_gateway" name="default_gateway" placeholder="authorize_net_cim_credit_card" value="<?php echo esc_attr( $settings['default_gateway'] ); ?>" />
						<p class="description">Used when the old gateway ID doesn't exist on the new store. Leave blank to keep original.</p>
					</td>
				</tr>
				<tr>
					<th><label for="gateway_mode">Gateway Mode</label></th>
					<td>
						<select id="gateway_mode" name="gateway_mode">
							<option value="1" <?php selected( 1, (int) $settings['gateway_mode'] ); ?>>On-site renewal processing</option>
							<option value="2" <?php selected( 2, (int) $settings['gateway_mode'] ); ?>>Off-site / webhook processing</option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="order_status">Parent Order Status</label></th>
					<td>
						<input class="regular-text" id="order_status" name="order_status" value="<?php echo esc_attr( $settings['order_status'] ); ?>" />
						<p class="description">WooCommerce status for the parent order created during migration. Default: <code>completed</code></p>
					</td>
				</tr>
				<tr>
					<th>Payment Tokens</th>
					<td><label><input type="checkbox" name="copy_token_rows" value="yes" <?php checked( 'yes', $settings['copy_token_rows'] ); ?> /> Copy payment token rows to new store</label>
					<p class="description">Only enable if the same payment gateway account can charge migrated tokens on the new store.</p></td>
				</tr>
				<tr>
					<th>⚠️ Safety Mode</th>
					<td><label><input type="checkbox" name="pause_after_import" value="yes" <?php checked( 'yes', $settings['pause_after_import'] ); ?> /> Import active subscriptions as <strong>Paused</strong> for manual review</label>
					<p class="description">Recommended for your first full migration run. Unpause in Sublium after verifying.</p></td>
				</tr>
				<!-- carry-over hidden fields not shown in this tab -->
				<input type="hidden" name="target_product_id"  value="<?php echo esc_attr( $settings['target_product_id'] ); ?>" />
				<input type="hidden" name="target_variation_id" value="<?php echo esc_attr( $settings['target_variation_id'] ); ?>" />
				<input type="hidden" name="test_customer_emails"   value="<?php echo esc_attr( $settings['test_customer_emails'] ); ?>" />
				<input type="hidden" name="test_customer_ids"      value="<?php echo esc_attr( $settings['test_customer_ids'] ); ?>" />
				<input type="hidden" name="test_subscription_ids"  value="<?php echo esc_attr( $settings['test_subscription_ids'] ); ?>" />
				<input type="hidden" name="product_map"  value="<?php echo esc_attr( $settings['product_map'] ); ?>" />
				<input type="hidden" name="interval_map" value="<?php echo esc_attr( $settings['interval_map'] ); ?>" />
			</table>
			<?php submit_button( 'Save Settings' ); ?>
		</form>
		</div>

		<!-- ===== MAPPING ===== -->
		<div id="tab-mapping" class="wsmb-tab" style="padding-top:16px">
		<form method="post" id="wsmb-mapping-form">
			<?php wp_nonce_field( 'wsmb_save_settings' ); ?>
			<input type="hidden" name="wsmb_action" value="save_settings" />
			<input type="hidden" name="bridge_token"        value="<?php echo esc_attr( $settings['bridge_token'] ); ?>" />
			<input type="hidden" name="source_url"          value="<?php echo esc_attr( $settings['source_url'] ); ?>" />
			<input type="hidden" name="source_token"        value="<?php echo esc_attr( $settings['source_token'] ); ?>" />
			<input type="hidden" name="default_gateway"     value="<?php echo esc_attr( $settings['default_gateway'] ); ?>" />
			<input type="hidden" name="target_product_id"   value="<?php echo esc_attr( $settings['target_product_id'] ); ?>" />
			<input type="hidden" name="target_variation_id" value="<?php echo esc_attr( $settings['target_variation_id'] ); ?>" />
			<input type="hidden" name="gateway_mode"        value="<?php echo esc_attr( $settings['gateway_mode'] ); ?>" />
			<input type="hidden" name="order_status"        value="<?php echo esc_attr( $settings['order_status'] ); ?>" />
			<input type="hidden" name="copy_token_rows"     value="<?php echo esc_attr( $settings['copy_token_rows'] ); ?>" />
			<input type="hidden" name="pause_after_import"  value="<?php echo esc_attr( $settings['pause_after_import'] ); ?>" />
			<input type="hidden" name="test_customer_emails"    value="<?php echo esc_attr( $settings['test_customer_emails'] ); ?>" />
			<input type="hidden" name="test_customer_ids"       value="<?php echo esc_attr( $settings['test_customer_ids'] ); ?>" />
			<input type="hidden" name="test_subscription_ids"   value="<?php echo esc_attr( $settings['test_subscription_ids'] ); ?>" />
			<textarea name="product_map"  id="wsmb-pm-hidden"  style="display:none"><?php echo esc_textarea( $settings['product_map'] ); ?></textarea>
			<textarea name="interval_map" id="wsmb-im-hidden"  style="display:none"><?php echo esc_textarea( $settings['interval_map'] ); ?></textarea>

			<h2>Product ID Mapping</h2>
			<p>Map each old store product/variation ID to the corresponding new store product and variation. <strong>95 mappings are hardcoded in the plugin</strong> and always active. Rows here are additional overrides.</p>
			<table class="wsmb-map-table widefat" id="wsmb-pm-table" style="max-width:860px;margin-bottom:10px">
				<thead><tr>
					<th style="width:160px">Old ID</th>
					<th style="width:160px">New Product ID</th>
					<th style="width:160px">New Variation ID</th>
					<th>Note / Label</th>
					<th style="width:40px"></th>
				</tr></thead>
				<tbody id="wsmb-pm-rows">
				<?php
				$pm_lines = array_values( array_filter( array_map( 'trim', explode( "\n", $settings['product_map'] ?? '' ) ) ) );
				if ( empty( $pm_lines ) ) {
				?>
				<tr style="background:#f0f6fc">
					<td colspan="5" style="padding:10px;color:#646970;font-style:italic">
						✓ <strong>95 product mappings are hardcoded</strong> and active automatically — no rows needed here.<br>
						<small>Add rows only to override a hardcoded mapping or add a new product not in the list.</small>
					</td>
				</tr>
				<?php } else {
					foreach ( $pm_lines as $line ) :
						$p = array_pad( array_map( 'trim', explode( ':', $line ) ), 4, '' );
				?>
				<tr class="wsmb-pm-row">
					<td><input type="number" class="small-text wsmb-f-old" min="0" placeholder="e.g. 2802" value="<?php echo esc_attr($p[0]); ?>"></td>
					<td><input type="number" class="small-text wsmb-f-np" min="0" placeholder="e.g. 643" value="<?php echo esc_attr($p[1]); ?>"></td>
					<td><input type="number" class="small-text wsmb-f-nv" min="0" placeholder="e.g. 5435" value="<?php echo esc_attr($p[2]); ?>"></td>
					<td><input type="text" class="regular-text wsmb-f-note" placeholder="Chocolate" value="<?php echo esc_attr($p[3]); ?>"></td>
					<td><button type="button" class="button button-small wsmb-rm">✕</button></td>
				</tr>
				<?php endforeach;
				} ?>
				</tbody>
			</table>
			<button type="button" class="button" id="wsmb-pm-add">+ Add Row</button>

			<h2 style="margin-top:28px">Billing Interval → Sublium Plan ID</h2>
			<p>Map old WCS billing intervals (number of months) to Sublium plan IDs. <strong>Defaults are hardcoded: 1→Plan 1, 4→Plan 2, 6→Plan 2.</strong> Add rows here to override.</p>
			<table class="wsmb-map-table widefat" id="wsmb-im-table" style="max-width:420px;margin-bottom:10px">
				<thead><tr>
					<th>Old Interval (months)</th>
					<th>Sublium Plan ID</th>
					<th style="width:40px"></th>
				</tr></thead>
				<tbody id="wsmb-im-rows">
				<?php
				$im_lines = array_values( array_filter( array_map( 'trim', explode( "\n", $settings['interval_map'] ?? '' ) ) ) );
				if ( empty( $im_lines ) ) {
					// Show hardcoded defaults as non-editable reference only — not saved to DB.
				?>
				<tr style="background:#f0f6fc">
					<td colspan="3" style="padding:10px;color:#646970;font-style:italic">
						✓ Hardcoded defaults active: 1 month → Plan 1 &nbsp;|&nbsp; 4 months → Plan 2 &nbsp;|&nbsp; 5 months → Plan 2 &nbsp;|&nbsp; 6 months → Plan 2<br>
						<small>Plans are also matched automatically by product. Add rows above only if you need to override.</small>
					</td>
				</tr>
				<?php } else {
					foreach ( $im_lines as $line ) :
						$p = array_pad( array_map( 'trim', explode( ':', $line ) ), 2, '' );
				?>
				<tr class="wsmb-im-row">
					<td><input type="number" class="small-text wsmb-f-int" min="1" placeholder="1" value="<?php echo esc_attr($p[0]); ?>"></td>
					<td><input type="number" class="small-text wsmb-f-plan" min="1" placeholder="1" value="<?php echo esc_attr($p[1]); ?>"></td>
					<td><button type="button" class="button button-small wsmb-rm">✕</button></td>
				</tr>
				<?php endforeach;
				} ?>
				</tbody>
			</table>
			<button type="button" class="button" id="wsmb-im-add">+ Add Row</button>

			<p style="margin-top:20px"><?php submit_button( 'Save Mappings', 'primary', 'submit', false ); ?></p>
		</form>
		</div>

		<!-- ===== BATCH MIGRATE ===== -->
		<div id="tab-migrate" class="wsmb-tab" style="padding-top:16px">
			<h2>Batch Migration</h2>
			<p>Fetches subscriptions from the old store in batches and imports them into Sublium on this store. <strong>Always run a Dry Run first.</strong></p>

			<table class="form-table" style="max-width:700px">
				<tr>
					<th style="width:180px">Statuses</th>
					<td>
						<label style="margin-right:12px"><input type="checkbox" class="wsmb-status" value="active" checked> Active</label>
						<label style="margin-right:12px"><input type="checkbox" class="wsmb-status" value="on-hold" checked> On Hold</label>
						<label style="margin-right:12px"><input type="checkbox" class="wsmb-status" value="pending-cancel" checked> Pending Cancellation</label>
						<label style="margin-right:12px"><input type="checkbox" class="wsmb-status" value="pending"> Pending Payment</label>
						<label style="margin-right:12px"><input type="checkbox" class="wsmb-status" value="cancelled"> Cancelled</label>
					</td>
				</tr>
				<tr>
					<th>Batch Size</th>
					<td>
						<select id="wsmb-batch-size">
							<option value="2">2 — minimum (single test)</option>
							<option value="5">5 — careful</option>
							<option value="10">10 — recommended for testing</option>
							<option value="25" selected>25 — standard</option>
							<option value="50">50 — fast (stable server only)</option>
						</select>
					</td>
				</tr>
				<tr>
					<th>Start Offset</th>
					<td>
						<input type="number" id="wsmb-offset" value="0" min="0" class="small-text" />
						<p class="description">Set to resume an interrupted run. Updates automatically as batches complete.</p>
					</td>
				</tr>
				<tr>
					<th>Filter by Subscription IDs</th>
					<td>
						<input type="text" id="wsmb-sub-ids" class="regular-text" placeholder="e.g. 75891 (leave blank for all)" />
						<p class="description">Enter old-store subscription IDs to test specific records. Leave blank to migrate all.</p>
					</td>
				</tr>
			</table>

			<p style="margin-top:4px">
				<button type="button" id="wsmb-btn-dry" class="button button-secondary button-large">🔍 Dry Run (safe preview)</button>
				&nbsp;&nbsp;
				<button type="button" id="wsmb-btn-live" class="button button-primary button-large" style="background:#d63638;border-color:#b32d2e">🚀 Live Import</button>
				&nbsp;&nbsp;
				<button type="button" id="wsmb-btn-stop" class="button button-large" style="display:none">⏹ Stop</button>
				&nbsp;&nbsp;
				<button type="button" id="wsmb-btn-cleanup" class="button button-large" style="background:#f0f0f1;border-color:#8c8f94;color:#3c434a">🗑 Reset Migration Data</button>
			</p>
			<p class="description" style="margin-top:4px">⚠️ <strong>Reset Migration Data</strong> clears tracking so subscriptions can be reimported. Use after deleting bad test imports. Enter Subscription IDs above to reset specific ones only, or leave blank to reset all.</p>

			<div id="wsmb-progress-wrap" class="wsmb-progress-wrap" style="display:none">
				<div class="wsmb-progress-track">
					<div id="wsmb-bar" class="wsmb-progress-bar"></div>
				</div>
				<p id="wsmb-progress-text" style="margin:6px 0 0;color:#646970"></p>
			</div>

			<div id="wsmb-result-box" style="display:none" class="wsmb-result-box"></div>
			<div id="wsmb-log" class="wsmb-log"></div>

			<hr style="margin:30px 0">
			<h3>🔧 Bulk Fix All Subscriptions</h3>
			<p>Fixes all migrated subscriptions in one click:</p>
			<ul>
				<li>✅ Corrects Authorize.net Customer Profile IDs from payment token meta</li>
				<li>✅ Links parent orders to Sublium subscriptions (<code>_sublium_wcs_subscription_id</code>)</li>
				<li>✅ Links renewal orders to Sublium subscriptions</li>
			</ul>
			<p><button type="button" id="wsmb-btn-bulk-fix" class="button button-primary button-large">🔧 Run Bulk Fix</button></p>
			<div id="wsmb-bulk-fix-result" style="margin-top:10px"></div>

			<hr style="margin:30px 0">
			<h3>🔑 Sync Authorize.net Payment Profile IDs</h3>
			<p>After migration, run this to copy Authorize.net Customer Profile IDs from the old site to beta — required for renewal payments to process correctly.</p>
			<p>
				<input type="email" id="wsmb-sync-email" class="regular-text" placeholder="Filter by email (leave blank for all users)" style="margin-right:8px">
				<button type="button" id="wsmb-btn-sync-profiles" class="button button-secondary button-large">🔄 Sync Payment Profiles</button>
			</p>
			<p class="description">Enter a specific email to test one user first. Leave blank to sync all migrated users.</p>
			<div id="wsmb-sync-result" style="margin-top:10px"></div>
		</div>

		<!-- ===== CURL REFERENCE ===== -->
		<div id="tab-curl" class="wsmb-tab" style="padding-top:16px">
			<h2>cURL Reference</h2>
			<p>Use these commands from your terminal as an alternative to the batch migration UI — useful for server-side scripting or when the browser times out on large batches.</p>

			<h3>1. Export from Old Store</h3>
			<p>Run on your local machine. Replace <code>OLD_TOKEN</code> with the old store bridge token.</p>
			<div class="wsmb-curl-block"># Export first 25 active subscriptions
curl "<?php echo esc_html( $source_url ); ?>/wp-json/<?php echo esc_html( self::REST_NS ); ?>/source/subscriptions?token=OLD_TOKEN&amp;status=active&amp;limit=25&amp;offset=0" &gt; batch_001.json

# Export specific subscription IDs (for testing)
curl "<?php echo esc_html( $source_url ); ?>/wp-json/<?php echo esc_html( self::REST_NS ); ?>/source/subscriptions?token=OLD_TOKEN&amp;subscription_ids=75891,75892" &gt; test.json

# Export by status (comma-separated)
curl "<?php echo esc_html( $source_url ); ?>/wp-json/<?php echo esc_html( self::REST_NS ); ?>/source/subscriptions?token=OLD_TOKEN&amp;status=active,on-hold&amp;limit=50&amp;offset=0" &gt; batch.json</div>

			<h3>2. Dry Run Push to New Store</h3>
			<p>Preview the import — no data is written. Check for warnings before running live.</p>
			<div class="wsmb-curl-block">curl -X POST "<?php echo esc_html( $push_url ); ?>?dry_run=1&amp;token=<?php echo esc_html( $token ); ?>"   -H "Content-Type: application/json"   -d @batch_001.json</div>

			<h3>3. Live Import</h3>
			<div class="wsmb-curl-block">curl -X POST "<?php echo esc_html( $push_url ); ?>?dry_run=0&amp;token=<?php echo esc_html( $token ); ?>"   -H "Content-Type: application/json"   -d @batch_001.json</div>

			<h3>4. Full Batch Script (bash)</h3>
			<p>Save as <code>migrate.sh</code>, run with <code>bash migrate.sh</code>. Automatically paginates through all subscriptions.</p>
			<div class="wsmb-curl-block">#!/bin/bash
OLD_URL="<?php echo esc_html( $source_url ); ?>"
OLD_TOKEN="OLD_TOKEN_HERE"
NEW_TOKEN="<?php echo esc_html( $token ); ?>"
NEW_PUSH="<?php echo esc_html( $push_url ); ?>"
STATUS="active,on-hold,pending-cancel"
BATCH=25
OFFSET=0

while true; do
  echo "Fetching offset=$OFFSET..."
  curl -s "$OLD_URL/wp-json/<?php echo esc_html( self::REST_NS ); ?>/source/subscriptions?token=$OLD_TOKEN&amp;status=$STATUS&amp;limit=$BATCH&amp;offset=$OFFSET" &gt; /tmp/wsmb_batch.json

  COUNT=$(python3 -c "import json,sys; d=json.load(open('/tmp/wsmb_batch.json')); print(d.get('count',0))")
  echo "Got $COUNT subscriptions"

  if [ "$COUNT" -eq "0" ]; then
    echo "Done! All batches processed."
    break
  fi

  echo "Pushing batch (dry_run=0)..."
  curl -s -X POST "$NEW_PUSH?dry_run=0&amp;token=$NEW_TOKEN"     -H "Content-Type: application/json"     -d @/tmp/wsmb_batch.json | python3 -m json.tool

  OFFSET=$((OFFSET + BATCH))
  sleep 1
done</div>

			<h3>5. Debug Plans (if plan ID keeps returning 0)</h3>
			<div class="wsmb-curl-block">curl "<?php echo esc_html( rest_url( self::REST_NS ) ); ?>/destination/debug-plans?token=<?php echo esc_html( $token ); ?>&amp;product_id=643"</div>
		</div>

		</div><!-- .wrap -->

		<script>
		(function() {
			// Tab switching — pure vanilla JS, no jQuery needed
			var nav   = document.getElementById('wsmb-nav');
			var tabs  = document.querySelectorAll('.wsmb-tab');
			var links = nav ? nav.querySelectorAll('.nav-tab') : [];

			links.forEach(function(link) {
				link.addEventListener('click', function(e) {
					e.preventDefault();
					links.forEach(function(l) { l.classList.remove('nav-tab-active'); });
					tabs.forEach(function(t) { t.classList.remove('wsmb-active'); });
					link.classList.add('nav-tab-active');
					var target = document.getElementById(link.getAttribute('data-tab'));
					if (target) target.classList.add('wsmb-active');
				});
			});

			// --- Product map table ---
			var pmTbody = document.getElementById('wsmb-pm-rows');
			var pmHidden = document.getElementById('wsmb-pm-hidden');
			var imTbody = document.getElementById('wsmb-im-rows');
			var imHidden = document.getElementById('wsmb-im-hidden');

			function pmRow(old_id, np, nv, note) {
				var tr = document.createElement('tr');
				tr.className = 'wsmb-pm-row';
				tr.innerHTML = '<td><input type="number" class="small-text wsmb-f-old" min="0" placeholder="e.g. 2802" value="' + (old_id||'')+'"></td>' +
					'<td><input type="number" class="small-text wsmb-f-np" min="0" placeholder="e.g. 643" value="' + (np||'')+'"></td>' +
					'<td><input type="number" class="small-text wsmb-f-nv" min="0" placeholder="e.g. 5435" value="' + (nv||'')+'"></td>' +
					'<td><input type="text" class="regular-text wsmb-f-note" placeholder="e.g. Chocolate" value="' + (note||'')+'"></td>' +
					'<td><button type="button" class="button button-small wsmb-rm">✕</button></td>';
				return tr;
			}
			function imRow(interval, plan) {
				var tr = document.createElement('tr');
				tr.className = 'wsmb-im-row';
				tr.innerHTML = '<td><input type="number" class="small-text wsmb-f-int" min="1" placeholder="1" value="' + (interval||'')+'"></td>' +
					'<td><input type="number" class="small-text wsmb-f-plan" min="1" placeholder="1" value="' + (plan||'')+'"></td>' +
					'<td><button type="button" class="button button-small wsmb-rm">✕</button></td>';
				return tr;
			}
			if (document.getElementById('wsmb-pm-add')) {
				document.getElementById('wsmb-pm-add').addEventListener('click', function(){ pmTbody.appendChild(pmRow()); });
				document.getElementById('wsmb-im-add').addEventListener('click', function(){ imTbody.appendChild(imRow()); });
			}
			document.addEventListener('click', function(e) {
				if (e.target && e.target.classList.contains('wsmb-rm')) {
					e.target.closest('tr').remove();
				}
			});
			var mappingForm = document.getElementById('wsmb-mapping-form');
			if (mappingForm) {
				mappingForm.addEventListener('submit', function() {
					// Serialize product map
					var pmLines = [];
					document.querySelectorAll('#wsmb-pm-rows .wsmb-pm-row').forEach(function(row) {
						var old_id = row.querySelector('.wsmb-f-old').value.trim();
						var np     = row.querySelector('.wsmb-f-np').value.trim();
						var nv     = row.querySelector('.wsmb-f-nv').value.trim();
						var note   = row.querySelector('.wsmb-f-note').value.trim();
						if (old_id && np) pmLines.push([old_id, np, nv, note].join(':'));
					});
					pmHidden.value = pmLines.join('\n');
					// Serialize interval map
					var imLines = [];
					document.querySelectorAll('#wsmb-im-rows .wsmb-im-row').forEach(function(row) {
						var interval = row.querySelector('.wsmb-f-int').value.trim();
						var plan     = row.querySelector('.wsmb-f-plan').value.trim();
						if (interval && plan) imLines.push(interval + ':' + plan);
					});
					imHidden.value = imLines.join('\n');
				});
			}

			// --- Batch Migration ---
			var wsmbStop    = false;
			var wsmbRunning = false;
			var wsmbToken   = <?php echo wp_json_encode( $token ); ?>;
			var wsmbPushUrl  = <?php echo wp_json_encode( rest_url( self::REST_NS . '/destination/push' ) ); ?>;
			// Use server-side proxy so browser never calls tikvadrink.com directly (avoids Cloudflare CORS block)
			var wsmbProxyUrl = <?php echo wp_json_encode( rest_url( self::REST_NS . '/destination/proxy-fetch' ) ); ?>;

			function log(msg, cls) {
				var el = document.getElementById('wsmb-log');
				el.style.display = 'block';
				var d = document.createElement('div');
				d.className = cls || '';
				d.textContent = msg;
				el.appendChild(d);
				el.scrollTop = el.scrollHeight;
			}
			function setProgress(pct, text) {
				document.getElementById('wsmb-progress-wrap').style.display = 'block';
				var bar = document.getElementById('wsmb-bar');
				bar.style.width = pct + '%';
				bar.textContent = pct > 8 ? pct + '%' : '';
				document.getElementById('wsmb-progress-text').textContent = text;
			}
			function setRunning(running) {
				wsmbRunning = running;
				document.getElementById('wsmb-btn-dry').disabled  = running;
				document.getElementById('wsmb-btn-live').disabled = running;
				document.getElementById('wsmb-btn-stop').style.display = running ? 'inline-block' : 'none';
			}

			async function runMigration(dryRun) {
				if (wsmbRunning) return;
				wsmbStop = false;
				setRunning(true);
				document.getElementById('wsmb-log').innerHTML = '';
				document.getElementById('wsmb-log').style.display = 'none';
				document.getElementById('wsmb-result-box').style.display = 'none';

				var batchSize = parseInt(document.getElementById('wsmb-batch-size').value);
				var offset    = parseInt(document.getElementById('wsmb-offset').value) || 0;
				var subIds    = document.getElementById('wsmb-sub-ids').value.trim();
				var statuses  = [];
				document.querySelectorAll('.wsmb-status:checked').forEach(function(cb){ statuses.push(cb.value); });
				var status    = statuses.join(',') || 'active';
				var mode      = dryRun ? 'DRY RUN' : 'LIVE IMPORT';
				var ok = 0, skip = 0, fail = 0, total = 0;

				log('[' + mode + '] Started — status: ' + status + ', batch: ' + batchSize + ', offset: ' + offset, 'wsmb-log-done');

				try {
					while (!wsmbStop) {
						var params = 'token=' + encodeURIComponent(wsmbToken) + '&limit=' + batchSize + '&offset=' + offset + '&status=' + encodeURIComponent(status);
						if (subIds) params += '&subscription_ids=' + encodeURIComponent(subIds);
						var proxyUrl = wsmbProxyUrl + '?' + params;

						log('Fetching offset=' + offset + ' via server proxy...', 'wsmb-log-info');
						var srcRes = await fetch(proxyUrl, { credentials: 'same-origin' });
						if (!srcRes.ok) {
							var errTxt = await srcRes.text();
							try { var errJson = JSON.parse(errTxt); errTxt = errJson.message || errTxt; } catch(e){}
							throw new Error('Proxy fetch failed ' + srcRes.status + ': ' + errTxt.substring(0,300));
						}
						var srcData = await srcRes.json();

						if (!srcData.subscriptions || srcData.subscriptions.length === 0) {
							log('No more subscriptions found. Migration complete.', 'wsmb-log-done');
							break;
						}

						log('Fetched ' + srcData.subscriptions.length + ' subscriptions. Pushing...', 'wsmb-log-info');
						var pushRes = await fetch(wsmbPushUrl + '?dry_run=' + (dryRun?1:0) + '&token=' + encodeURIComponent(wsmbToken), {
							method: 'POST',
							headers: {'Content-Type': 'application/json'},
							body: JSON.stringify(srcData)
						});
						if (!pushRes.ok) throw new Error('Push failed HTTP ' + pushRes.status);
						var result = await pushRes.json();

						(result.results || []).forEach(function(r) {
							total++;
							var w = r.warnings && r.warnings.length ? ' ⚠ ' + r.warnings.join('; ') : '';
							if (r.status === 'exists')    { skip++; log('  #' + r.old_id + ' SKIPPED (already migrated)', 'wsmb-log-info'); }
							else if (r.status === 'dry_run')  { ok++;   log('  #' + r.old_id + ' DRY-OK' + w, w ? 'wsmb-log-warn' : 'wsmb-log-ok'); }
							else if (r.status === 'imported') { ok++;   log('  #' + r.old_id + ' → Sublium #' + r.sublium_id + w, w ? 'wsmb-log-warn' : 'wsmb-log-ok'); }
							else { fail++; log('  #' + r.old_id + ' ERROR: ' + JSON.stringify(r), 'wsmb-log-error'); }
						});

						offset += srcData.subscriptions.length;
						document.getElementById('wsmb-offset').value = offset;
						if (subIds) break; // specific IDs: one batch only

						var pct = Math.min(99, Math.round(ok / Math.max(total, 1) * 100));
						setProgress(pct, 'Processed: ' + total + ' | OK: ' + ok + ' | Skipped: ' + skip + ' | Errors: ' + fail);
						await new Promise(function(r){ setTimeout(r, 600); });
					}
				} catch(e) {
					log('FATAL: ' + e.message, 'wsmb-log-error');
				}

				setProgress(100, 'Done — Processed: ' + total + ' | OK: ' + ok + ' | Skipped: ' + skip + ' | Errors: ' + fail);
				var rb = document.getElementById('wsmb-result-box');
				rb.style.display = 'block';
				rb.innerHTML = '<strong>' + mode + ' complete</strong><br>Processed: <strong>' + total + '</strong> &nbsp;|&nbsp; ' +
					'OK: <strong style="color:green">' + ok + '</strong> &nbsp;|&nbsp; ' +
					'Skipped: <strong>' + skip + '</strong> &nbsp;|&nbsp; ' +
					'Errors: <strong style="color:red">' + fail + '</strong>';
				setRunning(false);
			}

			var btnDry  = document.getElementById('wsmb-btn-dry');
			var btnLive = document.getElementById('wsmb-btn-live');
			var btnStop = document.getElementById('wsmb-btn-stop');
			if (btnDry)  btnDry.addEventListener('click',  function(){ runMigration(true); });
			if (btnLive) btnLive.addEventListener('click', function(){
				if (!confirm('This will write real data to Sublium. Did you run a dry run first and review the results?\n\nClick OK to proceed with LIVE IMPORT.')) return;
				runMigration(false);
			});
			if (btnStop) btnStop.addEventListener('click',  function(){ wsmbStop = true; log('Stop requested — finishing current batch...', 'wsmb-log-warn'); });

			var btnCleanup = document.getElementById('wsmb-btn-cleanup');
			var wsmbCleanupUrl = <?php echo wp_json_encode( rest_url( self::REST_NS . '/destination/cleanup' ) ); ?>;
			if (btnCleanup) btnCleanup.addEventListener('click', async function() {
				var subIds = document.getElementById('wsmb-sub-ids').value.trim();
				var msg = subIds
					? 'This will reset migration tracking for subscription IDs: ' + subIds + '.\nThey can then be reimported. Continue?'
					: 'This will reset ALL migration tracking data so everything can be reimported.\n\nOnly do this if you have deleted all previously imported Sublium subscriptions. Continue?';
				if (!confirm(msg)) return;
				log('Cleaning up migration tracking data...', 'wsmb-log-info');
				document.getElementById('wsmb-log').style.display = 'block';
				try {
					var url = wsmbCleanupUrl + '?token=' + encodeURIComponent(wsmbToken);
					if (subIds) url += '&subscription_ids=' + encodeURIComponent(subIds);
					var res = await fetch(url, { method: 'POST', credentials: 'same-origin' });
					var data = await res.json();
					if (data.message) {
						log('✓ ' + data.message, 'wsmb-log-done');
						log('  Sublium subscriptions deleted: ' + (data.deleted_sublium_subscriptions || 0), 'wsmb-log-info');
						log('  WC order meta cleared', 'wsmb-log-info');
						document.getElementById('wsmb-offset').value = 0;
					} else {
						log('Response: ' + JSON.stringify(data), 'wsmb-log-warn');
					}
				} catch(e) {
					log('Cleanup error: ' + e.message, 'wsmb-log-error');
				}
			});
		var wsmbBulkFixUrl = <?php echo wp_json_encode( rest_url( self::REST_NS . '/destination/bulk-fix' ) ); ?>;
		var btnBulkFix = document.getElementById('wsmb-btn-bulk-fix');
		if (btnBulkFix) {
			btnBulkFix.addEventListener('click', async function() {
				if (!confirm('This will fix profile IDs, order links and renewal links for ALL migrated subscriptions. Continue?')) return;
				btnBulkFix.disabled = true;
				btnBulkFix.textContent = '⏳ Running...';
				var resultEl = document.getElementById('wsmb-bulk-fix-result');
				resultEl.innerHTML = '<div style="color:#646970">Processing all subscriptions...</div>';
				try {
					var res = await fetch(wsmbBulkFixUrl + '?token=' + encodeURIComponent(wsmbToken), { method: 'POST', credentials: 'same-origin' });
					var data = await res.json();
					resultEl.innerHTML = '<div style="background:#f0f6fc;border-left:4px solid #00a32a;padding:10px 14px;border-radius:0 4px 4px 0">' +
						'✅ <strong>' + (data.message || 'Done') + '</strong><br>' +
						'Total subscriptions: <strong>' + (data.total_subs || 0) + '</strong><br>' +
						'Profile IDs fixed: <strong style="color:green">' + (data.fixed_profile || 0) + '</strong><br>' +
						'Parent orders linked: <strong style="color:green">' + (data.fixed_orders || 0) + '</strong><br>' +
						'Renewal orders linked: <strong style="color:green">' + (data.fixed_renewals || 0) + '</strong>' +
						'</div>';
				} catch(e) {
					resultEl.innerHTML = '<div style="color:red">Error: ' + e.message + '</div>';
				}
				btnBulkFix.disabled = false;
				btnBulkFix.textContent = '🔧 Run Bulk Fix';
			});
		}

		var wsmbSyncUrl = <?php echo wp_json_encode( rest_url( self::REST_NS . '/destination/sync-payment-profiles' ) ); ?>;
		var btnSyncProfiles = document.getElementById('wsmb-btn-sync-profiles');
		if (btnSyncProfiles) {
			btnSyncProfiles.addEventListener('click', async function() {
				btnSyncProfiles.disabled = true;
				btnSyncProfiles.textContent = '⏳ Syncing...';
				var resultEl = document.getElementById('wsmb-sync-result');
				var emailFilter = document.getElementById('wsmb-sync-email').value.trim();
				resultEl.innerHTML = '';
				var totalUpdated = 0, totalSkipped = 0, totalNotFound = 0;
				var offset = 0;
				var limit = emailFilter ? 200 : 100;
				var hasMore = true;
				try {
					while ( hasMore ) {
						var url = wsmbSyncUrl + '?token=' + encodeURIComponent(wsmbToken) + '&offset=' + offset + '&limit=' + limit;
						if (emailFilter) url += '&email=' + encodeURIComponent(emailFilter);
						var res = await fetch(url, { method: 'POST', credentials: 'same-origin' });
						if (!res.ok) {
							var err = await res.text();
							try { err = JSON.parse(err).message || err; } catch(e) {}
							resultEl.innerHTML = '<div style="color:red">Error: ' + err + '</div>';
							break;
						}
						var data = await res.json();
						totalUpdated  += data.updated || 0;
						totalSkipped  += data.skipped || 0;
						totalNotFound += data.not_found || 0;

						// Show details for single email test
						if (emailFilter && data.details && data.details.length > 0) {
							var d = data.details[0];
							resultEl.innerHTML = '<div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:10px 14px;border-radius:0 4px 4px 0">' +
								'Email: <strong>' + d.email + '</strong><br>' +
								'Result: <strong>' + d.result + '</strong>' + (d.user_id ? ' (User ID: ' + d.user_id + ')' : '') +
								'</div>';
							break;
						}

						// Continue if source returned a full batch
						var batchSize = (data.profiles || []).length;
						hasMore = !emailFilter && batchSize >= limit && data.source_count > offset + limit;
						offset += limit;

						resultEl.innerHTML = '<div style="color:#646970">Syncing... offset: ' + offset +
							' | Updated: ' + totalUpdated + ' | Already set: ' + totalSkipped + ' | Not found: ' + totalNotFound + '</div>';
						await new Promise(function(r){ setTimeout(r, 300); });
					}
					if (!emailFilter) {
						resultEl.innerHTML = '<div style="background:#f0f6fc;border-left:4px solid #00a32a;padding:10px 14px;border-radius:0 4px 4px 0">' +
							'✅ <strong>Sync complete!</strong><br>' +
							'Profile IDs updated: <strong style="color:green">' + totalUpdated + '</strong> &nbsp;|&nbsp; ' +
							'Already had profile ID: <strong>' + totalSkipped + '</strong> &nbsp;|&nbsp; ' +
							'User not found on beta: <strong>' + totalNotFound + '</strong>' +
							'</div>';
					}
				} catch(e) {
					resultEl.innerHTML = '<div style="color:red">Error: ' + e.message + '</div>';
				}
				btnSyncProfiles.disabled = false;
				btnSyncProfiles.textContent = '🔄 Sync Payment Profiles';
			});
		}
		})();
		</script>
		<?php
	}


	public function register_routes() {
		register_rest_route(
			self::REST_NS,
			'/source/subscriptions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'export_source_subscriptions' ),
				'permission_callback' => array( $this, 'source_permission' ),
				'args'                => array(
					'limit'  => array( 'default' => 25 ),
					'offset' => array( 'default' => 0 ),
					'status' => array( 'default' => 'active' ),
					'customer_emails' => array( 'default' => '' ),
					'customer_ids' => array( 'default' => '' ),
					'subscription_ids' => array( 'default' => '' ),
				'product_ids' => array( 'default' => '' ),
				),
			)
		);

		register_rest_route(
			self::REST_NS,
			'/destination/pull',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( $this, 'pull_into_destination' ),
				'permission_callback' => function ( WP_REST_Request $request ) {
					if ( current_user_can( 'manage_woocommerce' ) ) {
						return true;
					}
					$settings = $this->get_settings();
					$token    = (string) $request->get_param( 'token' );
					return ! empty( $settings['bridge_token'] ) && ! empty( $token ) && hash_equals( $settings['bridge_token'], $token );
				},
				'args'                => array(
					'token'   => array( 'default' => '' ),
					'limit'   => array( 'default' => 10 ),
					'offset'  => array( 'default' => 0 ),
					'dry_run' => array( 'default' => 1 ),
					'customer_emails' => array( 'default' => '' ),
					'customer_ids' => array( 'default' => '' ),
					'subscription_ids' => array( 'default' => '' ),
				),
			)
		);

		register_rest_route(
			self::REST_NS,
			'/destination/push',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'push_into_destination' ),
				'permission_callback' => function ( WP_REST_Request $request ) {
					if ( current_user_can( 'manage_woocommerce' ) ) {
						return true;
					}
					$settings = $this->get_settings();
					$token    = (string) $request->get_param( 'token' );
					return ! empty( $settings['bridge_token'] ) && ! empty( $token ) && hash_equals( $settings['bridge_token'], $token );
				},
				'args'                => array(
					'token'   => array( 'default' => '' ),
					'dry_run' => array( 'default' => 1 ),
				),
			)
		);

		register_rest_route(
			self::REST_NS,
			'/destination/debug-plans',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'debug_plans' ),
				'permission_callback' => function ( WP_REST_Request $request ) {
					if ( current_user_can( 'manage_woocommerce' ) ) {
						return true;
					}
					$settings = $this->get_settings();
					$token    = (string) $request->get_param( 'token' );
					return ! empty( $settings['bridge_token'] ) && ! empty( $token ) && hash_equals( $settings['bridge_token'], $token );
				},
				'args' => array(
					'token'      => array( 'default' => '' ),
					'product_id' => array( 'default' => 0 ),
				),
			)
		);

		// Order debug endpoint
		register_rest_route(
			self::REST_NS,
			'/destination/debug-order',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'debug_order' ),
				'permission_callback' => function( WP_REST_Request $r ) {
					if ( current_user_can( 'manage_woocommerce' ) ) return true;
					$s = $this->get_settings(); $t = (string)$r->get_param('token');
					return !empty($s['bridge_token']) && !empty($t) && hash_equals($s['bridge_token'],$t);
				},
				'args' => array(
					'token'    => array( 'default' => '' ),
					'order_id' => array( 'default' => 0 ),
				),
			)
		);

		// Subscriber debug endpoint
		register_rest_route(
			self::REST_NS,
			'/destination/debug-subscriber',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'debug_subscriber' ),
				'permission_callback' => function( WP_REST_Request $r ) {
					if ( current_user_can( 'manage_woocommerce' ) ) return true;
					$s = $this->get_settings(); $t = (string)$r->get_param('token');
					return !empty($s['bridge_token']) && !empty($t) && hash_equals($s['bridge_token'],$t);
				},
				'args' => array(
					'token'   => array( 'default' => '' ),
					'user_id' => array( 'default' => 0 ),
					'sub_id'  => array( 'default' => 0 ),
				),
			)
		);

		// Proxy endpoint — browser calls this on beta, beta fetches from source server-side
		register_rest_route(
			self::REST_NS,
			'/destination/proxy-fetch',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'proxy_fetch' ),
				'permission_callback' => function ( WP_REST_Request $request ) {
					if ( current_user_can( 'manage_woocommerce' ) ) {
						return true;
					}
					$settings = $this->get_settings();
					$token    = (string) $request->get_param( 'token' );
					return ! empty( $settings['bridge_token'] ) && ! empty( $token ) && hash_equals( $settings['bridge_token'], $token );
				},
				'args' => array(
					'token'            => array( 'default' => '' ),
					'limit'            => array( 'default' => 25 ),
					'offset'           => array( 'default' => 0 ),
					'status'           => array( 'default' => 'active' ),
					'subscription_ids' => array( 'default' => '' ),
					'product_ids'      => array( 'default' => '' ),
				),
			)
		);

		// Sync Authorize.net customer profile IDs from old site to new site user meta.
		register_rest_route(
			self::REST_NS,
			'/destination/sync-payment-profiles',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'sync_payment_profiles' ),
				'permission_callback' => function ( WP_REST_Request $request ) {
					if ( current_user_can( 'manage_woocommerce' ) ) return true;
					$settings = $this->get_settings();
					$token    = (string) $request->get_param( 'token' );
					return ! empty( $settings['bridge_token'] ) && ! empty( $token ) && hash_equals( $settings['bridge_token'], $token );
				},
				'args' => array(
					'token'  => array( 'default' => '' ),
					'offset' => array( 'default' => 0 ),
					'limit'  => array( 'default' => 50 ),
					'email'  => array( 'default' => '' ),
				),
			)
		);

		// Also add source endpoint to export payment profile IDs.
		register_rest_route(
			self::REST_NS,
			'/source/payment-profiles',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'export_payment_profiles' ),
				'permission_callback' => array( $this, 'source_permission' ),
				'args' => array(
					'token'  => array( 'default' => '' ),
					'offset' => array( 'default' => 0 ),
					'limit'  => array( 'default' => 50 ),
					'email'  => array( 'default' => '' ),
				),
			)
		);

		// Bulk fix endpoint — fixes profile IDs, order links, renewal links for all subscriptions.
		register_rest_route(
			self::REST_NS,
			'/destination/bulk-fix',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'bulk_fix_subscriptions' ),
				'permission_callback' => function( WP_REST_Request $r ) {
					if ( current_user_can( 'manage_woocommerce' ) ) return true;
					$s = $this->get_settings(); $t = (string)$r->get_param('token');
					return !empty($s['bridge_token']) && !empty($t) && hash_equals($s['bridge_token'],$t);
				},
				'args' => array( 'token' => array( 'default' => '' ) ),
			)
		);

		// Cleanup endpoint — clears all migration tracking so records can be reimported.
		register_rest_route(
			self::REST_NS,
			'/destination/cleanup',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cleanup_migration' ),
				'permission_callback' => function ( WP_REST_Request $request ) {
					if ( current_user_can( 'manage_woocommerce' ) ) return true;
					$settings = $this->get_settings();
					$token = (string) $request->get_param( 'token' );
					return ! empty( $settings['bridge_token'] ) && ! empty( $token ) && hash_equals( $settings['bridge_token'], $token );
				},
				'args' => array(
					'token'            => array( 'default' => '' ),
					'subscription_ids' => array( 'default' => '' ),
				),
			)
		);
	}

	/**
	 * Fix existing migrated parent orders — add _sublium_wcs_subscription_id meta.
	 * Sublium requires this to link orders to subscriptions in the admin and for renewals.
	 */
	private function fix_migrated_order_subscription_links() {
		global $wpdb;
		$sub_meta_table  = $wpdb->prefix . 'sublium_wcs_subscription_meta';
		$hpos_meta_table = $wpdb->prefix . 'wc_orders_meta';

		// Get all migrated subscriptions with their old WCS subscription IDs.
		$subs = $wpdb->get_results(
			"SELECT s.id as sub_id, s.parent_order_id, sm.meta_value as old_wcs_id
			 FROM {$wpdb->prefix}sublium_wcs_subscriptions s
			 INNER JOIN {$sub_meta_table} sm ON sm.subscription_id = s.id AND sm.meta_key = '_wsmb_old_wcs_subscription_id'
			 WHERE s.parent_order_id > 0",
			ARRAY_A
		);

		if ( empty( $subs ) ) return 0;

		$fixed = 0;
		foreach ( $subs as $row ) {
			$order_id   = (int) $row['parent_order_id'];
			$sub_id     = (int) $row['sub_id'];
			$old_wcs_id = (int) $row['old_wcs_id'];

			if ( ! $order_id || ! $sub_id ) continue;

			// Fix parent order.
			$existing = $wpdb->get_var( $wpdb->prepare(
				"SELECT meta_value FROM {$hpos_meta_table} WHERE order_id = %d AND meta_key = '_sublium_wcs_subscription_id' LIMIT 1",
				$order_id
			) );
			if ( ! $existing ) {
				$wpdb->insert( $hpos_meta_table, array( 'order_id' => $order_id, 'meta_key' => '_sublium_wcs_subscription_id', 'meta_value' => $sub_id ) );
				$fixed++;
			} elseif ( (int) $existing !== $sub_id ) {
				$wpdb->update( $hpos_meta_table, array( 'meta_value' => $sub_id ), array( 'order_id' => $order_id, 'meta_key' => '_sublium_wcs_subscription_id' ) );
				$fixed++;
			}

			// Fix renewal orders.
			if ( $old_wcs_id ) {
				$renewal_ids = $wpdb->get_col( $wpdb->prepare(
					"SELECT DISTINCT order_id FROM {$hpos_meta_table} WHERE meta_key = '_subscription_renewal' AND meta_value = %s",
					$old_wcs_id
				) );
				if ( empty( $renewal_ids ) ) {
					$renewal_ids = $wpdb->get_col( $wpdb->prepare(
						"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_subscription_renewal' AND meta_value = %s",
						$old_wcs_id
					) );
				}
				foreach ( $renewal_ids as $renewal_id ) {
					$renewal_id = absint( $renewal_id );
					if ( ! $renewal_id ) continue;

					$ex = $wpdb->get_var( $wpdb->prepare(
						"SELECT id FROM {$hpos_meta_table} WHERE order_id = %d AND meta_key = '_sublium_wcs_subscription_id' LIMIT 1",
						$renewal_id
					) );
					if ( ! $ex ) {
						$wpdb->insert( $hpos_meta_table, array( 'order_id' => $renewal_id, 'meta_key' => '_sublium_wcs_subscription_id', 'meta_value' => $sub_id ) );
						$fixed++;
					}
					$ex2 = $wpdb->get_var( $wpdb->prepare(
						"SELECT id FROM {$hpos_meta_table} WHERE order_id = %d AND meta_key = '_sublium_wcs_subscription_renewal' LIMIT 1",
						$renewal_id
					) );
					if ( ! $ex2 ) {
						$wpdb->insert( $hpos_meta_table, array( 'order_id' => $renewal_id, 'meta_key' => '_sublium_wcs_subscription_renewal', 'meta_value' => 'yes' ) );
						$fixed++;
					}
				}
			}
		}

		return $fixed;
	}

	/**
	 * SOURCE: Export Authorize.net customer profile IDs from old site.
	 * Returns list of email → profile_id mappings.
	 */
	public function export_payment_profiles( WP_REST_Request $request ) {
		global $wpdb;

		$offset       = absint( $request->get_param( 'offset' ) );
		$limit        = min( 200, max( 1, absint( $request->get_param( 'limit' ) ) ) );
		$email_filter = sanitize_email( $request->get_param( 'email' ) );

		// Fix existing migrated orders — add _sublium_wcs_subscription_id to parent orders.
		if ( $offset === 0 ) {
			$this->fix_migrated_order_subscription_links();
		}

		$profile_keys = array(
			'_wc_authorize_net_cim_credit_card_customer_id',
			'_wc_authorize_net_cim_echeck_customer_id',
		);

		$profiles = array();

		$email_where = $email_filter ? $wpdb->prepare( " AND u.user_email = %s", $email_filter ) : '';

		// First try: user meta.
		foreach ( $profile_keys as $meta_key ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT u.user_email, u.ID as user_id, um.meta_value as profile_id
				 FROM {$wpdb->usermeta} um
				 INNER JOIN {$wpdb->users} u ON u.ID = um.user_id
				 WHERE um.meta_key = %s
				   AND um.meta_value != ''
				   {$email_where}
				 ORDER BY u.ID ASC
				 LIMIT %d OFFSET %d",
				$meta_key, $limit, $offset
			), ARRAY_A );

			foreach ( $rows as $row ) {
				$email = strtolower( $row['user_email'] );
				if ( ! isset( $profiles[ $email ] ) ) {
					$profiles[ $email ] = array( 'email' => $email, 'user_id' => (int) $row['user_id'] );
				}
				$profiles[ $email ][ $meta_key ] = $row['profile_id'];
			}
		}

		// Second: order meta — Authorize.net stores customer_id on orders, not always on users.
		foreach ( $profile_keys as $meta_key ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DISTINCT om.meta_value as profile_id, o.customer_id, u.user_email
					 FROM {$wpdb->prefix}wc_orders_meta om
					 INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = om.order_id
					 INNER JOIN {$wpdb->users} u ON u.ID = o.customer_id
					 WHERE om.meta_key = %s
					   AND om.meta_value != ''
					   AND o.customer_id > 0
					   {$email_where}
					 ORDER BY o.customer_id ASC
					 LIMIT %d OFFSET %d",
					$meta_key, $limit, $offset
				),
				ARRAY_A
			);

			foreach ( $rows as $row ) {
				$email = strtolower( $row['user_email'] );
				if ( ! isset( $profiles[ $email ] ) ) {
					$profiles[ $email ] = array( 'email' => $email, 'user_id' => (int) $row['customer_id'] );
				}
				// Only set if not already found in user meta.
				if ( empty( $profiles[ $email ][ $meta_key ] ) && ! empty( $row['profile_id'] ) ) {
					$profiles[ $email ][ $meta_key ] = $row['profile_id'];
				}
			}
		}

		// Third: postmeta fallback for legacy non-HPOS orders.
		foreach ( $profile_keys as $meta_key ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DISTINCT pm.meta_value as profile_id, p.post_author, u.user_email,
					        om2.meta_value as customer_user
					 FROM {$wpdb->postmeta} pm
					 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'shop_subscription'
					 LEFT JOIN {$wpdb->postmeta} om2 ON om2.post_id = pm.post_id AND om2.meta_key = '_customer_user'
					 INNER JOIN {$wpdb->users} u ON u.ID = COALESCE(om2.meta_value, p.post_author)
					 WHERE pm.meta_key = %s
					   AND pm.meta_value != ''
					   {$email_where}
					 ORDER BY pm.post_id ASC
					 LIMIT %d OFFSET %d",
					$meta_key, $limit, $offset
				),
				ARRAY_A
			);

			foreach ( $rows as $row ) {
				$email = strtolower( $row['user_email'] );
				if ( ! isset( $profiles[ $email ] ) ) {
					$profiles[ $email ] = array( 'email' => $email, 'user_id' => (int) $row['customer_user'] );
				}
				if ( empty( $profiles[ $email ][ $meta_key ] ) && ! empty( $row['profile_id'] ) ) {
					$profiles[ $email ][ $meta_key ] = $row['profile_id'];
				}
			}
		}

		// Filter out profiles with no profile IDs found.
		$profiles = array_filter( $profiles, function( $p ) use ( $profile_keys ) {
			foreach ( $profile_keys as $k ) {
				if ( ! empty( $p[ $k ] ) ) return true;
			}
			return false;
		} );

		return rest_ensure_response( array(
			'count'    => count( $profiles ),
			'offset'   => $offset,
			'limit'    => $limit,
			'profiles' => array_values( $profiles ),
		) );
	}

	/**
	 * DESTINATION: Fetch profile IDs from old site and write to beta user meta.
	 * Call this once after migration to fix renewal payment processing.
	 */
	public function sync_payment_profiles( WP_REST_Request $request ) {
		global $wpdb;
		$settings    = $this->get_settings();
		$src_url     = rtrim( $settings['source_url'] ?? '', '/' );
		$src_token   = $settings['source_token'] ?? '';
		$offset      = absint( $request->get_param( 'offset' ) );
		$limit       = min( 200, max( 1, absint( $request->get_param( 'limit' ) ) ) );
		$email_filter = sanitize_email( $request->get_param( 'email' ) );

		if ( empty( $src_url ) || empty( $src_token ) ) {
			return new WP_Error( 'config_missing', 'Old Store URL or Bridge Token not configured in Settings.', array( 'status' => 400 ) );
		}

		$params = array(
			'token'  => $src_token,
			'limit'  => $limit,
			'offset' => $offset,
		);
		if ( $email_filter ) {
			$params['email'] = $email_filter;
		}

		$url = add_query_arg( $params, $src_url . '/wp-json/' . self::REST_NS . '/source/payment-profiles' );

		$response = wp_remote_get( $url, array(
			'timeout'    => 30,
			'user-agent' => 'Mozilla/5.0 (compatible; WSMB-Sync/1.0)',
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'fetch_failed', $response->get_error_message(), array( 'status' => 502 ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 || ! is_array( $data ) || ! isset( $data['profiles'] ) ) {
			return new WP_Error( 'fetch_error', 'Source returned HTTP ' . $code, array( 'status' => 502, 'body' => wp_remote_retrieve_body( $response ) ) );
		}

		$profile_keys = array(
			'_wc_authorize_net_cim_credit_card_customer_id',
			'_wc_authorize_net_cim_echeck_customer_id',
		);

		$updated  = 0;
		$skipped  = 0;
		$notfound = 0;
		$details  = array();

		foreach ( $data['profiles'] as $profile ) {
			$email = sanitize_email( $profile['email'] ?? '' );
			if ( empty( $email ) ) continue;

			// Find matching user on beta by email.
			$user = get_user_by( 'email', $email );
			if ( ! $user ) {
				$notfound++;
				$details[] = array( 'email' => $email, 'result' => 'user_not_found_on_beta' );
				continue;
			}

			$wrote = false;
			foreach ( $profile_keys as $key ) {
				if ( ! empty( $profile[ $key ] ) ) {
					$profile_value = sanitize_text_field( $profile[ $key ] );

					// Check if token meta has a more authoritative value.
					$token_profile_id = $wpdb->get_var( $wpdb->prepare(
						"SELECT tm.meta_value
						 FROM {$wpdb->prefix}woocommerce_payment_tokens t
						 INNER JOIN {$wpdb->prefix}woocommerce_payment_tokenmeta tm ON tm.payment_token_id = t.token_id
						 WHERE t.user_id = %d
						   AND t.gateway_id = 'authorize_net_cim_credit_card'
						   AND tm.meta_key = 'customer_profile_id'
						   AND tm.meta_value != ''
						 ORDER BY t.is_default DESC, t.token_id DESC
						 LIMIT 1",
						$user->ID
					) );
					// Use token meta value if available — it's the most reliable source.
					if ( $token_profile_id ) {
						$profile_value = $token_profile_id;
					}

					// Write to user meta — always update to fix potentially wrong values.
					$existing = get_user_meta( $user->ID, $key, true );
					if ( empty( $existing ) || $existing !== $profile_value ) {
						update_user_meta( $user->ID, $key, $profile_value );
						$wrote = true;
					}

				// Also write to Sublium subscription meta for ALL subscriptions belonging to this user.
				// Sublium reads customer_id from subscription meta for renewal processing.
				$sub_meta_table = $wpdb->prefix . 'sublium_wcs_subscription_meta';
				$sub_table      = $wpdb->prefix . 'sublium_wcs_subscriptions';
				$plan_table     = $wpdb->prefix . 'sublium_wcs_plan';
				$rel_table      = $wpdb->prefix . 'sublium_wcs_plan_relations';
				$sub_ids = $wpdb->get_results( $wpdb->prepare(
					"SELECT id, plan_id FROM {$sub_table} WHERE user_id = %d",
					$user->ID
				), ARRAY_A );
				foreach ( $sub_ids as $sub_row ) {
					$sub_id = $sub_row['id'];

					// Write customer profile ID — use token meta value if available (most reliable).
					$exists = $wpdb->get_var( $wpdb->prepare(
						"SELECT id FROM {$sub_meta_table} WHERE subscription_id = %d AND meta_key = %s LIMIT 1",
						$sub_id, $key
					) );
					if ( ! $exists ) {
						$wpdb->insert( $sub_meta_table, array(
							'subscription_id' => $sub_id,
							'meta_key'        => $key,
							'meta_value'      => $profile_value,
						) );
						$wrote = true;
					} elseif ( isset( $token_profile_id ) && $token_profile_id ) {
						// Update existing wrong value with the correct token meta value.
						$current = $wpdb->get_var( $wpdb->prepare(
							"SELECT meta_value FROM {$sub_meta_table} WHERE subscription_id = %d AND meta_key = %s LIMIT 1",
							$sub_id, $key
						) );
						if ( $current !== $profile_value ) {
							$wpdb->update( $sub_meta_table,
								array( 'meta_value' => $profile_value ),
								array( 'subscription_id' => $sub_id, 'meta_key' => $key )
							);
							$wrote = true;
						}
					}

					// Also add missing plan_data, _sublium_payment_mode, retry_count.
					$missing_keys = array( 'plan_data', '_sublium_payment_mode', 'retry_count' );
					foreach ( $missing_keys as $mk ) {
						$mk_exists = $wpdb->get_var( $wpdb->prepare(
							"SELECT id FROM {$sub_meta_table} WHERE subscription_id = %d AND meta_key = %s LIMIT 1",
							$sub_id, $mk
						) );
						if ( ! $mk_exists ) {
							$mk_value = '';
							if ( $mk === '_sublium_payment_mode' ) {
								$mk_value = 'live';
							} elseif ( $mk === 'retry_count' ) {
								$mk_value = '0';
							} elseif ( $mk === 'plan_data' ) {
								// Get plan data from plan table.
								$plan_ids_raw = json_decode( $sub_row['plan_id'] ?? '[]', true );
								$first_plan   = is_array( $plan_ids_raw ) ? reset( $plan_ids_raw ) : 0;
								if ( $first_plan ) {
									$plan_row = $wpdb->get_row( $wpdb->prepare(
										"SELECT * FROM {$plan_table} WHERE id = %d LIMIT 1",
										$first_plan
									), ARRAY_A );
									if ( $plan_row ) {
										$rel = $wpdb->get_row( $wpdb->prepare(
											"SELECT * FROM {$rel_table} WHERE plan_id = %d AND status = 1 LIMIT 1",
											$first_plan
										), ARRAY_A );
										$plan_row['object_type']   = '1';
										$plan_row['relation_data'] = $rel ? json_decode( $rel['data'] ?? '{}', true ) : new stdClass();
										$mk_value = wp_json_encode( $plan_row );
									}
								}
							}
							if ( $mk_value !== '' ) {
								$wpdb->insert( $sub_meta_table, array(
									'subscription_id' => $sub_id,
									'meta_key'        => $mk,
									'meta_value'      => $mk_value,
								) );
								$wrote = true;
							}
						}
					}
				}
				}
			}

			if ( $wrote ) {
				$updated++;
				$details[] = array( 'email' => $email, 'result' => 'updated', 'user_id' => $user->ID );
			} else {
				$skipped++;
				$details[] = array( 'email' => $email, 'result' => 'already_set', 'user_id' => $user->ID );
			}
		}

		return rest_ensure_response( array(
			'source_count' => $data['count'],
			'updated'      => $updated,
			'skipped'      => $skipped,
			'not_found'    => $notfound,
			'offset'       => $offset,
			'limit'        => $limit,
			'details'      => $details,
		) );
	}

	/**
	 * Bulk fix all migrated subscriptions:
	 * 1. Fix customer_profile_id from wp_woocommerce_payment_tokenmeta (authoritative source)
	 * 2. Fix _sublium_wcs_subscription_id on parent orders
	 * 3. Fix _sublium_wcs_subscription_renewal on renewal orders
	 */
	public function bulk_fix_subscriptions( WP_REST_Request $request ) {
		global $wpdb;

		$sub_table      = $wpdb->prefix . 'sublium_wcs_subscriptions';
		$sub_meta_table = $wpdb->prefix . 'sublium_wcs_subscription_meta';
		$hpos_meta      = $wpdb->prefix . 'wc_orders_meta';
		$tokens_table   = $wpdb->prefix . 'woocommerce_payment_tokens';
		$tokenmeta      = $wpdb->prefix . 'woocommerce_payment_tokenmeta';

		$fixed_profile  = 0;
		$fixed_orders   = 0;
		$fixed_renewals = 0;

		// Get all migrated subscriptions.
		$subs = $wpdb->get_results(
			"SELECT s.id as sub_id, s.parent_order_id, s.user_id, sm.meta_value as old_wcs_id
			 FROM {$sub_table} s
			 INNER JOIN {$sub_meta_table} sm ON sm.subscription_id = s.id AND sm.meta_key = '_wsmb_old_wcs_subscription_id'
			 WHERE s.user_id > 0",
			ARRAY_A
		);

		if ( empty( $subs ) ) {
			return rest_ensure_response( array( 'message' => 'No migrated subscriptions found.', 'fixed_profile' => 0, 'fixed_orders' => 0, 'fixed_renewals' => 0 ) );
		}

		foreach ( $subs as $row ) {
			$sub_id     = (int) $row['sub_id'];
			$order_id   = (int) $row['parent_order_id'];
			$user_id    = (int) $row['user_id'];
			$old_wcs_id = (int) $row['old_wcs_id'];

			// 1. Fix customer_profile_id — get from token meta first, then API.
			$payment_token = $wpdb->get_var( $wpdb->prepare(
				"SELECT meta_value FROM {$sub_meta_table}
				 WHERE subscription_id = %d AND meta_key = '_wc_authorize_net_cim_credit_card_payment_token' LIMIT 1",
				$sub_id
			) );

			$token_profile_id = $this->get_authnet_customer_profile_id( $user_id, $payment_token );

			if ( $token_profile_id ) {
				$key = '_wc_authorize_net_cim_credit_card_customer_id';

				// Fix user meta.
				update_user_meta( $user_id, $key, $token_profile_id );

				// Fix customer_id = 0 on parent order.
				if ( $order_id && $user_id ) {
					$wpdb->query( $wpdb->prepare(
						"UPDATE {$wpdb->prefix}wc_orders SET customer_id = %d WHERE id = %d AND customer_id = 0",
						$user_id, $order_id
					) );
				}

				// Fix subscription meta — insert or update.
				$exists = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$sub_meta_table} WHERE subscription_id = %d AND meta_key = %s LIMIT 1",
					$sub_id, $key
				) );
				if ( $exists ) {
					$wpdb->update( $sub_meta_table, array( 'meta_value' => $token_profile_id ), array( 'subscription_id' => $sub_id, 'meta_key' => $key ) );
				} else {
					$wpdb->insert( $sub_meta_table, array( 'subscription_id' => $sub_id, 'meta_key' => $key, 'meta_value' => $token_profile_id ) );
				}
				$fixed_profile++;
			}

			// 2. Fix parent order link — use parent_order_id directly from subscription record.
			if ( $order_id ) {
				$exists = $wpdb->get_var( $wpdb->prepare(
					"SELECT meta_value FROM {$hpos_meta} WHERE order_id = %d AND meta_key = '_sublium_wcs_subscription_id' LIMIT 1",
					$order_id
				) );
				if ( ! $exists ) {
					$wpdb->insert( $hpos_meta, array(
						'order_id'   => $order_id,
						'meta_key'   => '_sublium_wcs_subscription_id',
						'meta_value' => $sub_id,
					) );
					$fixed_orders++;
				} elseif ( (int) $exists !== $sub_id ) {
					$wpdb->update( $hpos_meta,
						array( 'meta_value' => $sub_id ),
						array( 'order_id' => $order_id, 'meta_key' => '_sublium_wcs_subscription_id' )
					);
					$fixed_orders++;
				}
			}

			// 3. Fix renewal orders — find via Sublium subscription meta renewal_orders JSON.
			$renewal_orders_raw = $wpdb->get_var( $wpdb->prepare(
				"SELECT meta_value FROM {$sub_meta_table} WHERE subscription_id = %d AND meta_key = 'renewal_orders' LIMIT 1",
				$sub_id
			) );
			$renewal_order_ids = $renewal_orders_raw ? json_decode( $renewal_orders_raw, true ) : array();

			// Also check beta orders linked via _sublium_wcs_subscription_id already.
			$beta_renewal_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT order_id FROM {$hpos_meta}
				 WHERE meta_key = '_sublium_wcs_subscription_id' AND meta_value = %d
				 AND order_id != %d",
				$sub_id, $order_id
			) );

			$all_renewal_ids = array_unique( array_merge(
				array_map( 'absint', (array) $renewal_order_ids ),
				array_map( 'absint', $beta_renewal_ids )
			) );

			foreach ( $all_renewal_ids as $rid ) {
				if ( ! $rid || $rid === $order_id ) continue;
				$ex2 = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$hpos_meta} WHERE order_id = %d AND meta_key = '_sublium_wcs_subscription_renewal' LIMIT 1",
					$rid
				) );
				if ( ! $ex2 ) {
					$wpdb->insert( $hpos_meta, array(
						'order_id'   => $rid,
						'meta_key'   => '_sublium_wcs_subscription_renewal',
						'meta_value' => 'yes',
					) );
					$fixed_renewals++;
				}
				// Also fix customer_id = 0 on renewal orders.
				if ( $user_id ) {
					$wpdb->query( $wpdb->prepare(
						"UPDATE {$wpdb->prefix}wc_orders SET customer_id = %d WHERE id = %d AND customer_id = 0",
						$user_id, $rid
					) );
				}
			}
		}

		// Fix all orders with customer_id=0 that are linked to Sublium subscriptions.
		$fixed_customers = (int) $wpdb->query(
			"UPDATE {$wpdb->prefix}wc_orders o
			 INNER JOIN {$hpos_meta} om ON om.order_id = o.id AND om.meta_key = '_sublium_wcs_subscription_id'
			 INNER JOIN {$wpdb->prefix}sublium_wcs_subscriptions s ON s.id = om.meta_value
			 SET o.customer_id = s.user_id
			 WHERE o.customer_id = 0
			 AND s.user_id > 0"
		);

		// Also fix orders linked via billing_email.
		$fixed_customers += (int) $wpdb->query(
			"UPDATE {$wpdb->prefix}wc_orders o
			 INNER JOIN {$wpdb->users} u ON LOWER(u.user_email) = LOWER(o.billing_email)
			 SET o.customer_id = u.ID
			 WHERE o.customer_id = 0
			 AND o.billing_email != ''
			 AND o.type IN ('shop_order', 'shop_subscription')"
		);

		$total_orders_zero_customer = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE customer_id = 0 AND type IN ('shop_order','shop_subscription')"
		);
		$total_renewal_flags = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$hpos_meta} WHERE meta_key = '_sublium_wcs_subscription_renewal' AND meta_value = 'yes'"
		);

		return rest_ensure_response( array(
			'message'                      => 'Bulk fix complete.',
			'total_subs'                   => count( $subs ),
			'fixed_profile'                => $fixed_profile,
			'fixed_orders'                 => $fixed_orders,
			'fixed_renewals'               => $fixed_renewals,
			'fixed_customer_ids'           => $fixed_customers,
			'remaining_zero_customer'      => $total_orders_zero_customer,
			'total_orders_with_sub_link'   => $total_orders_with_link,
			'total_renewal_flags'          => $total_renewal_flags,
			'last_sql_error'               => $wpdb->last_error,
		) );
	}

	public function cleanup_migration( WP_REST_Request $request ) {
		global $wpdb;

		$sub_ids_raw = sanitize_text_field( $request->get_param( 'subscription_ids' ) );
		$specific_ids = $sub_ids_raw ? array_map( 'absint', preg_split( '/[\s,]+/', $sub_ids_raw, -1, PREG_SPLIT_NO_EMPTY ) ) : array();

		$meta_table = $wpdb->prefix . 'sublium_wcs_subscription_meta';
		$sub_table  = $wpdb->prefix . 'sublium_wcs_subscriptions';
		$items_table = $wpdb->prefix . 'sublium_wcs_subscription_items';
		$sub_table_wc = $wpdb->prefix . 'sublium_wcs_subscribers';

		if ( ! empty( $specific_ids ) ) {
			// Delete only specific old subscription IDs from Sublium meta.
			$placeholders = implode( ',', array_fill( 0, count( $specific_ids ), '%s' ) );
			$sub_ids_to_delete = $wpdb->get_col( $wpdb->prepare(
				"SELECT subscription_id FROM {$meta_table} WHERE meta_key = %s AND meta_value IN ({$placeholders})",
				array_merge( array( self::META_OLD_ID ), array_map( 'strval', $specific_ids ) )
			) );
		} else {
			// Get all migration-created subscription IDs.
			$sub_ids_to_delete = $wpdb->get_col(
				"SELECT subscription_id FROM {$meta_table} WHERE meta_key = '" . self::META_OLD_ID . "'"
			);
		}

		if ( empty( $sub_ids_to_delete ) ) {
			return rest_ensure_response( array( 'deleted' => 0, 'message' => 'No migration records found to clean up.' ) );
		}

		$ids_list      = implode( ',', array_map( 'absint', $sub_ids_to_delete ) );
		$deleted_items = $wpdb->query( "DELETE FROM {$items_table} WHERE subscription_id IN ({$ids_list})" );
		$deleted_meta  = $wpdb->query( "DELETE FROM {$meta_table} WHERE subscription_id IN ({$ids_list})" );
		$deleted_subs  = $wpdb->query( "DELETE FROM {$sub_table} WHERE id IN ({$ids_list})" );

		// Also delete migration-created WooCommerce orders (HPOS).
		$deleted_orders = 0;
		$hpos_meta_table = $wpdb->prefix . 'wc_orders_meta';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$hpos_meta_table}'" ) ) {
			$wc_order_ids = $wpdb->get_col(
				"SELECT order_id FROM {$hpos_meta_table} WHERE meta_key = '" . self::META_OLD_ID . "'"
			);
			if ( ! empty( $wc_order_ids ) ) {
				foreach ( $wc_order_ids as $wc_oid ) {
					$order = wc_get_order( absint( $wc_oid ) );
					if ( $order ) {
						$order->delete( true );
						$deleted_orders++;
					}
				}
			}
			$wpdb->query( "DELETE FROM {$hpos_meta_table} WHERE meta_key = '" . self::META_OLD_ID . "'" );
		}
		// Legacy postmeta fallback.
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '" . self::META_OLD_ID . "'" );

		// Delete from sublium subscribers table too.
		$sub_subscribers = $wpdb->prefix . 'sublium_wcs_subscribers';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$sub_subscribers}'" ) ) {
			$wpdb->query( "DELETE FROM {$sub_subscribers} WHERE id NOT IN (SELECT DISTINCT user_id FROM {$sub_table})" );
		}

		return rest_ensure_response( array(
			'deleted_sublium_subscriptions' => (int) $deleted_subs,
			'deleted_sublium_items'         => (int) $deleted_items,
			'deleted_sublium_meta'          => (int) $deleted_meta,
			'deleted_wc_orders'             => $deleted_orders,
			'sublium_ids_cleaned'           => $sub_ids_to_delete,
			'message'                       => 'Migration data cleared including WC orders. You can now reimport.',
		) );
	}

	public function proxy_fetch( WP_REST_Request $request ) {
		$settings = $this->get_settings();
		$src_url  = rtrim( $settings['source_url'] ?? '', '/' );
		$src_token = $settings['source_token'] ?? '';

		if ( empty( $src_url ) || empty( $src_token ) ) {
			return new WP_Error( 'proxy_config', 'Old Store URL or Old Store Bridge Token not configured in Settings.', array( 'status' => 400 ) );
		}

		$params = array(
			'token'  => $src_token,
			'limit'  => absint( $request->get_param( 'limit' ) ),
			'offset' => absint( $request->get_param( 'offset' ) ),
			'status' => sanitize_text_field( $request->get_param( 'status' ) ),
		);
		$sub_ids = sanitize_text_field( $request->get_param( 'subscription_ids' ) );
		$prod_ids = sanitize_text_field( $request->get_param( 'product_ids' ) );
		if ( $sub_ids )  $params['subscription_ids'] = $sub_ids;
		if ( $prod_ids ) $params['product_ids']      = $prod_ids;

		$endpoint = $src_url . '/wp-json/' . self::REST_NS . '/source/subscriptions';
		$url      = add_query_arg( $params, $endpoint );

		$response = wp_remote_get( $url, array(
			'timeout'    => 60,
			'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
			'headers'    => array(
				'Accept'          => 'application/json, */*',
				'Accept-Language' => 'en-US,en;q=0.9',
				'Cache-Control'   => 'no-cache',
				'Referer'         => $src_url . '/wp-admin/',
			),
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'proxy_error', $response->get_error_message(), array( 'status' => 502 ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code !== 200 || ! is_array( $data ) ) {
			return new WP_Error( 'proxy_upstream', 'Source returned HTTP ' . $code . ': ' . $body, array( 'status' => 502 ) );
		}

		return rest_ensure_response( $data );
	}

	public function debug_plans( WP_REST_Request $request ) {
		global $wpdb;
		$product_id    = absint( $request->get_param( 'product_id' ) );
		$plan_table    = $wpdb->prefix . 'sublium_wcs_plan';
		$selling_table = $wpdb->prefix . 'sublium_wcs_selling_plan';
		$product_table = $wpdb->prefix . 'sublium_wcs_plan_product';

		// Show all tables with prefix sublium
		$tables = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}sublium%'" );

		// All plans
		$all_plans = $wpdb->get_results( "SELECT * FROM {$plan_table} LIMIT 20", ARRAY_A );

		// All selling plans
		$all_selling = $wpdb->get_results( "SELECT * FROM {$selling_table} LIMIT 20", ARRAY_A );

		$group_table   = $wpdb->prefix . 'sublium_wcs_plan_group';
		$rel_table     = $wpdb->prefix . 'sublium_wcs_plan_relations';

		$all_groups    = $wpdb->get_results( "SELECT * FROM {$group_table} LIMIT 20", ARRAY_A );
		$all_relations = $wpdb->get_results( "SELECT * FROM {$rel_table} LIMIT 20", ARRAY_A );

		$filtered = array();
		if ( $product_id ) {
			$filtered = $wpdb->get_results( $wpdb->prepare(
				"SELECT p.id as plan_id, p.title, p.billing_frequency, p.billing_interval, p.status, r.oid as product_id, r.vid as variation_id
				 FROM {$plan_table} p
				 INNER JOIN {$rel_table} r ON r.plan_id = p.id
				 WHERE r.oid = %d",
				$product_id
			), ARRAY_A );
		}

		// Show subscription items table structure
		$items_table = $wpdb->prefix . 'sublium_wcs_subscription_items';
		$item_cols   = $wpdb->get_results( "DESCRIBE {$items_table}", ARRAY_A );
		$sample_items = $wpdb->get_results( "SELECT * FROM {$items_table} LIMIT 3", ARRAY_A );

		// Show subscriptions table structure
		$sub_table  = $wpdb->prefix . 'sublium_wcs_subscriptions';
		$sub_cols   = $wpdb->get_results( "DESCRIBE {$sub_table}", ARRAY_A );
		$sample_sub = $wpdb->get_results( "SELECT * FROM {$sub_table} ORDER BY id DESC LIMIT 3", ARRAY_A );
		// Show subscription meta table
		$meta_table  = $wpdb->prefix . 'sublium_wcs_subscription_meta';
		$meta_cols   = $wpdb->get_results( "DESCRIBE {$meta_table}", ARRAY_A );
		$sample_meta = $wpdb->get_results( "SELECT * FROM {$meta_table} ORDER BY id DESC LIMIT 10", ARRAY_A );

		return rest_ensure_response( array(
			'tables'              => $tables,
			'plans'               => $all_plans,
			'plan_groups'         => $all_groups,
			'plan_relations'      => $all_relations,
			'filtered_by_product' => $filtered,
			'subscription_items_columns' => $item_cols,
			'sample_subscription_items'  => $sample_items,
			'subscriptions_columns'      => $sub_cols,
			'sample_subscriptions'       => $sample_sub,
			'subscription_meta_columns'  => $meta_cols,
			'sample_subscription_meta'   => $sample_meta,
			'last_sql_error'      => $wpdb->last_error,
		) );
	}

	public function debug_order( WP_REST_Request $request ) {
		$order_id = absint( $request->get_param( 'order_id' ) );
		if ( ! $order_id ) return new WP_Error( 'missing', 'Pass ?order_id=XXX', array('status'=>400) );
		$order = wc_get_order( $order_id );
		if ( ! $order ) return new WP_Error( 'not_found', 'Order not found', array('status'=>404) );
		return rest_ensure_response( array(
			'order_id'          => $order_id,
			'customer_id'       => $order->get_customer_id(),
			'billing_first'     => $order->get_billing_first_name(),
			'billing_last'      => $order->get_billing_last_name(),
			'billing_email'     => $order->get_billing_email(),
			'billing_phone'     => $order->get_billing_phone(),
			'billing_address_1' => $order->get_billing_address_1(),
			'billing_city'      => $order->get_billing_city(),
			'shipping_first'    => $order->get_shipping_first_name(),
			'shipping_last'     => $order->get_shipping_last_name(),
			'shipping_address_1'=> $order->get_shipping_address_1(),
			'shipping_city'     => $order->get_shipping_city(),
			'created_via'       => $order->get_created_via(),
			'old_sub_id'        => $order->get_meta( '_wsmb_old_wcs_subscription_id' ),
		) );
	}

	public function debug_subscriber( WP_REST_Request $request ) {
		global $wpdb;
		$user_id = absint( $request->get_param( 'user_id' ) );
		$sub_id  = absint( $request->get_param( 'sub_id' ) );

		$sub_tbl  = $wpdb->prefix . 'sublium_wcs_subscriptions';
		$meta_tbl = $wpdb->prefix . 'sublium_wcs_subscription_meta';
		$sub_sub  = $wpdb->prefix . 'sublium_wcs_subscribers';

		// Get latest migrated subscriptions.
		$subs = $wpdb->get_results( "SELECT * FROM {$sub_tbl} ORDER BY id DESC LIMIT 5", ARRAY_A );

		// Get specific subscription if sub_id provided.
		$specific_sub = array();
		if ( $sub_id ) {
			$specific_sub = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$sub_tbl} WHERE id = %d", $sub_id ), ARRAY_A );
		}

		// Get subscriber records.
		$subscribers = $wpdb->get_results( "SELECT * FROM {$sub_sub} ORDER BY id DESC LIMIT 5", ARRAY_A );

		// Get WP user info for user_id.
		$wp_user = null;
		if ( $user_id ) {
			$u = get_user_by( 'id', $user_id );
			if ( $u ) {
				$wp_user = array(
					'ID'           => $u->ID,
					'user_email'   => $u->user_email,
					'display_name' => $u->display_name,
					'first_name'   => get_user_meta( $u->ID, 'first_name', true ),
					'last_name'    => get_user_meta( $u->ID, 'last_name', true ),
					'billing_first_name' => get_user_meta( $u->ID, 'billing_first_name', true ),
					'billing_last_name'  => get_user_meta( $u->ID, 'billing_last_name', true ),
					'billing_email'      => get_user_meta( $u->ID, 'billing_email', true ),
					'billing_phone'      => get_user_meta( $u->ID, 'billing_phone', true ),
					'billing_address_1'  => get_user_meta( $u->ID, 'billing_address_1', true ),
					'billing_city'       => get_user_meta( $u->ID, 'billing_city', true ),
				);
			}
		}

		// Get subscription meta for sub_id.
		$sub_meta = array();
		if ( $sub_id ) {
			$sub_meta = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$meta_tbl} WHERE subscription_id = %d", $sub_id ), ARRAY_A );
		}

		// Also get meta for native subscription #47 for comparison.
		$native_meta = $wpdb->get_results( "SELECT meta_key, meta_value FROM {$meta_tbl} WHERE subscription_id = 47 ORDER BY id ASC", ARRAY_A );

		return rest_ensure_response( array(
			'specific_sublium_subscription' => $specific_sub,
			'latest_sublium_subscriptions' => $subs,
			'latest_sublium_subscribers'   => $subscribers,
			'wp_user_for_user_id'          => $wp_user,
			'subscription_meta_for_sub_id' => $sub_meta,
			'native_sub_47_meta'           => $native_meta,
			'sublium_subscribers_columns'  => $wpdb->get_col( "DESCRIBE {$sub_sub}", 0 ),
			'last_sql_error'               => $wpdb->last_error,
		) );
	}

	public function source_permission( WP_REST_Request $request ) {
		$settings = $this->get_settings();
		$token    = (string) $request->get_param( 'token' );

		return ! empty( $settings['bridge_token'] ) && hash_equals( $settings['bridge_token'], $token );
	}

	public function export_source_subscriptions( WP_REST_Request $request ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error( 'missing_woocommerce', 'WooCommerce is not active.', array( 'status' => 500 ) );
		}

		$limit  = min( 100, max( 1, absint( $request->get_param( 'limit' ) ) ) );
		$offset = max( 0, absint( $request->get_param( 'offset' ) ) );
		$status = sanitize_text_field( (string) $request->get_param( 'status' ) );
		$filters = $this->get_request_filters( $request );
		// Store requested statuses in filters for post-query enforcement.
		if ( 'any' !== $status ) {
			$filters['statuses'] = array_map( 'trim', explode( ',', $status ) );
		}

		$args = array(
			'type'    => 'shop_subscription',
			'limit'   => $limit,
			'offset'  => $offset,
			'orderby' => 'ID',
			'order'   => 'ASC',
			'return'  => 'objects',
		);

		if ( 'any' !== $status ) {
			// WCS requires 'wc-' prefixed statuses in wc_get_orders queries.
			$raw_statuses = array_map( 'trim', explode( ',', $status ) );
			$args['status'] = array_map( function( $s ) {
				return ( strpos( $s, 'wc-' ) === 0 ) ? $s : 'wc-' . $s;
			}, $raw_statuses );
		}
		if ( ! empty( $filters['subscription_ids'] ) ) {
			$args['include'] = $filters['subscription_ids'];
			$args['limit']   = max( $limit, count( $filters['subscription_ids'] ) );
			$args['offset']  = 0;
		} elseif ( ! empty( $filters['customer_ids'] ) ) {
			$args['limit']  = -1;
			$args['offset'] = 0;
			if ( 1 === count( $filters['customer_ids'] ) ) {
				$args['customer_id'] = $filters['customer_ids'][0];
			}
		} elseif ( ! empty( $filters['customer_emails'] ) ) {
			$args['limit']  = -1;
			$args['offset'] = 0;
		}

		// When specific IDs are requested, fetch each one directly via
		// wcs_get_subscription() — this is HPOS-safe and avoids the
		// wc_get_orders 'include' arg inconsistency under HPOS.
		if ( ! empty( $filters['subscription_ids'] ) && function_exists( 'wcs_get_subscription' ) ) {
			$subscriptions = array();
			foreach ( $filters['subscription_ids'] as $sid ) {
				$sub = wcs_get_subscription( $sid );
				if ( $sub ) {
					$subscriptions[] = $sub;
				}
			}
		} else {
			$subscriptions = wc_get_orders( $args );
		}

		$payload = array();
		foreach ( $subscriptions as $subscription ) {
			if ( ! $this->subscription_matches_filters( $subscription, $filters ) ) {
				continue;
			}
			$payload[] = $this->export_one_subscription( $subscription );
		}

		return rest_ensure_response(
			array(
				'count'         => count( $payload ),
				'limit'         => $limit,
				'offset'        => $offset,
				'next_offset'   => $offset + count( $payload ),
				'subscriptions' => $payload,
			)
		);
	}

	private function get_request_filters( WP_REST_Request $request ) {
		$customer_emails = $this->parse_email_list( (string) $request->get_param( 'customer_emails' ) );
		$customer_ids    = $this->parse_id_list( (string) $request->get_param( 'customer_ids' ) );
		foreach ( $customer_emails as $email ) {
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				$customer_ids[] = (int) $user->ID;
			}
		}

		return array(
			'customer_emails'  => $customer_emails,
			'customer_ids'     => array_values( array_unique( array_filter( array_map( 'absint', $customer_ids ) ) ) ),
			'subscription_ids' => $this->parse_id_list( (string) $request->get_param( 'subscription_ids' ) ),
			'product_ids'      => $this->parse_id_list( (string) $request->get_param( 'product_ids' ) ),
		);
	}

	private function get_destination_filters( WP_REST_Request $request, $settings ) {
		$customer_emails = (string) $request->get_param( 'customer_emails' );
		$customer_ids    = (string) $request->get_param( 'customer_ids' );
		$subscription_ids = (string) $request->get_param( 'subscription_ids' );

		if ( '' === trim( $customer_emails ) ) {
			$customer_emails = (string) ( $settings['test_customer_emails'] ?? '' );
		}
		if ( '' === trim( $customer_ids ) ) {
			$customer_ids = (string) ( $settings['test_customer_ids'] ?? '' );
		}
		if ( '' === trim( $subscription_ids ) ) {
			$subscription_ids = (string) ( $settings['test_subscription_ids'] ?? '' );
		}

		return array(
			'customer_emails_raw'  => $this->normalize_list_string( $customer_emails ),
			'customer_ids_raw'     => $this->normalize_list_string( $customer_ids ),
			'subscription_ids_raw' => $this->normalize_list_string( $subscription_ids ),
		);
	}

	private function subscription_matches_filters( $subscription, $filters ) {
		// Strictly enforce status filter — WCS under HPOS sometimes ignores it in wc_get_orders.
		if ( ! empty( $filters['statuses'] ) ) {
			$sub_status = $subscription->get_status();
			// WCS statuses may be prefixed with 'wc-', normalize both sides.
			$sub_status_clean = str_replace( 'wc-', '', $sub_status );
			$requested = array_map( function( $s ) { return str_replace( 'wc-', '', $s ); }, $filters['statuses'] );
			if ( ! in_array( $sub_status_clean, $requested, true ) ) {
				return false;
			}
		}
		if ( ! empty( $filters['subscription_ids'] ) && ! in_array( (int) $subscription->get_id(), $filters['subscription_ids'], true ) ) {
			return false;
		}
		if ( ! empty( $filters['customer_ids'] ) && ! in_array( (int) $subscription->get_customer_id(), $filters['customer_ids'], true ) ) {
			return false;
		}
		if ( ! empty( $filters['customer_emails'] ) ) {
			$email = strtolower( (string) $subscription->get_billing_email() );
			if ( ! in_array( $email, $filters['customer_emails'], true ) ) {
				return false;
			}
		}
		if ( ! empty( $filters['product_ids'] ) ) {
			$sub_product_ids = array();
			foreach ( $subscription->get_items() as $item ) {
				$sub_product_ids[] = (int) $item->get_product_id();
				$vid = (int) $item->get_variation_id();
				if ( $vid ) $sub_product_ids[] = $vid;
			}
			if ( empty( array_intersect( $filters['product_ids'], $sub_product_ids ) ) ) {
				return false;
			}
		}

		return true;
	}

	private function parse_id_list( $value ) {
		$items = preg_split( '/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY );

		return array_values( array_unique( array_filter( array_map( 'absint', $items ) ) ) );
	}

	private function parse_email_list( $value ) {
		$items = preg_split( '/[\s,]+/', strtolower( (string) $value ), -1, PREG_SPLIT_NO_EMPTY );
		$emails = array();
		foreach ( $items as $item ) {
			$email = sanitize_email( $item );
			if ( is_email( $email ) ) {
				$emails[] = $email;
			}
		}

		return array_values( array_unique( $emails ) );
	}

	private function normalize_list_string( $value ) {
		$items = preg_split( '/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY );

		return implode( ',', array_map( 'trim', $items ) );
	}

	private function export_one_subscription( $subscription ) {
		$customer_id = (int) $subscription->get_customer_id();
		$user        = $customer_id ? get_userdata( $customer_id ) : null;
		$parent      = $subscription->get_parent_id() ? wc_get_order( $subscription->get_parent_id() ) : null;
		$dates       = array(
			'created'      => $this->format_wc_date( $subscription->get_date_created() ),
			'modified'     => $this->format_wc_date( $subscription->get_date_modified() ),
			'start'        => $this->get_wcs_date( $subscription, 'start' ),
			'trial_end'    => $this->get_wcs_date( $subscription, 'trial_end' ),
			'next_payment' => $this->get_wcs_date( $subscription, 'next_payment' ),
			'last_payment' => $this->get_wcs_date( $subscription, 'last_payment' ),
			'end'          => $this->get_wcs_date( $subscription, 'end' ),
			'cancelled'    => $this->get_wcs_date( $subscription, 'cancelled' ),
		);

		return array(
			'id'                 => $subscription->get_id(),
			'number'             => $subscription->get_order_number(),
			'status'             => $subscription->get_status(),
			'currency'           => $subscription->get_currency(),
			'total'              => (float) $subscription->get_total(),
			'subtotal'           => (float) $subscription->get_subtotal(),
			'billing_period'     => method_exists( $subscription, 'get_billing_period' ) ? $subscription->get_billing_period() : '',
			'billing_interval'   => method_exists( $subscription, 'get_billing_interval' ) ? (int) $subscription->get_billing_interval() : 1,
			'requires_manual'    => method_exists( $subscription, 'get_requires_manual_renewal' ) ? (bool) $subscription->get_requires_manual_renewal() : false,
			'customer'           => $this->export_customer( $subscription, $user ),
			'addresses'          => $this->export_addresses( $subscription ),
			'payment'            => $this->export_payment( $subscription, $parent ),
			'items'              => $this->export_items( $subscription ),
			'parent_order'       => $parent ? $this->export_order_summary( $parent ) : null,
			'renewal_orders'     => $this->export_renewal_order_ids( $subscription ),
			'dates'              => $dates,
			'meta'               => $this->export_selected_meta( $subscription ),
		);
	}

	private function export_customer( $subscription, $user ) {
		return array(
			'old_user_id' => (int) $subscription->get_customer_id(),
			'email'       => $subscription->get_billing_email() ? $subscription->get_billing_email() : ( $user ? $user->user_email : '' ),
			'username'    => $user ? $user->user_login : '',
			'first_name'  => $subscription->get_billing_first_name(),
			'last_name'   => $subscription->get_billing_last_name(),
		);
	}

	private function export_addresses( $order ) {
		$billing = array();
		$shipping = array();
		foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' ) as $field ) {
			$method = 'get_billing_' . $field;
			if ( is_callable( array( $order, $method ) ) ) {
				$billing[ $field ] = $order->$method();
			}
		}
		foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' ) as $field ) {
			$method = 'get_shipping_' . $field;
			if ( is_callable( array( $order, $method ) ) ) {
				$shipping[ $field ] = $order->$method();
			}
		}

		return array(
			'billing'  => $billing,
			'shipping' => $shipping,
		);
	}

	private function export_payment( $subscription, $parent ) {
		$gateway_id = $subscription->get_payment_method();
		$tokens     = array();

		if ( class_exists( 'WC_Payment_Tokens' ) ) {
			$order_tokens = WC_Payment_Tokens::get_order_tokens( $subscription->get_id() );
			if ( empty( $order_tokens ) && $parent ) {
				$order_tokens = WC_Payment_Tokens::get_order_tokens( $parent->get_id() );
			}
			$customer_tokens = WC_Payment_Tokens::get_customer_tokens( $subscription->get_customer_id(), $gateway_id );
			foreach ( array_merge( $order_tokens, $customer_tokens ) as $token ) {
				$tokens[ $token->get_token() ] = $this->export_token( $token );
			}
		}

		return array(
			'gateway_id'    => $gateway_id,
			'gateway_title' => $subscription->get_payment_method_title(),
			'transaction_id' => $subscription->get_transaction_id(),
			'tokens'        => array_values( $tokens ),
			'gateway_meta'  => $this->export_gateway_meta( $subscription, $parent ),
		);
	}

	private function export_token( $token ) {
		$meta = array();
		foreach ( $token->get_meta_data() as $meta_obj ) {
			$meta[ $meta_obj->key ] = $meta_obj->value;
		}

		return array(
			'old_token_id' => $token->get_id(),
			'gateway_id'   => $token->get_gateway_id(),
			'token'        => $token->get_token(),
			'type'         => $token->get_type(),
			'is_default'   => $token->is_default(),
			'meta'         => $meta,
		);
	}

	private function export_renewal_order_ids( $subscription ) {
		global $wpdb;
		$hpos_meta = $wpdb->prefix . 'wc_orders_meta';
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT order_id FROM {$hpos_meta}
			 WHERE meta_key = '_subscription_renewal' AND meta_value = %s",
			$subscription->get_id()
		) );
		if ( empty( $ids ) ) {
			// Fallback for non-HPOS
			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key = '_subscription_renewal' AND meta_value = %s",
				$subscription->get_id()
			) );
		}
		return array_map( 'absint', $ids );
	}

	private function export_gateway_meta( $subscription, $parent ) {
		$keys = array(
			'_fkwcs_source_id',
			'_fkwcs_customer_id',
			'_stripe_source_id',
			'_stripe_customer_id',
			'_square_payment_token',
			'_square_customer_id',
			'_paypal_billing_agreement_id',
			'_wc_authorize_net_cim_credit_card_payment_token',
			'_wc_authorize_net_cim_credit_card_customer_id',
			'_wc_authorize_net_cim_echeck_payment_token',
			'_wc_authorize_net_cim_echeck_customer_id',
			'_payment_tokens',
		);
		$out = array();
		foreach ( $keys as $key ) {
			$value = $subscription->get_meta( $key, true );
			if ( empty( $value ) && $parent ) {
				$value = $parent->get_meta( $key, true );
			}
			// Also check user meta as fallback for gateway customer IDs.
			if ( ( empty( $value ) || $value === '' ) && strpos( $key, 'customer_id' ) !== false ) {
				$customer_id = $subscription->get_customer_id();
				if ( $customer_id ) {
					$value = get_user_meta( $customer_id, $key, true );
				}
			}
			if ( '' !== $value && null !== $value && array() !== $value ) {
				$out[ $key ] = $value;
			}
		}

		return $out;
	}

	private function export_items( $subscription ) {
		$items = array();
		foreach ( $subscription->get_items( array( 'line_item', 'shipping', 'fee', 'coupon', 'tax' ) ) as $item ) {
			$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;
			$items[] = array(
				'type'         => $item->get_type(),
				'name'         => $item->get_name(),
				'product_id'   => is_callable( array( $item, 'get_product_id' ) ) ? (int) $item->get_product_id() : 0,
				'variation_id' => is_callable( array( $item, 'get_variation_id' ) ) ? (int) $item->get_variation_id() : 0,
				'sku'          => $product ? $product->get_sku() : '',
				'quantity'     => is_callable( array( $item, 'get_quantity' ) ) ? (int) $item->get_quantity() : 0,
				'subtotal'     => is_callable( array( $item, 'get_subtotal' ) ) ? (float) $item->get_subtotal() : 0,
				'total'        => is_callable( array( $item, 'get_total' ) ) ? (float) $item->get_total() : 0,
				'taxes'        => is_callable( array( $item, 'get_taxes' ) ) ? $item->get_taxes() : array(),
				'meta'         => $this->flatten_item_meta( $item ),
			);
		}

		return $items;
	}

	private function flatten_item_meta( $item ) {
		$meta = array();
		foreach ( $item->get_meta_data() as $meta_obj ) {
			$data = $meta_obj->get_data();
			if ( isset( $data['key'] ) ) {
				$meta[ $data['key'] ] = $data['value'];
			}
		}

		return $meta;
	}

	private function export_order_summary( $order ) {
		return array(
			'id'             => $order->get_id(),
			'number'         => $order->get_order_number(),
			'status'         => $order->get_status(),
			'date_created'   => $this->format_wc_date( $order->get_date_created() ),
			'payment_method' => $order->get_payment_method(),
			'transaction_id' => $order->get_transaction_id(),
		);
	}

	private function export_selected_meta( $subscription ) {
		$keys = array(
			'_billing_period',
			'_billing_interval',
			'_schedule_start',
			'_schedule_trial_end',
			'_schedule_next_payment',
			'_schedule_end',
			'_schedule_cancelled',
			'_requires_manual_renewal',
		);
		$out = array();
		foreach ( $keys as $key ) {
			$value = $subscription->get_meta( $key, true );
			if ( '' !== $value && null !== $value ) {
				$out[ $key ] = $value;
			}
		}

		return $out;
	}

	public function push_into_destination( WP_REST_Request $request ) {
		if ( ! class_exists( '\Sublium_WCS\Includes\Controller\Subscriptions\Subscription' ) ) {
			return new WP_Error( 'missing_sublium', 'Sublium Subscriptions is not active on this site.', array( 'status' => 500 ) );
		}

		$dry_run = wc_string_to_bool( $request->get_param( 'dry_run' ) );
		$body    = $request->get_json_params();

		// Accept either a full source export envelope { subscriptions: [...] }
		// or a bare array of subscription objects directly.
		if ( isset( $body['subscriptions'] ) && is_array( $body['subscriptions'] ) ) {
			$subscriptions = $body['subscriptions'];
		} elseif ( is_array( $body ) && isset( $body[0] ) ) {
			$subscriptions = $body;
		} else {
			return new WP_Error( 'invalid_payload', 'Body must be a source export envelope { subscriptions: [...] } or a bare array of subscription objects.', array( 'status' => 400 ) );
		}

		if ( empty( $subscriptions ) ) {
			return rest_ensure_response( array( 'dry_run' => $dry_run, 'count' => 0, 'results' => array() ) );
		}

		$settings = $this->get_settings();
		$results  = array();
		foreach ( $subscriptions as $payload ) {
			try {
				$results[] = $this->import_one_subscription( $payload, $settings, $dry_run );
			} catch ( Throwable $e ) {
				$results[] = array(
					'old_id'  => absint( $payload['id'] ?? 0 ),
					'status'  => 'error',
					'message' => $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(),
				);
			}
		}

		return rest_ensure_response(
			array(
				'dry_run' => $dry_run,
				'count'   => count( $results ),
				'results' => $results,
			)
		);
	}

	public function pull_into_destination( WP_REST_Request $request ) {
		$settings = $this->get_settings();
		if ( empty( $settings['source_url'] ) || empty( $settings['source_token'] ) ) {
			return new WP_Error( 'missing_source', 'Configure the source URL and source bridge token first.', array( 'status' => 400 ) );
		}
		if ( ! class_exists( '\Sublium_WCS\Includes\Controller\Subscriptions\Subscription' ) ) {
			return new WP_Error( 'missing_sublium', 'Sublium Subscriptions is not active on this site.', array( 'status' => 500 ) );
		}

		$limit   = min( 50, max( 1, absint( $request->get_param( 'limit' ) ) ) );
		$offset  = max( 0, absint( $request->get_param( 'offset' ) ) );
		$dry_run = wc_string_to_bool( $request->get_param( 'dry_run' ) );
		$filters = $this->get_destination_filters( $request, $settings );
		$url     = trailingslashit( $settings['source_url'] ) . 'wp-json/' . self::REST_NS . '/source/subscriptions';
		$query_args = array(
			'token'  => $settings['source_token'],
			'limit'  => $limit,
			'offset' => $offset,
		);
		if ( ! empty( $filters['customer_emails_raw'] ) ) {
			$query_args['customer_emails'] = $filters['customer_emails_raw'];
		}
		if ( ! empty( $filters['customer_ids_raw'] ) ) {
			$query_args['customer_ids'] = $filters['customer_ids_raw'];
		}
		if ( ! empty( $filters['subscription_ids_raw'] ) ) {
			$query_args['subscription_ids'] = $filters['subscription_ids_raw'];
		}
		$url = add_query_arg(
			$query_args,
			$url
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 60,
				'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
				'headers'    => array(
					'Accept'          => 'application/json, text/plain, */*',
					'Accept-Language' => 'en-US,en;q=0.9',
					'Cache-Control'   => 'no-cache',
					'Pragma'          => 'no-cache',
					'Referer'         => rtrim( get_bloginfo( 'url' ), '/' ) . '/wp-admin/',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $body ) ) {
			return new WP_Error( 'source_error', 'Source export failed.', array( 'status' => 502, 'body' => wp_remote_retrieve_body( $response ) ) );
		}

		$results = array();
		foreach ( $body['subscriptions'] as $payload ) {
			$results[] = $this->import_one_subscription( $payload, $settings, $dry_run );
		}

		return rest_ensure_response(
			array(
				'dry_run'     => $dry_run,
				'count'       => count( $results ),
				'next_offset' => $body['next_offset'],
				'results'     => $results,
			)
		);
	}

	private function import_one_subscription( $payload, $settings, $dry_run ) {
		// Kill all emails at the very start of every import — before any user/order creation.
		if ( ! has_filter( 'wp_mail', array( $this, 'suppress_wp_mail' ) ) ) {
			add_filter( 'wp_mail', array( $this, 'suppress_wp_mail' ), 999 );
		}

		$old_id = absint( $payload['id'] ?? 0 );
		if ( ! $old_id ) {
			return array( 'status' => 'skipped', 'reason' => 'Missing old subscription ID.' );
		}

		$existing = $this->find_existing_sublium_subscription( $old_id );
		if ( $existing ) {
			return array( 'old_id' => $old_id, 'status' => 'exists', 'sublium_id' => $existing );
		}

		$user_id = $this->find_or_create_user( $payload['customer'] ?? array(), $dry_run );
		$all_items = $this->map_items( $payload['items'] ?? array(), $payload, $settings, $dry_run );

		// Separate mapped items from unmapped bundle parents.
		// Rule: if an item was successfully mapped (has product_id), keep it.
		// Bundle parent (e.g. 2468) has no explicit mapping — skip it when mapped children exist.
		$mapped_items   = array_filter( $all_items, function( $i ) { return ! empty( $i['product_id'] ) && empty( $i['warning'] ); } );
		$unmapped_items = array_filter( $all_items, function( $i ) { return empty( $i['product_id'] ) || ! empty( $i['warning'] ); } );

		if ( ! empty( $mapped_items ) ) {
			// Use only successfully mapped items.
			$product_items = array_values( $mapped_items );
		} else {
			// Nothing mapped — use all items (will show warnings).
			$product_items = array_values( $all_items );
		}

		// Further filter: if we have items with variation_id, drop any without (bundle parents).
		$with_variation    = array_filter( $product_items, function( $i ) { return ! empty( $i['variation_id'] ); } );
		$without_variation = array_filter( $product_items, function( $i ) { return empty( $i['variation_id'] ); } );
		if ( ! empty( $with_variation ) && ! empty( $without_variation ) ) {
			// Mix of parent + variation — drop the parents (they're bundle placeholders).
			$product_items = array_values( $with_variation );
		}

		// Redistribute total price across variation items weighted by quantity.
		// Old bundle had $0 on variations and total on parent — fix this.
		$subscription_total    = (float) ( $payload['total'] ?? 0 );
		$items_with_zero_price = array_filter( $product_items, function( $i ) { return (float) $i['line_total'] == 0; } );

		if ( count( $items_with_zero_price ) === count( $product_items ) && $subscription_total > 0 ) {
			// All items $0 — distribute by quantity weight.
			$total_qty = array_sum( array_column( $product_items, 'quantity' ) );
			if ( $total_qty < 1 ) $total_qty = count( $product_items );
			$unit_price    = $subscription_total / $total_qty;
			$assigned      = 0;
			$last_idx      = count( $product_items ) - 1;
			foreach ( $product_items as $idx => &$item ) {
				$qty = max( 1, (int) $item['quantity'] );
				if ( $idx === $last_idx ) {
					// Last item gets remainder to avoid rounding gaps.
					$amount = round( $subscription_total - $assigned, 2 );
				} else {
					$amount = round( $unit_price * $qty, 2 );
					$assigned += $amount;
				}
				$item['line_total']    = $amount;
				$item['line_subtotal'] = $amount;
				$item['total']         = $amount;
				$item['subtotal']      = $amount;
			}
			unset( $item );
		} elseif ( ! empty( $items_with_zero_price ) ) {
			// Some items priced, some $0 — keep only priced items.
			$priced = array_filter( $product_items, function( $i ) { return (float) $i['line_total'] > 0; } );
			if ( ! empty( $priced ) ) $product_items = array_values( $priced );
		}

		$warnings = array_values( array_filter( wp_list_pluck( $product_items, 'warning' ) ) );
		$plan_ids = array_values( array_filter( array_unique( wp_list_pluck( $product_items, 'plan' ) ) ) );

		// If plan_ids is still empty, do a direct DB lookup using the first mapped product_id.
		if ( empty( $plan_ids ) && ! empty( $product_items ) ) {
			global $wpdb;
			$first_pid    = (int) ( $product_items[0]['product_id'] ?? 0 );
			$wcs_interval = absint( $payload['billing_interval'] ?? 1 );
			$query_freq   = ( $wcs_interval >= 3 ) ? 3 : 1;
			$period_code  = $this->map_billing_period_to_sublium_frequency( $payload['billing_period'] ?? 'month' );
			$plan_table   = $wpdb->prefix . 'sublium_wcs_plan';
			$rel_table    = $wpdb->prefix . 'sublium_wcs_plan_relations';
			if ( $first_pid ) {
				// Try frequency-specific plan first.
				$found_plan = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT p.id FROM {$plan_table} p
					 INNER JOIN {$rel_table} r ON r.plan_id = p.id
					 WHERE r.oid = %d AND p.billing_frequency = %d AND p.billing_interval = %d AND p.status = 1
					 LIMIT 1",
					$first_pid, $query_freq, $period_code
				) );
				// Fall back to any plan for this product.
				if ( ! $found_plan ) {
					$found_plan = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT p.id FROM {$plan_table} p
						 INNER JOIN {$rel_table} r ON r.plan_id = p.id
						 WHERE r.oid = %d AND p.status = 1 ORDER BY p.billing_frequency ASC LIMIT 1",
						$first_pid
					) );
				}
				if ( $found_plan ) {
					$plan_ids = array( $found_plan );
					// Update plan in product items too.
					foreach ( $product_items as &$pi ) { $pi['plan'] = $found_plan; }
					unset( $pi );
				}
			}
		}
		$gateway = ! empty( $settings['default_gateway'] ) ? $settings['default_gateway'] : sanitize_text_field( $payload['payment']['gateway_id'] ?? '' );
		$status  = $this->map_status( sanitize_text_field( $payload['status'] ?? 'pending' ), $settings );

		if ( $dry_run ) {
			return array(
				'old_id'      => $old_id,
				'status'      => 'dry_run',
				'user_id'     => $user_id,
				'gateway'     => $gateway,
				'sub_status'  => $status,
				'items'       => $product_items,
				'warnings'    => $warnings,
			);
		}

		$this->copy_payment_tokens( $payload, $user_id, $settings );
		$order_id = $this->create_parent_order( $payload, $user_id, $settings, $product_items, $gateway );

		// Guard: if no valid plan found, skip rather than crash Sublium.
		if ( empty( $plan_ids ) ) {
			// Delete the order we just created since we can't complete the import.
			$order = wc_get_order( $order_id );
			if ( $order ) $order->delete( true );
			return array(
				'old_id'   => $old_id,
				'status'   => 'skipped',
				'reason'   => 'No Sublium plan found for this product/frequency. Add a matching plan in Sublium → Plans.',
				'warnings' => $warnings,
			);
		}

		// billing_frequency = numeric interval count (e.g. 1 for "every 1 month")
		// billing_interval  = period code: 1=day, 2=week, 3=month, 4=year
		// If interval_map remapped this subscription to a different plan,
		// use that plan's billing_frequency instead of the original WCS interval.
		$wcs_interval      = absint( $payload['billing_interval'] ?? 1 );
		$billing_interval  = $this->map_billing_period_to_sublium_frequency( $payload['billing_period'] ?? 'month' );
		$interval_map      = $this->parse_interval_map( $settings );
		if ( ! empty( $interval_map ) && isset( $interval_map[ $wcs_interval ] ) ) {
			// This subscription was remapped — get billing_frequency from target plan.
			$target_plan_id = (int) $interval_map[ $wcs_interval ];
			global $wpdb;
			$plan_row = $wpdb->get_row( $wpdb->prepare(
				"SELECT billing_frequency, billing_interval FROM {$wpdb->prefix}sublium_wcs_plan WHERE id = %d LIMIT 1",
				$target_plan_id
			), ARRAY_A );
			$billing_frequency = $plan_row ? (int) $plan_row['billing_frequency'] : $wcs_interval;
			$billing_interval  = $plan_row ? (int) $plan_row['billing_interval']  : $billing_interval;
		} else {
			$billing_frequency = $wcs_interval;
		}

		$billing  = $payload['addresses']['billing'] ?? array();
		$shipping = $payload['addresses']['shipping'] ?? array();
		$customer = $payload['customer'] ?? array();

		$args = array(
			'parent_order_id'       => $order_id,
			'gateway'               => $gateway,
			'gateway_mode'          => absint( $settings['gateway_mode'] ),
			'user_id'               => $user_id,
			'status'                => $status,
			'plan_id'               => $plan_ids,
			'plan_type'             => $this->infer_plan_type( $plan_ids ),
			'currency'              => sanitize_text_field( $payload['currency'] ?? get_woocommerce_currency() ),
			'totals'                => (float) ( $payload['total'] ?? 0 ),
			'base_totals'           => (float) ( $payload['total'] ?? 0 ),
			'billing_frequency'     => $billing_frequency,
			'billing_interval'      => $billing_interval,
			'next_payment_date'     => $this->mysql_date( $payload['dates']['next_payment'] ?? null ),
			'next_payment_date_utc' => $this->mysql_date( $payload['dates']['next_payment'] ?? null ),
			'last_payment_date'     => $this->mysql_date( $payload['dates']['last_payment'] ?? null ),
			'end_date'              => $this->mysql_date( $payload['dates']['end'] ?? null ),
			'end_date_utc'          => $this->mysql_date( $payload['dates']['end'] ?? null ),
			'items'                 => wp_list_pluck( $product_items, 'product_id' ),
			'search_str'            => trim( ( $payload['customer']['email'] ?? '' ) . ' #' . $old_id ),
			// Billing details — Sublium reads these for subscriber panel display.
			'billing_first_name'    => sanitize_text_field( $billing['first_name'] ?? $customer['first_name'] ?? '' ),
			'billing_last_name'     => sanitize_text_field( $billing['last_name'] ?? $customer['last_name'] ?? '' ),
			'billing_email'         => sanitize_email( $customer['email'] ?? '' ),
			'billing_phone'         => sanitize_text_field( $billing['phone'] ?? '' ),
			'billing_address_1'     => sanitize_text_field( $billing['address_1'] ?? '' ),
			'billing_address_2'     => sanitize_text_field( $billing['address_2'] ?? '' ),
			'billing_city'          => sanitize_text_field( $billing['city'] ?? '' ),
			'billing_state'         => sanitize_text_field( $billing['state'] ?? '' ),
			'billing_postcode'      => sanitize_text_field( $billing['postcode'] ?? '' ),
			'billing_country'       => sanitize_text_field( $billing['country'] ?? '' ),
			'shipping_first_name'   => sanitize_text_field( $shipping['first_name'] ?? $billing['first_name'] ?? '' ),
			'shipping_last_name'    => sanitize_text_field( $shipping['last_name'] ?? $billing['last_name'] ?? '' ),
			'shipping_address_1'    => sanitize_text_field( $shipping['address_1'] ?? $billing['address_1'] ?? '' ),
			'shipping_address_2'    => sanitize_text_field( $shipping['address_2'] ?? '' ),
			'shipping_city'         => sanitize_text_field( $shipping['city'] ?? $billing['city'] ?? '' ),
			'shipping_state'        => sanitize_text_field( $shipping['state'] ?? $billing['state'] ?? '' ),
			'shipping_postcode'     => sanitize_text_field( $shipping['postcode'] ?? $billing['postcode'] ?? '' ),
			'shipping_country'      => sanitize_text_field( $shipping['country'] ?? $billing['country'] ?? '' ),
		);

		$subscription = \Sublium_WCS\Includes\Controller\Subscriptions\Subscription::create( $args );
		$sub_id       = $subscription->get_id();

		foreach ( $product_items as $item ) {
			if ( empty( $item['product_id'] ) ) continue;

			// Build variation attribute array from the product.
			$variation_attrs = array();
			if ( ! empty( $item['variation_id'] ) ) {
				$var_product = wc_get_product( $item['variation_id'] );
				if ( $var_product ) {
					foreach ( $var_product->get_variation_attributes() as $attr_key => $attr_val ) {
						$variation_attrs[ 'attribute_' . sanitize_title( $attr_key ) ] = $attr_val;
					}
				}
			}

			// Get flavor value for meta field.
			$flavor_val = ! empty( $variation_attrs ) ? reset( $variation_attrs ) : '';
			$flavor_key = ! empty( $variation_attrs ) ? str_replace( 'attribute_', '', array_key_first( $variation_attrs ) ) : 'flavours';

			// Build plan summary string.
			$period      = sanitize_text_field( $item['billing_period'] ?? 'month' );
			$interval    = absint( $item['billing_interval'] ?? 1 );
			$price       = (float) $item['line_total'];
			$plan_summary = $interval === 1
				? 'Billed &#36;' . number_format( $price, 2 ) . ' \/ ' . $period
				: 'Billed &#36;' . number_format( $price, 2 ) . ' \/ ' . $interval . ' ' . $period . 's';

			// Build item_data JSON matching Sublium's expected structure.
			$item_data = array(
				'quantity'                => (int) $item['quantity'],
				'variation'               => $variation_attrs,
				'subtotal'                => (float) $item['line_subtotal'],
				'total'                   => (float) $item['line_total'],
				'downpayment'             => 0,
				'signup_fee'              => 0,
				'name'                    => sanitize_text_field( $item['name'] ?? '' ),
				'tax_class'               => '',
				'product_id'              => (int) $item['product_id'],
				'variation_id'            => (int) ( $item['variation_id'] ?? 0 ),
				'plan'                    => (int) ( $item['plan'] ?? 0 ),
				'cart_item_key'           => null,
				'sublium_available_plans' => null,
				'sublium_upgrade'         => null,
				'tax'                     => array( 'subtotal' => array(), 'total' => array() ),
				'sublium_wcs_plan_summary' => $plan_summary,
				'meta'                    => $flavor_val ? array( array( 'key' => $flavor_key, 'value' => $flavor_val ) ) : array(),
			);

			$subscription->add_item(
				array(
					'item_type'   => 1,
					'product_id'  => (int) $item['product_id'],
					'variation_id'=> (int) ( $item['variation_id'] ?? 0 ),
					'quantity'    => (int) $item['quantity'],
					'plan_id'     => (int) ( $item['plan'] ?? 0 ),
					'base_totals' => (float) $item['line_total'],
					'item_data'   => $item_data,
				)
			);
		}

		$meta = $this->build_sublium_meta( $payload, $settings, $plan_ids );
		foreach ( $meta as $key => $value ) {
			$subscription->update( $key, $value );
		}
		$subscription->update( self::META_OLD_ID, $old_id );
		$subscription->save();

		// Directly update subscription record to ensure all fields are correct.
		if ( $sub_id ) {
			// Update the parent WC order with the Sublium subscription ID.
			// This is required for Sublium to link the order to the subscription.
			$parent_order = wc_get_order( $order_id );
			if ( $parent_order ) {
				$parent_order->update_meta_data( '_sublium_wcs_subscription_id', $sub_id );
				$parent_order->save_meta_data();
			}
			global $wpdb;
			$sub_tbl  = $wpdb->prefix . 'sublium_wcs_subscriptions';
			$sub_cols = array_flip( $wpdb->get_col( "DESCRIBE {$sub_tbl}", 0 ) );

			$update_data = array();
			if ( isset( $sub_cols['billing_frequency'] ) ) $update_data['billing_frequency'] = $billing_frequency;
			if ( isset( $sub_cols['billing_interval'] ) )  $update_data['billing_interval']  = $billing_interval;
			if ( $user_id && isset( $sub_cols['user_id'] ) ) $update_data['user_id'] = $user_id;

			// Write billing/shipping directly to subscription record — Sublium reads these for display.
			$address_fields = array(
				'billing_first_name', 'billing_last_name', 'billing_email', 'billing_phone',
				'billing_address_1', 'billing_address_2', 'billing_city', 'billing_state',
				'billing_postcode', 'billing_country',
				'shipping_first_name', 'shipping_last_name',
				'shipping_address_1', 'shipping_address_2', 'shipping_city', 'shipping_state',
				'shipping_postcode', 'shipping_country',
			);
			foreach ( $address_fields as $field ) {
				if ( isset( $sub_cols[ $field ] ) && isset( $args[ $field ] ) ) {
					$update_data[ $field ] = $args[ $field ];
				}
			}

			if ( ! empty( $update_data ) ) {
				$wpdb->update( $sub_tbl, $update_data, array( 'id' => $sub_id ) );
			}
		}

		$this->upsert_subscriber_rollup( $payload, $user_id, $sub_id, $status, $product_items );

		// Link all renewal orders to this Sublium subscription.
		// Sublium requires _sublium_wcs_subscription_id on renewals to show them in Orders tab
		// and _sublium_wcs_subscription_renewal=yes to identify them as renewals.
		if ( $sub_id && ! empty( $payload['renewal_orders'] ) ) {
			global $wpdb;
			$hpos_meta = $wpdb->prefix . 'wc_orders_meta';
			foreach ( (array) $payload['renewal_orders'] as $renewal_order_id ) {
				$renewal_order_id = absint( $renewal_order_id );
				if ( ! $renewal_order_id ) continue;

				// _sublium_wcs_subscription_id
				$exists = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$hpos_meta} WHERE order_id = %d AND meta_key = '_sublium_wcs_subscription_id' LIMIT 1",
					$renewal_order_id
				) );
				if ( ! $exists ) {
					$wpdb->insert( $hpos_meta, array( 'order_id' => $renewal_order_id, 'meta_key' => '_sublium_wcs_subscription_id', 'meta_value' => $sub_id ) );
				}

				// _sublium_wcs_subscription_renewal=yes
				$exists_renewal = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$hpos_meta} WHERE order_id = %d AND meta_key = '_sublium_wcs_subscription_renewal' LIMIT 1",
					$renewal_order_id
				) );
				if ( ! $exists_renewal ) {
					$wpdb->insert( $hpos_meta, array( 'order_id' => $renewal_order_id, 'meta_key' => '_sublium_wcs_subscription_renewal', 'meta_value' => 'yes' ) );
				}
			}
		}

		// Fire sublium_wcs_subscription_created for analytics (MRR/ARR dashboards).
		if ( $sub_id && ! $dry_run ) {
			$sublium_sub_obj = function_exists( 'sublium_get_subscription' ) ? sublium_get_subscription( $sub_id ) : null;
			if ( $sublium_sub_obj ) {
				$parent_order_obj = $order_id ? wc_get_order( $order_id ) : null;
				do_action( 'sublium_wcs_subscription_created', $sublium_sub_obj, $parent_order_obj );
			}
		}

		// Verify user_id was written correctly.
		global $wpdb;
		$stored_uid = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT user_id FROM {$wpdb->prefix}sublium_wcs_subscriptions WHERE id = %d",
			$sub_id
		) );

		return array(
			'old_id'          => $old_id,
			'status'          => 'imported',
			'sublium_id'      => $sub_id,
			'order_id'        => $order_id,
			'user_id'         => $user_id,
			'stored_user_id'  => $stored_uid,
			'customer_email'  => $payload['customer']['email'] ?? '',
			'warnings'        => $warnings,
		);
	}

	private function find_existing_sublium_subscription( $old_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'sublium_wcs_subscription_meta';

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT subscription_id FROM {$table} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				self::META_OLD_ID,
				(string) $old_id
			)
		);
	}

	private function find_or_create_user( $customer, $dry_run ) {
		$email = sanitize_email( $customer['email'] ?? '' );
		if ( empty( $email ) ) {
			return 0;
		}

		$user = get_user_by( 'email', $email );
		if ( $user ) {
			// Update display name and billing meta so Sublium subscriber panel shows correct info.
			$first = sanitize_text_field( $customer['first_name'] ?? '' );
			$last  = sanitize_text_field( $customer['last_name'] ?? '' );
			wp_update_user( array(
				'ID'           => $user->ID,
				'first_name'   => $first,
				'last_name'    => $last,
				'display_name' => trim( $first . ' ' . $last ),
			) );
			return (int) $user->ID;
		}

		if ( $dry_run ) {
			return 0;
		}

		$email_parts = explode( '@', $email );
		$username    = sanitize_user( $customer['username'] ?? $email_parts[0], true );
		if ( username_exists( $username ) ) {
			$username .= '_' . wp_generate_password( 6, false, false );
		}

		// Suppress all emails for new customer account creation.
		add_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false', 999 );
		add_filter( 'woocommerce_email_enabled_customer_reset_password', '__return_false', 999 );
		add_filter( 'woocommerce_registration_auth_new_customer', '__return_false', 999 );
		remove_all_actions( 'woocommerce_created_customer_notification' );
		remove_all_actions( 'woocommerce_new_customer_data' );
		if ( ! has_filter( 'wp_mail', array( $this, 'suppress_wp_mail' ) ) ) {
			add_filter( 'wp_mail', array( $this, 'suppress_wp_mail' ), 999 );
		}

		$user_id = wc_create_new_customer( $email, $username, wp_generate_password( 24, true, true ) );
		if ( is_wp_error( $user_id ) ) {
			return 0;
		}

		$first = sanitize_text_field( $customer['first_name'] ?? '' );
		$last  = sanitize_text_field( $customer['last_name'] ?? '' );
		wp_update_user( array(
			'ID'           => $user_id,
			'first_name'   => $first,
			'last_name'    => $last,
			'display_name' => trim( $first . ' ' . $last ),
		) );

		return (int) $user_id;
	}

	private function map_items( $items, $payload, $settings, $dry_run ) {
		$mapped = array();
		foreach ( $items as $item ) {
			if ( 'line_item' !== ( $item['type'] ?? '' ) ) {
				continue;
			}

			$product_id   = 0;
			$variation_id = 0;

			// Check product_map first — explicit old→new ID mapping takes highest priority.
			$product_map = $this->parse_product_map( $settings );
			$old_product_id   = absint( $item['product_id'] ?? 0 );
			$old_variation_id = absint( $item['variation_id'] ?? 0 );

			// Skip bundle parent items only when:
			// 1. Another item in this subscription maps to a variation (it's a bundle with children)
			// 2. This item has no variation (it's the parent)
			// 3. This item maps to the SAME new product as the variation children
			// This prevents importing both bundle parent AND its children.
			// Simple products mapped to a DIFFERENT product (e.g. HBU→1940) should NOT be skipped.
			$has_variation_map = false;
			$variation_map_product = 0;
			foreach ( $items as $other_item ) {
				$other_vid = absint( $other_item['variation_id'] ?? 0 );
				if ( $other_vid && isset( $product_map[ $other_vid ] ) && ! empty( $product_map[ $other_vid ]['variation_id'] ) ) {
					$has_variation_map     = true;
					$variation_map_product = $product_map[ $other_vid ]['product_id'];
					break;
				}
			}
			// Only skip if this no-variation item maps to the SAME new product as the children.
			$this_mapped_product = isset( $product_map[ $old_product_id ] ) ? $product_map[ $old_product_id ]['product_id'] : 0;
			if ( $has_variation_map && ! $old_variation_id && $this_mapped_product > 0 && $this_mapped_product === $variation_map_product ) {
				continue;
			}

			// Try variation-level map first, then product-level map.
			if ( $old_variation_id && isset( $product_map[ $old_variation_id ] ) ) {
				$product_id   = $product_map[ $old_variation_id ]['product_id'];
				$variation_id = $product_map[ $old_variation_id ]['variation_id'];
			} elseif ( $old_product_id && isset( $product_map[ $old_product_id ] ) ) {
				$product_id   = $product_map[ $old_product_id ]['product_id'];
				$variation_id = $product_map[ $old_product_id ]['variation_id'];
			}

			$forced_product_id = absint( $settings['target_product_id'] ?? 0 );
			$forced_variation_id = absint( $settings['target_variation_id'] ?? 0 );
			if ( 0 === $forced_product_id && $forced_variation_id > 0 ) {
				$forced_variation = wc_get_product( $forced_variation_id );
				if ( $forced_variation && $forced_variation->is_type( 'variation' ) ) {
					$forced_product_id = (int) $forced_variation->get_parent_id();
				}
			}
			if ( $product_id > 0 ) {
				// Already resolved via product_map — skip auto-detection.
				$forced_product_id = 0;
			} elseif ( $forced_product_id > 0 ) {
				$product_id = $forced_product_id;
				if ( $forced_variation_id > 0 ) {
					$variation_id = $forced_variation_id;
				}
			} else {
				// 1. Try SKU match — wc_get_product_id_by_sku() may return a variation ID.
				if ( ! empty( $item['sku'] ) ) {
					$sku_id = (int) wc_get_product_id_by_sku( $item['sku'] );
					if ( $sku_id ) {
						$sku_product = wc_get_product( $sku_id );
						if ( $sku_product ) {
							if ( $sku_product->is_type( 'variation' ) ) {
								$variation_id = $sku_id;
								$product_id   = (int) $sku_product->get_parent_id();
							} else {
								$product_id = $sku_id;
							}
						}
					}
				}
				// 2. Try old product ID directly (works when IDs match across sites).
				if ( ! $product_id && ! empty( $item['product_id'] ) && wc_get_product( (int) $item['product_id'] ) ) {
					$product_id = (int) $item['product_id'];
				}
				// 3. Try old variation ID directly.
				if ( ! $variation_id && ! empty( $item['variation_id'] ) ) {
					$old_var = wc_get_product( (int) $item['variation_id'] );
					if ( $old_var && $old_var->is_type( 'variation' ) ) {
						$variation_id = (int) $item['variation_id'];
						if ( ! $product_id ) {
							$product_id = (int) $old_var->get_parent_id();
						}
					}
				}
				// 4. If we have a product but no variation, try to match the flavor from the item name.
				// Also used as full fallback when product_id is still 0.
				if ( ! empty( $item['name'] ) ) {
					$old_name    = sanitize_text_field( $item['name'] );
					$parts       = explode( ' - ', $old_name, 2 );
					$parent_name = trim( $parts[0] );
					$flavor_name = isset( $parts[1] ) ? trim( $parts[1] ) : '';

					// If no product found yet, try matching parent by name.
					if ( ! $product_id ) {
						$parent_posts = get_posts( array(
							'post_type'      => 'product',
							'post_status'    => array( 'publish', 'draft', 'private' ),
							'title'          => $parent_name,
							'posts_per_page' => 1,
							'fields'         => 'ids',
							'exact'          => true,
							's'              => '',
						) );
						if ( ! empty( $parent_posts ) ) {
							$product_id = (int) $parent_posts[0];
						}
					}

					// If we have a product but no variation, loop children to match flavor attribute.
					if ( $product_id && ! $variation_id && $flavor_name ) {
						$parent_product = wc_get_product( $product_id );
						if ( $parent_product && $parent_product->is_type( 'variable' ) ) {
							foreach ( $parent_product->get_children() as $child_id ) {
								$child = wc_get_product( $child_id );
								if ( ! $child ) continue;
								foreach ( $child->get_variation_attributes() as $attr_val ) {
									if ( strcasecmp( $attr_val, $flavor_name ) === 0 ) {
										$variation_id = $child_id;
										break 2;
									}
								}
							}
						}
					}
				}
			}

			$product = $variation_id ? wc_get_product( $variation_id ) : wc_get_product( $product_id );
			$plan_id = $this->find_matching_plan_id( $product, $payload, $settings, $product_id );
			$warning = '';
			if ( ! $product_id ) {
				$warning = 'Product not found by SKU or old product ID.';
			} elseif ( $forced_product_id > 0 && ! $product ) {
				$warning = 'Forced target product/variation was not found.';
			} elseif ( ! $plan_id ) {
				$warning = 'Sublium plan not found for product/frequency; using plan 0.';
			}

			$quantity = max( 1, absint( $item['quantity'] ?? 1 ) );
			$line_total = (float) ( $item['total'] ?? 0 );
			$line_subtotal = (float) ( $item['subtotal'] ?? $line_total );
			$unit_total = $line_total / $quantity;
			$unit_subtotal = $line_subtotal / $quantity;

			$mapped[] = array(
				'product_id'       => $product_id,
				'variation_id'     => $variation_id,
				'quantity'         => $quantity,
				'total'            => $unit_total,
				'subtotal'         => $unit_subtotal,
				'line_total'       => $line_total,
				'line_subtotal'    => $line_subtotal,
				'name'             => $product ? $product->get_name() : sanitize_text_field( $item['name'] ?? '' ),
				'plan'             => $plan_id,
				'billing_interval' => absint( $payload['billing_interval'] ?? 1 ),
				'billing_period'   => sanitize_text_field( $payload['billing_period'] ?? '' ),
				'old_product_id'   => absint( $item['product_id'] ?? 0 ),
				'old_variation_id' => absint( $item['variation_id'] ?? 0 ),
				'old_product_name' => sanitize_text_field( $item['name'] ?? '' ),
				'old_sku'          => sanitize_text_field( $item['sku'] ?? '' ),
				'forced_product'   => $forced_product_id > 0,
				'warning'          => $warning,
			);
		}

		return $mapped;
	}

	private function get_hardcoded_product_map() {
		return array(
			// Tikva Heart bundle children
			2802  => array( 'product_id' => 643, 'variation_id' => 5435 ),
			2803  => array( 'product_id' => 643, 'variation_id' => 5437 ),
			2804  => array( 'product_id' => 643, 'variation_id' => 5436 ),
			38975 => array( 'product_id' => 643, 'variation_id' => 5438 ),
			// Simple/old Tikva Heart products
			5684  => array( 'product_id' => 643, 'variation_id' => 0 ),
			6029  => array( 'product_id' => 643, 'variation_id' => 5437 ),
			21356 => array( 'product_id' => 643, 'variation_id' => 0 ),
			28400 => array( 'product_id' => 643, 'variation_id' => 0 ),
			41384 => array( 'product_id' => 643, 'variation_id' => 0 ),
			// Tikva Heart 4 Month
			50610 => array( 'product_id' => 643, 'variation_id' => 0 ),
			50611 => array( 'product_id' => 643, 'variation_id' => 5435 ),
			50612 => array( 'product_id' => 643, 'variation_id' => 5436 ),
			50614 => array( 'product_id' => 643, 'variation_id' => 5438 ),
			// Tikva Heart 6 Month
			50615 => array( 'product_id' => 643, 'variation_id' => 0 ),
			50616 => array( 'product_id' => 643, 'variation_id' => 5435 ),
			50617 => array( 'product_id' => 643, 'variation_id' => 5436 ),
			50619 => array( 'product_id' => 643, 'variation_id' => 5438 ),
			52548 => array( 'product_id' => 643, 'variation_id' => 5437 ),
			// Tikva Heart 1 Container
			55319 => array( 'product_id' => 643, 'variation_id' => 0 ),
			55320 => array( 'product_id' => 643, 'variation_id' => 5436 ),
			55321 => array( 'product_id' => 643, 'variation_id' => 5435 ),
			55322 => array( 'product_id' => 643, 'variation_id' => 5437 ),
			55323 => array( 'product_id' => 643, 'variation_id' => 5438 ),
			// Tikva Heart 4 Containers
			55367 => array( 'product_id' => 643, 'variation_id' => 0 ),
			55368 => array( 'product_id' => 643, 'variation_id' => 5436 ),
			55369 => array( 'product_id' => 643, 'variation_id' => 5435 ),
			55370 => array( 'product_id' => 643, 'variation_id' => 5437 ),
			55371 => array( 'product_id' => 643, 'variation_id' => 5438 ),
			// Tikva Heart 6 Containers
			55385 => array( 'product_id' => 643, 'variation_id' => 0 ),
			55386 => array( 'product_id' => 643, 'variation_id' => 5436 ),
			55387 => array( 'product_id' => 643, 'variation_id' => 5435 ),
			55388 => array( 'product_id' => 643, 'variation_id' => 5437 ),
			55389 => array( 'product_id' => 643, 'variation_id' => 5438 ),
			// Tikva Heart Monthly WB
			55743 => array( 'product_id' => 643, 'variation_id' => 0 ),
			55744 => array( 'product_id' => 643, 'variation_id' => 5436 ),
			// Tikva Heart 1 Month Plan
			63979 => array( 'product_id' => 643, 'variation_id' => 0 ),
			63980 => array( 'product_id' => 643, 'variation_id' => 5436 ),
			63981 => array( 'product_id' => 643, 'variation_id' => 5435 ),
			63982 => array( 'product_id' => 643, 'variation_id' => 5437 ),
			63983 => array( 'product_id' => 643, 'variation_id' => 5438 ),
			// Tikva Heart 4 Month Plan
			63986 => array( 'product_id' => 643, 'variation_id' => 0 ),
			63988 => array( 'product_id' => 643, 'variation_id' => 5435 ),
			63989 => array( 'product_id' => 643, 'variation_id' => 5437 ),
			63990 => array( 'product_id' => 643, 'variation_id' => 5438 ),
			// Tikva Heart 6 Month Plan
			63992 => array( 'product_id' => 643, 'variation_id' => 0 ),
			63993 => array( 'product_id' => 643, 'variation_id' => 5436 ),
			63994 => array( 'product_id' => 643, 'variation_id' => 5435 ),
			63995 => array( 'product_id' => 643, 'variation_id' => 5437 ),
			63996 => array( 'product_id' => 643, 'variation_id' => 5438 ),
			// Tikva Heart 6 Month Sub
			66387 => array( 'product_id' => 643, 'variation_id' => 0 ),
			66388 => array( 'product_id' => 643, 'variation_id' => 5436 ),
			66389 => array( 'product_id' => 643, 'variation_id' => 5435 ),
			66390 => array( 'product_id' => 643, 'variation_id' => 5437 ),
			66391 => array( 'product_id' => 643, 'variation_id' => 5438 ),
			// Tikva Heart variable 68897
			68897 => array( 'product_id' => 643, 'variation_id' => 0 ),
			68898 => array( 'product_id' => 643, 'variation_id' => 5435 ),
			68899 => array( 'product_id' => 643, 'variation_id' => 5437 ),
			68900 => array( 'product_id' => 643, 'variation_id' => 5436 ),
			68901 => array( 'product_id' => 643, 'variation_id' => 5438 ),
			// Tikva Heart current 71643
			71643 => array( 'product_id' => 643, 'variation_id' => 0 ),
			71644 => array( 'product_id' => 643, 'variation_id' => 5435 ),
			71645 => array( 'product_id' => 643, 'variation_id' => 5437 ),
			71646 => array( 'product_id' => 643, 'variation_id' => 5436 ),
			71647 => array( 'product_id' => 643, 'variation_id' => 5438 ),
			// Tikva Drink 1 Container
			16045 => array( 'product_id' => 643, 'variation_id' => 0 ),
			42361 => array( 'product_id' => 643, 'variation_id' => 5438 ),
			// 30 Travel Packs
			43537 => array( 'product_id' => 7700, 'variation_id' => 0 ),
			43538 => array( 'product_id' => 7700, 'variation_id' => 7721 ),
			// Heart Beet Ultra / Nitric Oxide
			39277 => array( 'product_id' => 1940, 'variation_id' => 0 ),
			39278 => array( 'product_id' => 1940, 'variation_id' => 0 ),
			39279 => array( 'product_id' => 1940, 'variation_id' => 0 ),
			39280 => array( 'product_id' => 1940, 'variation_id' => 0 ),
			49651 => array( 'product_id' => 1940, 'variation_id' => 0 ),
			59341 => array( 'product_id' => 1940, 'variation_id' => 0 ),
			59343 => array( 'product_id' => 1940, 'variation_id' => 0 ),
			59347 => array( 'product_id' => 1940, 'variation_id' => 0 ),
			59355 => array( 'product_id' => 1940, 'variation_id' => 0 ),
			73758 => array( 'product_id' => 1940, 'variation_id' => 0 ),
			73856 => array( 'product_id' => 1940, 'variation_id' => 0 ),
			9574  => array( 'product_id' => 1940, 'variation_id' => 0 ),
			12801 => array( 'product_id' => 1940, 'variation_id' => 0 ),
			12802 => array( 'product_id' => 1940, 'variation_id' => 0 ),
			9605  => array( 'product_id' => 1940, 'variation_id' => 0 ),
			9607  => array( 'product_id' => 1940, 'variation_id' => 0 ),
			// === HAPPY PRODUCT → 1965 ===
			51500 => array( 'product_id' => 1965, 'variation_id' => 0 ), // Happy subscription parent
			51541 => array( 'product_id' => 1965, 'variation_id' => 0 ), // Happy 1 Month
			51542 => array( 'product_id' => 1965, 'variation_id' => 0 ), // Happy 4 Month
			51543 => array( 'product_id' => 1965, 'variation_id' => 0 ), // Happy 6 Month
			51610 => array( 'product_id' => 1965, 'variation_id' => 0 ), // Happy 1x only
			53319 => array( 'product_id' => 1965, 'variation_id' => 0 ), // Happy BOGO
			60004 => array( 'product_id' => 1965, 'variation_id' => 0 ), // Happy 1 Month Sub
			60006 => array( 'product_id' => 1965, 'variation_id' => 0 ), // Happy 4 Month Sub
			60009 => array( 'product_id' => 1965, 'variation_id' => 0 ), // Happy 6 Month Sub

			// Skipped: Brain & Focus (59976,59980,2910,9649)
			// Skipped: Tikva Club (10330)
		);
	}

	private function parse_product_map( $settings ) {
		// Always start with hardcoded map. User-defined settings override individual entries.
		$map = $this->get_hardcoded_product_map();
		$raw = trim( $settings['product_map'] ?? '' );
		if ( empty( $raw ) ) return $map;
		// Parse user overrides and merge on top of hardcoded.
		foreach ( explode( "\n", $raw ) as $line ) {
			$line = trim( $line );
			if ( empty( $line ) || strpos( $line, ':' ) === false ) continue;
			$parts = array_map( 'absint', explode( ':', $line ) );
			if ( count( $parts ) >= 2 && $parts[0] > 0 ) {
				$map[ $parts[0] ] = array(
					'product_id'   => $parts[1],
					'variation_id' => $parts[2] ?? 0,
				);
			}
		}
		return $map;
	}

	private function parse_interval_map( $settings ) {
		// Hardcoded defaults: 1 month→plan 1, 4/5/6 months→plan 2.
		// Happy product uses plans 7 (1 month), 8 (4 months), 9 (6 months).
		// Plan lookup is product-aware so these are fallback defaults only.
		$map = array( 1 => 1, 4 => 2, 5 => 2, 6 => 2 );
		$raw = trim( $settings['interval_map'] ?? '' );
		if ( empty( $raw ) ) return $map;
		$user_map = array();
		foreach ( explode( "\n", $raw ) as $line ) {
			$line = trim( $line );
			if ( empty( $line ) || strpos( $line, ':' ) === false ) continue;
			$parts = array_map( 'absint', explode( ':', $line ) );
			if ( count( $parts ) >= 2 ) {
				$user_map[ $parts[0] ] = $parts[1];
			}
		}
		return array_merge( $map, $user_map );
	}

	private function find_matching_plan_id( $product, $payload, $settings = array(), $fallback_product_id = 0 ) {
		global $wpdb;

		if ( ! $product && ! $fallback_product_id ) {
			return 0;
		}

		// Sublium plans are linked to the parent variable product, not the variation.
		if ( $product && $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent ) {
				$product = $parent;
			}
		}

		// Use product object ID if available, otherwise use fallback_product_id passed from map lookup.
		$product_id = $product ? (int) $product->get_id() : (int) $fallback_product_id;
		if ( ! $product_id ) return 0;

		$wcs_interval = absint( $payload['billing_interval'] ?? 1 );
		$period       = sanitize_text_field( $payload['billing_period'] ?? 'month' );
		$frequency    = $this->map_billing_period_to_sublium_frequency( $period );

		// Determine target billing_frequency for Sublium.
		// Map: 1 month → freq 1 (monthly), anything >= 3 months → freq 3 (quarterly).
		$query_freq = ( $wcs_interval >= 3 ) ? 3 : 1;

		$plan_table = $wpdb->prefix . 'sublium_wcs_plan';
		$rel_table  = $wpdb->prefix . 'sublium_wcs_plan_relations';

		// First: find plan linked to this specific product matching the frequency.
		$plan_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT p.id
			 FROM {$plan_table} p
			 INNER JOIN {$rel_table} r ON r.plan_id = p.id
			 WHERE r.oid = %d
			   AND r.status = 1
			   AND p.billing_frequency = %d
			   AND p.billing_interval = %d
			   AND p.status = 1
			 LIMIT 1",
			$product_id, $query_freq, $frequency
		) );

		if ( $plan_id ) return $plan_id;

		// Fallback: first active plan for this product regardless of frequency.
		$plan_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT p.id
			 FROM {$plan_table} p
			 INNER JOIN {$rel_table} r ON r.plan_id = p.id
			 WHERE r.oid = %d
			   AND r.status = 1
			   AND p.status = 1
			 ORDER BY p.billing_frequency ASC
			 LIMIT 1",
			$product_id
		) );

		// Last resort: store plan_id in subscription meta for debugging.
		if ( ! $plan_id ) {
			// Direct raw query to confirm what's in the table.
			$raw = $wpdb->get_results( $wpdb->prepare(
				"SELECT r.plan_id, r.oid, p.billing_frequency, p.billing_interval, p.status FROM {$rel_table} r
				 INNER JOIN {$plan_table} p ON p.id = r.plan_id
				 WHERE r.oid = %d LIMIT 5",
				$product_id
			), ARRAY_A );
			if ( ! empty( $raw ) ) {
				// Plans exist for this product — pick the best match by frequency.
				foreach ( $raw as $row ) {
					if ( (int) $row['billing_frequency'] === $query_freq && (int) $row['status'] === 1 ) {
						return (int) $row['plan_id'];
					}
				}
				// No frequency match — return first active plan.
				foreach ( $raw as $row ) {
					if ( (int) $row['status'] === 1 ) {
						return (int) $row['plan_id'];
					}
				}
				// All statuses — just return first.
				return (int) $raw[0]['plan_id'];
			}
		}

		return $plan_id;
	}

	private function map_billing_period_to_sublium_frequency( $period ) {
		$map = array(
			'day'   => 1,
			'week'  => 2,
			'month' => 3,
			'year'  => 4,
		);

		return $map[ $period ] ?? 3;
	}

	private function map_status( $wcs_status, $settings ) {
		$class = '\Sublium_WCS\Includes\Controller\Subscriptions\Subscription';
		if ( 'yes' === $settings['pause_after_import'] && in_array( $wcs_status, array( 'active', 'pending-cancel' ), true ) ) {
			return $class::STATUSES['PAUSED'];
		}

		$map = array(
			'pending'        => $class::STATUSES['PENDING'],
			'active'         => $class::STATUSES['ACTIVE'],
			'on-hold'        => $class::STATUSES['ONHOLD'],
			'pending-cancel' => $class::STATUSES['PENDING_CANCEL'],
			'cancelled'      => $class::STATUSES['CANCELLED'],
			'expired'        => $class::STATUSES['COMPLETED'],
			'switched'       => $class::STATUSES['CANCELLED'],
			'trash'          => $class::STATUSES['CANCELLED'],
		);

		return $map[ $wcs_status ] ?? $class::STATUSES['PENDING'];
	}

	/**
	 * Suppress wp_mail by blanking the recipient — returning false breaks PHPMailer.
	 * This is attached as a filter only during migration imports.
	 */
	public function suppress_wp_mail( $args ) {
		$args['to'] = '';
		return $args;
	}

	private function create_parent_order( $payload, $user_id, $settings, $items, $gateway ) {
		// Suppress ALL emails — nuclear approach: kill at every possible layer.

		// 1. Disable every known WooCommerce email type.
		$wc_email_types = array(
			'new_order', 'cancelled_order', 'failed_order',
			'customer_processing_order', 'customer_completed_order',
			'customer_refunded_order', 'customer_on_hold_order',
			'customer_invoice', 'customer_note',
			'customer_new_account', 'customer_reset_password',
		);
		foreach ( $wc_email_types as $type ) {
			add_filter( 'woocommerce_email_enabled_' . $type, '__return_false', 999 );
		}

		// 2. Remove all status-change email triggers on WooCommerce orders.
		remove_all_actions( 'woocommerce_order_status_pending_to_processing_notification' );
		remove_all_actions( 'woocommerce_order_status_pending_to_completed_notification' );
		remove_all_actions( 'woocommerce_order_status_pending_to_on-hold_notification' );
		remove_all_actions( 'woocommerce_order_status_failed_to_processing_notification' );
		remove_all_actions( 'woocommerce_order_status_failed_to_completed_notification' );
		remove_all_actions( 'woocommerce_order_status_on-hold_to_processing_notification' );
		remove_all_actions( 'woocommerce_order_status_on-hold_to_cancelled_notification' );
		remove_all_actions( 'woocommerce_order_status_completed_notification' );
		remove_all_actions( 'woocommerce_order_status_cancelled_notification' );
		remove_all_actions( 'woocommerce_order_status_changed' );
		remove_all_actions( 'woocommerce_new_order_notification' );
		remove_all_actions( 'woocommerce_payment_complete' );

		// 3. Remove all WooCommerce email action hooks directly.
		$mailer = function_exists( 'WC' ) ? WC()->mailer() : null;
		if ( $mailer ) {
			$emails = $mailer->get_emails();
			foreach ( $emails as $email ) {
				if ( $email->enabled ) {
					$email->enabled = 'no';
				}
			}
		}

		// 4. Last resort — intercept wp_mail and set empty recipient so nothing sends.
		if ( ! has_filter( 'wp_mail', array( $this, 'suppress_wp_mail' ) ) ) {
			add_filter( 'wp_mail', array( $this, 'suppress_wp_mail' ), 999 );
		}

		$order = wc_create_order( array( 'customer_id' => $user_id ) );
		$order->set_created_via( 'wsmb_migration' );
		$order->set_currency( sanitize_text_field( $payload['currency'] ?? get_woocommerce_currency() ) );
		$order->set_payment_method( $gateway );
		$order->set_payment_method_title( sanitize_text_field( $payload['payment']['gateway_title'] ?? $gateway ) );
		$order->set_transaction_id( sanitize_text_field( $payload['payment']['transaction_id'] ?? '' ) );

		// Set order date to original purchase date so it appears with the correct date.
		$original_date = sanitize_text_field( $payload['parent_order']['date_created'] ?? ( $payload['dates']['created'] ?? '' ) );
		if ( $original_date ) {
			try {
				$dt = new WC_DateTime( $original_date );
				$order->set_date_created( $dt );
				$order->set_date_modified( $dt );
				$order->set_date_paid( $dt );
				$order->set_date_completed( $dt );
			} catch ( Exception $e ) {
				// Non-fatal — use current date as fallback.
			}
		}

		foreach ( $payload['addresses']['billing'] ?? array() as $key => $value ) {
			$setter = 'set_billing_' . $key;
			if ( is_callable( array( $order, $setter ) ) ) {
				$order->$setter( $value );
			}
		}
		foreach ( $payload['addresses']['shipping'] ?? array() as $key => $value ) {
			$setter = 'set_shipping_' . $key;
			if ( is_callable( array( $order, $setter ) ) ) {
				$order->$setter( $value );
			}
		}

		foreach ( $items as $item ) {
			$product = ! empty( $item['variation_id'] ) ? wc_get_product( $item['variation_id'] ) : wc_get_product( $item['product_id'] );
			if ( $product ) {
				$order->add_product(
					$product,
					$item['quantity'],
					array(
						'subtotal' => $item['line_subtotal'],
						'total'    => $item['line_total'],
					)
				);
			}
		}

		foreach ( $payload['payment']['gateway_meta'] ?? array() as $key => $value ) {
			$order->update_meta_data( sanitize_key( $key ), $value );
		}
		$order->update_meta_data( '_wsmb_old_parent_order_id', absint( $payload['parent_order']['id'] ?? 0 ) );
		$order->update_meta_data( self::META_OLD_ID, absint( $payload['id'] ?? 0 ) );
		// Required by Sublium to link the parent order to the subscription.
		// Without this, the order won't appear in subscription #Order tab and renewals may fail.
		// We set this after subscription creation — see create_sublium_subscription().
		$order->update_meta_data( '_sublium_wcs_subscription_id', 0 ); // Will be updated after sub created.
		$order->calculate_totals();

		// Map WCS subscription status → WooCommerce order status.
		$wcs_status  = sanitize_key( $payload['status'] ?? 'active' );
		$status_map  = array(
			'active'         => 'completed',
			'on-hold'        => 'on-hold',
			'pending'        => 'pending',
			'pending-cancel' => 'completed',
			'cancelled'      => 'cancelled',
			'expired'        => 'completed',
			'switched'       => 'completed',
		);
		$order_status = $status_map[ $wcs_status ] ?? sanitize_key( $settings['order_status'] ?? 'completed' );

		// Use silent status transition — pass false as third arg to skip email hooks.
		$order->set_status( $order_status );
		$order->add_order_note( 'Migrated from old store by WCS to Sublium Migration Bridge.', false, false );
		$order->save();

		return $order->get_id();
	}

	private function copy_payment_tokens( $payload, $user_id, $settings ) {
		// Always write Authorize.net customer profile ID to user meta — needed for renewals.
		$gateway_meta = $payload['payment']['gateway_meta'] ?? array();
		$authnet_customer_id_keys = array(
			'_wc_authorize_net_cim_credit_card_customer_id',
			'_wc_authorize_net_cim_echeck_customer_id',
		);
		if ( $user_id ) {
			foreach ( $authnet_customer_id_keys as $key ) {
				if ( ! empty( $gateway_meta[ $key ] ) ) {
					update_user_meta( $user_id, $key, sanitize_text_field( $gateway_meta[ $key ] ) );
				}
				if ( ! empty( $gateway_meta[ sanitize_key( $key ) ] ) ) {
					update_user_meta( $user_id, $key, sanitize_text_field( $gateway_meta[ sanitize_key( $key ) ] ) );
				}
			}
		}

		if ( 'yes' !== $settings['copy_token_rows'] || ! $user_id || empty( $payload['payment']['tokens'] ) || ! class_exists( 'WC_Payment_Token_CC' ) ) {
			return;
		}

		foreach ( $payload['payment']['tokens'] as $token_data ) {
			if ( empty( $token_data['token'] ) || empty( $token_data['gateway_id'] ) ) {
				continue;
			}
			if ( $this->token_value_exists( $token_data['token'], $user_id ) ) {
				continue;
			}

			$type = strtolower( $token_data['type'] ?? 'cc' );
			$token = 'echeck' === $type && class_exists( 'WC_Payment_Token_ECheck' ) ? new WC_Payment_Token_ECheck() : new WC_Payment_Token_CC();
			$token->set_token( sanitize_text_field( $token_data['token'] ) );
			$token->set_gateway_id( sanitize_text_field( $token_data['gateway_id'] ) );
			$token->set_user_id( $user_id );
			$token->set_default( ! empty( $token_data['is_default'] ) );

			foreach ( (array) ( $token_data['meta'] ?? array() ) as $key => $value ) {
				if ( is_callable( array( $token, 'set_' . $key ) ) ) {
					call_user_func( array( $token, 'set_' . $key ), $value );
				} else {
					$token->add_meta_data( sanitize_key( $key ), $value, true );
				}
			}
			$token->save();
		}
	}

	private function token_value_exists( $token_value, $user_id ) {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT token_id FROM {$wpdb->prefix}woocommerce_payment_tokens WHERE user_id = %d AND token = %s LIMIT 1",
				$user_id,
				$token_value
			)
		);
	}

	private function build_sublium_meta( $payload, $settings, $plan_ids = array() ) {
		$billing  = $payload['addresses']['billing'] ?? array();
		$shipping = $payload['addresses']['shipping'] ?? array();
		$customer = $payload['customer'] ?? array();

		// Build billing_details JSON — this is what Sublium reads for subscriber panel display.
		$billing_details = array(
			'first_name' => sanitize_text_field( $billing['first_name'] ?? $customer['first_name'] ?? '' ),
			'last_name'  => sanitize_text_field( $billing['last_name']  ?? $customer['last_name']  ?? '' ),
			'company'    => sanitize_text_field( $billing['company']    ?? '' ),
			'address_1'  => sanitize_text_field( $billing['address_1']  ?? '' ),
			'address_2'  => sanitize_text_field( $billing['address_2']  ?? '' ),
			'city'       => sanitize_text_field( $billing['city']       ?? '' ),
			'state'      => sanitize_text_field( $billing['state']      ?? '' ),
			'postcode'   => sanitize_text_field( $billing['postcode']   ?? '' ),
			'country'    => sanitize_text_field( $billing['country']    ?? '' ),
			'email'      => sanitize_email( $customer['email']          ?? '' ),
			'phone'      => sanitize_text_field( $billing['phone']      ?? '' ),
		);

		$shipping_details = array(
			'first_name' => sanitize_text_field( $shipping['first_name'] ?? $billing['first_name'] ?? '' ),
			'last_name'  => sanitize_text_field( $shipping['last_name']  ?? $billing['last_name']  ?? '' ),
			'company'    => sanitize_text_field( $shipping['company']    ?? '' ),
			'address_1'  => sanitize_text_field( $shipping['address_1']  ?? $billing['address_1'] ?? '' ),
			'address_2'  => sanitize_text_field( $shipping['address_2']  ?? '' ),
			'city'       => sanitize_text_field( $shipping['city']       ?? $billing['city']    ?? '' ),
			'state'      => sanitize_text_field( $shipping['state']      ?? $billing['state']   ?? '' ),
			'postcode'   => sanitize_text_field( $shipping['postcode']   ?? $billing['postcode'] ?? '' ),
			'country'    => sanitize_text_field( $shipping['country']    ?? $billing['country'] ?? '' ),
			'phone'      => sanitize_text_field( $shipping['phone']      ?? $billing['phone']   ?? '' ),
		);

		$meta = array(
			'_wsmb_source_payload_version' => '0.1.0',
			'_wsmb_old_wcs_status'        => sanitize_text_field( $payload['status'] ?? '' ),
			'billing_frequency'           => absint( $payload['billing_interval'] ?? 1 ),
			'billing_interval'            => $this->map_billing_period_to_sublium_frequency( $payload['billing_period'] ?? '' ),
			'_requires_manual_renewal'    => ! empty( $payload['requires_manual'] ) ? 'yes' : 'no',
			'billing_details'             => wp_json_encode( $billing_details ),
			'shipping_details'            => wp_json_encode( $shipping_details ),
			'downpayment'                 => 0,
			'signup_fee'                  => 0,
			'_sublium_payment_mode'       => 'live',
			'retry_count'                 => 0,
		);

		foreach ( $payload['payment']['gateway_meta'] ?? array() as $key => $value ) {
			$meta[ sanitize_key( $key ) ] = $value;
		}

		// Add plan_data from Sublium plan table — required for renewal processing.
		if ( ! empty( $plan_ids ) ) {
			global $wpdb;
			$plan_id_val = reset( $plan_ids );
			$plan_row    = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}sublium_wcs_plan WHERE id = %d LIMIT 1",
				$plan_id_val
			), ARRAY_A );
			if ( $plan_row ) {
				// Get relation data for this plan.
				$rel = $wpdb->get_row( $wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}sublium_wcs_plan_relations WHERE plan_id = %d AND status = 1 LIMIT 1",
					$plan_id_val
				), ARRAY_A );
				$plan_row['object_type']   = '1';
				$plan_row['relation_data'] = $rel ? json_decode( $rel['data'] ?? '{}', true ) : new stdClass();
				$meta['plan_data']         = wp_json_encode( $plan_row );
			}
		}

		return $meta;
	}

	private function upsert_subscriber_rollup( $payload, $user_id, $sub_id, $status, $items ) {
		global $wpdb;

		$email    = sanitize_email( $payload['customer']['email'] ?? '' );
		$billing  = $payload['addresses']['billing'] ?? array();
		$shipping = $payload['addresses']['shipping'] ?? array();
		$customer = $payload['customer'] ?? array();

		// Use billing address name, fallback to customer name from old site.
		$first_name = sanitize_text_field( $billing['first_name'] ?? $customer['first_name'] ?? '' );
		$last_name  = sanitize_text_field( $billing['last_name']  ?? $customer['last_name']  ?? '' );
		$phone      = sanitize_text_field( $billing['phone'] ?? '' );

		// 1. Update WordPress user — display_name, first_name, last_name, billing meta.
		if ( $user_id ) {
			// Force update display name so Sublium subscriber panel shows correct name.
			wp_update_user( array(
				'ID'           => $user_id,
				'display_name' => trim( $first_name . ' ' . $last_name ),
				'first_name'   => $first_name,
				'last_name'    => $last_name,
			) );
			update_user_meta( $user_id, 'first_name',          $first_name );
			update_user_meta( $user_id, 'last_name',           $last_name );
			update_user_meta( $user_id, 'billing_first_name',  $first_name );
			update_user_meta( $user_id, 'billing_last_name',   $last_name );
			update_user_meta( $user_id, 'billing_email',       $email );
			update_user_meta( $user_id, 'billing_phone',       $phone );
			update_user_meta( $user_id, 'billing_address_1',   sanitize_text_field( $billing['address_1'] ?? '' ) );
			update_user_meta( $user_id, 'billing_address_2',   sanitize_text_field( $billing['address_2'] ?? '' ) );
			update_user_meta( $user_id, 'billing_city',        sanitize_text_field( $billing['city'] ?? '' ) );
			update_user_meta( $user_id, 'billing_state',       sanitize_text_field( $billing['state'] ?? '' ) );
			update_user_meta( $user_id, 'billing_postcode',    sanitize_text_field( $billing['postcode'] ?? '' ) );
			update_user_meta( $user_id, 'billing_country',     sanitize_text_field( $billing['country'] ?? '' ) );
			update_user_meta( $user_id, 'shipping_first_name', sanitize_text_field( $shipping['first_name'] ?? $first_name ) );
			update_user_meta( $user_id, 'shipping_last_name',  sanitize_text_field( $shipping['last_name'] ?? $last_name ) );
			update_user_meta( $user_id, 'shipping_address_1',  sanitize_text_field( $shipping['address_1'] ?? '' ) );
			update_user_meta( $user_id, 'shipping_address_2',  sanitize_text_field( $shipping['address_2'] ?? '' ) );
			update_user_meta( $user_id, 'shipping_city',       sanitize_text_field( $shipping['city'] ?? '' ) );
			update_user_meta( $user_id, 'shipping_state',      sanitize_text_field( $shipping['state'] ?? '' ) );
			update_user_meta( $user_id, 'shipping_postcode',   sanitize_text_field( $shipping['postcode'] ?? '' ) );
			update_user_meta( $user_id, 'shipping_country',    sanitize_text_field( $shipping['country'] ?? '' ) );
		}

		// 2. Get actual columns in subscribers table so we only insert valid fields.
		$sub_table = $wpdb->prefix . 'sublium_wcs_subscribers';
		$columns   = array_flip( $wpdb->get_col( "DESCRIBE {$sub_table}", 0 ) );
		$is_active   = in_array( $status, array( 2, 3, 8 ), true );
		$full_name   = trim( $first_name . ' ' . $last_name );
		$address_str = sanitize_text_field( implode( ', ', array_filter( array(
			$billing['address_1'] ?? '',
			$billing['city']      ?? '',
			$billing['state']     ?? '',
			$billing['postcode']  ?? '',
		) ) ) );

		// Subscribers table only has: user_id, email, joined_date, status,
		// last/next order dates, subscriptions, counts, revenue, products.
		// Name/phone/address are NOT columns — Sublium reads those from wp_users + wp_usermeta.
		$joined = $this->mysql_date( $payload['dates']['created'] ?? null ) ?: current_time( 'mysql' );
		$all_data = array(
			'user_id'                      => $user_id,
			'email'                        => $email,
			'joined_date'                  => $joined,
			'status'                       => $is_active ? 1 : 2,
			'last_order_date'              => $this->mysql_date( $payload['dates']['last_payment'] ?? null ),
			'next_renewal_date'            => $this->mysql_date( $payload['dates']['next_payment'] ?? null ),
			'subscriptions'                => wp_json_encode( array( $sub_id ) ),
			'renewals_count'               => 0,
			'active_count'                 => in_array( $status, array( 2, 3 ), true ) ? 1 : 0,
			'inactive_count'               => in_array( $status, array( 2, 3 ), true ) ? 0 : 1,
			'revenue'                      => (float) ( $payload['total'] ?? 0 ),
			'revenue_base'                 => (float) ( $payload['total'] ?? 0 ),
			'purchased_products'           => wp_json_encode( wp_list_pluck( $items, 'product_id' ) ),
			'active_subscription_products' => wp_json_encode( $is_active ? wp_list_pluck( $items, 'product_id' ) : array() ),
		);

		// Filter to only columns that actually exist in the table.
		$data = array_intersect_key( $all_data, $columns );

		// 3. Check if subscriber already exists.
		$existing_id = 0;
		if ( $user_id ) {
			$existing_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$sub_table} WHERE user_id = %d LIMIT 1", $user_id ) );
		}
		if ( ! $existing_id && $email ) {
			$existing_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$sub_table} WHERE email = %s LIMIT 1", $email ) );
		}

		if ( $existing_id ) {
			// Merge subscription IDs.
			$existing_subs_raw = $wpdb->get_var( $wpdb->prepare( "SELECT subscriptions FROM {$sub_table} WHERE id = %d", $existing_id ) );
			$existing_subs     = json_decode( $existing_subs_raw, true ) ?: array();
			$merged            = array_values( array_unique( array_merge( $existing_subs, array( $sub_id ) ) ) );
			if ( isset( $data['subscriptions'] ) ) {
				$data['subscriptions'] = wp_json_encode( $merged );
			}
			$wpdb->update( $sub_table, $data, array( 'id' => $existing_id ) );
		} else {
			// Remove null values — some columns may not accept null.
			$data = array_filter( $data, function( $v ) { return $v !== null; } );
			$result = $wpdb->insert( $sub_table, $data );
			if ( $result ) {
				$existing_id = $wpdb->insert_id;
			} else {
				// Log insert error to subscription meta for debugging.
				if ( $sub_id ) {
					$wpdb->insert(
						$wpdb->prefix . 'sublium_wcs_subscription_meta',
						array(
							'subscription_id' => $sub_id,
							'meta_key'        => '_wsmb_subscriber_insert_error',
							'meta_value'      => $wpdb->last_error . ' | data: ' . wp_json_encode( $data ),
						)
					);
				}
			}
		}

		// 4. Directly link subscriber to Sublium subscription record via user_id.
		if ( $user_id && $sub_id ) {
			$sublium_sub_table = $wpdb->prefix . 'sublium_wcs_subscriptions';
			$wpdb->update( $sublium_sub_table, array( 'user_id' => $user_id ), array( 'id' => $sub_id ) );
		}

		// 5. Store subscriber_id in subscription meta so Sublium can find subscriber from subscription.
		if ( $existing_id && $sub_id ) {
			$meta_table = $wpdb->prefix . 'sublium_wcs_subscription_meta';
			// Check if already exists.
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$meta_table} WHERE subscription_id = %d AND meta_key = 'subscriber_id' LIMIT 1",
				$sub_id
			) );
			if ( $exists ) {
				$wpdb->update( $meta_table, array( 'meta_value' => $existing_id ), array( 'id' => $exists ) );
			} else {
				$wpdb->insert( $meta_table, array(
					'subscription_id' => $sub_id,
					'meta_key'        => 'subscriber_id',
					'meta_value'      => $existing_id,
				) );
			}
		}
	}

	private function infer_plan_type( $plan_ids ) {
		if ( empty( $plan_ids ) ) {
			return 1;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'sublium_wcs_plan';

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT type FROM {$table} WHERE id = %d", absint( $plan_ids[0] ) ) );
	}

	private function get_wcs_date( $subscription, $type ) {
		if ( is_callable( array( $subscription, 'get_date' ) ) ) {
			$date = $subscription->get_date( $type );
			if ( $date ) {
				return $date;
			}
		}
		if ( is_callable( array( $subscription, 'get_time' ) ) ) {
			$time = (int) $subscription->get_time( $type );
			if ( $time > 0 ) {
				return gmdate( 'Y-m-d H:i:s', $time );
			}
		}

		return null;
	}

	private function format_wc_date( $date ) {
		if ( $date instanceof WC_DateTime ) {
			return $date->date( 'Y-m-d H:i:s' );
		}

		return $date ? (string) $date : null;
	}

	private function mysql_date( $date ) {
		if ( empty( $date ) ) {
			return null;
		}
		$timestamp = strtotime( $date );

		return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : null;
	}
}

WSMB_Migration_Bridge::instance();
