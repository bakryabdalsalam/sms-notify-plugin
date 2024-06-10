<?php
/*
Plugin Name: SMS Notify Plugin
Description: Send SMS to the customer and notify the admin when an order is placed on hold.
Version: 1.0
Author: Bakry Abdalsalam
*/

// Ensure direct access is not allowed
if (!defined('ABSPATH')) {
    exit;
}

// Create the admin menu item
add_action('admin_menu', 'sms_notify_admin_menu');
function sms_notify_admin_menu() {
    add_options_page(
        'SMS Notify Settings',
        'SMS Notify Settings',
        'manage_options',
        'sms-notify-settings',
        'sms_notify_settings_page'
    );
}

// Register settings
add_action('admin_init', 'sms_notify_register_settings');
function sms_notify_register_settings() {
    register_setting('sms_notify_options', 'sms_notify_username');
    register_setting('sms_notify_options', 'sms_notify_apikey');
    register_setting('sms_notify_options', 'sms_notify_usersender');
    register_setting('sms_notify_options', 'sms_notify_order_statuses');
    register_setting('sms_notify_options', 'sms_notify_message');
}

// Create the settings page
function sms_notify_settings_page() {
    ?>
    <div class="wrap">
        <h1>SMS Notify Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('sms_notify_options');
            do_settings_sections('sms_notify_options');
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Username</th>
                    <td><input type="text" name="sms_notify_username" value="<?php echo esc_attr(get_option('sms_notify_username')); ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">API Key</th>
                    <td><input type="text" name="sms_notify_apikey" value="<?php echo esc_attr(get_option('sms_notify_apikey')); ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">User Sender</th>
                    <td><input type="text" name="sms_notify_usersender" value="<?php echo esc_attr(get_option('sms_notify_usersender')); ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Order Statuses</th>
                    <td>
                        <select name="sms_notify_order_statuses[]" multiple style="height: 100px;">
                            <?php
                            $statuses = wc_get_order_statuses();
                            $selected_statuses = get_option('sms_notify_order_statuses', []);
                            foreach ($statuses as $status_key => $status_label) {
                                echo '<option value="' . esc_attr($status_key) . '"' . (in_array($status_key, $selected_statuses) ? ' selected' : '') . '>' . esc_html($status_label) . '</option>';
                            }
                            ?>
                        </select>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Custom Message</th>
                    <td><textarea name="sms_notify_message" rows="5" cols="50"><?php echo esc_textarea(get_option('sms_notify_message', 'عميل لفتة العزيز، تم شحن طلبك رقم {order_id} مع شركة Aramex تتبع الشحنة {track_url} رقم خدمة العملاء 966920033702 شكرا لكم')); ?></textarea></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// Send SMS on selected order statuses
add_action('woocommerce_order_status_changed', 'on_order_status_changed', 10, 4);
function on_order_status_changed($order_id, $old_status, $new_status, $order) {
    $selected_statuses = get_option('sms_notify_order_statuses', []);
    if (in_array('wc-' . $new_status, $selected_statuses)) {
        send_sms_notification($order_id, $new_status);
    }
}

function send_sms_notification($order_id, $new_status) {
    // Retrieve the order details using the order ID
    $order = wc_get_order($order_id);

    if (!$order) {
        error_log('Invalid order ID: ' . $order_id);
        return;
    }

    // Get the customer user ID from the order metadata
    $user_id = get_post_meta($order_id, '_customer_user', true);

    // Get the Aramex tracking number from the order metadata
    $ced_aramex_awno = get_post_meta($order_id, 'ced_aramex_awno', true);

    // Generate the tracking URL for the shipment
    $track_url = "https://www.aramex.com/sa/ar/track/track-results-new?ShipmentNumber=" . $ced_aramex_awno;

    // Create a new WooCommerce customer object using the user ID
    $customer = new WC_Customer($user_id);

    // Retrieve the customer's billing phone number
    $billing_phone = $customer->get_billing_phone();

    // Ensure phone number is in international format
    if (!preg_match('/^\+?\d{10,15}$/', $billing_phone)) {
        $billing_phone = '+' . $billing_phone;
    }

    // Check if the phone number is valid
    if (empty($billing_phone) || !preg_match('/^\+?\d{10,15}$/', $billing_phone)) {
        $error_message = 'Invalid phone number: ' . $billing_phone;
        set_transient('on_hold_sms_error', $error_message, 45);
        error_log($error_message);
        return;
    }

    // Get settings from the admin page
    $username = get_option('sms_notify_username');
    $apikey = get_option('sms_notify_apikey');
    $usersender = get_option('sms_notify_usersender');
    $custom_message = get_option('sms_notify_message');

    // Replace placeholders in the custom message
    $message = str_replace(
        ['{order_id}', '{track_url}', '{new_status}'],
        [$order_id, $track_url, wc_get_order_status_name($new_status)],
        $custom_message
    );

    // Prepare the data to send the SMS
    $url = "https://www.msegat.com/gw/";
    $dataArray = array(
        "userName" => $username,
        "apiKey" => $apikey,
        "userSender" => $usersender,
        "numbers" => $billing_phone,
        "msg" => $message,
        "msgEncoding" => 'UTF8'
    );

    // Initialize a cURL session to send the SMS
    $ch = curl_init();
    $data = http_build_query($dataArray);
    $getUrl = $url . "?" . $data;

    // Set cURL options for the request
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_URL, $getUrl);
    curl_setopt($ch, CURLOPT_TIMEOUT, 80);

    // Execute the cURL request and capture the response
    $response = curl_exec($ch);

    // Check for cURL errors and create an admin notice
    if (curl_error($ch)) {
        $error_message = 'Request Error: ' . curl_error($ch);
        // Store the error message in a transient for use in admin notice
        set_transient('on_hold_sms_error', $error_message, 45);
        error_log($error_message);
    } else {
        // Log the full response
        error_log('SMS API Full Response: ' . print_r($response, true));

        // Interpret the response (assuming '1' is success based on your example)
        if ($response == '1') {
            $success_message = 'SMS sent successfully to ' . $billing_phone;
            // Store the success message in a transient for use in admin notice
            set_transient('on_hold_sms_success', $success_message, 45);
            error_log($success_message);
        } else {
            // Provide a detailed error message for the admin
            $error_message = 'Failed to send SMS. Response code: ' . $response . '. Please refer to the SMS gateway documentation for more details.';
            // Store the error message in a transient for use in admin notice
            set_transient('on_hold_sms_error', $error_message, 45);
            error_log($error_message);
        }
    }

    // Close the cURL session
    curl_close($ch);
}

// Add admin notice for SMS success
add_action('admin_notices', 'on_hold_sms_success_notice');
function on_hold_sms_success_notice() {
    if ($message = get_transient('on_hold_sms_success')) {
        ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo $message; ?></p>
        </div>
        <?php
        delete_transient('on_hold_sms_success');
    }
}

// Add admin notice for SMS error
add_action('admin_notices', 'on_hold_sms_error_notice');
function on_hold_sms_error_notice() {
    if ($message = get_transient('on_hold_sms_error')) {
        ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo $message; ?></p>
        </div>
        <?php
        delete_transient('on_hold_sms_error');
    }
}
