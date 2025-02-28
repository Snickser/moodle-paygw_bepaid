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
 * recurrent payments
 *
 * @package    paygw_bepaid
 * @copyright  2024 Alex Orlov <snicker@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace paygw_bepaid\task;

defined('MOODLE_INTERNAL') || die();

use core_payment\helper;
use paygw_bepaid\notifications;

require_once($CFG->libdir . '/filelib.php');

/**
 * Default tasks.
 *
 * @package    paygw_bepaid
 * @copyright  2024 Alex Orlov <snicker@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recurrent_payments extends \core\task\scheduled_task {
    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('pluginname', 'paygw_bepaid');
    }
    /**
     * Execute.
     */
    public function execute() {
        global $DB, $CFG;

        mtrace('Start');

        // Stage One.
        $stime = strtotime(date('d-M-Y H:00', strtotime("+1day")));
        $ctime = strtotime(date('d-M-Y H:00', strtotime("+1day1hour")));

        $bepaidtx = $DB->get_records_sql('SELECT * FROM {paygw_bepaid} WHERE (success=1 OR success=3) ' .
                  'AND recurrent>=? AND recurrent<?', [ $stime, $ctime ]);

        foreach ($bepaidtx as $data) {
            // Get payment data.
            if (!$payment = $DB->get_record('payments', ['id' => $data->paymentid])) {
                mtrace("$data->paymentid not found");
                continue;
            }

            $component   = $payment->component;
            $paymentarea = $payment->paymentarea;
            $itemid      = $payment->itemid;
            $paymentid   = $payment->id;
            $userid      = $payment->userid;

            // Get config.
            $config = (object) helper::get_gateway_configuration($component, $paymentarea, $itemid, 'bepaid');
            $payable = helper::get_payable($component, $paymentarea, $itemid);
            $surcharge = helper::get_gateway_surcharge('bepaid');// In case user uses surcharge.
            $user = \core_user::get_user($userid);

            switch ($config->recurrentcost) {
                case 'suggest':
                    $cost = $config->suggest;
                    break;
                case 'last':
                    $cost = $payment->amount;
                    break;
                default:
                    $cost = helper::get_rounded_cost($payable->get_amount(), $payable->get_currency(), $surcharge);
            }

            // Notify user.
            notifications::notify(
                $userid,
                $cost,
                $payment->currency,
                $data->paymentid,
                'Recurrent notify',
                userdate($data->recurrent, "%d %B %k:00"),
            );

            mtrace("$data->paymentid notified");
        }

        // Stage Two.
        $ctime = strtotime(date('d-M-Y H:00', strtotime("+1hour")));

        $bepaidtx = $DB->get_records_sql('SELECT * FROM {paygw_bepaid} WHERE (success=1 OR success=3) ' .
                  'AND recurrent>0 AND recurrent < ?', [ $ctime ]);

        foreach ($bepaidtx as $data) {
            // To avoid abuse.
            sleep(1);

            // Get payment data.
            if (!$payment = $DB->get_record('payments', ['id' => $data->paymentid])) {
                mtrace("$data->paymentid not found");
                continue;
            }

            $component   = $payment->component;
            $paymentarea = $payment->paymentarea;
            $itemid      = $payment->itemid;
            $paymentid   = $payment->id;
            $userid      = $payment->userid;

            // Get config.
            $config = (object) helper::get_gateway_configuration($component, $paymentarea, $itemid, 'bepaid');
            $payable = helper::get_payable($component, $paymentarea, $itemid);
            $surcharge = helper::get_gateway_surcharge('bepaid');// In case user uses surcharge.

            if (date('d') != $config->recurrentday && $config->recurrentday > 0) {
                mtrace("$data->paymentid too early");
                continue;
            }

            switch ($config->recurrentcost) {
                case 'suggest':
                    $cost = $config->suggest;
                    break;
                case 'last':
                    $cost = $payment->amount;
                    break;
                default:
                    $cost = helper::get_rounded_cost($payable->get_amount(), $payable->get_currency(), $surcharge);
            }

            $user = \core_user::get_user($userid);

            // Save payment.
            $newpaymentid = helper::save_payment(
                $payable->get_account_id(),
                $component,
                $paymentarea,
                $itemid,
                $userid,
                $cost,
                $payment->currency,
                'bepaid'
            );

            // Make new transaction.
            $newtx = new \stdClass();
            $newtx->paymentid = $newpaymentid;
            $newtx->courseid = $data->courseid;
            $newtx->groupnames = $data->groupnames;
            $newtx->timecreated = time();
            $invid = $DB->insert_record('paygw_bepaid', $newtx);
            $newtx->id = $invid;

            // Make invoice.
            $invoice = new \stdClass();
            $invoice->request = [
               "amount" => $cost * 100,
               "currency" => $payment->currency,
               "description" => "Recurrent payment " . $data->paymentid,
               "credit_card" => [
                   "token" => $data->invoiceid,
               ],
               "notification_url" => $CFG->wwwroot . '/payment/gateway/bepaid/recurrent.php',
               "tracking_id" => $newpaymentid,
               "customer" => [ 'email' => $user->email ],
            ];

            if ($config->istestmode) {
                $invoice->request['test'] = true;
            }

            $jsondata = json_encode($invoice);

            // Make payment.
            $location = 'https://gateway.bepaid.by/services/credit_cards/charges';
            $options = [
              'CURLOPT_RETURNTRANSFER' => true,
              'CURLOPT_TIMEOUT' => 30,
              'CURLOPT_HTTP_VERSION' => CURL_HTTP_VERSION_1_1,
              'CURLOPT_SSLVERSION' => CURL_SSLVERSION_TLSv1_2,
              'CURLOPT_HTTPHEADER' => [
                'RequestID: ' . uniqid($data->paymentid, true),
                'Content-Type: application/json',
                'Accept: application/json',
                'X-API-Version: 2',
              ],
              'CURLOPT_HTTPAUTH' => CURLAUTH_BASIC,
              'CURLOPT_USERPWD' => $config->shopid . ':' . $config->apikey,
            ];
            $curl = new \curl();
            $jsonresponse = $curl->post($location, $jsondata, $options);

            if (!empty($curl->errno)) {
                mtrace("$data->paymentid curl error.");
                continue;
            }

            $response = json_decode($jsonresponse);

            if (
                $response->transaction->status == 'successful' &&
                $response->transaction->type == 'charge'
            ) {
                // Write status.
                $newtx->invoiceid = $data->paymentid;
                $DB->update_record('paygw_bepaid', $newtx);

                mtrace("$data->paymentid done.");
                // Notify user.
                notifications::notify(
                    $userid,
                    $cost,
                    $payment->currency,
                    $data->paymentid,
                    'Recurrent created'
                );
            } else {
                echo serialize($jsonresponse) . "\n";
                mtrace("$data->paymentid error");
                $data->recurrent = 0;
                $DB->update_record('paygw_bepaid', $data);
                // Notify user.
                notifications::notify(
                    $userid,
                    $cost,
                    $payment->currency,
                    $data->paymentid,
                    'Recurrent error'
                );
            }
        }

        mtrace('End');
    }
}
