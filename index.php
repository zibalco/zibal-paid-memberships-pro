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
//load classes init method
add_action('plugins_loaded', 'load_zibal_pmpro_class', 11);
add_action('plugins_loaded', ['PMProGateway_Zibal', 'init'], 12);

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
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://gateway.zibal.ir/".$url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json; charset=utf-8'));
    curl_setopt($ch, CURLOPT_POST, 1);
    if ($data) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    $result = curl_exec($ch);
    curl_close($ch);
    return !empty($result) ? json_decode($result) : false;
}

function load_zibal_pmpro_class()
{
    if (class_exists('PMProGateway')) {
        class PMProGateway_Zibal extends PMProGateway
        {
            public function PMProGateway_Zibal($gateway = null)
            {
                $this->gateway = $gateway;
                $this->gateway_environment = pmpro_getOption('gateway_environment');

                return $this->gateway;
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
                ?>
                <tr class="pmpro_settings_divider gateway gateway_zibal" <?php if ($gateway != 'zibal') {
                    ?>style="display: none;"<?php 
                }
                ?>>
                <td colspan="2">
                    <?php echo 'تنظیمات زیبال';
                ?>
                </td>
                </tr>
                <tr class="gateway gateway_zibal" <?php if ($gateway != 'zibal') {
                    ?>style="display: none;"<?php 
                }
                ?>>
                <th scope="row" valign="top">
                <label for="zibal_merchantid">کد مرچنت جهت اتصال به زیبال:</label>
                </th>
                <td>
                    <input type="text" id="zibal_merchantid" name="zibal_merchantid" size="60" value="<?php echo esc_attr($values['zibal_merchantid']);
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
                    $wpdb->query("INSERT INTO $wpdb->pmpro_discount_codes_uses (code_id, user_id, order_id, timestamp) VALUES('".$discount_code_id."', '".$user_id."', '".$morder->id."', now())");
                }

                global $pmpro_currency;

                $gtw_env = pmpro_getOption('gateway_environment');

                if ($gtw_env == '' || $gtw_env == 'sandbox') {
                    $merchant = 'zibal';
                } else {
                    $merchant = pmpro_getOption('zibal_merchantid');
                }

                $order_id = $morder->code;
                $redirect = admin_url('admin-ajax.php')."?action=zibal-ins&oid=$order_id";


                global $pmpro_currency;

                $amount = intval($morder->subtotal);
                if ($pmpro_currency == 'IRT') {
                    $amount *= 10;
                }

                $data = [
                    'merchant' => $merchant,
                    'amount' => $amount,
                    'orderId' => $order_id,
                    'callbackUrl' => $redirect,
                ];
                
                $result = post_to_zibal('v1/request', $data);

                if ($result->result == 100) {
                    $go = 'https://gateway.zibal.ir/start/'.$result->trackId;
                    header("Location: {$go}");
                    die();

                } else {
                    $Err = 'خطا در ارسال اطلاعات به زیبال کد خطا :  '.$result->result;
                    $morder->status = 'cancelled';
                    $morder->notes = $Err;
                    $morder->saveOrder();
                    die($Err);
                }
            }

            public static function pmpro_wp_ajax_zibal_ins()
            {
                global $gateway_environment;
                global $pmpro_currency;
                if (!isset($_GET['oid']) || is_null($_GET['oid'])) {
                    die('meghdare oid dar dargahe zibal elzamist');
                }

                $oid = $_GET['oid'];

                $morder = null;
                try {
                    $morder = new MemberOrder($oid);
                    $morder->getMembershipLevel();
                    $morder->getUser();
                } catch (Exception $exception) {
                    die('meghdare oid na motabar ast');
                }

                $current_user_id = get_current_user_id();

                if ($current_user_id !== intval($morder->user_id)) {
                    die('in kharid motealegh be shoma nist');
                }

                $gtw_env = pmpro_getOption('gateway_environment');

                if ($gtw_env == '' || $gtw_env == 'sandbox') {
                    $merchant = 'zibal';
                } else {
                    $merchant = pmpro_getOption('zibal_merchantid');
                }
                 
                if ($_GET['status'] == 2) {
                    $trackId = $_GET['trackId'];
                    $amount = intval($morder->subtotal);
                    if ($pmpro_currency == 'IRT') {
                        $amount *= 10;
                    }
                    
                    $data = [
                        'merchant' => $merchant,
                        'trackId' => $trackId,
                    ];
                    
                    $result = post_to_zibal('v1/verify', $data);

                    if ($result->result == 100 && $result->amount == $amount) {
                        // $trans_id = 9823018241;
                        if (self::do_level_up($morder, $trans_id)) {
                            $go = pmpro_url("confirmation", "?level=".$morder->membership_level->id);
                            header("Location: {$go}");
                            die();
                        }
                    } else {
                        $Err = 'خطا در ارسال اطلاعات به زیبال کد خطا :  '.$result->result;
                        $morder->status = 'cancelled';
                        $morder->notes = $Err;
                        $morder->saveOrder();
                        header('Location: '.pmpro_url());
                        die($Err);
                    }
                } else {
                    $Err = '  '.$result->result;
                    $morder->status = 'cancelled';
                    $morder->notes = $Err;
                    $morder->saveOrder();
                    header('Location: '.pmpro_url());
                    die($Err);
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
                    echo $pmpro_error;
                    inslog($pmpro_error);
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

                    //add discount code use
                    if (!empty($discount_code) && !empty($use_discount_code)) {
                        $wpdb->query("INSERT INTO $wpdb->pmpro_discount_codes_uses (code_id, user_id, order_id, timestamp) VALUES('".$discount_code_id."', '".$morder->user_id."', '".$morder->id."', '".current_time('mysql')."')");
                    }

                    //save first and last name fields
                    if (!empty($_POST['first_name'])) {
                        $old_firstname = get_user_meta($morder->user_id, 'first_name', true);
                        if (!empty($old_firstname)) {
                            update_user_meta($morder->user_id, 'first_name', $_POST['first_name']);
                        }
                    }
                    if (!empty($_POST['last_name'])) {
                        $old_lastname = get_user_meta($morder->user_id, 'last_name', true);
                        if (!empty($old_lastname)) {
                            update_user_meta($morder->user_id, 'last_name', $_POST['last_name']);
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
    }
}
