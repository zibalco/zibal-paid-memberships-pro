<?php
/**
 * Plugin Name: Zibal Paid Memberships Pro
 * Description: درگاه پرداخت زیبال برای افزونه Paid Memberships Pro
 * Author: Yahya Kangi
 * Version: 1.0
 * Plugin URI: https://docs.zibal.ir/
 * Author URI: http://github.com/YahyaKng
 * License: GPL v2.0.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

//load classes init method
add_action('plugins_loaded', 'load_zibal_pmpro_class', 11);

add_filter('pmpro_currencies', 'zibal_pmpro_add_currency');
function zibal_pmpro_add_currency($currencies) {
	$currencies['IRT'] =  array(
		'name' =>'تومان',
		'symbol' => ' تومان ',
		'position' => 'left'
	);
	$currencies['IRR'] = array(
		'name' => 'ریال',
		'symbol' => ' ریال ',
		'position' => 'left'
	);
	return $currencies;
}

function post_to_zibal($url, $data = false) {
    $endpoint = 'https://gateway.zibal.ir/' . ltrim($url, '/');
    $response = wp_remote_post(
        $endpoint,
        [
            'headers'   => [
                'Content-Type' => 'application/json; charset=utf-8',
                'User-Agent'   => zibal_pmpro_user_agent(),
            ],
            'body'      => $data ? wp_json_encode($data) : '',
            'timeout'   => 20,
            'sslverify' => false,
        ]
    );

    if (is_wp_error($response)) {
        return false;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    if ($status_code < 200 || $status_code >= 300 || empty($body)) {
        return false;
    }

    $decoded = json_decode($body);

    return json_last_error() === JSON_ERROR_NONE ? $decoded : false;
}

function zibal_pmpro_user_agent() {
    global $wp_version;

    return sprintf(
        'ZibalPaidMembershipsPro/%s WordPress/%s; %s',
        '1.0',
        isset($wp_version) ? $wp_version : 'unknown',
        home_url()
    );
}

function zibal_pmpro_expected_amount($morder) {
    global $pmpro_currency;

    $amount = absint($morder->subtotal);
    if ($pmpro_currency === 'IRT') {
        $amount *= 10;
    }

    return $amount;
}

function zibal_pmpro_get_merchant() {
    $gtw_env = pmpro_getOption('gateway_environment');

    if ($gtw_env === '' || $gtw_env === 'sandbox') {
        return 'zibal';
    }

    return sanitize_text_field(pmpro_getOption('zibal_merchantid'));
}

function zibal_pmpro_store_pending_order($morder, $track_id, $amount) {
    $morder->status = 'pending';
    $morder->payment_transaction_id = $track_id;
    $morder->notes = sprintf(
        'Zibal pending payment. Track ID: %s; requested amount: %d',
        sanitize_text_field($track_id),
        absint($amount)
    );
    $morder->saveOrder();
}

function zibal_pmpro_response_to_text($response) {
    if (!$response) {
        return 'No response from Zibal.';
    }

    if (isset($response->message) && $response->message !== '') {
        return sanitize_textarea_field((string) $response->message);
    }

    return sanitize_textarea_field(wp_json_encode($response, JSON_UNESCAPED_UNICODE));
}

function zibal_pmpro_response_value($response, $keys, $default = '') {
    if (!$response) {
        return $default;
    }

    foreach ((array) $keys as $key) {
        if (isset($response->{$key}) && $response->{$key} !== '') {
            return sanitize_text_field((string) $response->{$key});
        }
    }

    return $default;
}

function zibal_pmpro_mask_card_number($card_number) {
    $digits = preg_replace('/\D+/', '', (string) $card_number);

    if (strlen($digits) < 10) {
        return sanitize_text_field((string) $card_number);
    }

    return substr($digits, 0, 6) . str_repeat('*', max(0, strlen($digits) - 10)) . substr($digits, -4);
}

function zibal_pmpro_update_order_meta($order_id, $key, $value) {
    $order_id = absint($order_id);
    $key = sanitize_key($key);

    if (!$order_id || $key === '') {
        return;
    }

    if (function_exists('pmpro_update_order_meta')) {
        pmpro_update_order_meta($order_id, $key, $value);
    } else {
        update_option('zibal_pmpro_order_' . $order_id . '_' . $key, $value, false);
    }
}

function zibal_pmpro_get_order_meta($order_id, $key) {
    $order_id = absint($order_id);
    $key = sanitize_key($key);

    if (!$order_id || $key === '') {
        return '';
    }

    if (function_exists('pmpro_get_order_meta')) {
        return pmpro_get_order_meta($order_id, $key, true);
    }

    return get_option('zibal_pmpro_order_' . $order_id . '_' . $key, '');
}

function zibal_pmpro_record_order_report($morder, $response, $successful) {
    $track_id = zibal_pmpro_response_value($response, ['trackId', 'refNumber'], (string) $morder->payment_transaction_id);
    $card_number = zibal_pmpro_response_value($response, ['cardNumber', 'cardNo', 'card'], (string) $morder->accountnumber);
    $report = [
        'transaction_number' => $track_id,
        'order_date' => isset($morder->timestamp) ? $morder->timestamp : current_time('mysql'),
        'card_number' => zibal_pmpro_mask_card_number($card_number),
        'payment_successful' => $successful ? 'بله' : 'خیر',
        'zibal_message' => zibal_pmpro_response_to_text($response),
    ];

    foreach ($report as $key => $value) {
        zibal_pmpro_update_order_meta($morder->id, 'zibal_' . $key, $value);
    }
}

function zibal_pmpro_get_stored_requested_amount($morder) {
    if (empty($morder->notes) || !preg_match('/requested amount:\s*(\d+)/', $morder->notes, $matches)) {
        return 0;
    }

    return absint($matches[1]);
}

function zibal_pmpro_cancel_order($morder, $message) {
    $morder->status = 'cancelled';
    $morder->notes = sanitize_text_field($message);
    $morder->saveOrder();
}

function zibal_pmpro_exit_with_message($message, $status_code = 400) {
    wp_die(esc_html($message), esc_html__('Zibal payment error', 'zibal-paid-memberships-pro'), ['response' => absint($status_code)]);
}

function zibal_pmpro_render_order_report($order = null) {
    if (!is_admin() || !current_user_can('manage_options')) {
        return;
    }

    if (empty($order)) {
        $order_id = isset($_GET['order']) ? sanitize_text_field(wp_unslash($_GET['order'])) : '';

        if ($order_id === '') {
            return;
        }

        try {
            $order = new MemberOrder($order_id);
        } catch (Exception $exception) {
            return;
        }
    }

    if (!is_object($order)) {
        try {
            $order = new MemberOrder(sanitize_text_field((string) $order));
        } catch (Exception $exception) {
            return;
        }
    }

    if (empty($order->id) || !isset($order->gateway) || $order->gateway !== 'zibal') {
        return;
    }

    static $rendered = [];
    if (isset($rendered[$order->id])) {
        return;
    }
    $rendered[$order->id] = true;

    $report = [
        'شماره تراکنش' => zibal_pmpro_get_order_meta($order->id, 'zibal_transaction_number'),
        'تاریخ ثبت سفارش' => zibal_pmpro_get_order_meta($order->id, 'zibal_order_date'),
        'شماره کارت' => zibal_pmpro_get_order_meta($order->id, 'zibal_card_number'),
        'پرداخت موفق' => zibal_pmpro_get_order_meta($order->id, 'zibal_payment_successful'),
        'متن زیبال' => zibal_pmpro_get_order_meta($order->id, 'zibal_zibal_message'),
    ];

    if (implode('', array_map('strval', $report)) === '') {
        return;
    }

    ?>
    <div class="postbox zibal-pmpro-order-report" style="padding:12px;margin:16px 0;">
        <h2><?php echo esc_html('گزارش پرداخت زیبال'); ?></h2>
        <table class="widefat striped">
            <tbody>
                <?php foreach ($report as $label => $value) : ?>
                    <tr>
                        <th scope="row"><?php echo esc_html($label); ?></th>
                        <td><?php echo esc_html($value); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function zibal_pmpro_maybe_render_admin_order_report() {
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

    if ($page !== 'pmpro-orders') {
        return;
    }

    zibal_pmpro_render_order_report();
}

function load_zibal_pmpro_class()
{
    if (class_exists('PMProGateway')) {
        class PMProGateway_Zibal extends PMProGateway
        {
            public function __construct($gateway = null)
            {
                $this->gateway = $gateway;
                $this->gateway_environment = pmpro_getOption('gateway_environment');
            }

            public function PMProGateway_Zibal($gateway = null)
            {
                $this->__construct($gateway);
            }

            public static function init()
            {
                //make sure Zibal is a gateway option
                add_filter('pmpro_gateways', ['PMProGateway_Zibal', 'pmpro_gateways']);

                //add fields to payment settings
                add_filter('pmpro_payment_options', ['PMProGateway_Zibal', 'pmpro_payment_options']);
                add_filter('pmpro_payment_option_fields', ['PMProGateway_Zibal', 'pmpro_payment_option_fields'], 10, 2);
                $gateway = pmpro_getOption('gateway');

                if ($gateway == 'zibal') {
                    add_filter('pmpro_checkout_before_change_membership_level', ['PMProGateway_Zibal', 'pmpro_checkout_before_change_membership_level'], 10, 2);
                    add_filter('pmpro_include_billing_address_fields', '__return_false');
                    add_filter('pmpro_include_payment_information_fields', '__return_false');
                    add_filter('pmpro_required_billing_fields', ['PMProGateway_Zibal', 'pmpro_required_billing_fields']);
                }

                add_action('wp_ajax_nopriv_zibal-ins', ['PMProGateway_Zibal', 'pmpro_wp_ajax_zibal_ins']);
                add_action('wp_ajax_zibal-ins', ['PMProGateway_Zibal', 'pmpro_wp_ajax_zibal_ins']);
                add_action('admin_notices', 'zibal_pmpro_maybe_render_admin_order_report');
                add_action('pmpro_after_order_settings', 'zibal_pmpro_render_order_report');
            }

            /**
             * Make sure Zibal is in the gateways list.
             *
             * @since 1.0
             */
            public static function pmpro_gateways($gateways)
            {
                if (empty($gateways['zibal'])) {
                    $gateways['zibal'] = 'زیبال';
                }

                return $gateways;
            }

            /**
             * Get a list of payment options that the Zibal gateway needs/supports.
             *
             * @since 1.0
             */
            public static function getGatewayOptions()
            {
                $options = [
                    'zibal_merchantid',
					'currency',
					'tax_rate',
                ];

                return $options;
            }

            /**
             * Set payment options for payment settings page.
             *
             * @since 1.0
             */
            public static function pmpro_payment_options($options)
            {
                //get zibal options
                $zibal_options = self::getGatewayOptions();

                //merge with others.
                $options = array_merge($zibal_options, $options);

                return $options;
            }

            /**
             * Remove required billing fields.
             *
             * @since 1.8
             */
            public static function pmpro_required_billing_fields($fields)
            {
                unset($fields['bfirstname']);
                unset($fields['blastname']);
                unset($fields['baddress1']);
                unset($fields['bcity']);
                unset($fields['bstate']);
                unset($fields['bzipcode']);
                unset($fields['bphone']);
                unset($fields['bemail']);
                unset($fields['bcountry']);
                unset($fields['CardType']);
                unset($fields['AccountNumber']);
                unset($fields['ExpirationMonth']);
                unset($fields['ExpirationYear']);
                unset($fields['CVV']);

                return $fields;
            }

            /**
             * Display fields for Zibal options.
             *
             * @since 1.0
             */
            public static function pmpro_payment_option_fields($values, $gateway)
            {
                $merchant_id = isset($values['zibal_merchantid']) ? $values['zibal_merchantid'] : '';
                ?>
                <tr class="pmpro_settings_divider gateway gateway_zibal" <?php if ($gateway !== 'zibal') {
                    ?>style="display: none;"<?php 
                }
                ?>>
                <td colspan="2">
                    <?php echo esc_html('تنظیمات زیبال');
                ?>
                </td>
                </tr>
                <tr class="gateway gateway_zibal" <?php if ($gateway !== 'zibal') {
                    ?>style="display: none;"<?php 
                }
                ?>>
                <th scope="row" valign="top">
                <label for="zibal_merchantid"><?php echo esc_html('کد مرچنت جهت اتصال به زیبال:'); ?></label>
                </th>
                <td>
                    <input type="text" id="zibal_merchantid" name="zibal_merchantid" size="60" value="<?php echo esc_attr($merchant_id);
                ?>" />
                </td>
                </tr>

                <?php

            }

            /**
             * Instead of change membership levels, send users to Zibal to pay.
             *
             * @since 1.8
             */
            public static function pmpro_checkout_before_change_membership_level($user_id, $morder)
            {
                global $wpdb, $discount_code_id;

                //if no order, no need to pay
                if (empty($morder)) {
                    return;
                }

                $morder->user_id = $user_id;
                $morder->saveOrder();

                //save discount code use
                if (!empty($discount_code_id)) {
                    $wpdb->query(
                        $wpdb->prepare(
                            "INSERT INTO {$wpdb->pmpro_discount_codes_uses} (code_id, user_id, order_id, timestamp) VALUES(%d, %d, %d, %s)",
                            absint($discount_code_id),
                            absint($user_id),
                            absint($morder->id),
                            current_time('mysql')
                        )
                    );
                }

                $order_id = $morder->code;
                $redirect = add_query_arg(
                    [
                        'action' => 'zibal-ins',
                        'oid'    => $order_id,
                    ],
                    admin_url('admin-ajax.php')
                );

                $amount = zibal_pmpro_expected_amount($morder);

                $data = [
                    'merchant' => zibal_pmpro_get_merchant(),
                    'amount' => $amount,
                    'orderId' => $order_id,
                    'callbackUrl' => $redirect,
                ];
                
                $result = post_to_zibal('v1/request', $data);

                if ($result && isset($result->result, $result->trackId) && intval($result->result) === 100) {
                    $track_id = sanitize_text_field((string) $result->trackId);
                    zibal_pmpro_store_pending_order($morder, $track_id, $amount);
                    $go = 'https://gateway.zibal.ir/start/' . rawurlencode($track_id);
                    wp_redirect(esc_url_raw($go));
                    exit;

                } else {
                    $result_code = ($result && isset($result->result)) ? intval($result->result) : 0;
                    $zibal_message = zibal_pmpro_response_to_text($result);
                    $Err = 'خطا در ارسال اطلاعات به زیبال کد خطا :  ' . $result_code;
                    zibal_pmpro_cancel_order($morder, $Err);
                    zibal_pmpro_record_order_report($morder, $result, false);
                    zibal_pmpro_exit_with_message($Err . ' - ' . $zibal_message);
                }
            }

            public static function pmpro_wp_ajax_zibal_ins()
            {
                if (!isset($_GET['oid']) || is_null($_GET['oid'])) {
                    zibal_pmpro_exit_with_message('meghdare oid dar dargahe zibal elzamist');
                }

                $oid = sanitize_text_field(wp_unslash($_GET['oid']));

                $morder = null;
                try {
                    $morder = new MemberOrder($oid);
                    $morder->getMembershipLevel();
                    $morder->getUser();
                } catch (Exception $exception) {
                    zibal_pmpro_exit_with_message('meghdare oid na motabar ast');
                }

                $current_user_id = get_current_user_id();

                if ($current_user_id !== intval($morder->user_id)) {
                    zibal_pmpro_exit_with_message('in kharid motealegh be shoma nist', 403);
                }

                $status = isset($_GET['status']) ? absint(wp_unslash($_GET['status'])) : 0;
                $trackId = isset($_GET['trackId']) ? sanitize_text_field(wp_unslash($_GET['trackId'])) : '';

                if (empty($trackId)) {
                    zibal_pmpro_cancel_order($morder, 'Zibal callback missing trackId');
                    wp_safe_redirect(pmpro_url());
                    exit;
                }

                if (!empty($morder->payment_transaction_id) && !hash_equals((string) $morder->payment_transaction_id, (string) $trackId)) {
                    zibal_pmpro_cancel_order($morder, 'Zibal callback trackId mismatch');
                    zibal_pmpro_exit_with_message('trackId na motabar ast', 403);
                }

                if ($morder->status === 'success') {
                    wp_safe_redirect(pmpro_url("confirmation", "?level=" . absint($morder->membership_level->id)));
                    exit;
                }
                 
                if ($status === 2) {
                    $amount = zibal_pmpro_expected_amount($morder);
                    $stored_amount = zibal_pmpro_get_stored_requested_amount($morder);

                    if ($stored_amount > 0 && $stored_amount !== $amount) {
                        zibal_pmpro_cancel_order($morder, 'Zibal requested amount mismatch');
                        zibal_pmpro_exit_with_message('meghdare pardakht ba sefaresh motabegh nist', 403);
                    }

                    $lock_key = 'zibal_pmpro_verify_' . md5($oid . '|' . $trackId);

                    if (get_transient($lock_key)) {
                        zibal_pmpro_exit_with_message('pardakht dar hale barrasi ast', 409);
                    }

                    set_transient($lock_key, 1, MINUTE_IN_SECONDS);
                    
                    $data = [
                        'merchant' => zibal_pmpro_get_merchant(),
                        'trackId' => $trackId,
                    ];
                    
                    $result = post_to_zibal('v1/verify', $data);
                    delete_transient($lock_key);

                    if ($result && isset($result->result, $result->amount) && intval($result->result) === 100 && absint($result->amount) === $amount) {
                        zibal_pmpro_record_order_report($morder, $result, true);

                        if (self::do_level_up($morder, $trackId)) {
                            $go = pmpro_url("confirmation", "?level=" . absint($morder->membership_level->id));
                            wp_safe_redirect($go);
                            exit;
                        }

                        zibal_pmpro_cancel_order($morder, 'Zibal payment verified but membership level change failed');
                        zibal_pmpro_record_order_report($morder, $result, false);
                        zibal_pmpro_exit_with_message('taghire sath ozviat ba khata movajeh shod', 500);
                    } else {
                        $result_code = ($result && isset($result->result)) ? intval($result->result) : 0;
                        $zibal_message = zibal_pmpro_response_to_text($result);
                        $Err = 'خطا در ارسال اطلاعات به زیبال کد خطا :  ' . $result_code;
                        zibal_pmpro_cancel_order($morder, $Err);
                        zibal_pmpro_record_order_report($morder, $result, false);
                        wp_safe_redirect(pmpro_url());
                        exit;
                    }
                } else {
                    $Err = 'پرداخت توسط کاربر یا درگاه زیبال لغو شد';
                    zibal_pmpro_cancel_order($morder, $Err);
                    zibal_pmpro_record_order_report($morder, false, false);
                    wp_safe_redirect(pmpro_url());
                    exit;
                }
            }

            public static function do_level_up(&$morder, $txn_id)
            {
                global $wpdb;
                //filter for level
                $morder->membership_level = apply_filters('pmpro_inshandler_level', $morder->membership_level, $morder->user_id);

                //fix expiration date
                if (!empty($morder->membership_level->expiration_number)) {
                    $enddate = "'".date('Y-m-d', strtotime('+ '.$morder->membership_level->expiration_number.' '.$morder->membership_level->expiration_period, current_time('timestamp')))."'";
                } else {
                    $enddate = 'NULL';
                }

                //get discount code
                $morder->getDiscountCode();
                if (!empty($morder->discount_code)) {
                    //update membership level
                    $morder->getMembershipLevel(true);
                    $discount_code_id = $morder->discount_code->id;
                } else {
                    $discount_code_id = '';
                }

                //set the start date to current_time('mysql') but allow filters
                $startdate = apply_filters('pmpro_checkout_start_date', "'".current_time('mysql')."'", $morder->user_id, $morder->membership_level);

                //custom level to change user to
                $custom_level = [
                    'user_id'         => $morder->user_id,
                    'membership_id'   => $morder->membership_level->id,
                    'code_id'         => $discount_code_id,
                    'initial_payment' => $morder->membership_level->initial_payment,
                    'billing_amount'  => $morder->membership_level->billing_amount,
                    'cycle_number'    => $morder->membership_level->cycle_number,
                    'cycle_period'    => $morder->membership_level->cycle_period,
                    'billing_limit'   => $morder->membership_level->billing_limit,
                    'trial_amount'    => $morder->membership_level->trial_amount,
                    'trial_limit'     => $morder->membership_level->trial_limit,
                    'startdate'       => $startdate,
                    'enddate'         => $enddate, ];

                global $pmpro_error;
                if (!empty($pmpro_error)) {
                    echo esc_html($pmpro_error);
                    if (function_exists('inslog')) {
                        inslog($pmpro_error);
                    }
                }
                
                if (pmpro_changeMembershipLevel($custom_level, $morder->user_id) !== false) {
                    //update order status and transaction ids
                    $morder->status = 'success';
                    $morder->payment_transaction_id = $txn_id;
                    //if( $recurring )
                    //    $morder->subscription_transaction_id = $txn_id;
                    //else
                    $morder->subscription_transaction_id = '';
                    $morder->saveOrder();

                    //save first and last name fields
                    if (!empty($_POST['first_name'])) {
                        $first_name = sanitize_text_field(wp_unslash($_POST['first_name']));
                        $old_firstname = get_user_meta($morder->user_id, 'first_name', true);
                        if (!empty($old_firstname)) {
                            update_user_meta($morder->user_id, 'first_name', $first_name);
                        }
                    }
                    if (!empty($_POST['last_name'])) {
                        $last_name = sanitize_text_field(wp_unslash($_POST['last_name']));
                        $old_lastname = get_user_meta($morder->user_id, 'last_name', true);
                        if (!empty($old_lastname)) {
                            update_user_meta($morder->user_id, 'last_name', $last_name);
                        }
                    }
                    
                    //hook
                    do_action('pmpro_after_checkout', $morder->user_id, $morder);

                    
                    //setup some values for the emails
                    if (!empty($morder)) {
                        $invoice = new MemberOrder($morder->id);
                    } else {
                        $invoice = null;
                    }

                    //inslog("CHANGEMEMBERSHIPLEVEL: ORDER: " . var_export($morder, true) . "\n---\n");

                    $user = get_userdata(intval($morder->user_id));
                    if (empty($user)) {
                        return false;
                    }

                    $user->membership_level = $morder->membership_level;  //make sure they have the right level info
                    //send email to member
                    $pmproemail = new PMProEmail();
                    $pmproemail->sendCheckoutEmail($user, $invoice);

                    //send email to admin
                    $pmproemail = new PMProEmail();
                    $pmproemail->sendCheckoutAdminEmail($user, $invoice);

                    return true;
                } else {
                    return false;
                }
            }
        }

        PMProGateway_Zibal::init();
    }
}
