<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Redirects user to the payment page
 *
 * @package   paygw_bepaid
 * @copyright 2024 Alex Orlov <snickser@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core_payment\helper;
use paygw_bepaid\notifications;

require_once(__DIR__ . '/../../../config.php');
global $CFG, $USER, $DB;
require_once($CFG->libdir . '/filelib.php');

require_login();
require_sesskey();

$userid = $USER->id;

$component   = required_param('component', PARAM_COMPONENT);
$paymentarea = required_param('paymentarea', PARAM_AREA);
$itemid      = required_param('itemid', PARAM_INT);
$description = required_param('description', PARAM_TEXT);

$password    = optional_param('password', null, PARAM_TEXT);
$skipmode    = optional_param('skipmode', 0, PARAM_INT);
$costself    = optional_param('costself', null, PARAM_TEXT);

$config = (object) helper::get_gateway_configuration($component, $paymentarea, $itemid, 'bepaid');
$payable = helper::get_payable($component, $paymentarea, $itemid);// Get currency and payment amount.
$currency = $payable->get_currency();
if ($currency == 'BYR') {
    $currency = 'BYN';
}
$surcharge = helper::get_gateway_surcharge('bepaid');// In case user uses surcharge.
// TODO: Check if currency is IDR. If not, then something went really wrong in config.
$cost = helper::get_rounded_cost($payable->get_amount(), $payable->get_currency(), $surcharge);

// Check self cost if not fixcost.
if (!empty($costself) && !$config->fixcost) {
    $cost = $costself;
}

// Check maxcost.
if ($config->maxcost && $cost > $config->maxcost) {
    $cost = $config->maxcost;
}

$cost = number_format($cost, 2, '.', '');

// Get course and groups for user.
if ($component == "enrol_yafee" || $component == "enrol_fee") {
    $cs = $DB->get_record('enrol', ['id' => $itemid]);
    $cs->course = $cs->courseid;
} else if ($component == "mod_gwpayments") {
    $cs = $DB->get_record('gwpayments', ['id' => $itemid]);
} else if ($paymentarea == "cmfee") {
    $cs = $DB->get_record('course_modules', ['id' => $itemid]);
} else if ($paymentarea == "sectionfee") {
    $cs = $DB->get_record('course_sections', ['id' => $itemid]);
}
$groupnames = '';
if (!empty($cs->course)) {
    $courseid = $cs->course;
    if ($gs = groups_get_user_groups($courseid, $userid, true)) {
        foreach ($gs as $gr) {
            foreach ($gr as $g) {
                $groups[] = groups_get_group_name($g);
            }
        }
        if (isset($groups)) {
            $groupnames = implode(',', $groups);
        }
    }
} else {
    $courseid = '';
}

// Write tx to DB.
$paygwdata = new stdClass();
$paygwdata->courseid = $courseid;
$paygwdata->groupnames = $groupnames;
$paygwdata->timecreated = time();
if (!$transactionid = $DB->insert_record('paygw_bepaid', $paygwdata)) {
    throw new \moodle_exception(get_string('error_txdatabase', 'paygw_bepaid'), 'paygw_bepaid');
}
$paygwdata->id = $transactionid;

// Build redirect.
$url = helper::get_success_url($component, $paymentarea, $itemid);

// Set the context of the page.
$PAGE->set_url($SCRIPT);
$PAGE->set_context(context_system::instance());

// Check passwordmode or skipmode.
if (!empty($password) || $skipmode) {
    $success = false;
    if ($config->skipmode) {
        $success = true;
    } else if (isset($cs->password) && !empty($cs->password)) {
        // Check module password.
        if ($password === $cs->password) {
            $success = true;
        }
    } else if ($config->passwordmode && !empty($config->password)) {
        // Check payment password.
        if ($password === $config->password) {
            $success = true;
        }
    }

    if ($success) {
        // Make fake pay.
        $paymentid = helper::save_payment(
            $payable->get_account_id(),
            $component,
            $paymentarea,
            $itemid,
            $userid,
            0,
            $payable->get_currency(),
            'bepaid'
        );
        helper::deliver_order($component, $paymentarea, $itemid, $paymentid, $userid);

        // Write to DB.
        $paygwdata->success = 2;
        $paygwdata->paymentid = $paymentid;
        $DB->update_record('paygw_bepaid', $paygwdata);

        redirect($url, get_string('password_success', 'paygw_bepaid'), 0, 'success');
    } else {
        redirect($url, get_string('password_error', 'paygw_bepaid'), 0, 'error');
    }
    die; // Never.
}

