<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Tbz_WC_Payfi_Gateway
 */
class Tbz_WC_Payfi_Gateway extends WC_Payment_Gateway
{

    /**
     * Checkout page title
     *
     * @var string
     */
    public $title;

    /**
     * Checkout page description
     *
     * @var string
     */
    public $description;

    /**
     * Is gateway enabled?
     *
     * @var bool
     */
    public $enabled;

    /**
     * Is test mode active?
     *
     * @var bool
     */
    public $testmode;

    /**
     * Text displayed as the title of the payment modal
     *
     * @var string
     */
    public $custom_title;

    /**
     * Text displayed as a short modal description
     *
     * @var string
     */
    public $custom_desc;

    /**
     * Image to be displayed on the payment popup
     *
     * @var string
     */
    public $custom_logo;

    /**
     * Payfi test public key.
     *
     * @var string
     */
    public $test_public_key;

    /**
     * Payfi test secret key.
     *
     * @var string
     */
    public $test_secret_key;

    /**
     * Payfi live public key.
     *
     * @var string
     */
    public $live_public_key;

    /**
     * Payfi live secret key.
     *
     * @var string
     */
    public $live_secret_key;

    /**
     * API public key.
     *
     * @var string
     */
    public $public_key;

    /**
     * API secret key.
     *
     * @var string
     */
    public $secret_key;

    /**
     * Should we save customer cards?
     *
     * @var bool
     */
    public $saved_cards;

    /**
     * payfi API query URL
     *
     * @var string
     */
    public $query_url;

    /**
     * payfi API tokenized URL
     *
     * @var string
     */
    public $tokenized_url;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->id = 'tbz_payfi';
        $this->method_title = 'Payfi';
        $this->method_description = sprintf('Payfi is a Buy Now Pay Later platform. <a href="%1$s" target="_blank">Sign up</a> for a Merchant account, and <a href="%2$s" target="_blank">get your API keys</a>.', 'https://merchant.payfi.ng', 'https://merchant.payfi.ng');

        $this->has_fields = true;

        $this->supports = array(
            'products',
            'tokenization',
            'subscriptions',
            'multiple_subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'subscription_payment_method_change',
            'subscription_payment_method_change_customer',
        );

        // Load the form fields.
        $this->init_form_fields();

        // Load the settings.
        $this->init_settings();

        // Get setting values.
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->testmode = $this->get_option('testmode') === 'yes' ? true : false;

        $this->custom_title = $this->get_option('custom_title');
        $this->custom_desc = $this->get_option('custom_desc');
        $this->custom_logo = $this->get_option('custom_logo');

        $this->test_public_key = $this->get_option('test_public_key');
        $this->test_secret_key = $this->get_option('test_secret_key');

        $this->live_public_key = $this->get_option('live_public_key');
        $this->live_secret_key = $this->get_option('live_secret_key');

        $this->public_key = $this->testmode ? $this->test_public_key : $this->live_public_key;
        $this->secret_key = $this->testmode ? $this->test_secret_key : $this->live_secret_key;

        $this->saved_cards = false;

        $this->query_url = 'https://api.payfi.ng/v1/merchant/purchase/verify-by-reference';

