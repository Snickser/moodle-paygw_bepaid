<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Plugin administration pages are defined here.
 *
 * @package     paygw_bepaid
 * @copyright   2024 Alex Orlov <snickser@gmail.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core_payment\helper;
use paygw_bepaid\notifications;

require("../../../config.php");
global $CFG, $USER, $DB;

require_once($CFG->libdir . '/filelib.php');

defined('MOODLE_INTERNAL') || die();

$source = file_get_contents('php://input');
$data = json_decode($source, false);

// Check json.
if ($data === null) {
    $lasterror = json_last_error_msg();
    throw new \moodle_exception('FAIL. Invalid json in request: ' . $lasterror);
}

if ($data->transaction->status == 'pending') {
    die('OK');
}

if ($data->transaction->status !== 'successful') {
    throw new \moodle_exception('FAIL. Payment not successful');
}

// Check data.
if (isset($data->transaction->additional_data->vendor->token)) {
    $invoiceid  = clean_param($data->transaction->additional_data->vendor->token, PARAM_ALPHANUMEXT);
} else {
    throw new \moodle_exception('FAIL. No invoiceid.');
}

if (isset($data->transaction->amount)) {
    $outsumm = clean_param($data->transaction->amount, PARAM_FLOAT) / 100.0;
} else {
    throw new \moodle_exception('FAIL. No amount.');
}

// Get paymentid.
if (!$bepaidtx = $DB->get_record('paygw_bepaid', ['invoiceid' => $invoiceid])) {
    throw new \moodle_exception('FAIL. Not a valid transaction id');
}

// Get payment data.
if (!$payment = $DB->get_record('payments', ['id' => $bepaidtx->paymentid])) {
    throw new \moodle_exception('FAIL. Not a valid payment.');
}

$component   = $payment->component;
$paymentarea = $payment->paymentarea;
$itemid      = $payment->itemid;
$paymentid   = $payment->id;
$userid      = $payment->userid;

// Get config.
$config = (object) helper::get_gateway_configuration($component, $paymentarea, $itemid, 'bepaid');
$payable = helper::get_payable($component, $paymentarea, $itemid);

// Check payment on site.
$location = 'https://checkout.bepaid.by/ctp/api/checkouts/' . $invoiceid;
$options = [
    'CURLOPT_RETURNTRANSFER' => true,
    'CURLOPT_TIMEOUT' => 30,
    'CURLOPT_HTTP_VERSION' => CURL_HTTP_VERSION_1_1,
    'CURLOPT_SSLVERSION' => CURL_SSLVERSION_TLSv1_2,
    'CURLOPT_HTTPHEADER' => [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-API-Version: 2',
    ],
    'CURLOPT_HTTPAUTH' => CURLAUTH_BASIC,
    'CURLOPT_USERPWD' => $config->shopid . ':' . $config->apikey,
];
$curl = new curl();
$jsonresponse = $curl->get($location, null, $options);

$response = json_decode($jsonresponse, false);

if ($response->checkout->status !== 'successful' || $response->checkout->finished != true) {
    throw new \moodle_exception("FAIL. Invoice not paid.");
}

if ($config->recurrent == 1 && $config->recurrentperiod > 0 && $data->transaction->recurring_type == 'initial') {
    $bepaidtx->recurrent = time() + $config->recurrentperiod;
    $bepaidtx->invoiceid = $data->transaction->credit_card->token;
    $nextpay = userdate($bepaidtx->recurrent, "%d %B %Y, %k:%M");
    $DB->update_record('paygw_bepaid', $bepaidtx);
    unset($bepaidtx->recurrent);
    $reason = 'Success recurrent';
} else {
    $reason = 'Success completed';
}

if ($invoiceid !== $data->transaction->additional_data->vendor->token) {
    // Save new payment.
    $newpaymentid = helper::save_payment(
        $payable->get_account_id(),
        $component,
        $paymentarea,
        $itemid,
        $userid,
        $outsumm,
        $payment->currency,
        'bepaid'
    );

    // Make new transaction.
    $bepaidtx->invoiceid = $bepaidtx->paymentid;
    $bepaidtx->paymentid = $newpaymentid;
    $bepaidtx->timecreated = time();
    $bepaidtx->id = $DB->insert_record('paygw_bepaid', $bepaidtx);
    $reason = 'Success completed';
} else {
    // Update payment.
    $payment->amount = $outsumm;
    $payment->timemodified = time();
    $DB->update_record('payments', $payment);
    $newpaymentid = $paymentid;
}

// Deliver order.
helper::deliver_order($component, $paymentarea, $itemid, $newpaymentid, $userid);

// Notify user.
notifications::notify(
    $userid,
    $outsumm,
    $payment->currency,
    $newpaymentid,
    $reason,
    $nextpay
);

// Write to DB.
if ($response->checkout->test == true) {
    $bepaidtx->success = 3;
} else {
    $bepaidtx->success = 1;
}

$DB->update_record('paygw_bepaid', $bepaidtx);

die("OK");
