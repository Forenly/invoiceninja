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
            
            $invoice = Invoice::withTrashed()->where('id', $this->decodePrimaryKey($this->input['modified_invoice_id']))->company()->firstOrFail();
                
            if(!$invoice) {
                $fail("Invoice not found");
            }
            elseif ($invoice->backup->adjustable_amount <= 0) {
                $fail("Invoice already credited in full");
            }

            \DB::connection(config('database.default'))->beginTransaction();

                $array_data = request()->all();
                unset($array_data['client_id']);

                $invoice->fill($array_data);
                // $invoice->line_items = $line_items;
                $total = $invoice->calc()->getTotal();

            \DB::connection(config('database.default'))->rollBack();
    
            if($total > 0) {
                $fail("Only negative invoices can be linked to existing invoices {$total}");
            }
            elseif(abs($total) > $invoice->backup->adjustable_amount) {
                $total = abs($total);
                $fail("Total Adjustment {$total} cannot exceed the remaining invoice amount {$invoice->backup->adjustable_amount}");
            }
        }

    }
}
