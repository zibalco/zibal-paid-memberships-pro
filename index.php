<?php
/**
 * Plugin Name: Zibal Paid Memberships Pro
 * Description: درگاه پرداخت زیبال برای افزونه Paid Memberships Pro
 * Author: Zibal
 * Version: 1.7
 * Requires PHP: 7.4
 * Plugin URI: https://zibal.ir/
 * Author URI: http://github.com/zibalco
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

function zibal_pmpro_remote_post($url, $data = false) {
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
        return (object) [
            'result'  => 'transport_error',
            'message' => sprintf(
                'خطا در ارتباط با زیبال: %s',
                sanitize_text_field($response->get_error_message())
            ),
        ];
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    $decoded = !empty($body) ? json_decode($body) : null;

    if ($status_code < 200 || $status_code >= 300) {
        if (json_last_error() === JSON_ERROR_NONE && is_object($decoded)) {
            if (!isset($decoded->message) || !is_scalar($decoded->message) || $decoded->message === '') {
                $decoded->message = sprintf('زیبال پاسخ HTTP %d برگرداند.', absint($status_code));
            }

            return $decoded;
        }

        return (object) [
            'result'  => 'http_error',
            'message' => sprintf('زیبال پاسخ HTTP %d برگرداند.', absint($status_code)),
        ];
    }

    if (empty($body)) {
        return (object) [
            'result'  => 'empty_response',
            'message' => 'پاسخ زیبال خالی بود.',
        ];
    }

    if (json_last_error() !== JSON_ERROR_NONE || !is_object($decoded)) {
        return (object) [
            'result'  => 'invalid_response',
            'message' => 'پاسخ زیبال قابل خواندن نبود.',
        ];
    }

    return $decoded;
}

function zibal_pmpro_user_agent() {
    global $wp_version;

    return sprintf(
        'ZibalPaidMembershipsPro/%s WordPress/%s; %s',
        '1.7',
        isset($wp_version) ? $wp_version : 'unknown',
        home_url()
    );
}

function zibal_pmpro_normalize_currency($currency = null) {
    global $pmpro_currency;

    if ($currency === null || $currency === '') {
        $currency = $pmpro_currency;
    }

    return strtoupper(trim(sanitize_text_field((string) $currency)));
}

function zibal_pmpro_validate_currency($currency = null) {
    $currency = zibal_pmpro_normalize_currency($currency);

    if (!in_array($currency, ['IRR', 'IRT'], true)) {
        return new WP_Error(
            'zibal_pmpro_unsupported_currency',
            'درگاه زیبال فقط واحد پول ریال (IRR) یا تومان (IRT) را می‌پذیرد. واحد پول را در تنظیمات Paid Memberships Pro اصلاح کنید.'
        );
    }

    return true;
}

function zibal_pmpro_expected_amount($morder, $currency = null) {
    if (!is_object($morder)) {
        return new WP_Error(
            'zibal_pmpro_invalid_order',
            'اطلاعات سفارش برای محاسبه مبلغ پرداخت معتبر نیست.'
        );
    }

    $currency = zibal_pmpro_normalize_currency($currency);
    $currency_validation = zibal_pmpro_validate_currency($currency);

    if (is_wp_error($currency_validation)) {
        return $currency_validation;
    }

    if (isset($morder->total) && $morder->total !== '') {
        $order_total = $morder->total;
    } else {
        $subtotal = isset($morder->subtotal) && is_numeric($morder->subtotal) ? (float) $morder->subtotal : 0;
        $tax = isset($morder->tax) && is_numeric($morder->tax) ? (float) $morder->tax : 0;
        $order_total = $subtotal + $tax;
    }

    if (!is_numeric($order_total)) {
        return new WP_Error(
            'zibal_pmpro_invalid_amount',
            'مبلغ نهایی سفارش معتبر نیست.'
        );
    }

    $order_total = (float) $order_total;

    if (!is_finite($order_total) || $order_total <= 0) {
        return new WP_Error(
            'zibal_pmpro_invalid_amount',
            'مبلغ نهایی سفارش باید یک عدد صحیح بزرگ‌تر از صفر باشد.'
        );
    }

    if (abs($order_total - round($order_total)) > 0.0000001) {
        return new WP_Error(
            'zibal_pmpro_decimal_amount',
            'درگاه زیبال مبلغ اعشاری را نمی‌پذیرد. مبلغ نهایی سطح عضویت، مالیات و تخفیف‌ها باید بدون اعشار تنظیم شوند.'
        );
    }

    $currency_multiplier = $currency === 'IRT' ? 10 : 1;

    if ($order_total > floor(PHP_INT_MAX / $currency_multiplier)) {
        return new WP_Error(
            'zibal_pmpro_amount_overflow',
            'مبلغ سفارش از محدوده قابل پردازش خارج است.'
        );
    }

    return (int) round($order_total) * $currency_multiplier;
}

function zibal_pmpro_amount_matches($gateway_amount, $expected_amount) {
    if (!is_numeric($gateway_amount) || !is_int($expected_amount)) {
        return false;
    }

    $gateway_amount = (float) $gateway_amount;

    if (!is_finite($gateway_amount) || abs($gateway_amount - round($gateway_amount)) > 0.0000001) {
        return false;
    }

    return (int) round($gateway_amount) === $expected_amount;
}

/**
 * Validate that a PMPro level can be paid through Zibal.
 *
 * Zibal's request/verify API creates one-time payments and does not return a
 * subscription identifier that PMPro could renew, synchronize, or cancel.
 *
 * @param object|null $level PMPro membership level.
 * @return true|WP_Error
 */
