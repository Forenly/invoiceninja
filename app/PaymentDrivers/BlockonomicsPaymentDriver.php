<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2025. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://opensource.org/licenses/AAL
 */

namespace App\PaymentDrivers;

use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SystemLog;
use App\Models\GatewayType;
use App\Models\PaymentHash;
use App\Models\PaymentType;
use App\Utils\Traits\MakesHash;
use App\Jobs\Util\SystemLogger;
use App\Exceptions\PaymentFailed;
use Illuminate\Support\Facades\Http;
use App\PaymentDrivers\Blockonomics\Blockonomics;
use App\Http\Requests\Payments\PaymentWebhookRequest;

class BlockonomicsPaymentDriver extends BaseDriver
{
    use MakesHash;

    public $refundable = false; //does this gateway support refunds?

    public $token_billing = false; //does this gateway support token billing?

    public $can_authorise_credit_card = false; //does this gateway support authorizations?

    public $gateway; //initialized gateway

    public $payment_method; //initialized payment method

    public static $methods = [
        GatewayType::CRYPTO => Blockonomics::class, //maps GatewayType => Implementation class
    ];

    public const SYSTEM_LOG_TYPE = SystemLog::TYPE_BLOCKONOMICS; //define a constant for your gateway ie TYPE_YOUR_CUSTOM_GATEWAY - set the const in the SystemLog model

    public $BASE_URL = 'https://www.blockonomics.co';
    public $NEW_ADDRESS_URL = 'https://www.blockonomics.co/api/new_address';
    public $PRICE_URL = 'https://www.blockonomics.co/api/price';
    public $STORES_URL = 'https://www.blockonomics.co/api/v2/stores';
    private string $test_txid = 'WarningThisIsAGeneratedTestPaymentAndNotARealBitcoinTransaction';

    public function init()
    {
        return $this; /* This is where you boot the gateway with your auth credentials*/
    }

    /* Returns an array of gateway types for the payment gateway */
    public function gatewayTypes(): array
    {
        $types = [];

        $types[] = GatewayType::CRYPTO;

        return $types;
    }

    public function setPaymentMethod($payment_method_id)
    {
        $class = self::$methods[$payment_method_id];
        $this->payment_method = new $class($this);
        return $this;
    }

    public function processPaymentView(array $data)
    {
        $this->init();

        return $this->payment_method->paymentView($data);  //this is your custom implementation from here
    }

    public function processPaymentResponse($request)
    {

        $this->init();

        return $this->payment_method->paymentResponse($request);
    }

    public function processWebhookRequest(PaymentWebhookRequest $request)
    {
        $company = $request->getCompany();

        // Re-introduce secret in a later stage if needed.
        // $url_callback_secret = $request->secret;
        // $db_callback_secret = $this->company_gateway->getConfigField('callbackSecret');

        // if ($url_callback_secret != $db_callback_secret) {
        //     throw new PaymentFailed('Secret does not match');
        // }

        $txid   = $request->txid;
        $value  = $request->value;
        $status = $request->status;
        $addr   = $request->addr;

        $payment = ($txid === $this->test_txid)
            ? Payment::query()
                ->where('company_id', $company->id)
                ->where('private_notes', "$addr - $value")
                ->first()
            : Payment::query()
                ->where('company_id', $company->id)
                ->where('transaction_reference', $txid)
                ->first();

        // If payment doesn't exist yet, let paymentResponse handle creation
        if (!$payment) {
            return response()->json(['message' => 'Payment not found'], 200);
        }

        // If payment is already completed, no need to process again
        if ($payment->status_id == Payment::STATUS_COMPLETED) {
            return response()->json(['message' => 'Payment already completed'], 200);
        }

        // Only process confirmed payments (status 2)
        if ((int) $status !== 2) {
            return response()->json(['message' => 'Only confirmed payments processed'], 200);
        }

        // Update payment to completed
        $payment->status_id = Payment::STATUS_COMPLETED;
        $payment->save();

        // Find the associated invoice using multiple methods for reliability
        $invoice = null;

        // Method 1: Through payment hash (most reliable)
        if ($payment_hash = PaymentHash::where('payment_id', $payment->id)->first()) {
            $invoice = $payment_hash->fee_invoice ?? $payment_hash->paymentable;
        }

        // Method 2: Direct relationship (fallback)
        if (!$invoice) {
            $invoice = $payment->invoices()->first();
        }

        if (!$invoice) {
            return response()->json(['message' => 'No associated invoice found'], 200);
        }

        // Store original balance for logging
        $original_balance = $invoice->balance;

        // Debug: Log current payment-invoice relationship
        $payment_invoices = $payment->invoices;
        $invoice_payments = $invoice->payments;

        SystemLogger::dispatch(
            [
                'debug' => 'payment_invoice_relationship',
                'payment_id' => $payment->id,
                'payment_amount' => $payment->amount,
                'payment_status' => $payment->status_id,
                'invoice_id' => $invoice->id,
                'invoice_balance_before' => $original_balance,
                'invoice_amount' => $invoice->amount,
                'payment_invoices_count' => $payment_invoices->count(),
                'invoice_payments_count' => $invoice_payments->count(),
                'invoice_paid_to_date' => $invoice->paid_to_date ?? 'null',
            ],
            SystemLog::CATEGORY_GATEWAY_RESPONSE,
            SystemLog::EVENT_GATEWAY_SUCCESS,
            SystemLog::TYPE_BLOCKONOMICS,
            $payment->client ?? $company->clients()->first(),
            $company,
        );

        // Ensure the payment is properly linked to the invoice
        if (!$payment->invoices()->where('invoices.id', $invoice->id)->exists()) {
            // Manually attach the payment to the invoice if not linked
            $payment->invoices()->attach($invoice->id, [
                'amount' => $payment->amount,
                'refunded' => 0,
            ]);
        }

        // Force InvoiceNinja to recalculate everything
        $invoice = $invoice->calc()->getInvoice();
        $invoice->service()->markPaid()->save();

        // Alternative approach - manually update if calc() doesn't work
        if ($invoice->balance == $original_balance) {
            // Calc didn't work, do manual calculation
            $total_payments = $invoice->payments()
                ->where('status_id', Payment::STATUS_COMPLETED)
                ->sum('payments.amount');

            $invoice->balance = $invoice->amount - $total_payments;
            $invoice->paid_to_date = $total_payments;

            // Set correct status
            if ($invoice->balance <= 0) {
                $invoice->status_id = Invoice::STATUS_PAID;
            } else {
                $invoice->status_id = Invoice::STATUS_PARTIAL;
            }

            $invoice->save();
        }

        // Log the successful payment confirmation
        SystemLogger::dispatch(
            [
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'status_change' => 'confirmed',
                'balance_before' => $original_balance,
                'balance_after' => $invoice->balance,
                'webhook_data' => [
                    'txid' => $txid,
                    'value' => $value,
                    'status' => $status,
                    'addr' => $addr
                ]
            ],
            SystemLog::CATEGORY_GATEWAY_RESPONSE,
            SystemLog::EVENT_GATEWAY_SUCCESS,
            SystemLog::TYPE_BLOCKONOMICS,
            $payment->client ?? $company->clients()->first(),
            $company,
        );

        return response()->json([
            'message' => 'Payment confirmed successfully',
            'payment_id' => $payment->id,
            'invoice_status' => $invoice->status_id,
            'invoice_balance' => $invoice->balance
        ], 200);
    }