// Save payment.
$paymentid = helper::save_payment(
    $payable->get_account_id(),
    $component,
    $paymentarea,
    $itemid,
    $userid,
    $cost,
    $currency,
    'bepaid'
);

// Make invoice.
$payment = new stdClass();
$payment->checkout = [
    "transaction_type" => 'payment',
    "test" => true,
    "order" => [
        "amount" => $cost * 100,
        "currency" => $currency,
        "description" => $description,
        "tracking_id" => $paymentid,
        "expired_at" => date("Y-m-d\\TH:i:sP", time() + 1800),
    ],
    "settings" => [
        "return_url" => $CFG->wwwroot . '/payment/gateway/bepaid/return.php?ID=' . $paymentid,
        "fail_url" => $CFG->wwwroot . '/payment/gateway/bepaid/return.php?ID=' . $paymentid,
        "cancel_url" => $CFG->wwwroot . '/payment/gateway/bepaid/return.php?ID=' . $paymentid,
        "notification_url" => $CFG->wwwroot . '/payment/gateway/bepaid/callback.php',
        "auto_return" => 30,
        "language" => current_language(),
        "customer_fields" => [
            "visible" => ['email'],
            "read_only" => ['email'],
        ],
    ],
    "customer" => [
    "email" => $USER->email,
    ],
];

if ($config->recurrent == 1 && ($config->recurrentperiod > 0 || $config->recurrentday > 0)) {
    $payment->checkout['order']['additional_data'] = [ "contract" => ['recurring'] ];
    $payment->checkout['settings']['save_card_toggle'] = [ "display" => true ];
    $payment->checkout['settings']['customer_contract'] = true;
    $payment->checkout['payment_method'] = [ 'types' => ['credit_card'] ];
}

$jsondata = json_encode($payment);

file_put_contents("/tmp/aaaa", $jsondata . "\n\n", FILE_APPEND);

// Make payment.
$location = 'https://checkout.bepaid.by/ctp/api/checkouts';
$options = [
    'CURLOPT_RETURNTRANSFER' => true,
    'CURLOPT_TIMEOUT' => 30,
    'CURLOPT_HTTP_VERSION' => CURL_HTTP_VERSION_1_1,
    'CURLOPT_SSLVERSION' => CURL_SSLVERSION_TLSv1_2,
    'CURLOPT_HTTPHEADER' => [
        'RequestID: ' . uniqid($paymentid, true),
        'Content-Type: application/json',
        'Accept: application/json',
        'X-API-Version: 2',
    ],
    'CURLOPT_HTTPAUTH' => CURLAUTH_BASIC,
    'CURLOPT_USERPWD' => $config->shopid . ':' . $config->apikey,
];
$curl = new curl();
$jsonresponse = $curl->post($location, $jsondata, $options);

file_put_contents("/tmp/aaaa", serialize($jsonresponse) . "\n\n", FILE_APPEND);

$response = json_decode($jsonresponse);

if (!isset($response->checkout)) {
    $DB->delete_records('paygw_bepaid', ['id' => $transactionid]);
    $error = $response->description;
    throw new \moodle_exception(get_string('payment_error', 'paygw_bepaid') . " ($error)", 'paygw_bepaid');
}

$confirmationurl = $response->checkout->redirect_url;

if (empty($confirmationurl)) {
    $DB->delete_records('paygw_bepaid', ['id' => $transactionid]);
    $error = $response;
    throw new \moodle_exception(get_string('payment_error', 'paygw_bepaid') . " ($error)", 'paygw_bepaid');
}

// Notify user.
if ($config->sendlinkmsg || is_siteadmin()) {
    notifications::notify(
        $userid,
        $cost,
        $currency,
        $confirmationurl,
        'Invoice created'
    );
}

// Write to DB.
$paygwdata->paymentid = $paymentid;
$paygwdata->invoiceid = $response->checkout->token;
$DB->update_record('paygw_bepaid', $paygwdata);

redirect($confirmationurl);
