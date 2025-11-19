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

namespace App\DataMapper\TaxReport;

/**
 * Tax summary with totals for different tax states
 */
class TaxSummary
{
    public float $taxable_amount;
    public float $total_taxes; // Tax collected and confirmed (ie. Invoice Paid)
    public string $status; // updated, deleted, cancelled, adjustment
    public float $adjustment;
    public float $tax_adjustment;

    public function __construct(array $attributes = [])
    {
        $this->taxable_amount = $attributes['taxable_amount'] ?? 0.0;
        $this->total_taxes = $attributes['total_taxes'] ?? 0.0;
        $this->status = $attributes['status'] ?? 'updated';
        $this->adjustment = $attributes['adjustment'] ?? 0.0;
        $this->tax_adjustment = $attributes['tax_adjustment'] ?? 0.0;
    }

    public function toArray(): array
    {
        return [
            'taxable_amount' => $this->taxable_amount,
            'total_taxes' => $this->total_taxes,
            'status' => $this->status,
            'adjustment' => $this->adjustment,
            'tax_adjustment' => $this->tax_adjustment,
        ];
    }
}