    public function refund(Payment $payment, $amount, $return_client_response = false)
    {
        $this->setPaymentMethod(GatewayType::CRYPTO);
        return $this->payment_method->refund($payment, $amount); //this is your custom implementation from here
    }

    //dead code? //2025-04-23
    // public function testNewAddressGen($crypto = 'btc', $response): string
    // {
    //     $api_key = $this->company_gateway->getConfigField('apiKey');
    //     $new_address_reset_url = $this->NEW_ADDRESS_URL . '?reset=1';
    //     $new_address_response = Http::withToken($api_key)
    //         ->post($new_address_reset_url, []);
    //     if ($new_address_response->response_code != 200) {
    //         return isset($new_address_response->response_message) && $new_address_response->response_message
    //             ? $new_address_response->response_message
    //             : 'Could not generate new address';
    //     }

    //     if (empty($new_address_response->address)) {
    //         return 'No address returned from Blockonomics API';
    //     }

    //     return 'ok';
    // }

    public function checkStores($stores): string
    {
        if (empty($stores['data'])) {
            return "Please add a store to your Blockonomics' account";
        }

        $invoice_ninja_callback_url = $this->company_gateway->webhookUrl();

        $matching_store = null;
        $store_without_callback = null;
        $partial_match_store = null;

        foreach ($stores['data'] as $store) {
            if ($store['http_callback'] === $invoice_ninja_callback_url) {
                $matching_store = $store;
                break;
            }
            if (empty($store['http_callback'])) {
                $store_without_callback = $store;
                continue;
            }
            // Check for partial match - only secret or protocol differs
            // TODO: Implement logic for updating partial matches
            $store_base_url = preg_replace('/https?:\/\//', '', $store['http_callback']);
            if (strpos($store_base_url, $invoice_ninja_callback_url) === 0) {
                $partial_match_store = $store;
            }
        }

        if ($matching_store) {
            $matching_store_wallet = $matching_store['wallets'];
            if (empty($matching_store_wallet)) {
                return 'Please add a wallet to your Blockonomics store';
            }
            return 'ok';
        }
        return "Copy your Invoice Ninja Webhook URL and set it as your callback URL in Blockonomics";
    }

    public function auth(): string
    {
        try {
            $api_key = $this->company_gateway->getConfigField('apiKey');

            if (!$api_key) {
                return 'No API Key';
            }
            $get_stores_response = Http::withToken($api_key)
                ->get($this->STORES_URL, ['wallets' => 'true']);
            $get_stores_response_status = $get_stores_response->status();

            if ($get_stores_response_status == 401) {
                return 'API Key is incorrect';
            }


            if (!$get_stores_response || $get_stores_response_status !== 200) {
                return 'Could not connect to Blockonomics API';
            }

            $stores = $get_stores_response->json();
            $stores_check_result = $this->checkStores($stores);

            return $stores_check_result;
        } catch (\Exception $e) {
            return $e->getMessage();
        }

    }
}