function zibal_pmpro_validate_one_time_level($level) {
    if (!is_object($level)) {
        return true;
    }

    if (function_exists('pmpro_isLevelRecurring')) {
        $is_recurring = pmpro_isLevelRecurring($level);
    } else {
        $billing_amount = isset($level->billing_amount) && is_numeric($level->billing_amount)
            ? (float) $level->billing_amount
            : 0;
        $trial_amount = isset($level->trial_amount) && is_numeric($level->trial_amount)
            ? (float) $level->trial_amount
            : 0;
        $is_recurring = $billing_amount > 0 || $trial_amount > 0;
    }

    if ($is_recurring) {
        return new WP_Error(
            'zibal_pmpro_recurring_not_supported',
            'درگاه زیبال فقط پرداخت یک‌باره را پشتیبانی می‌کند. برای این سطح، پرداخت دوره‌ای را غیرفعال کنید یا درگاه دیگری انتخاب کنید.'
        );
    }

    return true;
}

/**
 * Validate the membership level attached to an order.
 *
 * @param object $morder PMPro order.
 * @return true|WP_Error
 */
function zibal_pmpro_validate_one_time_order($morder) {
    $level = is_object($morder) && isset($morder->membership_level)
        ? $morder->membership_level
        : null;

    return zibal_pmpro_validate_one_time_level($level);
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

    zibal_pmpro_update_order_meta($morder->id, 'zibal_track_id', (string) $track_id);
    zibal_pmpro_update_order_meta($morder->id, 'zibal_requested_amount', (int) $amount);
    zibal_pmpro_update_order_meta($morder->id, 'zibal_payment_state', 'pending');
}

function zibal_pmpro_response_to_text($response) {
    if (is_wp_error($response)) {
        return sanitize_textarea_field($response->get_error_message());
    }

    if (!$response) {
        return 'پاسخی از زیبال دریافت نشد.';
    }

    if (isset($response->message) && is_scalar($response->message) && $response->message !== '') {
        return sanitize_textarea_field((string) $response->message);
    }

    $status_message = zibal_pmpro_response_value($response, ['statusMessage'], '');
    if ($status_message !== '') {
        return sanitize_textarea_field($status_message);
    }

    $status = zibal_pmpro_response_value($response, ['status'], '');
    if ($status !== '') {
        return sprintf(
            '%s (کد وضعیت زیبال: %s)',
            zibal_pmpro_status_to_text($status),
            sanitize_text_field((string) $status)
        );
    }

    $result_code = zibal_pmpro_response_value($response, ['result', 'status'], 'نامشخص');

    return sanitize_textarea_field(sprintf(
        '%s (کد نتیجه زیبال: %s)',
        zibal_pmpro_result_to_text($result_code),
        $result_code
    ));
}

function zibal_pmpro_status_to_text($status) {
    $messages = [
        '-2' => 'خطای داخلی زیبال رخ داده است.',
        '-1' => 'تراکنش در انتظار پرداخت است.',
        '1'  => 'پرداخت انجام و تراکنش تأیید شده است.',
        '2'  => 'پرداخت انجام شده و در انتظار تأیید است.',
        '3'  => 'پرداخت توسط کاربر لغو شد.',
        '4'  => 'شماره کارت واردشده نامعتبر است.',
        '5'  => 'موجودی حساب برای پرداخت کافی نیست.',
        '6'  => 'رمز کارت یا رمز پویای واردشده صحیح نیست.',
        '7'  => 'تعداد درخواست‌های پرداخت بیش از حد مجاز است.',
        '8'  => 'تعداد پرداخت اینترنتی روزانه کارت از حد مجاز عبور کرده است.',
        '9'  => 'مبلغ پرداخت اینترنتی روزانه کارت از حد مجاز عبور کرده است.',
        '10' => 'صادرکننده کارت نامعتبر است.',
        '11' => 'خطایی در سوئیچ بانکی رخ داده است.',
        '12' => 'کارت بانکی قابل دسترسی نیست.',
        '15' => 'مبلغ تراکنش بازگشت داده شده است.',
        '16' => 'بازگشت مبلغ تراکنش در حال انجام است.',
        '18' => 'تراکنش برگشت خورده است.',
        '21' => 'پذیرنده نامعتبر است.',
    ];

    $status = (string) $status;

    return isset($messages[$status]) ? $messages[$status] : 'وضعیت اعلام‌شده توسط زیبال ناشناخته است.';
}

function zibal_pmpro_result_to_text($result) {
    $messages = [
        '100' => 'عملیات با موفقیت انجام شد.',
        '102' => 'پذیرنده زیبال یافت نشد.',
        '103' => 'پذیرنده زیبال غیرفعال است.',
        '104' => 'اطلاعات پذیرنده زیبال نامعتبر است.',
        '105' => 'مبلغ پرداخت کمتر از حد مجاز زیبال است.',
        '106' => 'آدرس بازگشت پرداخت نامعتبر است.',
        '113' => 'مبلغ تراکنش از سقف مجاز عبور کرده است.',
        '115' => 'نشانی IP در پنل زیبال ثبت نشده است.',
        '201' => 'تراکنش قبلاً تأیید شده است.',
        '202' => 'سفارش پرداخت نشده یا پرداخت ناموفق بوده است.',
        '203' => 'شناسه پیگیری زیبال نامعتبر است.',
        'transport_error' => 'ارتباط شبکه‌ای با زیبال برقرار نشد.',
        'http_error' => 'زیبال پاسخ HTTP ناموفق برگرداند.',
        'empty_response' => 'پاسخ زیبال خالی بود.',
        'invalid_response' => 'پاسخ زیبال قابل خواندن نبود.',
    ];

    $result = (string) $result;

    return isset($messages[$result]) ? $messages[$result] : 'نتیجه اعلام‌شده توسط زیبال ناشناخته است.';
}

function zibal_pmpro_response_value($response, $keys, $default = '') {
    if (!$response || is_wp_error($response)) {
        return $default;
    }

    $properties = is_object($response) ? get_object_vars($response) : [];

    foreach ((array) $keys as $key) {
        if (isset($response->{$key}) && $response->{$key} !== '') {
            return sanitize_text_field((string) $response->{$key});
        }

        foreach ($properties as $property_key => $property_value) {
            if (strtolower((string) $property_key) === strtolower((string) $key) && $property_value !== '') {
                return is_scalar($property_value)
                    ? sanitize_text_field((string) $property_value)
                    : $default;
            }
        }
    }

    return $default;
}

