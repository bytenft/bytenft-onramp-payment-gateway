<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

// Include the configuration file
require_once plugin_dir_path(__FILE__) . 'config.php';

/**
 * Class BYTENFT_ONRAMP_PAYMENT_GATEWAY_Loader
 * Handles the loading and initialization of the ByteNFT Onramp Payment Gateway plugin.
 */
class BYTENFT_ONRAMP_PAYMENT_GATEWAY_Loader
{
	private static $instance = null;
	private $admin_notices;

	private $base_url;

	/**
	 * Get the singleton instance of this class.
	 * @return BYTENFT_ONRAMP_PAYMENT_GATEWAY_Loader
	 */
	public static function get_instance()
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}


	/**
	 * Constructor. Sets up actions and hooks.
	 */
	private function __construct()
	{

		$this->base_url = BYTENFT_ONRAMP_BASE_URL;

		$this->admin_notices = new BYTENFT_ONRAMP_PAYMENT_GATEWAY_Admin_Notices();

		add_action('admin_init', [$this, 'bnftonramp_handle_environment_check']);
		add_action('admin_notices', [$this->admin_notices, 'display_notices']);
		add_action('plugins_loaded', [$this, 'bnftonramp_init'], 11);

		// Register the AJAX action callback for checking payment status
		add_action('wp_ajax_bnftonramp_check_payment_status', array($this, 'bnftonramp_handle_check_payment_status_request'));
		add_action('wp_ajax_nopriv_bnftonramp_check_payment_status', array($this, 'bnftonramp_handle_check_payment_status_request'));

		add_action('wp_ajax_bnftonramp_popup_closed_event', array($this, 'handle_popup_close'));
		add_action('wp_ajax_nopriv_bnftonramp_popup_closed_event', array($this, 'handle_popup_close'));

		add_action('wp_ajax_bnftonramp_manual_sync', [$this, 'bnftonramp_manual_sync_callback']);
		add_filter('cron_schedules', [$this, 'bnftonramp_add_cron_interval']);
		add_action('bnftonramp_cron_event', [$this, 'handle_cron_event']);
	}


	/**
	 * Initializes the plugin.
	 * This method is hooked into 'plugins_loaded' action.
	 */
	public function bnftonramp_init()
	{
		// Check if the environment is compatible
		$environment_warning = bnftonramp_check_system_requirements();
		if ($environment_warning) {
			return;
		}

		// Initialize gateways
		$this->bnftonramp_init_gateways();

		// Initialize REST API
		$rest_api = BYTENFT_ONRAMP_PAYMENT_GATEWAY_REST_API::get_instance();
		$rest_api->bnftonramp_register_routes();

		// Add plugin action links
		add_filter('plugin_action_links_' . plugin_basename(BYTENFT_ONRAMP_PAYMENT_GATEWAY_FILE), [$this, 'bnftonramp_plugin_action_links']);

		// Add plugin row meta
		add_filter('plugin_row_meta', [$this, 'bnftonramp_plugin_row_meta'], 10, 2);
	}

	/**
	 * Initialize gateways.
	 */
	private function bnftonramp_init_gateways()
	{
		if (!class_exists('WC_Payment_Gateway')) {
			return;
		}

		include_once BYTENFT_ONRAMP_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/class-bytenft-onramp-payment-gateway.php';

		add_filter('woocommerce_payment_gateways', function ($methods) {
			$methods[] = 'BYTENFT_ONRAMP_PAYMENT_GATEWAY';			
			return $methods;
		});
	}


	private function get_api_url($endpoint)
	{
		return $this->base_url . $endpoint;
	}

	/**
	 * Add action links to the plugin page.
	 * @param array $links
	 * @return array
	 */
	public function bnftonramp_plugin_action_links($links)
	{
		$plugin_links = [
			'<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=bnftonramp')) . '">' . esc_html__('Settings', 'bytenft-onramp-payment-gateway') . '</a>',
		];

		return array_merge($plugin_links, $links);
	}

	/**
	 * Add row meta to the plugin page.
	 * @param array $links
	 * @param string $file
	 * @return array
	 */
	public function bnftonramp_plugin_row_meta($links, $file)
	{
		if (plugin_basename(BYTENFT_ONRAMP_PAYMENT_GATEWAY_FILE) === $file) {
			$row_meta = [
				'docs'    => '<a href="' . esc_url(apply_filters('bnftonramp_docs_url', 'https://www.bytenft.xyz/api/docs/wordpress-plugin')) . '" target="_blank">' . esc_html__('Documentation', 'bytenft-onramp-payment-gateway') . '</a>',
				'support' => '<a href="' . esc_url(apply_filters('bnftonramp_support_url', 'https://www.bytenft.xyz/reach-out')) . '" target="_blank">' . esc_html__('Support', 'bytenft-onramp-payment-gateway') . '</a>',
			];

			$links = array_merge($links, $row_meta);
		}

		return $links;
	}

	/**
	 * Check the environment and display notices if necessary.
	 */
	public function bnftonramp_handle_environment_check()
	{
		$environment_warning = bnftonramp_check_system_requirements();
		if ($environment_warning) {
			// Sanitize the environment warning before displaying it
			$this->admin_notices->bnftonramp_add_notice('error', 'error', sanitize_text_field($environment_warning));
		}
	}

	/**
	 * Handle the AJAX request for checking payment status.
	 * @param $request
	 */
	public function bnftonramp_handle_check_payment_status_request($request)
	{
		check_ajax_referer('bnftonramp_payment', 'security');

		// Sanitize and validate the order ID from $_POST
		$order_id = isset($_POST['order_id']) ? intval(sanitize_text_field(wp_unslash($_POST['order_id']))) : null;
		if (!$order_id) {
			wp_send_json_error(array('error' => esc_html__('Invalid order ID', 'bytenft-onramp-payment-gateway')));
		}

		// Call the function to check payment status with the validated order ID
		return $this->bnftonramp_check_payment_status($order_id);
	}

	/**
	 * Check the payment status for an order.
	 * @param int $order_id
	 * @return WP_REST_Response
	 */
	public function bnftonramp_check_payment_status($order_id)
	{
		// Get the order details
		$order = wc_get_order($order_id);

		if (!$order) {
			return new WP_REST_Response(['error' => esc_html__('Order not found', 'bytenft-onramp-payment-gateway')], 404);
		}

		$payment_return_url = $order->get_checkout_order_received_url();

		// Determine order status
		if ($order->is_paid()) {
			wp_send_json_success(['status' => 'success', 'redirect_url' => $payment_return_url]);
			exit;
		}

		if ($order->has_status('failed')) {
			wp_send_json_success(['status' => 'failed', 'redirect_url' => $payment_return_url]);
			exit;
		}

		if ($order->has_status(['on-hold', 'pending'])) {
			wp_send_json_success(['status' => 'pending', 'redirect_url' => $payment_return_url]);
			exit;
		}

		if ($order->has_status('cancelled') || $order->has_status('canceled')) {
			wp_send_json_success(['status' => 'cancelled', 'redirect_url' => $payment_return_url]);
			exit;
		}

		if ($order->has_status('refunded')) {
			wp_send_json_success(['status' => 'refunded', 'redirect_url' => $payment_return_url]);
			exit;
		}

		// Default response (unknown status)
		wp_send_json_success(['status' => 'unknown', 'redirect_url' => $payment_return_url]);
		exit;
	}

	public function handle_popup_close()
	{
		// Sanitize and unslash the 'security' value
		$security = isset($_POST['security']) ? sanitize_text_field(wp_unslash($_POST['security'])) : '';

		// Check the nonce for security
		if (empty($security) || !wp_verify_nonce($security, 'bnftonramp_payment')) {
			wp_send_json_error(['message' => 'Nonce verification failed.']);
			wp_die();
		}

		// Get the order ID from the request
		$order_id = isset($_POST['order_id']) ? sanitize_text_field(wp_unslash($_POST['order_id'])) : null;

		// Validate order ID
		if (!$order_id) {
			wp_send_json_error(['message' => 'Order ID is missing.']);
			wp_die();
		}

		// Fetch the WooCommerce order
		$order = wc_get_order($order_id);

		// Check if the order exists
		if (!$order) {
			wp_send_json_error(['message' => 'Order not found in WordPress.']);
			wp_die();
		}

		//Get uuid from WP
		$payment_token = $order->get_meta('_bnftonramp_pay_id');

		// Proceed only if the order status is 'pending'
		if ($order->get_status() === 'pending') {
			// Call the ByteNFT Onramp API to update status
			$transactionStatusApiUrl = $this->get_api_url('/api/update-txn-status');
			$response = wp_remote_post($transactionStatusApiUrl, [
				'method'    => 'POST',
				'body'      => wp_json_encode(['order_id' => $order_id, 'payment_token' => $payment_token]),
				'headers'   => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $security,
				],
				'timeout'   => 15,
			]);

			// Check for errors in the API request
			if (is_wp_error($response)) {
				wp_send_json_error(['message' => 'Failed to connect to the ByteNFT Onramp API.']);
				wp_die();
			}

			// Parse the API response
			$response_body = wp_remote_retrieve_body($response);
			$response_data = json_decode($response_body, true);

			$log_message = 'Popup closed. Transaction status received from ByteNFT Onramp API.';

			wc_get_logger()->info($log_message, [
				'source'  => 'bytenft-onramp-payment-gateway',
				'context' => [
					'order_id'           => $order_id,
					'transaction_status' => $response_data['transaction_status'] ?? 'unknown'
				],
			]);

			// Ensure the response contains the expected data
			if (!isset($response_data['transaction_status'])) {
				wp_send_json_error(['message' => 'Invalid response from ByteNFT Onramp API.']);
				wp_die();
			}

			// Get the configured order status from the payment gateway settings
			$gateway_id = 'bnftonramp'; // Replace with your gateway ID
			$payment_gateways = WC()->payment_gateways->payment_gateways();
			if (isset($payment_gateways[$gateway_id])) {
				$gateway = $payment_gateways[$gateway_id];
				$configured_order_status = sanitize_text_field($gateway->get_option('order_status'));
			} else {
				wp_send_json_error(['message' => 'Payment gateway not found.']);
				wp_die();
			}

			// Validate the configured order status
			$allowed_statuses = wc_get_order_statuses();
			if (!array_key_exists('wc-' . $configured_order_status, $allowed_statuses)) {
				wp_send_json_error(['message' => 'Invalid order status configured: ' . esc_html($configured_order_status)]);
				wp_die();
			}

			$payment_return_url = $order->get_checkout_order_received_url();

			if (isset($response_data['transaction_status'])) {
				// Handle transaction status from API
				switch ($response_data['transaction_status']) {
					case 'success':
					case 'paid':
					case 'processing':
						// Update the order status based on the selected value
						try {
							$order->update_status($configured_order_status, 'Order marked as ' . $configured_order_status . ' by ByteNFT Onramp.');
							wp_send_json_success(['message' => 'Order status updated successfully.', 'order_id' => $order_id, 'redirect_url' => $payment_return_url]);
						} catch (Exception $e) {
							wp_send_json_error(['message' => 'Failed to update order status: ' . $e->getMessage()]);
						}
						break;

					case 'failed':
						try {
							$order->update_status('failed', 'Order marked as failed by ByteNFT Onramp.');
							wp_send_json_success(['message' => 'Order status updated to failed.', 'order_id' => $order_id, 'redirect_url' => $payment_return_url]);
						} catch (Exception $e) {
							wp_send_json_error(['message' => 'Failed to update order status: ' . $e->getMessage()]);
						}
						break;
					case 'canceled':
					case 'expired':
						try {
							$order->update_status('canceled', 'Order marked as canceled by ByteNFT Onramp.');
							wp_send_json_success(['message' => 'Order status updated to canceled.', 'order_id' => $order_id, 'redirect_url' => $payment_return_url]);
						} catch (Exception $e) {
							wp_send_json_error(['message' => 'Failed to update order status: ' . $e->getMessage()]);
						}
						break;
					default:
						wp_send_json_error(['message' => 'Unknown transaction status received.']);
				}
			}
		} else {
			// Skip API call if the order status is not 'pending'
			wp_send_json_success(['message' => 'No update required as the order status is not pending.', 'order_id' => $order_id]);
		}

		wp_die();
	}

	/**
     * Add custom cron schedules.
     */


	public function bnftonramp_add_cron_interval($schedules)
	{
		$schedules['every_two_hours'] = array(
			'interval' => 2 * 60 * 60, // 2 hours in seconds = 7200
			'display'  => __('Every Two Hours', 'bytenft-onramp-payment-gateway')
		);
		return $schedules;
	}

	function activate_cron_job()
	{
		wc_get_logger()->info('Automatic payment status checks have been enabled.', ['source' => 'bytenft-onramp-payment-gateway']);

		// Clear existing scheduled event if it exists
		$timestamp = wp_next_scheduled('bnftonramp_cron_event');
		if ($timestamp) {
			wp_unschedule_event($timestamp, 'bnftonramp_cron_event');
		}

		// Schedule with new interval
		wp_schedule_event(time(), 'every_two_hours', 'bnftonramp_cron_event');
	}

	function deactivate_cron_job()
	{
		wc_get_logger()->info('Automatic payment status checks have been disabled.', ['source' => 'bytenft-onramp-payment-gateway']);
		wp_clear_scheduled_hook('bnftonramp_cron_event');
	}


	public function handle_cron_event()
	{
		$logger_context = ['source' => 'bytenft-onramp-payment-gateway'];

		$accounts = get_option('woocommerce_bnftonramp_payment_gateway_accounts');
		if (is_string($accounts)) {
			$unserialized = maybe_unserialize($accounts);
			$accounts = is_array($unserialized) ? $unserialized : [];
		}

		if (!$accounts || !is_array($accounts)) {
			wc_get_logger()->warning('No payment accounts found or the account format is invalid. Sync aborted.', $logger_context);
			return [];
		}

		$accountsData = [];

		foreach ($accounts as &$account) {
			$isSandboxEnabled = isset($account['has_sandbox']) && $account['has_sandbox'] === 'on';

			// Prepare both live and sandbox entries
			if (!empty($account['live_public_key']) && !empty($account['live_secret_key'])) {
				$accountsData[] = [
					'account_name' => $account['title'],
					'public_key'   => $account['live_public_key'],
					'secret_key'   => $account['live_secret_key'],
					'mode'         => 'live',
				];
			}

			if ($isSandboxEnabled && !empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key'])) {
				$accountsData[] = [
					'account_name' => $account['title'],
					'public_key'   => $account['sandbox_public_key'],
					'secret_key'   => $account['sandbox_secret_key'],
					'mode'         => 'sandbox',
				];
			}
		}

		if (empty($accountsData)) {
			wc_get_logger()->warning('No valid credentials found in any payment account. Sync skipped.', $logger_context);
			return [];
		}

		$url = esc_url($this->base_url . '/api/sync-account-status');
		$response = wp_remote_post($url, [
			'headers' => [
				'Content-Type'  => 'application/json',
			],
			'body' => json_encode(['accounts' => $accountsData]),
			'timeout' => 15,
		]);

		if (is_wp_error($response)) {
			wc_get_logger()->error('Unable to connect to the sync service. Please check the server connection or endpoint.', $logger_context);
			return [];
		}

		$response_body = wp_remote_retrieve_body($response);
		$response_data = json_decode($response_body, true);

		$updated = false;
		$statusSummary = [];

		if (!empty($response_data['data'])) {
			foreach ($response_data['data'] as $statusData) {
				if (
					isset($statusData['mode'], $statusData['public_key'], $statusData['status']) &&
					!empty($statusData['status'])
				) {
					foreach ($accounts as &$account) {
						if (
							$statusData['mode'] === 'live' &&
							$account['live_public_key'] === $statusData['public_key']
						) {
							$account['live_status'] = $statusData['status'];
							$updated = true;
							$statusSummary[] = [
								'title'  => $account['title'] ?? 'N/A',
								'mode'   => $statusData['mode'],
								'status' => $statusData['status'],
							];
						}

						if (
							$statusData['mode'] === 'sandbox' &&
							$account['sandbox_public_key'] === $statusData['public_key']
						) {
							$account['sandbox_status'] = $statusData['status'];
							$updated = true;
							$statusSummary[] = [
								'title'  => $account['title'] ?? 'N/A',
								'mode'   => $statusData['mode'],
								'status' => $statusData['status'],
							];
						}
					}
				}
			}
		}

		if (!empty($statusSummary)) {
			if ($updated) {
				update_option('woocommerce_bnftonramp_payment_gateway_accounts', $accounts);

				wc_get_logger()->info('Payment account statuses were successfully updated after syncing.', [
					'source'  => 'bytenft-onramp-payment-gateway',
					'context' => ['updated_accounts' => $statusSummary],
				]);
			} else {
				wc_get_logger()->info('Payment accounts were checked, but no updates were necessary.', [
					'source'  => 'bytenft-onramp-payment-gateway',
					'context' => ['checked_accounts' => $statusSummary],
				]);
			}
		} else {
			wc_get_logger()->info('Sync completed. No account status data was returned from the server.', $logger_context);
		}

		return $statusSummary;
	}


	function bnftonramp_manual_sync_callback()
	{
		$logger_context = ['source' => 'bytenft-onramp-payment-gateway'];
		// Verify nonce first
		if (!check_ajax_referer('bnftonramp_sync_nonce', 'nonce', false)) {
			wc_get_logger()->error('Security validation failed during manual sync.', $logger_context);
			wp_send_json_error([
				'message' => __('Security check failed. Please refresh the page and try again.', 'bytenft-onramp-payment-gateway')
			], 400);
			wp_die();
		}

		// Check user capabilities
		if (!current_user_can('manage_woocommerce')) {
		wc_get_logger()->error('Unauthorized manual sync attempt by user ID: ' . get_current_user_id(), $logger_context);
			wp_send_json_error([
				'message' => __('You do not have permission to perform this action.', 'bytenft-onramp-payment-gateway')
			], 403);
			wp_die();
		}

		wc_get_logger()->info("Payment accounts sync initiated", $logger_context);

		try {
			ob_start();

			$statusSummary = $this->handle_cron_event();
			$output = ob_get_clean();

			if (!empty($output)) {
				wc_get_logger()->warning('Unexpected output generated during sync: ' . $output, $logger_context);
			}

			wc_get_logger()->info('Payment accounts sync completed successfully.', $logger_context);

			wp_send_json_success([
				'message'  => __('Payment accounts synchronized successfully.', 'bytenft-onramp-payment-gateway'),
				'timestamp' => current_time('mysql'),
				'statuses' => $statusSummary
			]);
		} catch (Exception $e) {
			wc_get_logger()->error('Payment accounts sync failed: ' . $e->getMessage(), $logger_context);
			wp_send_json_error([
				'message' => __('Sync failed: ', 'bytenft-onramp-payment-gateway') . $e->getMessage(),
				'code'    => $e->getCode()
			], 500);
		}

		wp_die(); // Always include this
	}
}