        // Hooks.
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));

        add_action('admin_notices', array($this, 'admin_notices'));
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

        add_action('woocommerce_admin_order_totals_after_total', array($this, 'display_payfi_fee'));
        add_action('woocommerce_admin_order_totals_after_total', array($this, 'display_order_payout'), 20);

        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

        // Payment listener/API hook.
        add_action('woocommerce_api_tbz_wc_payfi_gateway', array($this, 'verify_payfi_transaction'));

        // Webhook listener/API hook.
        add_action('woocommerce_api_tbz_wc_payfi_webhook', array($this, 'process_webhooks'));
    }

    public function sanitize_text_field( $str ) {

        $filtered = _sanitize_text_fields( $str, false );
     
        /**
         * Filters a sanitized text field string.
         *
         * @since 2.9.0
         *
         * @param string $filtered The sanitized string.
         * @param string $str      The string prior to being sanitized.
         */
        return apply_filters( 'sanitize_text_field', $filtered, $str );
    }

    /**
     * Display the payment icon on the checkout page
     */
    public function get_icon()
    {

        $icon = '<img src="' . WC_HTTPS::force_https_url(plugins_url('assets/images/payfi.png', TBZ_WC_PAYFI_MAIN_FILE)) . '" alt="cards" style="height: 40px; margin-right: 0.4em;margin-bottom: 0.6em;" />'; 

        return apply_filters('woocommerce_gateway_icon', $icon, $this->id);

    }

    /**
     * Check if Payfi merchant details is filled
     */
    public function admin_notices()
    {

        if ('no' === $this->enabled) {
            return;
        }

        // Check required fields.
        if (!($this->public_key && $this->secret_key)) {
            echo '<div class="error"><p>' . sprintf('Please enter your payfi merchant details <a href="%s">here</a> to be able to use the Payfi WooCommerce plugin.', admin_url('admin.php?page=wc-settings&tab=checkout&section=tbz_payfi')) . '</p></div>';
            return;
        }

    }

    /**
     * Check if Payfi gateway is enabled.
     */
    public function is_available()
    {

        if ('yes' === $this->enabled) {

            if (!($this->public_key && $this->secret_key)) {

                return false;

            }

            return true;

        }

        return false;

    }

    /**
     * Admin Panel Options
     */
    public function admin_options()
    {

        ?>

		<h3>Payfi</h3>

		<h4>Optional: To avoid situations where bad network makes it impossible to verify transactions, set your webhook URL <a href="https://merchant.payfi.ng" target="_blank" rel="noopener noreferrer">here</a> to the URL below<strong style="color: red"><pre><code><?php echo WC()->api_request_url('Tbz_WC_Payfi_Webhook'); ?></code></pre></strong></h4>

		<?php

        echo '<table class="form-table">';
        $this->generate_settings_html();
        echo '</table>';

    }

    /**
     * Initialise Gateway Settings Form Fields
     */
    public function init_form_fields()
    {

        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'woo-payfi'),
                'label' => __('Enable Payfi', 'woo-payfi'),
                'type' => 'checkbox',
                'description' => __('Enable Payfi as a payment option on the checkout page.', 'woo-payfi'),
                'default' => 'no',
                'desc_tip' => true,
            ),
            'title' => array(
                'title' => __('Title', 'woo-payfi'),
                'type' => 'text',
                'description' => __('This controls the payment method title which the user sees during checkout.', 'woo-payfi'),
                'desc_tip' => true,
								'disabled' => true,
                'default' => __('Pay in 2-6 instalments with Payfi', 'woo-payfi'),
            ),
            'description' => array(
                'title' => __('Description', 'woo-payfi'),
                'type' => 'textarea',
                'description' => __('This controls the payment method description which the user sees during checkout.', 'woo-payfi'),
                'desc_tip' => true,
								'disabled' => true,
                'default' => __('Spread payments for up to 6 months', 'woo-payfi'),
            ),
            'testmode' => array(
                'title' => __('Test mode', 'woo-payfi'),
                'label' => __('Enable Test Mode', 'woo-payfi'),
                'type' => 'checkbox',
                'description' => __('Test mode enables you to test payments before going live. <br />Once you are live uncheck this.', 'woo-payfi'),
                'default' => 'yes',
                'desc_tip' => true,
            ),
            'test_public_key' => array(
                'title' => __('Test Public Key', 'woo-payfi'),
                'type' => 'text',
                'description' => __('Required: Enter your Test Public Key here.', 'woo-payfi'),
                'default' => '',
                'desc_tip' => true,
            ),
            'test_secret_key' => array(
                'title' => __('Test Secret Key', 'woo-payfi'),
                'type' => 'text',
                'description' => __('Required: Enter your Test Secret Key here', 'woo-payfi'),
                'default' => '',
                'desc_tip' => true,
            ),
            'live_public_key' => array(
                'title' => __('Live Public Key', 'woo-payfi'),
                'type' => 'text',
                'description' => __('Required: Enter your Live Public Key here.', 'woo-payfi'),
                'default' => '',
                'desc_tip' => true,
            ),
            'live_secret_key' => array(
                'title' => __('Live Secret Key', 'woo-payfi'),
                'type' => 'text',
                'description' => __('Required: Enter your Live Secret Key here.', 'woo-payfi'),
                'default' => '',
                'desc_tip' => true,
            ),
            'custom_title' => array(
                'title' => __('Custom Title', 'woo-payfi'),
                'type' => 'text',
                'description' => __('Optional: Text to be displayed as the title of the payment modal.', 'woo-payfi'),
                'default' => '',
                'desc_tip' => true,
            ),
            'custom_desc' => array(
                'title' => __('Custom Description', 'woo-payfi'),
                'type' => 'text',
                'description' => __('Optional: Text to be displayed as a short modal description.', 'woo-payfi'),
                'default' => '',
                'desc_tip' => true,
            ),
            'custom_logo' => array(
                'title' => __('Custom Logo', 'woo-payfi'),
                'type' => 'text',
                'description' => __('Optional: Enter the link to a image to be displayed on the payment popup. Preferably a square image.', 'woo-payfi'),
                'default' => '',
                'desc_tip' => true,
            ),
        );

    }

    /**
     * Payment form on checkout page
     */
    public function payment_fields()
    {

        if ($this->description) {
            echo wpautop(wptexturize(esc_attr($this->description)));
        }

        if (!is_ssl()) {
            return;
        }

        if ($this->supports('tokenization') && is_checkout() && $this->saved_cards && is_user_logged_in()) {
            $this->tokenization_script();
            $this->saved_payment_methods();
            $this->save_payment_method_checkbox();
        }

    }

    /**
     * Outputs scripts used by Payfi.
     */
    public function payment_scripts()
    {

        if (!is_checkout_pay_page()) {
            return;
        }

        if ('no' === $this->enabled) {
            return;
        }

        $order_key = urldecode(sanitize_text_field($_GET['key']));
        $order_id = absint(get_query_var('order-pay'));

        $order = wc_get_order($order_id);

        $payment_method = method_exists($order, 'get_payment_method') ? $order->get_payment_method() : $order->payment_method;
	    $items = method_exists($order, 'get_items') ? $order->get_items() : $order->items;

        if ($this->id !== $payment_method) {
            return;
        }

        $suffix = (defined('SCRIPT_DEBUG') && SCRIPT_DEBUG) ? '' : '.min';

        wp_enqueue_script('jquery');
        wp_enqueue_script('tbz_payfi', 'https://sdk.payfi.ng/payfi.js', array('jquery'), TBZ_WC_PAYFI_VERSION, false);
        wp_enqueue_script('tbz_wc_payfi', plugins_url('assets/js/payfi' . $suffix . '.js', TBZ_WC_PAYFI_MAIN_FILE), array('jquery', 'tbz_payfi'), TBZ_WC_PAYFI_VERSION, false);

        $payfi_params = array(
            'public_key' => $this->public_key,
        );

        if (is_checkout_pay_page() && get_query_var('order-pay')) {

            $email = method_exists($order, 'get_billing_email') ? $order->get_billing_email() : $order->billing_email;
            $billing_phone = method_exists($order, 'get_billing_phone') ? $order->get_billing_phone() : $order->billing_phone;
            $first_name = method_exists($order, 'get_billing_first_name') ? $order->get_billing_first_name() : $order->billing_first_name;
            $last_name = method_exists($order, 'get_billing_last_name') ? $order->get_billing_last_name() : $order->billing_last_name;

            $amount = $order->get_total();

            $txnref = $order_id;
            // $txnref = $order_id . '_' . time();

            $the_order_id = method_exists($order, 'get_id') ? $order->get_id() : $order->id;
            $the_order_key = method_exists($order, 'get_order_key') ? $order->get_order_key() : $order->order_key;

            $base_location = wc_get_base_location();
            $country = $base_location['country'];
            $currency = get_woocommerce_currency();

            $meta = array();

            if ($the_order_id == $order_id && $the_order_key == $order_key) {
		    
		$orderList = array();

      
		    
		if($items){
                    foreach ($items as &$ord) {
                        $itemName = $ord->get_name();
                        $itemAmount = $ord->get_total();
                        $itemQuantity = $ord->get_quantity();
                        $product = method_exists($ord, 'get_product') ? $ord->get_product() : $ord->get_product;
                        $itemId = '';
                        if($product){
                            $itemId = $product->get_id();
                        }
                    
                        array_push($orderList, ['name' => $itemName, 'price'  => $itemAmount, 'quantity' => $itemQuantity, 'id' => strval($itemId) ]);
                    }
                }

                $meta[] = array(
                    'metaname' => 'Order ID',
                    'metavalue' => $order_id,
                );

                $payfi_params['txref'] = $txnref;
                $payfi_params['amount'] = $amount;
                $payfi_params['currency'] = get_woocommerce_currency();
                $payfi_params['customer_email'] = $email;
                $payfi_params['customer_phone'] = $billing_phone;
                $payfi_params['customer_first_name'] = $first_name;
                $payfi_params['customer_last_name'] = $last_name;
                $payfi_params['custom_title'] = $this->custom_title;
                $payfi_params['custom_desc'] = $this->custom_desc;
                $payfi_params['custom_logo'] = $this->custom_logo;
                $payfi_params['country'] = $this->get_route_country($currency, $country);
                $payfi_params['meta'] = $meta;
		$payfi_params['orderList'] = $orderList;
                $payfi_params['hash'] = $this->generate_hash($payfi_params);

                update_post_meta($order_id, '_payfi_txn_ref', $txnref);

            }
        }

        wp_localize_script('tbz_wc_payfi', 'tbz_wc_payfi_params', $payfi_params);

    }

    /**
     * Generate integrity hash
     */
    public function generate_hash($params)
    {

        $hashed_payload = $params['public_key'];

        unset($params['public_key']);
        unset($params['meta']);
	unset($params['orderList']);

        ksort($params);

        foreach ($params as $key => $value) {
            $hashed_payload .= $value;
        }

        $hashed_payload .= $this->secret_key;

        $hashed_payload = html_entity_decode($hashed_payload);

        $hash = hash('sha256', $hashed_payload);

        return $hash;
    }

    /**
     * Get route country.
     *
     * @param string $currency     WooCommerce Store Currency Code
     * @param string $country_code WooCommerce Store Country Code
     *
     * @return string Country code.
     */
    public function get_route_country($currency, $country_code)
    {

        switch ($currency) {

            case 'NGN':
                $route_country = 'NG';
                break;

            case 'GHS':
                $route_country = 'GH';
                break;

            case 'KES':
                $route_country = 'KE';
                break;

            case 'RWF':
                $route_country = 'RW';
                break;

            case 'TZS':
                $route_country = 'TZ';
                break;

            case 'UGX':
                $route_country = 'UG';
                break;

            case 'ZAR':
                $route_country = 'ZA';
                break;

            case 'ZMW':
                $route_country = 'ZM';
                break;

            default:
                $route_country = $country_code;
                break;
        }

        return $route_country;
    }

    /**
     * Load admin scripts
     */
    public function admin_scripts()
    {

        if ('woocommerce_page_wc-settings' !== get_current_screen()->id) {
            return;
        }

        $suffix = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? '' : '.min';

        wp_enqueue_script('tbz_wc_payfi_admin', plugins_url('assets/js/payfi-admin' . $suffix . '.js', TBZ_WC_PAYFI_MAIN_FILE), array(), TBZ_WC_PAYFI_VERSION, true);

    }

    /**
     * Displays the Payfi fee
     *
     * @since 2.1.0
     *
     * @param int $order_id WC Order ID.
     */
    public function display_payfi_fee($order_id)
    {

        $order = wc_get_order($order_id);

        if ($this->is_wc_lt('3.0')) {
            $fee = get_post_meta($order_id, '_payfi_fee', true);
            $currency = get_post_meta($order_id, '_payfi_currency', true);
        } else {
            $fee = $order->get_meta('_payfi_fee', true);
            $currency = $order->get_meta('_payfi_currency', true);
        }

        if (!$fee || !$currency) {
            return;
        }

        ?>

		<tr>
			<td class="label payfi-fee">
				<?php echo wc_help_tip(__('This represents the fee Payfi collects for the transaction.', 'woo-payfi')); ?>
				<?php esc_html_e(__('Payfi Fee:', 'woo-payfi'));?>
			</td>
			<td width="1%"></td>
			<td class="total">
				-&nbsp;<?php echo wc_price($fee, array('currency' => $currency)); ?>
			</td>
		</tr>

		<?php
}

    /**
     * Displays the net total of the transaction without the charges of Payfi.
     *
     * @since 2.1.0
     *
     * @param int $order_id WC Order ID.
     */
    public function display_order_payout($order_id)
    {

        $order = wc_get_order($order_id);

        if ($this->is_wc_lt('3.0')) {
            $net = get_post_meta($order_id, '_payfi_net', true);
            $currency = get_post_meta($order_id, '_payfi_currency', true);
        } else {
            $net = $order->get_meta('_payfi_net', true);
            $currency = $order->get_meta('_payfi_currency', true);
        }

        if (!$net || !$currency) {
            return;
        }

        ?>

		<tr>
			<td class="label payfi-payout">
				<?php $message = __('This represents the net total that will be credited to your bank account for this order.', 'woo-payfi');?>
				<?php if ($net >= $order->get_total()): ?>
					<?php $message .= __(' Payfi transaction fees was passed to the customer.', 'woo-payfi');?>
				<?php endif;?>
				<?php echo wc_help_tip($message); ?>
				<?php esc_html_e(__('Payfi Payout:', 'woo-payfi'));?>
			</td>
			<td width="1%"></td>
			<td class="total">
				<?php echo wc_price($net, array('currency' => $currency)); ?>
			</td>
		</tr>

		<?php
}

    /**
     * Process payment
     *
     * @param int $order_id WC Order ID.
     *
     * @return array|void
     */
    public function process_payment($order_id)
    {

        if (isset($_POST['wc-tbz_payfi-payment-token']) && 'new' !== $_POST['wc-tbz_payfi-payment-token']) {

            $token_id = wc_clean($_POST['wc-tbz_payfi-payment-token']);
            $token = WC_Payment_Tokens::get($token_id);

            if ($token->get_user_id() !== get_current_user_id()) {

                wc_add_notice(__('Invalid token ID', 'woo-payfi'), 'error');

                return;

            } else {

                $status = $this->process_token_payment($token->get_token(), $order_id);

                if ($status) {

                    $order = wc_get_order($order_id);

                    return array(
                        'result' => 'success',
                        'redirect' => $this->get_return_url($order),
                    );

                }
            }
        } else {

            if (is_user_logged_in() && isset($_POST['wc-tbz_payfi-new-payment-method']) && true === (bool) $_POST['wc-tbz_payfi-new-payment-method'] && $this->saved_cards) {

                update_post_meta($order_id, '_wc_payfi_save_card', true);

            }

            $order = wc_get_order($order_id);

            return array(
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url(true),
            );

        }

    }

    /**
     * Process a token payment
     */
    public function process_token_payment($token, $order_id)
    {

        
    }

    /**
     * Show new card can only be added when placing an order notice
     */
    public function add_payment_method()
    {

        wc_add_notice(__('You can only add a new card when placing an order.', 'woo-payfi'), 'error');

        return;

    }

    /**
     * Displays the payment page
     */
    public function receipt_page($order_id)
    {

        $order = wc_get_order($order_id);

        echo '<p>Thank you for your order, please click the button below to pay with Payfi.</p>';

        echo '<div id="tbz_wc_payfi_form" style="position:relative;z-index:999999;"><form id="order_review" method="post" action="' . WC()->api_request_url('Tbz_WC_Payfi_Gateway') . '"></form><button class="button alt" id="tbz-payfi-wc-payment-button">Pay Now</button> <a class="button cancel" href="' . esc_url($order->get_cancel_order_url()) . '">Cancel order &amp; restore cart</a></div>
			';

    }

    /**
     * Verify Payfi payment
     */
    public function verify_payfi_transaction()
    {

        @ob_clean();

        error_log(print_r($_REQUEST['tbz_wc_payfi_txnref'], true));

        if (isset($_REQUEST['tbz_wc_payfi_txnref'])) {
           

            $headers = array(
                'Content-Type' => 'application/json',
                'payfi-sec-key' => $this->secret_key,
            );

            $body = array(
                'reference' => sanitize_text_field($_POST['tbz_wc_payfi_txnref']),
            );

            $args = array(
                'headers' => $headers,
                'body' => json_encode($body),
                'timeout' => 60,
            );

            $request = wp_remote_post($this->query_url, $args);

            if (!is_wp_error($request) && 200 == wp_remote_retrieve_response_code($request)) {

                $response = json_decode(wp_remote_retrieve_body($request));
               
                if(!isset($response->data)){
                    $order_details = sanitize_text_field(explode('_', $_POST['tbz_wc_payfi_txnref']));

                    $order_id = (int) $order_details[0];

                    $order = wc_get_order($order_id);
                    wp_redirect($this->get_return_url($order));
                    exit;
                }

                $status = $response->data->status;
                $paymentStatus = $response->data->paymentStatus;
                $transactionReference = $response->data->transactionReference;

                if ('success' === $status && $paymentStatus === 'success' || 'approved' === $status && $paymentStatus === 'approved') {

                    $order_details = explode('_', $transactionReference);

                    $order_id = (int) $order_details[0];

                    $order = wc_get_order($order_id);

                    if($order == false){
                        wp_redirect($this->get_return_url(null));
                        exit;
                    }

                    if (in_array($order->get_status(), array('processing', 'completed', 'on-hold'))) {

                        wp_redirect($this->get_return_url($order));

                        exit;

                    }

                    $order_currency = $order->get_currency();

                    $currency_symbol = get_woocommerce_currency_symbol($order_currency);

                    $order_total = $order->get_total();

                    $amount_paid = $response->data->amount;
                    

                    if ($amount_paid < $order_total) {

                        $order->update_status('on-hold', '');

                        update_post_meta($order_id, '_transaction_id', $transactionReference);

                        $notice = 'Thank you for shopping with us.<br />Your payment was successful, but the amount paid is not the same as the total order amount.<br />Your order is currently on-hold.<br />Kindly contact us for more information regarding your order and payment status.';
                        $notice_type = 'notice';

                        // Add Customer Order Note
                        $order->add_order_note($notice, 1);

                        // Add Admin Order Note
                        $order->add_order_note('<strong>Look into this order</strong><br />This order is currently on hold.<br />Reason: Amount paid is less than the total order amount.<br />Amount Paid was <strong>' . $currency_symbol . $amount_paid . '</strong> while the total order amount is <strong>' . $currency_symbol . $order_total . '</strong><br /><strong>Transaction ID:</strong> ' . $transactionReference);

                        wc_reduce_stock_levels($order_id);

                        wc_add_notice($notice, $notice_type);

                    } else {

                        $order->payment_complete($transactionReference);

                        $order->add_order_note(sprintf('Payment via Payfi successful (<strong>Transaction ID:</strong> %s)', $transactionReference));

                    }


                    wc_empty_cart();

                } else {

                    $order_details = explode('_', $transactionReference);

                    $order_id = (int) $order_details[0];
                    // $order_id = (int) 47;

                    $order = wc_get_order($order_id);

                    $order->update_status('pending', 'Payment is been processed by Payfi.');

                }

                wp_redirect($this->get_return_url($order));

                exit;

            }
        }

        wc_add_notice('Payment failed. Try again.', 'error');

        wp_redirect(wc_get_page_permalink('checkout'));

        exit;

    }

    /**
     * Process Webhook
     */
    public function process_webhooks()
    {

        if ((strtoupper($_SERVER['REQUEST_METHOD']) != 'POST')) {
            exit;
        }

        if (!array_key_exists('HTTP_PAYFI_SEC_KEY', $_SERVER)) {
            exit;
        }

        if ($_SERVER['HTTP_PAYFI_SEC_KEY'] !== $this->secret_key) {
            exit;
        }

        sleep(5);

        $body = @file_get_contents('php://input');

        if ($this->isJSON($body)) {
            $_POST = (array) json_decode($body);
        }

        if ($_POST['event'] != "payfi.events.payment") {
            exit;
        }

        if (!isset($_POST['txRef'])) {
            exit;
        }

        $headers = array(
            'Content-Type' => 'application/json',
            'payfi-sec-key' => $this->secret_key,
        );

        $body = array(
            'reference' => sanitize_text_field($_POST['txRef']),
        );

        $args = array(
            'headers' => $headers,
            'body' => json_encode($body),
            'timeout' => 60,
        );

        $request = wp_remote_post($this->query_url, $args);

        if (!is_wp_error($request) && 200 == wp_remote_retrieve_response_code($request)) {

            $response = json_decode(wp_remote_retrieve_body($request));
            error_log(print_r($response, true));

            $status = $response->data->status;
            $paymentStatus = $response->data->paymentStatus;
            $transactionReference = $response->data->transactionReference;
            

             if ('success' === $status && $paymentStatus === 'success' || 'approved' === $status && $paymentStatus === 'approved') {

                $order_details = explode('_', $transactionReference);

                $order_id = (int) $order_details[0];

                $order = wc_get_order($order_id);

                $payfi_txn_ref = get_post_meta($order_id, '_payfi_txn_ref', true);

                if ($order_id != $payfi_txn_ref) {
                    exit;
                }

                http_response_code(200);

                if (in_array($order->get_status(), array('processing', 'completed', 'on-hold'))) {
                    exit;
                }

                $order_currency = $order->get_currency();

                $currency_symbol = get_woocommerce_currency_symbol($order_currency);

                $order_total = $order->get_total();

                error_log(print_r($response, true));

                $amount_paid = $response->data->amount;
                

                // check if the amount paid is equal to the order amount.
                if ($amount_paid < $order_total) {

                    $order->update_status('on-hold', '');

                    update_post_meta($order_id, '_transaction_id', $transactionReference);

                    $notice = 'Thank you for shopping with us.<br />Your payment was successful, but the amount paid is not the same as the total order amount.<br />Your order is currently on-hold.<br />Kindly contact us for more information regarding your order and payment status.';

                    // Add Customer Order Note
                    $order->add_order_note($notice, 1);

                    // Add Admin Order Note.
                    $order->add_order_note('<strong>Look into this order</strong><br />This order is currently on hold.<br />Reason: Amount paid is less than the total order amount.<br />Amount Paid was <strong>' . $currency_symbol . $amount_paid . '</strong> while the total order amount is <strong>' . $currency_symbol . $order_total . '</strong><br /><strong>Transaction ID:</strong> ' . $transactionReference);

                    wc_reduce_stock_levels($order_id);

                } else {
                    $order->payment_complete($transactionReference);

                    $order->add_order_note(sprintf('Payment via Payfi successful (<strong>Transaction ID:</strong> %s)', $transactionReference));

                }

                wc_empty_cart();

            } else {
                $order_details = explode('_', $transactionReference);

                $order_id = (int) $order_details[0];

                $order = wc_get_order($order_id);

                $order->update_status('failed', 'Payment was declined by Payfi.');

            }
        }

        exit;
    }


    /**
     * Save payment token to the order for automatic renewal for further subscription payment
     */
    public function save_subscription_payment_token($order_id, $payment_token)
    {

        if (!function_exists('wcs_order_contains_subscription')) {

            return;

        }

        if ($this->order_contains_subscription($order_id) && !empty($payment_token)) {

            // Also store it on the subscriptions being purchased or paid for in the order.
            if (function_exists('wcs_order_contains_subscription') && wcs_order_contains_subscription($order_id)) {

                $subscriptions = wcs_get_subscriptions_for_order($order_id);

            } elseif (function_exists('wcs_order_contains_renewal') && wcs_order_contains_renewal($order_id)) {

                $subscriptions = wcs_get_subscriptions_for_renewal_order($order_id);

            } else {

                $subscriptions = array();

            }

            foreach ($subscriptions as $subscription) {

                $subscription_id = $subscription->get_id();

                update_post_meta($subscription_id, '_tbz_payfi_wc_token', $payment_token);

            }
        }

    }

    /**
     * @param $string
     *
     * @return bool
     */
    public function isJSON($string)
    {
        return is_string($string) && is_array(json_decode($string, true)) ? true : false;
    }

    /**
     * Checks if WC version is less than passed in version.
     *
     * @since 2.1.0
     * @param string $version Version to check against.
     * @return bool
     */
    public function is_wc_lt($version)
    {
        return version_compare(WC_VERSION, $version, '<');
    }

}