function zibal_pmpro_mask_card_number($card_number) {
    $digits = preg_replace('/\D+/', '', (string) $card_number);

    if ($digits === '') {
        return '-';
    }

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

    if (function_exists('update_pmpro_membership_order_meta')) {
        update_pmpro_membership_order_meta($order_id, $key, $value);
    } elseif (function_exists('pmpro_update_order_meta')) {
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

    if (function_exists('get_pmpro_membership_order_meta')) {
        return get_pmpro_membership_order_meta($order_id, $key, true);
    }

    if (function_exists('pmpro_get_order_meta')) {
        return pmpro_get_order_meta($order_id, $key, true);
    }

    return get_option('zibal_pmpro_order_' . $order_id . '_' . $key, '');
}

function zibal_pmpro_record_order_report($morder, $response, $successful) {
    $stored_track_id = zibal_pmpro_get_order_meta($morder->id, 'zibal_track_id');
    $track_id = zibal_pmpro_response_value(
        $response,
        ['trackId'],
        $stored_track_id !== '' ? (string) $stored_track_id : (string) $morder->payment_transaction_id
    );
    $reference_number = zibal_pmpro_response_value($response, ['refNumber'], '-');
    $card_number = zibal_pmpro_response_value($response, ['cardNumber', 'cardNo', 'card'], '-');
    $payment_time = zibal_pmpro_response_value(
        $response,
        ['paidAt', 'paid_at', 'paymentTime', 'payment_time'],
        current_time('mysql')
    );
    $stored_amount = zibal_pmpro_get_stored_requested_amount($morder);
    $gateway_amount = zibal_pmpro_response_value($response, ['amount'], (string) $stored_amount);
    $gateway_amount = is_numeric($gateway_amount) ? (int) round((float) $gateway_amount) : $stored_amount;
    $masked_card = zibal_pmpro_mask_card_number($card_number);
    $zibal_message = zibal_pmpro_response_to_text($response);
    $status_code = zibal_pmpro_response_value($response, ['status', 'result'], '-');
    $report = [
        'transaction_number' => $reference_number,
        'track_id' => $track_id,
        'order_date' => isset($morder->timestamp) ? $morder->timestamp : current_time('mysql'),
        'payment_time' => $payment_time,
        'paid_amount' => $gateway_amount,
        'card_number' => $masked_card,
        'payment_successful' => $successful ? 'بله' : 'خیر',
        'zibal_status_code' => $status_code,
        'zibal_message' => $zibal_message,
    ];

    foreach ($report as $key => $value) {
        zibal_pmpro_update_order_meta($morder->id, 'zibal_' . $key, $value);
    }

    $note_lines = [
        $successful ? 'وضعیت پرداخت زیبال: موفق' : 'وضعیت پرداخت زیبال: ناموفق / لغوشده',
        'مبلغ تراکنش در زیبال: ' . number_format($gateway_amount) . ' ریال',
        'شناسه پیگیری زیبال (trackId): ' . ($track_id !== '' ? $track_id : '-'),
        'شماره تراکنش بانکی (refNumber): ' . ($reference_number !== '' ? $reference_number : '-'),
        'شماره کارت: ' . $masked_card,
        'زمان دقیق رویداد پرداخت: ' . $payment_time,
        'کد وضعیت/نتیجه زیبال: ' . $status_code,
        'پیام زیبال: ' . $zibal_message,
    ];

    $review_note = isset($morder->status) && $morder->status === 'review' && !empty($morder->notes)
        ? sanitize_textarea_field((string) $morder->notes)
        : '';

    if ($review_note !== '') {
        array_unshift($note_lines, 'نیازمند بررسی مدیر: ' . $review_note, '');
    }

    $morder->notes = sanitize_textarea_field(implode("\n", $note_lines));
    $morder->saveOrder();
}

function zibal_pmpro_callback_response_from_request() {
    $callback_data = [];

    foreach ($_GET as $key => $value) {
        $sanitized_key = sanitize_key(wp_unslash($key));

        if ($sanitized_key === '' || in_array($sanitized_key, ['action', 'oid'], true)) {
            continue;
        }

        $callback_data[$sanitized_key] = is_scalar($value)
            ? sanitize_text_field(wp_unslash($value))
            : '';
    }

    return (object) $callback_data;
}

function zibal_pmpro_get_stored_requested_amount($morder) {
    if (empty($morder->id)) {
        return 0;
    }

    return absint(zibal_pmpro_get_order_meta($morder->id, 'zibal_requested_amount'));
}

function zibal_pmpro_callback_token_hash($token) {
    return hash_hmac('sha256', (string) $token, wp_salt('auth'));
}

function zibal_pmpro_generate_callback_token() {
    return wp_generate_password(40, false, false);
}

function zibal_pmpro_store_request_context($morder, $amount, $merchant, $callback_token, $currency = null) {
    $currency = zibal_pmpro_normalize_currency($currency);

    zibal_pmpro_update_order_meta($morder->id, 'zibal_requested_amount', (int) $amount);
    zibal_pmpro_update_order_meta($morder->id, 'zibal_merchant', sanitize_text_field($merchant));
    zibal_pmpro_update_order_meta($morder->id, 'zibal_currency', $currency);
    zibal_pmpro_update_order_meta($morder->id, 'zibal_callback_token_hash', zibal_pmpro_callback_token_hash($callback_token));
    zibal_pmpro_update_order_meta($morder->id, 'zibal_payment_state', 'requesting');
}

function zibal_pmpro_get_stored_currency($morder) {
    if (!is_object($morder) || empty($morder->id)) {
        return '';
    }

    return zibal_pmpro_normalize_currency(zibal_pmpro_get_order_meta($morder->id, 'zibal_currency'));
}

function zibal_pmpro_validate_callback_binding($morder, $track_id, $callback_token) {
    if (!is_object($morder) || empty($morder->id) || !isset($morder->gateway) || $morder->gateway !== 'zibal') {
        return new WP_Error('zibal_pmpro_invalid_gateway_order', 'سفارش انتخاب‌شده متعلق به درگاه زیبال نیست.');
    }

    $stored_track_id = (string) zibal_pmpro_get_order_meta($morder->id, 'zibal_track_id');
    $stored_token_hash = (string) zibal_pmpro_get_order_meta($morder->id, 'zibal_callback_token_hash');
    $stored_amount = zibal_pmpro_get_stored_requested_amount($morder);

    if ($stored_track_id === '' || $stored_token_hash === '' || $stored_amount <= 0) {
        return new WP_Error('zibal_pmpro_missing_payment_context', 'اطلاعات اتصال تراکنش به سفارش کامل نیست و سفارش باید توسط مدیر بررسی شود.');
    }

    if ($track_id === '' || !hash_equals($stored_track_id, (string) $track_id)) {
        return new WP_Error('zibal_pmpro_track_id_mismatch', 'شناسه تراکنش با سفارش مطابقت ندارد.');
    }

    $received_token_hash = zibal_pmpro_callback_token_hash($callback_token);
    if ($callback_token === '' || !hash_equals($stored_token_hash, $received_token_hash)) {
        return new WP_Error('zibal_pmpro_callback_token_mismatch', 'شناسه امنیتی بازگشت پرداخت معتبر نیست.');
    }

    return true;
}

/**
 * Acquire an atomic database-backed lock for a payment callback.
 *
 * MySQL advisory locks are connection-scoped and are automatically released
 * if PHP terminates unexpectedly. The site namespace prevents lock collisions
 * between WordPress installations that share the same database server.
 *
 * @return array|false Lock details, or false when the lock is unavailable.
 */
function zibal_pmpro_acquire_callback_lock($order_id, $track_id) {
    global $wpdb;

    if (!is_object($wpdb) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_var')) {
        return false;
    }

    $database_name = isset($wpdb->dbname) ? (string) $wpdb->dbname : '';
    $table_prefix = isset($wpdb->prefix) ? (string) $wpdb->prefix : '';
    $namespace = substr(md5($database_name . '|' . $table_prefix), 0, 12);
    $lock_name = 'zibal_pmpro_' . $namespace . '_' . substr(md5(absint($order_id) . '|' . (string) $track_id), 0, 32);
    $got_lock = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock_name));

    return (string) $got_lock === '1' ? ['name' => $lock_name] : false;
}

