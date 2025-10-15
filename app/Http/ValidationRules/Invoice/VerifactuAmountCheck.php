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

namespace App\Http\ValidationRules\Invoice;

use Closure;
use App\Utils\BcMath;
use App\Models\Client;
use App\Models\Invoice;
use App\Utils\Traits\MakesHash;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Class VerifactuAmountCheck.
 */
class VerifactuAmountCheck implements ValidationRule
{

    use MakesHash;
    
    public function __construct(private array $input){}
    
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {

        if (empty($value)) {
            return;
        }

        $user = auth()->user();

        $company = $user->company();

        if ($company->verifactuEnabled() && isset($this->input['modified_invoice_id'])) { // Company level check if Verifactu is enabled
            
            /** Harvest the parent invoice to check balances available for cancellation */
            $invoice = Invoice::withTrashed()->where('id', $this->decodePrimaryKey($this->input['modified_invoice_id']))->company()->first();
                
            if(!$invoice) {
                $fail("Factura no encontrada."); // Invoice not found
            } elseif($invoice->is_deleted) {
                $fail("No se puede crear una factura de rectificación para una factura eliminada."); // Cannot create a rectification invoice for a deleted invoice
            } elseif($invoice->backup->document_type !== 'F1') {
                $fail("Solo las facturas originales F1 pueden ser rectificadas."); // Only original F1 invoices can be rectified
            } elseif($invoice->status_id === Invoice::STATUS_DRAFT){
                $fail("No se puede crear una factura de rectificación para una factura en borrador."); // Cannot create a rectification invoice for a draft invoice
            } elseif(in_array($invoice->status_id, [Invoice::STATUS_PARTIAL, Invoice::STATUS_PAID])) {
                $fail("No se puede crear una factura de rectificación cuando se ha realizado un pago."); // Cannot create a rectification invoice where a payment has been made
            } elseif($invoice->status_id === Invoice::STATUS_CANCELLED  ) {
                $fail("No se puede crear una factura de rectificación para una factura cancelada."); // Cannot create a rectification invoice for a cancelled invoice
            } elseif($invoice->status_id === Invoice::STATUS_REVERSED) {
                $fail("No se puede crear una factura de rectificación para una factura revertida."); // Cannot create a rectification invoice for a reversed invoice
            }
            
            /** Sum previously refunded amounts */
            $child_invoices_sum = Invoice::withTrashed()
                                        ->whereIn('id', $this->transformKeys($invoice->backup->child_invoice_ids->toArray()))
                                        ->get()
                                        ->sum('backup.adjustable_amount');

            $child_invoices_sum = abs($child_invoices_sum);

            /** Balance left to be cancelled */
            $adjustable_amount = $invoice->backup->adjustable_amount - $child_invoices_sum;
            
            if (BcMath::comp($adjustable_amount, 0) == 0) {
                $fail("Invoice already credited in full");
            }

            // \DB::connection(config('database.default'))->beginTransaction();


                $array_data = request()->all();
                unset($array_data['client_id']);

                $invoice->fill($array_data);
                
                /** Calculate the gross total of the cancellation invoice. */
                // $total = $invoice->calc()->getTotal();

                // $items = $array_data['line_items'];

                // /** Remove IRPF from the cancellation invoice. */
                // foreach($items as &$item){
                //     if(stripos($item['tax_name1'], 'irpf') !== false){
                //         $item['tax_name1'] = '';
                //         $item['tax_rate1'] = 0;
                //     }
                //     elseif(stripos($item['tax_name2'], 'irpf') !== false){
                //         $item['tax_name2'] = '';
                //         $item['tax_rate2'] = 0;
                //     }
                //     elseif(stripos($item['tax_name3'], 'irpf') !== false){
                //         $item['tax_name3'] = '';
                //         $item['tax_rate3'] = 0;
                //     }
                // }

                // $invoice->line_items = $items;
            
                /** Total WITHOUT IRPF */
                $total = $invoice->calc()->getTotal();

                $invoice->refresh();

            // \DB::connection(config('database.default'))->rollBack();
    
            if($total > 0) {
                $fail("Only negative invoices can rectify a invoice.");
            }

            /** The Calculated amount that can be cancelled */
            $adjustable_amount = $invoice->backup->adjustable_amount - $child_invoices_sum;

            /** The client facing amount that can be cancelled This is the amount that will NOT contain IRPF amounts */
            $client_facing_adjustable_amount = ($invoice->amount / $invoice->backup->adjustable_amount) * $adjustable_amount;

            nlog("total: " . $total);
            nlog("invoice->amount: " . $invoice->amount);
            nlog("adjustable_amount: " . $adjustable_amount);
            nlog("child_invoices_sum: " . $child_invoices_sum);
            nlog("invoice->backup->adjustable_amount: " . $invoice->backup->adjustable_amount);
            nlog("client_facing_adjustable_amount: " . $client_facing_adjustable_amount);
            

            if(abs($total) > $client_facing_adjustable_amount) {
                $fail("Total de ajuste {$total} no puede exceder el saldo de la factura {$client_facing_adjustable_amount}");
            }
        }

    }
}
