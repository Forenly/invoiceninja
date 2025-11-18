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
 * Individual tax detail object with status tracking
 */
class TaxDetail
{
    public string $tax_name; // e.g., Sales Tax
    public float $tax_rate = 0; //21%
    public string $nexus; // Tax jurisdiction nexus (e.g. "CA", "NY", "FL")
    public string $country_nexus; // Country nexus (e.g. "US", "UK", "CA")
    public float $taxable_amount; // net amount exclusive of taxes
    public float $tax_amount; // total tax amount
    public float $tax_amount_paid; // Amount actually paid (Based on the payment history)
    public float $tax_amount_remaining; // Amount still pending
    public string $tax_status; // "collected", "pending", "refundable", "partially_paid", "adjustment"
    
    // Adjustment-specific fields (used when tax_status is "adjustment")
    public ?string $adjustment_reason; // "invoice_cancelled", "tax_rate_change", "exemption_applied", "correction"
    public float $taxable_amount_adjustment;
    public float $tax_amount_adjustment;
    public float $tax_amount_paid_adjustment;
    public float $tax_amount_remaining_adjustment;

    public function __construct(array $attributes = [])
    {
        $this->tax_name = $attributes['tax_name'] ?? '';
        $this->tax_rate = $attributes['tax_rate'] ?? 0;
        $this->nexus = $attributes['nexus'] ?? '';
        $this->country_nexus = $attributes['country_nexus'] ?? '';
        $this->taxable_amount = $attributes['taxable_amount'] ?? 0.0;
        $this->tax_amount = $attributes['tax_amount'] ?? 0.0;
        $this->tax_amount_paid = $attributes['tax_amount_paid'] ?? 0.0;
        $this->tax_amount_remaining = $attributes['tax_amount_remaining'] ?? 0.0;
        $this->tax_status = $attributes['tax_status'] ?? 'pending';
        // Adjustment fields
        $this->adjustment_reason = $attributes['adjustment_reason'] ?? null;
        
        $this->taxable_amount_adjustment = $attributes['taxable_amount_adjustment'] ?? 0.0;
        $this->tax_amount_adjustment = $attributes['tax_amount_adjustment'] ?? 0.0;
        $this->tax_amount_paid_adjustment = $attributes['tax_amount_paid_adjustment'] ?? 0.0;
        $this->tax_amount_remaining_adjustment = $attributes['tax_amount_remaining_adjustment'] ?? 0.0;
    }

    public function toArray(): array
    {
        $data = [
            'tax_name' => $this->tax_name,
            'tax_rate' => $this->tax_rate,
            'nexus' => $this->nexus,
            'country_nexus' => $this->country_nexus,
            'taxable_amount' => $this->taxable_amount,
            'tax_amount' => $this->tax_amount,
            'tax_amount_paid' => $this->tax_amount_paid,
            'tax_amount_remaining' => $this->tax_amount_remaining,
            'tax_status' => $this->tax_status,
            'adjustment_reason' => $this->adjustment_reason,
            'taxable_amount_adjustment' => $this->taxable_amount_adjustment,
            'tax_amount_adjustment' => $this->tax_amount_adjustment,
            'tax_amount_paid_adjustment' => $this->tax_amount_paid_adjustment,
            'tax_amount_remaining_adjustment' => $this->tax_amount_remaining_adjustment,
        ];

        return $data;
    }
}
