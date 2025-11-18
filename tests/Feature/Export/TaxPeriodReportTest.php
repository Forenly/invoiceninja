<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2021. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace Tests\Feature\Export;

use Tests\TestCase;
use App\Models\User;
use App\Models\Client;
use App\Models\Account;
use App\Models\Company;
use App\Models\Invoice;
use App\Utils\Traits\MakesHash;
use App\DataMapper\CompanySettings;
use App\Factory\InvoiceItemFactory;
use App\Services\Report\TaxPeriodReport;
use Illuminate\Routing\Middleware\ThrottleRequests;
use App\Listeners\Invoice\InvoiceTransactionEventEntry;
use App\Listeners\Invoice\InvoiceTransactionEventEntryCash;

/**
 *
 */
class TaxPeriodReportTest extends TestCase
{
    use MakesHash;

    public $faker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faker = \Faker\Factory::create();

        $this->withoutMiddleware(
            ThrottleRequests::class
        );

        $this->withoutExceptionHandling();
    }

    public $company;

    public $user;

    public $payload;

    public $account;

    public $client;

    /**
     *      start_date - Y-m-d
            end_date - Y-m-d
            date_range -
                all
                last7
                last30
                this_month
                last_month
                this_quarter
                last_quarter
                this_year
                custom
            is_income_billed - true = Invoiced || false = Payments
            expense_billed - true = Expensed || false = Expenses marked as paid
            include_tax - true tax_included || false - tax_excluded
     */
    private function buildData()
    {
        $this->account = Account::factory()->create([
            'hosted_client_count' => 1000,
            'hosted_company_count' => 1000,
        ]);

        $this->account->num_users = 3;
        $this->account->save();

        $this->user = User::factory()->create([
            'account_id' => $this->account->id,
            'confirmation_code' => 'xyz123',
            'email' => \Illuminate\Support\Str::random(32).'@example.com',
        ]);

        $settings = CompanySettings::defaults();
        $settings->client_online_payment_notification = false;
        $settings->client_manual_payment_notification = false;

        $this->company = Company::factory()->create([
            'account_id' => $this->account->id,
            'settings' => $settings,
        ]);

        $this->company->settings = $settings;
        $this->company->save();

        $this->user->companies()->attach($this->company->id, [
            'account_id' => $this->account->id,
            'is_owner' => 1,
            'is_admin' => 1,
            'is_locked' => 0,
            'notifications' => \App\DataMapper\CompanySettings::notificationDefaults(),
            'settings' => null,
        ]);

        $company_token = new \App\Models\CompanyToken();
        $company_token->user_id = $this->user->id;
        $company_token->company_id = $this->company->id;
        $company_token->account_id = $this->account->id;
        $company_token->name = 'test token';
        $company_token->token = \Illuminate\Support\Str::random(64);
        $company_token->is_system = true;

        $company_token->save();

        $truth = app()->make(\App\Utils\TruthSource::class);
        $truth->setCompanyUser($this->user->company_users()->first());
        $truth->setCompanyToken($company_token);
        $truth->setUser($this->user);
        $truth->setCompany($this->company);


        $this->payload = [
            'start_date' => '2000-01-01',
            'end_date' => '2030-01-11',
            'date_range' => 'custom',
            'is_income_billed' => true,
            'include_tax' => false,
            'user_id' => $this->user->id,
        ];

        $this->client = Client::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'is_deleted' => 0,
        ]);
    }

    public function testSingleInvoiceTaxReportStructure()
    {
        $this->buildData();

        $this->travelTo(\Carbon\Carbon::createFromDate(2025, 10, 1)->startOfDay());

        $line_items = [];
        $item = InvoiceItemFactory::create();
        $item->quantity = 1;
        $item->cost = 300;
        $item->type_id = 1;
        $item->tax_name1 = 'GST';
        $item->tax_rate1 = 10;

        $line_items[] = $item;


        $invoice = Invoice::factory()->create([
            'client_id' => $this->client->id,
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'line_items' => $line_items,
            'status_id' => Invoice::STATUS_DRAFT,
            'discount' => 0,
            'is_amount_discount' => false,
            'uses_inclusive_taxes' => false,
            'tax_name1' => '',
            'tax_rate1' => 0,
            'tax_name2' => '',
            'tax_rate2' => 0,
            'tax_name3' => '',
            'tax_rate3' => 0,
            'custom_surcharge1' => 0,
            'custom_surcharge2' => 0,
            'custom_surcharge3' => 0,
            'custom_surcharge4' => 0,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(30)->format('Y-m-d'),
        ]);

        $invoice = $invoice->calc()->getInvoice();

        $invoice->service()->markSent()->createInvitations()->save();

        $invoice->fresh();

        (new InvoiceTransactionEventEntry())->run($invoice);

        $invoice->fresh();

        $transaction_event = $invoice->transaction_events()->first();

        // nlog($transaction_event->metadata->toArray());
        $this->assertNotNull($transaction_event);
        $this->assertEquals(330, $transaction_event->invoice_amount);
        $this->assertEquals('2025-10-01', $invoice->date);
        $this->assertEquals('2025-10-31', $invoice->due_date);
        $this->assertEquals(330, $invoice->balance);
        $this->assertEquals(30, $invoice->total_taxes);

        $payload = [
            'start_date' => '2025-10-01',
            'end_date' => '2025-10-31',
            'date_range' => 'custom',
            'is_income_billed' => true,
        ];

        $pl = new TaxPeriodReport($this->company, $payload);
        $data = $pl->boot()->getData();

        $this->assertNotEmpty($data);


        $payload = [
            'start_date' => '2025-10-01',
            'end_date' => '2025-10-31',
            'date_range' => 'custom',
            'is_income_billed' => false,
        ];

        $pl = new TaxPeriodReport($this->company, $payload);
        $data = $pl->boot()->getData();
        
        $this->assertCount(1,$data['invoices']);
        $this->assertCount(1,$data['invoice_items']);
        
        $invoice->service()->markPaid()->save();
        
        (new InvoiceTransactionEventEntryCash())->run($invoice, '2025-10-01', '2025-10-31');

        $invoice->fresh();
        
        $payload = [
            'start_date' => '2025-10-01',
            'end_date' => '2025-10-31',
            'date_range' => 'custom',
            'is_income_billed' => false,
        ];

        $pl = new TaxPeriodReport($this->company, $payload);
        $data = $pl->boot()->getData();
        
        $this->assertCount(2, $invoice->transaction_events);
        $this->assertCount(2, $data['invoices']);
        $this->assertCount(2, $data['invoice_items']);

        $this->travelBack();
    }
    
    
    /**
     * Test that we adjust appropriately across reporting period where an invoice amount has been both 
     * increased and decreased, and assess that the adjustments are correct.
     * 
     * @return void
     */
    public function testInvoiceReportingOverMultiplePeriodsWithAccrualAccountingCheckAdjustmentsForIncreases()
    {

        $this->buildData();

        $this->travelTo(\Carbon\Carbon::createFromDate(2025, 10, 1)->startOfDay());

        $line_items = [];
        $item = InvoiceItemFactory::create();
        $item->quantity = 1;
        $item->cost = 300;
        $item->type_id = 1;
        $item->tax_name1 = 'GST';
        $item->tax_rate1 = 10;

        $line_items[] = $item;


        $invoice = Invoice::factory()->create([
            'client_id' => $this->client->id,
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'line_items' => $line_items,
            'status_id' => Invoice::STATUS_DRAFT,
            'discount' => 0,
            'is_amount_discount' => false,
            'uses_inclusive_taxes' => false,
            'tax_name1' => '',
            'tax_rate1' => 0,
            'tax_name2' => '',
            'tax_rate2' => 0,
            'tax_name3' => '',
            'tax_rate3' => 0,
            'custom_surcharge1' => 0,
            'custom_surcharge2' => 0,
            'custom_surcharge3' => 0,
            'custom_surcharge4' => 0,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(30)->format('Y-m-d'),
        ]);

        $invoice = $invoice->calc()->getInvoice();

        $invoice->service()->markSent()->createInvitations()->save();

        $invoice->fresh();

        (new InvoiceTransactionEventEntry())->run($invoice);

        $invoice->fresh();

        $transaction_event = $invoice->transaction_events()->first();

        $this->assertEquals('2025-10-31', $transaction_event->period->format('Y-m-d'));
        $this->assertEquals(330, $transaction_event->invoice_amount);
        $this->assertEquals(30, $transaction_event->metadata->tax_report->tax_summary->total_taxes);
        $this->assertEquals(0, $transaction_event->invoice_paid_to_date);

        $this->travelTo(\Carbon\Carbon::createFromDate(2025, 11, 5)->startOfDay());

        $line_items = [];
        $item = InvoiceItemFactory::create();
        $item->quantity = 1;
        $item->cost = 400;
        $item->type_id = 1;
        $item->tax_name1 = 'GST';
        $item->tax_rate1 = 10;

        $line_items[] = $item;

        $invoice->line_items = $line_items;
        $invoice = $invoice->calc()->getInvoice();
        
        $invoice->fresh();

        (new InvoiceTransactionEventEntry())->run($invoice);

        $transaction_event = $invoice->transaction_events()->orderBy('timestamp', 'desc')->first();

        // nlog($transaction_event->metadata);
        $this->assertEquals('2025-11-30', $transaction_event->period->format('Y-m-d'));
        $this->assertEquals(440, $transaction_event->invoice_amount);
        $this->assertEquals("delta", $transaction_event->metadata->tax_report->tax_summary->status);
        $this->assertEquals(40, $transaction_event->metadata->tax_report->tax_summary->total_taxes);
        $this->assertEquals(100, $transaction_event->metadata->tax_report->tax_summary->adjustment);
        $this->assertEquals(10, $transaction_event->metadata->tax_report->tax_summary->tax_adjustment);

        $payload = [
            'start_date' => '2025-11-01',
            'end_date' => '2025-11-30',
            'date_range' => 'custom',
            'is_income_billed' => true,
        ];

        $pl = new TaxPeriodReport($this->company, $payload);
        $data = $pl->boot()->getData();
        
        // nlog($data);

        $invoice_report = $data['invoices'][1];
        $item_report = $data['invoice_items'][1];

        $this->assertEquals(100, $invoice_report[2]); //adjusted amount ex tax
        $this->assertEquals(10, $invoice_report[4]); //adjusted tax amount

        $this->assertEquals(100, $item_report[5]); //Taxable Adjustment Amount
        $this->assertEquals(10, $item_report[4]); //adjusted tax amount
    }


    public function testInvoiceReportingOverMultiplePeriodsWithAccrualAccountingCheckAdjustmentsForDecreases()
    {

        $this->buildData();

        $this->travelTo(\Carbon\Carbon::createFromDate(2025, 10, 1)->startOfDay());

        $line_items = [];
        $item = InvoiceItemFactory::create();
        $item->quantity = 1;
        $item->cost = 300;
        $item->type_id = 1;
        $item->tax_name1 = 'GST';
        $item->tax_rate1 = 10;

        $line_items[] = $item;


        $invoice = Invoice::factory()->create([
            'client_id' => $this->client->id,
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'line_items' => $line_items,
            'status_id' => Invoice::STATUS_DRAFT,
            'discount' => 0,
            'is_amount_discount' => false,
            'uses_inclusive_taxes' => false,
            'tax_name1' => '',
            'tax_rate1' => 0,
            'tax_name2' => '',
            'tax_rate2' => 0,
            'tax_name3' => '',
            'tax_rate3' => 0,
            'custom_surcharge1' => 0,
            'custom_surcharge2' => 0,
            'custom_surcharge3' => 0,
            'custom_surcharge4' => 0,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(30)->format('Y-m-d'),
        ]);

        $invoice = $invoice->calc()->getInvoice();

        $invoice->service()->markSent()->createInvitations()->save();

        $invoice->fresh();

        (new InvoiceTransactionEventEntry())->run($invoice);

        $invoice->fresh();

        $transaction_event = $invoice->transaction_events()->first();

        $this->assertEquals('2025-10-31', $transaction_event->period->format('Y-m-d'));
        $this->assertEquals(330, $transaction_event->invoice_amount);
        $this->assertEquals(30, $transaction_event->metadata->tax_report->tax_summary->total_taxes);
        $this->assertEquals(0, $transaction_event->invoice_paid_to_date);

        $this->travelTo(\Carbon\Carbon::createFromDate(2025, 11, 5)->startOfDay());

        $line_items = [];
        $item = InvoiceItemFactory::create();
        $item->quantity = 1;
        $item->cost = 200;
        $item->type_id = 1;
        $item->tax_name1 = 'GST';
        $item->tax_rate1 = 10;

        $line_items[] = $item;

        $invoice->line_items = $line_items;
        $invoice = $invoice->calc()->getInvoice();
        
        $invoice->fresh();

        (new InvoiceTransactionEventEntry())->run($invoice);

        $transaction_event = $invoice->transaction_events()->orderBy('timestamp', 'desc')->first();

        // nlog($transaction_event->metadata);
        $this->assertEquals('2025-11-30', $transaction_event->period->format('Y-m-d'));
        $this->assertEquals(220, $transaction_event->invoice_amount);
        $this->assertEquals("delta", $transaction_event->metadata->tax_report->tax_summary->status);
        $this->assertEquals(20, $transaction_event->metadata->tax_report->tax_summary->total_taxes);
        $this->assertEquals(-100, $transaction_event->metadata->tax_report->tax_summary->adjustment);
        $this->assertEquals(-10, $transaction_event->metadata->tax_report->tax_summary->tax_adjustment);

        $payload = [
            'start_date' => '2025-11-01',
            'end_date' => '2025-11-30',
            'date_range' => 'custom',
            'is_income_billed' => true,
        ];

        $pl = new TaxPeriodReport($this->company, $payload);
        $data = $pl->boot()->getData();
        
        $invoice_report = $data['invoices'][1];
        $item_report = $data['invoice_items'][1];

        $this->assertEquals(-100, $invoice_report[2]); //adjusted amount ex tax
        $this->assertEquals(-10, $invoice_report[4]); //adjusted tax amount

        $this->assertEquals(-100, $item_report[5]); //Taxable Adjustment Amount
        $this->assertEquals(-10, $item_report[4]); //adjusted tax amount
    }

    public function invoiceReportingOverMultiplePeriodsWithCashAccountingCheckAdjustments()
    {

    }

    //scenarios.

    // Cancelled invoices in the same period
    // Cancelled invoices in the next period
    // Cancelled invoices with payments in the same period
    // Cancelled invoices with payments in the next period


    // Deleted invoices in the same period
    // Deleted invoices in the next period
    // Deleted invoices with payments in the same period
    // Deleted invoices with payments in the next period

    // Updated invoices with payments in the same period
    // Updated invoices with payments in the next period

    //cash accounting.

    // What happens when a payment is deleted?
    // What happens when a paid invoice is deleted?
}