function zibal_pmpro_release_callback_lock($lock) {
    global $wpdb;

    if (!is_array($lock) || empty($lock['name']) || !is_object($wpdb) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_var')) {
        return;
    }

    $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', (string) $lock['name']));
}

function zibal_pmpro_cancel_order($morder, $message) {
    $morder->status = 'error';
    $morder->notes = sanitize_text_field($message);
    $morder->saveOrder();
}

function zibal_pmpro_exit_with_message($message, $status_code = 400) {
    wp_die(esc_html($message), esc_html__('Zibal payment error', 'zibal-paid-memberships-pro'), ['response' => absint($status_code)]);
}

function zibal_pmpro_cancel_order_for_review($morder, $message = 'پرداخت زیبال نیازمند بررسی دستی مدیر است.') {
    $existing_notes = !empty($morder->notes) ? sanitize_textarea_field((string) $morder->notes) : '';
    $morder->status = 'review';
    $morder->notes = sanitize_text_field($message);

    if ($existing_notes !== '') {
        $morder->notes .= "\n\n" . $existing_notes;
    }

    $morder->saveOrder();
}

function zibal_pmpro_show_customer_payment_result($morder = null, $status_code = 400, $failure_reason = '') {
    if (function_exists('status_header')) {
        status_header(absint($status_code));
    }

    if (function_exists('nocache_headers')) {
        nocache_headers();
    }

    $order_code = !empty($morder->code) ? $morder->code : '';
    $level_id = !empty($morder->membership_level->id) ? absint($morder->membership_level->id) : 0;
    $retry_url = $level_id ? pmpro_url('checkout', '?level=' . $level_id) : pmpro_url('levels');
    $account_url = pmpro_url('account');
    $failure_reason = sanitize_text_field((string) $failure_reason);

    if ($failure_reason === '') {
        $failure_reason = 'سفارش شما پرداخت موفق دریافت نکرد. اگر مبلغی از حساب شما کم شده باشد، معمولاً طبق روال بانکی برگشت داده می‌شود. برای جزئیات بیشتر می‌توانید با پشتیبانی سایت تماس بگیرید.';
    }
    ?>
    <!doctype html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo('charset'); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?php echo esc_html('پرداخت ناموفق'); ?></title>
        <?php if (function_exists('wp_head')) { wp_head(); } ?>
        <style>
            body.zibal-pmpro-payment-result {
                margin: 0;
                min-height: 100vh;
                background: #f6f7f7;
                color: #1d2327;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            }
            .zibal-pmpro-result-wrap {
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 32px 16px;
                box-sizing: border-box;
            }
            .zibal-pmpro-result-card {
                width: 100%;
                max-width: 560px;
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 10px;
                box-shadow: 0 12px 32px rgba(0,0,0,.08);
                overflow: hidden;
                direction: rtl;
                text-align: right;
            }
            .zibal-pmpro-result-head {
                padding: 22px 24px;
                border-bottom: 1px solid #f0f0f1;
                display: flex;
                align-items: center;
                gap: 14px;
            }
            .zibal-pmpro-result-icon {
                width: 44px;
                height: 44px;
                border-radius: 50%;
                background: #fef2f2;
                color: #b91c1c;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                font-size: 26px;
                font-weight: 700;
                flex: 0 0 auto;
            }
            .zibal-pmpro-result-title {
                margin: 0;
                font-size: 20px;
                line-height: 1.5;
                font-weight: 700;
            }
            .zibal-pmpro-result-body {
                padding: 22px 24px 24px;
            }
            .zibal-pmpro-result-message {
                margin: 0 0 18px;
                color: #50575e;
                font-size: 15px;
                line-height: 1.9;
            }
            .zibal-pmpro-result-summary {
                margin: 0 0 22px;
                padding: 0;
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                overflow: hidden;
            }
            .zibal-pmpro-result-row {
                display: flex;
                justify-content: space-between;
                gap: 16px;
                padding: 12px 14px;
                border-bottom: 1px solid #eef0f2;
                font-size: 14px;
            }
            .zibal-pmpro-result-row:last-child {
                border-bottom: 0;
            }
            .zibal-pmpro-result-label {
                color: #646970;
            }
            .zibal-pmpro-result-value {
                color: #1d2327;
                font-weight: 600;
                direction: ltr;
            }
            .zibal-pmpro-result-actions {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
            }
            .zibal-pmpro-result-button {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-height: 40px;
                padding: 0 16px;
                border-radius: 6px;
                text-decoration: none;
                font-size: 14px;
                font-weight: 600;
                border: 1px solid #2271b1;
            }
            .zibal-pmpro-result-button.primary {
                background: #2271b1;
                color: #fff;
            }
            .zibal-pmpro-result-button.secondary {
                background: #fff;
                color: #2271b1;
            }
            @media (max-width: 520px) {
                .zibal-pmpro-result-head,
                .zibal-pmpro-result-body {
                    padding-left: 18px;
                    padding-right: 18px;
                }
                .zibal-pmpro-result-actions {
                    flex-direction: column;
                }
                .zibal-pmpro-result-button {
                    width: 100%;
                    box-sizing: border-box;
                }
            }
        </style>
    </head>
    <body class="zibal-pmpro-payment-result">
        <main class="zibal-pmpro-result-wrap">
            <section class="zibal-pmpro-result-card" aria-labelledby="zibal-payment-result-title">
                <header class="zibal-pmpro-result-head">
                    <span class="zibal-pmpro-result-icon" aria-hidden="true">!</span>
                    <h1 id="zibal-payment-result-title" class="zibal-pmpro-result-title"><?php echo esc_html('پرداخت انجام نشد'); ?></h1>
                </header>
                <div class="zibal-pmpro-result-body">
                    <p class="zibal-pmpro-result-message">
                        <?php echo esc_html($failure_reason); ?>
                    </p>
                    <dl class="zibal-pmpro-result-summary">
                        <?php if ($order_code !== '') : ?>
                            <div class="zibal-pmpro-result-row">
                                <dt class="zibal-pmpro-result-label"><?php echo esc_html('شماره سفارش'); ?></dt>
                                <dd class="zibal-pmpro-result-value"><?php echo esc_html($order_code); ?></dd>
                            </div>
                        <?php endif; ?>
                        <div class="zibal-pmpro-result-row">
                            <dt class="zibal-pmpro-result-label"><?php echo esc_html('وضعیت'); ?></dt>
                            <dd class="zibal-pmpro-result-value"><?php echo esc_html('ناموفق / لغو شده'); ?></dd>
                        </div>
                    </dl>
                    <div class="zibal-pmpro-result-actions">
                        <a class="zibal-pmpro-result-button primary" href="<?php echo esc_url($retry_url); ?>"><?php echo esc_html('تلاش دوباره'); ?></a>
                        <a class="zibal-pmpro-result-button secondary" href="<?php echo esc_url($account_url); ?>"><?php echo esc_html('رفتن به حساب کاربری'); ?></a>
                    </div>
                </div>
            </section>
        </main>
        <?php if (function_exists('wp_footer')) { wp_footer(); } ?>
    </body>
    </html>
    <?php
    exit;
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
        'شماره تراکنش بانکی (refNumber)' => zibal_pmpro_get_order_meta($order->id, 'zibal_transaction_number'),
        'شناسه پیگیری زیبال (trackId)' => zibal_pmpro_get_order_meta($order->id, 'zibal_track_id'),
        'تاریخ ثبت سفارش' => zibal_pmpro_get_order_meta($order->id, 'zibal_order_date'),
        'زمان دقیق رویداد پرداخت' => zibal_pmpro_get_order_meta($order->id, 'zibal_payment_time'),
        'مبلغ تراکنش در زیبال (ریال)' => zibal_pmpro_get_order_meta($order->id, 'zibal_paid_amount'),
        'شماره کارت' => zibal_pmpro_get_order_meta($order->id, 'zibal_card_number'),
        'پرداخت موفق' => zibal_pmpro_get_order_meta($order->id, 'zibal_payment_successful'),
        'کد وضعیت/نتیجه زیبال' => zibal_pmpro_get_order_meta($order->id, 'zibal_zibal_status_code'),
        'متن زیبال' => zibal_pmpro_get_order_meta($order->id, 'zibal_zibal_message'),
    ];

    if (implode('', array_map('strval', $report)) === '') {
        return;
    }

    $successful = zibal_pmpro_get_order_meta($order->id, 'zibal_payment_successful');
    $status_color = $successful === 'بله' ? '#047857' : '#b91c1c';
    $status_bg = $successful === 'بله' ? '#ecfdf5' : '#fef2f2';
    ?>
    <div class="zibal-pmpro-order-report" style="margin:18px 0;border:1px solid #dcdcde;border-radius:8px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.04);overflow:hidden;">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;background:#f6f7f7;border-bottom:1px solid #dcdcde;">
            <h2 style="margin:0;font-size:15px;font-weight:600;"><?php echo esc_html('Payment Gateway Information'); ?></h2>
            <span style="display:inline-flex;align-items:center;border-radius:999px;padding:4px 10px;background:<?php echo esc_attr($status_bg); ?>;color:<?php echo esc_attr($status_color); ?>;font-size:12px;font-weight:600;">
                <?php echo esc_html($successful === 'بله' ? 'Successful payment' : 'Needs review'); ?>
            </span>
        </div>
        <div style="padding:14px 16px;">
            <table class="widefat striped" style="border:1px solid #e5e7eb;border-radius:6px;overflow:hidden;">
                <tbody>
                    <?php foreach ($report as $label => $value) : ?>
                        <tr>
                            <th scope="row" style="width:190px;padding:12px;font-weight:600;color:#1d2327;"><?php echo esc_html($label); ?></th>
                            <td style="padding:12px;color:#2c3338;white-space:pre-wrap;"><?php echo esc_html($value !== '' ? $value : '-'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
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
                    add_filter('pmpro_checkout_checks', ['PMProGateway_Zibal', 'pmpro_checkout_checks']);
                    add_filter('pmpro_include_billing_address_fields', '__return_false');
                    add_filter('pmpro_include_payment_information_fields', '__return_false');
                    add_filter('pmpro_required_billing_fields', ['PMProGateway_Zibal', 'pmpro_required_billing_fields']);
                }

                add_action('wp_ajax_nopriv_zibal-ins', ['PMProGateway_Zibal', 'pmpro_wp_ajax_zibal_ins']);
                add_action('wp_ajax_zibal-ins', ['PMProGateway_Zibal', 'pmpro_wp_ajax_zibal_ins']);
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
             * Zibal is a one-time payment gateway in this integration.
             *
             * @since 1.4
             */
            public static function supports($feature)
            {
                return false;
            }

            /**
             * Stop recurring levels before PMPro creates the checkout order.
             *
             * @since 1.4
             */
            public static function pmpro_checkout_checks($continue)
            {
                global $pmpro_level;

                if (!$continue) {
                    return false;
                }

                $currency_validation = zibal_pmpro_validate_currency();

                if (is_wp_error($currency_validation)) {
                    if (function_exists('pmpro_setMessage')) {
                        pmpro_setMessage($currency_validation->get_error_message(), 'pmpro_error');
                    }

                    return false;
                }

                $validation = zibal_pmpro_validate_one_time_level($pmpro_level);

                if (is_wp_error($validation)) {
                    if (function_exists('pmpro_setMessage')) {
                        pmpro_setMessage($validation->get_error_message(), 'pmpro_error');
                    }

                    return false;
                }

                return true;
            }

            /**
             * Never let PMPro's base gateway simulate a Zibal subscription.
             *
             * @since 1.4
             */
            public function subscribe(&$order)
            {
                $order->errorcode = 'zibal_pmpro_recurring_not_supported';
                $order->error = 'درگاه زیبال از پرداخت دوره‌ای پشتیبانی نمی‌کند.';

                return false;
            }

            public function update(&$order)
            {
                return false;
            }

            public function cancel(&$order)
            {
                return false;
            }

            public function cancel_subscription($subscription)
            {
                return false;
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
                    <p class="description">
                        <?php echo esc_html('این اتصال زیبال فقط پرداخت یک‌باره را پشتیبانی می‌کند. تسویه‌حساب سطح‌های دارای پرداخت دوره‌ای متوقف می‌شود و هیچ subscription ساختگی در PMPro ثبت نخواهد شد.'); ?>
                    </p>
                </td>
                </tr>

                <?php

            }

            /**
             * Save the asynchronous checkout and send the customer to Zibal.
             *
             * @since 1.5
             */
            public function process(&$morder)
            {
                global $pmpro_currency;

                //if no order, no need to pay
                if (empty($morder)) {
                    return false;
                }

                if (empty($morder->code) && method_exists($morder, 'getRandomCode')) {
                    $morder->code = $morder->getRandomCode();
                }

                $morder->status = 'token';
                $morder->saveOrder();

                if (empty($morder->id) || empty($morder->code)) {
                    $morder->error = 'سفارش پرداخت در Paid Memberships Pro ذخیره نشد. لطفاً دوباره تلاش کنید.';
                    $morder->errorcode = 'zibal_pmpro_order_not_saved';
                    return false;
                }

                $level_validation = zibal_pmpro_validate_one_time_order($morder);

                if (is_wp_error($level_validation)) {
                    $level_error = (object) [
                        'message' => $level_validation->get_error_message(),
                    ];
                    zibal_pmpro_cancel_order($morder, 'Zibal recurring membership level rejected.');
                    zibal_pmpro_record_order_report($morder, $level_error, false);
                    $morder->error = $level_validation->get_error_message();
                    $morder->errorcode = $level_validation->get_error_code();
                    return false;
                }

                $currency = zibal_pmpro_normalize_currency($pmpro_currency);
                $amount = zibal_pmpro_expected_amount($morder, $currency);

                if (is_wp_error($amount)) {
                    $amount_error = (object) [
                        'message' => $amount->get_error_message(),
                    ];
                    zibal_pmpro_cancel_order($morder, 'Zibal amount validation failed: ' . $amount->get_error_code());
                    zibal_pmpro_record_order_report($morder, $amount_error, false);
                    $morder->error = $amount->get_error_message();
                    $morder->errorcode = $amount->get_error_code();
                    return false;
                }

                if (function_exists('pmpro_save_checkout_data_to_order')) {
                    pmpro_save_checkout_data_to_order($morder);
                }

                $order_id = $morder->code;
                $callback_token = zibal_pmpro_generate_callback_token();
                $merchant = zibal_pmpro_get_merchant();

                if ($merchant === '') {
                    zibal_pmpro_cancel_order($morder, 'Zibal merchant is empty in live mode.');
                    $morder->error = 'کد مرچنت زیبال در تنظیمات پرداخت وارد نشده است.';
                    $morder->errorcode = 'zibal_pmpro_missing_merchant';
                    return false;
                }

                zibal_pmpro_store_request_context($morder, $amount, $merchant, $callback_token, $currency);

                $redirect = add_query_arg(
                    [
                        'action' => 'zibal-ins',
                        'oid'    => $order_id,
                        'ztoken' => $callback_token,
                    ],
                    admin_url('admin-ajax.php')
                );

                $data = [
                    'merchant' => $merchant,
                    'amount' => $amount,
                    'orderId' => $order_id,
                    'callbackUrl' => $redirect,
                ];
                
                $result = zibal_pmpro_remote_post('v1/request', $data);

                if ($result && isset($result->result, $result->trackId) && intval($result->result) === 100 && preg_match('/^\d{1,64}$/', (string) $result->trackId)) {
                    $track_id = sanitize_text_field((string) $result->trackId);
                    zibal_pmpro_store_pending_order($morder, $track_id, $amount);
                    $go = 'https://gateway.zibal.ir/start/' . rawurlencode($track_id);
                    wp_redirect(esc_url_raw($go));
                    exit;

                } else {
                    zibal_pmpro_update_order_meta($morder->id, 'zibal_payment_state', 'error');
                    zibal_pmpro_cancel_order($morder, 'Zibal payment request failed. See Payment Gateway Information.');
                    zibal_pmpro_record_order_report($morder, $result, false);
                    $morder->error = 'ارتباط با درگاه زیبال برقرار نشد. لطفاً دوباره تلاش کنید.';
                    $morder->errorcode = 'zibal_pmpro_request_failed';
                    return false;
                }
            }

            /**
             * Legacy entry point retained for custom code that called this method.
             */
            public static function pmpro_checkout_before_change_membership_level($user_id, $morder)
            {
                if (empty($morder)) {
                    return false;
                }

                $morder->user_id = $user_id;
                $gateway = new self('zibal');
                return $gateway->process($morder);
            }

            public static function pmpro_wp_ajax_zibal_ins()
            {
                $oid = isset($_GET['oid']) && is_scalar($_GET['oid']) ? sanitize_text_field(wp_unslash($_GET['oid'])) : '';
                $track_id = isset($_GET['trackId']) && is_scalar($_GET['trackId']) ? sanitize_text_field(wp_unslash($_GET['trackId'])) : '';
                $callback_token = isset($_GET['ztoken']) && is_scalar($_GET['ztoken']) ? sanitize_text_field(wp_unslash($_GET['ztoken'])) : '';
                $status = isset($_GET['status']) && is_scalar($_GET['status'])
                    ? intval(sanitize_text_field(wp_unslash($_GET['status'])))
                    : 0;

                if ($oid === '' || !preg_match('/^[A-Za-z0-9]{1,64}$/', $oid)) {
                    zibal_pmpro_exit_with_message('شماره سفارش بازگشت پرداخت معتبر نیست.');
                }

                $morder = null;

                try {
                    $morder = new MemberOrder($oid);
                    $morder->getMembershipLevel();
                } catch (Throwable $exception) {
                    zibal_pmpro_exit_with_message('شماره سفارش بازگشت پرداخت معتبر نیست.');
                }

                if (empty($morder->id)) {
                    zibal_pmpro_exit_with_message('سفارش پرداخت پیدا نشد.', 404);
                }

                $binding = zibal_pmpro_validate_callback_binding($morder, $track_id, $callback_token);
                if (is_wp_error($binding)) {
                    zibal_pmpro_exit_with_message($binding->get_error_message(), 403);
                }

                if ($morder->status === 'success' && hash_equals((string) $morder->payment_transaction_id, $track_id)) {
                    wp_safe_redirect(pmpro_url("confirmation", "?level=" . absint($morder->membership_level->id)));
                    exit;
                }

                if ($status !== 2) {
                    $callback_response = zibal_pmpro_callback_response_from_request();
                    $failure_reason = zibal_pmpro_status_to_text($status);
                    if (in_array($morder->status, ['token', 'pending'], true)) {
                        zibal_pmpro_update_order_meta($morder->id, 'zibal_payment_state', 'cancelled');
                        zibal_pmpro_cancel_order($morder, 'Zibal payment was not completed by the customer.');
                    }
                    zibal_pmpro_record_order_report($morder, $callback_response, false);
                    zibal_pmpro_show_customer_payment_result($morder, 400, $failure_reason);
                }

                if (!in_array($morder->status, ['token', 'pending'], true)) {
                    zibal_pmpro_exit_with_message('این سفارش در وضعیت قابل پردازش قرار ندارد.', 409);
                }

                $lock = zibal_pmpro_acquire_callback_lock($morder->id, $track_id);
                if ($lock === false) {
                    $fresh_order = new MemberOrder($oid);
                    $fresh_order->getMembershipLevel();
                    if ($fresh_order->status === 'success' && hash_equals((string) $fresh_order->payment_transaction_id, $track_id)) {
                        wp_safe_redirect(pmpro_url("confirmation", "?level=" . absint($fresh_order->membership_level->id)));
                        exit;
                    }

                    zibal_pmpro_exit_with_message('پرداخت در حال بررسی است. چند لحظه دیگر صفحه را تازه کنید.', 409);
                }

                try {
                    $outcome = self::process_locked_callback($oid, $track_id, $callback_token);
                } finally {
                    zibal_pmpro_release_callback_lock($lock);
                }

                if ($outcome['action'] === 'redirect') {
                    wp_safe_redirect($outcome['url']);
                    exit;
                }

                if ($outcome['action'] === 'show_failure') {
                    zibal_pmpro_show_customer_payment_result(
                        $outcome['order'],
                        $outcome['status'],
                        isset($outcome['message']) ? $outcome['message'] : ''
                    );
                }

                zibal_pmpro_exit_with_message($outcome['message'], $outcome['status']);
            }

            private static function process_locked_callback($oid, $track_id, $callback_token)
            {
                $morder = new MemberOrder($oid);
                $morder->getMembershipLevel();

                $binding = zibal_pmpro_validate_callback_binding($morder, $track_id, $callback_token);
                if (is_wp_error($binding)) {
                    return self::callback_message_outcome($binding->get_error_message(), 403);
                }

                if ($morder->status === 'success' && hash_equals((string) $morder->payment_transaction_id, $track_id)) {
                    return self::callback_redirect_outcome($morder);
                }

                if (!in_array($morder->status, ['token', 'pending'], true)) {
                    return self::callback_message_outcome('این سفارش قبلاً پردازش شده یا در وضعیت قابل پرداخت نیست.', 409);
                }

                $stored_currency = zibal_pmpro_get_stored_currency($morder);
                $amount = zibal_pmpro_expected_amount($morder, $stored_currency);
                $stored_amount = zibal_pmpro_get_stored_requested_amount($morder);

                if (is_wp_error($amount) || $stored_amount <= 0 || $stored_amount !== $amount) {
                    zibal_pmpro_update_order_meta($morder->id, 'zibal_payment_state', 'review');
                    zibal_pmpro_cancel_order_for_review($morder, 'مبلغ ذخیره‌شده زیبال با مبلغ محاسبه‌شده سفارش مطابقت ندارد.');
                    return self::callback_message_outcome('مبلغ ذخیره‌شده تراکنش با سفارش مطابقت ندارد و سفارش باید توسط مدیر بررسی شود.', 409);
                }

                $merchant = sanitize_text_field(zibal_pmpro_get_order_meta($morder->id, 'zibal_merchant'));
                if ($merchant === '') {
                    zibal_pmpro_update_order_meta($morder->id, 'zibal_payment_state', 'review');
                    zibal_pmpro_cancel_order_for_review($morder, 'اطلاعات مرچنت در سابقه پرداخت زیبال موجود نیست.');
                    return self::callback_message_outcome('اطلاعات مرچنت این تراکنش کامل نیست و سفارش باید توسط مدیر بررسی شود.', 409);
                }

                $result = zibal_pmpro_remote_post('v1/verify', [
                    'merchant' => $merchant,
                    'trackId' => $track_id,
                ]);

                if ($result && isset($result->result) && intval($result->result) === 201) {
                    zibal_pmpro_update_order_meta($morder->id, 'zibal_payment_state', 'review');
                    zibal_pmpro_cancel_order_for_review($morder, 'تراکنش در زیبال قبلاً تأیید شده، اما سفارش محلی هنوز موفق نشده است.');
                    zibal_pmpro_record_order_report($morder, $result, true);
                    return self::callback_message_outcome('این تراکنش قبلاً در زیبال تأیید شده، اما سفارش محلی کامل نشده است و باید توسط مدیر بررسی شود.', 409);
                }

                if (!$result || !isset($result->result, $result->amount) || intval($result->result) !== 100 || !zibal_pmpro_amount_matches($result->amount, $stored_amount)) {
                    zibal_pmpro_update_order_meta($morder->id, 'zibal_payment_state', 'review');
                    zibal_pmpro_cancel_order_for_review($morder, 'تأیید تراکنش در زیبال ناموفق بود یا مبلغ پاسخ با سفارش مطابقت نداشت.');
                    zibal_pmpro_record_order_report($morder, $result, false);
                    return [
                        'action' => 'show_failure',
                        'order' => $morder,
                        'message' => 'تأیید پرداخت در زیبال ناموفق بود. دلیل دقیق در اطلاعات سفارش ثبت شد.',
                        'status' => 400,
                    ];
                }

                $morder->payment_transaction_id = $track_id;
                $morder->subscription_transaction_id = '';
                zibal_pmpro_update_order_meta($morder->id, 'zibal_payment_state', 'verified');
                zibal_pmpro_record_order_report($morder, $result, true);

                try {
                    if (function_exists('pmpro_pull_checkout_data_from_order') && function_exists('pmpro_complete_async_checkout')) {
                        pmpro_pull_checkout_data_from_order($morder);
                        $level_validation = zibal_pmpro_validate_one_time_order($morder);
                        $completed = !is_wp_error($level_validation) && pmpro_complete_async_checkout($morder);
                    } else {
                        $completed = false;
                    }
                } catch (Throwable $exception) {
                    $completed = false;
                }

                if (!$completed) {
                    $fresh_order = new MemberOrder($oid);
                    $fresh_order->getMembershipLevel();
                    if ($fresh_order->status === 'success' && hash_equals((string) $fresh_order->payment_transaction_id, $track_id)) {
                        $completed = true;
                        $morder = $fresh_order;
                    }
                }

                if (!$completed) {
                    zibal_pmpro_update_order_meta($morder->id, 'zibal_payment_state', 'review');
                    zibal_pmpro_cancel_order_for_review($morder, 'پرداخت در زیبال تأیید شد، اما PMPro نتوانست عضویت را تکمیل کند.');
                    return self::callback_message_outcome('پرداخت تأیید شد، اما تکمیل عضویت با خطا مواجه شد و سفارش باید توسط مدیر بررسی شود.', 500);
                }

                zibal_pmpro_update_order_meta($morder->id, 'zibal_payment_state', 'completed');
                $completed_order = new MemberOrder($oid);
                $completed_order->getMembershipLevel();
                zibal_pmpro_record_order_report($completed_order, $result, true);
                return self::callback_redirect_outcome($completed_order);
            }

            private static function callback_redirect_outcome($morder)
            {
                return [
                    'action' => 'redirect',
                    'url' => pmpro_url("confirmation", "?level=" . absint($morder->membership_level->id)),
                    'order' => $morder,
                    'message' => '',
                    'status' => 200,
                ];
            }

            private static function callback_message_outcome($message, $status)
            {
                return [
                    'action' => 'message',
                    'url' => '',
                    'order' => null,
                    'message' => $message,
                    'status' => absint($status),
                ];
            }

        }

        PMProGateway_Zibal::init();
    }
}
