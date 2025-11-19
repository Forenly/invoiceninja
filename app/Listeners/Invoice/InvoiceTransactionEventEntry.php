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

use App\Utils\BcMath;
use App\Models\Invoice;
use App\Models\TransactionEvent;
use Illuminate\Support\Collection;
use App\DataMapper\TransactionEventMetadata;
/**
 * Handles entries for invoices.
 * Used for end of month aggregation of accrual accounting.
 */
class InvoiceTransactionEventEntry
{

    private Collection $payments;

    private float $paid_ratio;

    private string $entry_type = 'updated';

    /**
     * Handle the event.
     *
     * @param  Invoice  $invoice
     * @return void
     */
    public function run(?Invoice $invoice, ?string $force_period = null)
    {
        if(!$invoice)
            return;
        
        $this->setPaidRatio($invoice);

        // if($invoice->public_notes == 'iamdeleted')
        // nlog($invoice->toArray());
        //Long running tasks may spill over into the next day therefore month!
        $period = $force_period ?? now()->endOfMonth()->subHours(5)->format('Y-m-d');
        
        $event = $invoice->transaction_events()
                        ->where('event_id', TransactionEvent::INVOICE_UPDATED)
                        ->orderBy('timestamp', 'desc')
                        ->first();

        if($event){


            $this->entry_type = 'delta';
            
            if($invoice->is_deleted && $event->metadata->tax_report->tax_summary->status == 'deleted'){ 
                // Invoice was previously deleted, and is still deleted... return early!!
                return;
            }
            else if(in_array($invoice->status_id,[Invoice::STATUS_CANCELLED]) && $event->metadata->tax_report->tax_summary->status == 'cancelled'){
                // Invoice was previously cancelled, and is still cancelled... return early!!
                return;
            }
            else if (!$invoice->is_deleted && $event->metadata->tax_report->tax_summary->status == 'deleted'){
                //restored invoice must be reported!!!! _do not return early!!
                $this->entry_type = 'restored';
            }
            else if(in_array($invoice->status_id,[Invoice::STATUS_CANCELLED])){
                // Need to ensure first time cancellations are reported.  
                // return; // Only return if BOTH amount AND status unchanged - for handling cancellations.
                
                // return;
            }
            else if($invoice->is_deleted){
                
            }
            /** If the invoice hasn't changed its state... return early!! */
            else if(BcMath::comp($invoice->amount, $event->invoice_amount) == 0){
                return;
            }

        }
        elseif($invoice->is_deleted){
            // elseif($invoice->is_deleted && \Carbon\Carbon::parse($invoice->date)->lte(\Carbon\Carbon::parse($period))){
            //If the invoice was created and deleted in the same period, we don't need to report it!!!
            // return;
           
        }

        $this->payments = $invoice->payments->flatMap(function ($payment) {
            return $payment->invoices()->get()->map(function ($invoice) use ($payment) {
                return [
                    'number' => $payment->number,
                    'amount' => $invoice->pivot->amount,
                    'refunded' => $invoice->pivot->refunded,
                    'date' => $invoice->pivot->created_at->format('Y-m-d'),
                ];
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
            'event_id' => TransactionEvent::INVOICE_UPDATED,
            'timestamp' => now()->timestamp,
            'metadata' => $this->getMetadata($invoice),
            'period' => $period,
        ]);
    }

    private function setPaidRatio(Invoice $invoice): self
    {
        if($invoice->amount == 0){
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
     * calculateDeltaMetaData
     *
     * Calculates the differential between this period and the previous period.
     * 
     * @param  mixed $invoice
     *
     */
    private function calculateDeltaMetaData($invoice)
    {
        $this->paid_ratio = 1;

        $calc = $invoice->calc();

        $details = [];

        $taxes = array_merge($calc->getTaxMap()->merge($calc->getTotalTaxMap())->toArray());

        $previous_transaction_event = TransactionEvent::where('event_id', TransactionEvent::INVOICE_UPDATED)
                                            ->where('invoice_id', $invoice->id)
                                            ->orderBy('timestamp', 'desc')
                                            ->first();


        $previous_tax_details = $previous_transaction_event->metadata->tax_report->tax_details;

        foreach ($taxes as $tax) {
            $previousLine = collect($previous_tax_details)->where('tax_name', $tax['name'])->first() ?? null;

            $tax_detail = [
                'tax_name' => $tax['name'],
                'tax_rate' => $tax['tax_rate'],
                'taxable_amount' => $tax['base_amount'] ?? $calc->getNetSubtotal(),
                'tax_amount' => $this->calculateRatio($tax['total']),
                'taxable_amount_adjustment' => ($tax['base_amount'] ?? $calc->getNetSubtotal()) - ($previousLine->taxable_amount ?? 0),
                'tax_amount_adjustment' => $tax['total'] - ($previousLine->tax_amount ?? 0),
            ];

            $details[] = $tax_detail;
        }

        $this->setPaidRatio($invoice);

        return new TransactionEventMetadata([
            'tax_report' => [
                'tax_details' => $details,
                'payment_history' => $this->payments->toArray() ?? [], //@phpstan-ignore-line
                'tax_summary' => [
                    'taxable_amount' => $calc->getNetSubtotal(),
                    'total_taxes' => $calc->getTotalTaxes(),
                    'status' => 'delta',
                    'adjustment' => round($calc->getNetSubtotal() - $previous_transaction_event->metadata->tax_report->tax_summary->taxable_amount, 2),
                    'tax_adjustment' => round($calc->getTotalTaxes() - $previous_transaction_event->metadata->tax_report->tax_summary->total_taxes,2)
                ],
            ],
        ]);

    }
    
    /**
     * Existing tax details are not deleted, but pending taxes are set to 0
     *
     * @param  mixed $invoice
     */
    private function getCancelledMetaData($invoice)
    {
                
        $calc = $invoice->calc();

        $details = [];

        $taxes = array_merge($calc->getTaxMap()->merge($calc->getTotalTaxMap())->toArray());

        //If there is a previous transaction event, we need to consider the taxable amount.
        $previous_transaction_event = TransactionEvent::where('event_id', TransactionEvent::INVOICE_UPDATED)
                                            ->where('invoice_id', $invoice->id)
                                            ->orderBy('timestamp', 'desc')
                                            ->first();

        if($this->paid_ratio == 0){
            // setup a 0/0 recorded
        }
        
        //If there are no previous events, we setup a 0/0 record.

        // If there is a previous event, it must have a payment history?
        if($previous_transaction_event){
            $previous_tax_details = $previous_transaction_event->metadata->tax_report->tax_details;
        }

        foreach ($taxes as $tax) {
            $tax_detail = [
                'tax_name' => $tax['name'],
                'tax_rate' => $tax['tax_rate'],
                'taxable_amount' => ($tax['base_amount'] ?? $calc->getNetSubtotal()) * $this->paid_ratio,
                'tax_amount' => ($tax['total'] * $this->paid_ratio),
            ];
            $details[] = $tax_detail;
        }

        //@todo what happens if this is triggered in the "NEXT FINANCIAL PERIOD?
        return new TransactionEventMetadata([
            'tax_report' => [
                'tax_details' => $details,
                'payment_history' => $this->payments->toArray() ?? [], //@phpstan-ignore-line
                'tax_summary' => [
                    'taxable_amount' => $calc->getNetSubtotal() * $this->paid_ratio,
                    'total_taxes' => $calc->getTotalTaxes() * $this->paid_ratio,
                    'status' => 'cancelled',
                    // 'adjustment' => round($calc->getNetSubtotal() - $previous_transaction_event->metadata->tax_report->tax_summary->taxable_amount, 2),
                    // 'tax_adjustment' => round($calc->getTotalTaxes() - $previous_transaction_event->metadata->tax_report->tax_summary->total_taxes,2)
                ],
            ],
        ]);

    }
    
    /**
     * Set all tax details to 0
     *
     * @param  mixed $invoice
     */
    private function getDeletedMetaData($invoice)
    {
                
        $calc = $invoice->calc();

        $details = [];

        $taxes = array_merge($calc->getTaxMap()->merge($calc->getTotalTaxMap())->toArray());

        foreach ($taxes as $tax) {
            $tax_detail = [
                'tax_name' => $tax['name'],
                'tax_rate' => $tax['tax_rate'],
                'taxable_amount' => ($tax['base_amount'] ?? $calc->getNetSubtotal()) * -1,
                'tax_amount' => $tax['total'] * -1,
            ];
            $details[] = $tax_detail;
        }

        return new TransactionEventMetadata([
            'tax_report' => [
                'tax_details' => $details,
                'payment_history' => $this->payments->toArray(),
                'tax_summary' => [
                    'taxable_amount' => $calc->getNetSubtotal(),
                    'total_taxes' => $calc->getTotalTaxes(),
                    'status' => 'deleted',
                ],
            ],
        ]);

    }

    private function getMetadata($invoice)
    {

        if ($invoice->status_id == Invoice::STATUS_CANCELLED) {
            return $this->getCancelledMetaData($invoice);
        } elseif ($invoice->is_deleted) {
            return $this->getDeletedMetaData($invoice);
        } elseif ($this->entry_type == 'delta') {
            return $this->calculateDeltaMetaData($invoice);
        }

        $calc = $invoice->calc();

        $details = [];

        $taxes = array_merge($calc->getTaxMap()->merge($calc->getTotalTaxMap())->toArray());

        foreach ($taxes as $tax) {
            $tax_detail = [
                'tax_name' => $tax['name'],
                'tax_rate' => $tax['tax_rate'],
                'taxable_amount' => $tax['base_amount'] ?? $calc->getNetSubtotal(),
                'tax_amount' => $tax['total'],
            ];
            $details[] = $tax_detail;
        }

        return new TransactionEventMetadata([
            'tax_report' => [
                'tax_details' => $details,
                'payment_history' => $this->payments->toArray(),
                'tax_summary' => [
                    'taxable_amount' => $calc->getNetSubtotal(),
                    'total_taxes' => $calc->getTotalTaxes(),
                    'status' => 'updated',
                ],
            ],
        ]);

    }

    private function getTotalTaxPaid($invoice)
    {
        if($invoice->amount == 0){
            return 0;
        }

        $total_paid = $this->payments->sum('amount') - $this->payments->sum('refunded');

        return round($invoice->total_taxes * ($total_paid / $invoice->amount), 2);

    }

    
}
