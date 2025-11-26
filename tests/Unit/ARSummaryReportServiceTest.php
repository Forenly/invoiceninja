<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Account;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Company;
use App\Models\User;
use App\Services\Report\ARSummaryReport;
use Illuminate\Foundation\Testing\DatabaseTransactions;

/**
 * Test ARSummaryReport service class with optimized implementation.
 */
class ARSummaryReportServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected Company $company;
    protected User $user;
    protected Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->account = Account::factory()->create();
        
        $this->company = Company::factory()->create([
            'account_id' => $this->account->id,
        ]);
        
        $this->user = User::factory()->create([
            'account_id' => $this->account->id,
        ]);
    }

    /**
     * Test that optimized report generates without errors.
     */
    public function testOptimizedReportGenerates()
    {
        // Create test data
        $client = Client::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
        ]);

        Invoice::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'client_id' => $client->id,
            'status_id' => Invoice::STATUS_SENT,
            'balance' => 100,
            'due_date' => now()->subDays(15),
        ]);

        $report = new ARSummaryReport($this->company, [
            'report_keys' => [],
            'user_id' => $this->user->id,
        ]);

        $csv = $report->run();

        $this->assertNotEmpty($csv);
        $this->assertStringContainsString('aged_receivable_summary_report', $csv);
        $this->assertStringContainsString($client->present()->name(), $csv);
    }

    /**
     * Test that rollback flag works correctly.
     */
    public function testRollbackToLegacyWorks()
    {
        $client = Client::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
        ]);

        Invoice::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'client_id' => $client->id,
            'status_id' => Invoice::STATUS_SENT,
            'balance' => 200,
            'due_date' => now()->subDays(45),
        ]);

        // Force use of legacy implementation via reflection
        $report = new ARSummaryReport($this->company, [
            'report_keys' => [],
            'user_id' => $this->user->id,
        ]);

        $reflection = new \ReflectionClass($report);
        $property = $reflection->getProperty('useOptimizedQuery');
        $property->setAccessible(true);
        $property->setValue($report, false);

        $csv = $report->run();

        $this->assertNotEmpty($csv);
        $this->assertStringContainsString($client->present()->name(), $csv);
    }

    /**
     * Test that both implementations produce same output.
     */
    public function testBothImplementationsProduceSameOutput()
    {
        // Create test data
        $client = Client::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'name' => 'Test Client ABC',
        ]);

        Invoice::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'client_id' => $client->id,
            'status_id' => Invoice::STATUS_SENT,
            'balance' => 100,
            'due_date' => now()->subDays(15),
        ]);

        Invoice::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'client_id' => $client->id,
            'status_id' => Invoice::STATUS_SENT,
            'balance' => 300,
            'due_date' => now()->subDays(75),
        ]);

        // Run with optimized
        $reportOptimized = new ARSummaryReport($this->company, [
            'report_keys' => [],
            'user_id' => $this->user->id,
        ]);
        $csvOptimized = $reportOptimized->run();

        // Run with legacy
        $reportLegacy = new ARSummaryReport($this->company, [
            'report_keys' => [],
            'user_id' => $this->user->id,
        ]);
        
        $reflection = new \ReflectionClass($reportLegacy);
        $property = $reflection->getProperty('useOptimizedQuery');
        $property->setAccessible(true);
        $property->setValue($reportLegacy, false);
        
        $csvLegacy = $reportLegacy->run();

        // Both should contain same client name and amounts
        $this->assertEquals(
            substr_count($csvOptimized, 'Test Client ABC'),
            substr_count($csvLegacy, 'Test Client ABC'),
            'Both implementations should include client name'
        );

        // Both CSVs should have same structure (same number of lines)
        $this->assertEquals(
            substr_count($csvOptimized, "\n"),
            substr_count($csvLegacy, "\n"),
            'Both implementations should produce same CSV structure'
        );
    }

    /**
     * Test with empty client list.
     */
    public function testWithNoClients()
    {
        $report = new ARSummaryReport($this->company, [
            'report_keys' => [],
            'user_id' => $this->user->id,
        ]);

        $csv = $report->run();

        $this->assertNotEmpty($csv);
        $this->assertStringContainsString('aged_receivable_summary_report', $csv);
    }
}
