<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2025. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Listeners\Invoice;

use App\Models\Invoice;
use App\Models\Activity;
use App\Models\TransactionEvent;
use Illuminate\Support\Collection;
use App\DataMapper\TaxReport\TaxDetail;
use App\DataMapper\TaxReport\TaxReport;
use App\DataMapper\TaxReport\TaxSummary;
use App\Repositories\ActivityRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\DataMapper\TransactionEventMetadata;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class InvoiceTransactionEventEntryCash
{
    private Collection $payments;

    private float $paid_ratio;

    /**
     * Handle the event.
     *
     */
    public function run($invoice, $start_date, $end_date)
    {

        if(!$invoice)
            return;
        
        $this->setPaidRatio($invoice);
        
        $this->payments = $invoice->payments->flatMap(function ($payment) use ($start_date, $end_date) {
            return $payment->invoices()->get()->map(function ($invoice) use ($payment) {
                return [
                    'number' => $payment->number,
                    'amount' => $invoice->pivot->amount,
                    'refunded' => $invoice->pivot->refunded,
                    'date' => $invoice->pivot->created_at->format('Y-m-d'),
                ];
            })->filter(function ($payment) use ($start_date, $end_date) {
                // Filter payments where the pivot created_at is within the date boundaries
                return \Carbon\Carbon::parse($payment['date'])->isBetween($start_date, $end_date);
            });
        });

        TransactionEvent::create([
            'invoice_id' => $invoice->id,
            'client_id' => $invoice->client_id,
            'client_balance' => $invoice->client->balance,
            'client_paid_to_date' => $invoice->client->paid_to_date,
            'client_credit_balance' => $invoice->client->credit_balance,
            'invoice_balance' => $invoice->balance ?? 0,
            'invoice_amount' => $invoice->amount ?? 0  ,
            'invoice_partial' => $invoice->partial ?? 0,
            'invoice_paid_to_date' => $invoice->paid_to_date ?? 0,
            'invoice_status' => $invoice->is_deleted ? 7 : $invoice->status_id,
            'event_id' => TransactionEvent::PAYMENT_CASH,
            'timestamp' => now()->timestamp,
            'metadata' => $this->getMetadata($invoice),
            'period' => $end_date,
        ]);
    }

    private function setPaidRatio(Invoice $invoice): self
    {
        if ($invoice->amount == 0) {
            $this->paid_ratio = 0;
            return $this;
        }

        $this->paid_ratio = $invoice->paid_to_date / $invoice->amount;

        return $this;
    }

    private function calculateRatio(float $amount): float
    {
        return round($amount * $this->paid_ratio, 2);
    }

    /**
     * Existing tax details are not deleted, but pending taxes are set to 0
     *
     * @param  mixed $invoice
     */
    // private function getCancelledMetaData($invoice)
    // {

    //     $calc = $invoice->calc();

    //     $details = [];

    //     $taxes = array_merge($calc->getTaxMap()->merge($calc->getTotalTaxMap())->toArray());

    //     foreach ($taxes as $tax) {
    //         $tax_detail = [
    //             'tax_name' => $tax['name'],
    //             'tax_rate' => $tax['tax_rate'],
    //             'taxable_amount' => $tax['base_amount'] ?? $calc->getNetSubtotal(),
    //             'tax_amount' => $this->calculateRatio($tax['total']),
    //             'tax_amount_paid' => $this->calculateRatio($tax['total']),
    //             'tax_amount_remaining' => 0,
    //         ];
    //         $details[] = $tax_detail;
    //     }

    //     return new TransactionEventMetadata([
    //         'tax_report' => [
    //             'tax_details' => $details,
    //             'payment_history' => $this->payments->toArray(),
    //             'tax_summary' => [
    //                 'total_taxes' => $invoice->total_taxes,
    //                 'total_paid' => $this->getTotalTaxPaid($invoice),
    //                 'status' => 'cancelled',
    //             ],
    //         ],
    //     ]);

    // }

    /**
     * Set all tax details to 0
     *
     * @param  mixed $invoice
     */
    // private function getDeletedMetaData($invoice)
    // {

    //     $calc = $invoice->calc();

    //     $details = [];

    //     $taxes = array_merge($calc->getTaxMap()->merge($calc->getTotalTaxMap())->toArray());

    //     foreach ($taxes as $tax) {
    //         $tax_detail = [
    //             'tax_name' => $tax['name'],
    //             'tax_rate' => $tax['tax_rate'],
    //             'taxable_amount' => $tax['base_amount'] ?? $calc->getNetSubtotal(),
    //             'tax_amount' => $tax['total'],
    //             'tax_amount_paid' => $this->calculateRatio($tax['total']),
    //             'tax_amount_remaining' => 0,
    //         ];
    //         $details[] = $tax_detail;
    //     }

    //     return new TransactionEventMetadata([
    //         'tax_report' => [
    //             'tax_details' => $details,
    //             'payment_history' => $this->payments->toArray(),
    //             'tax_summary' => [
    //                 'total_taxes' => $invoice->total_taxes,
    //                 'total_paid' => $this->getTotalTaxPaid($invoice),0,
    //                 'status' => 'deleted',
    //             ],
    //         ],
    //     ]);

    // }

    private function getMetadata($invoice)
    {

        // if ($invoice->status_id == Invoice::STATUS_CANCELLED) {
        //     return $this->getCancelledMetaData($invoice);
        // } elseif ($invoice->is_deleted) {
        //     return $this->getDeletedMetaData($invoice);
        // }

        $calc = $invoice->calc();

        $details = [];

        $taxes = array_merge($calc->getTaxMap()->merge($calc->getTotalTaxMap())->toArray());

        foreach ($taxes as $tax) {
            $tax_detail = [
                'tax_name' => $tax['name'],
                'tax_rate' => $tax['tax_rate'],
                'taxable_amount' => $tax['base_amount'] ?? $calc->getNetSubtotal(),
                'tax_amount' => $tax['total'],
                'tax_amount_paid' => $this->calculateRatio($tax['total']),
                'tax_amount_remaining' => $tax['total'] - $this->calculateRatio($tax['total']),
            ];
            $details[] = $tax_detail;
        }
        
        return new TransactionEventMetadata([
            'tax_report' => [
                'tax_details' => $details,
                'payment_history' => $this->payments->toArray(),
                'tax_summary' => [
                    'total_taxes' => $invoice->total_taxes,
                    'total_paid' => $this->getTotalTaxPaid($invoice),
                    'status' => 'updated',
                ],
            ],
        ]);

    }

    private function getTotalTaxPaid($invoice)
    {
        if ($invoice->amount == 0) {
            return 0;
        }

        $total_paid = $this->payments->sum('amount') - $this->payments->sum('refunded');

        return round($invoice->total_taxes * ($total_paid / $invoice->amount), 2);

    }


}
