<?php

namespace App\Console\Commands\Elastic;

use Illuminate\Console\Command;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Credit;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Models\RecurringInvoice;
use App\Models\Task;
use App\Models\Vendor;
use App\Models\VendorContact;
use Elastic\Elasticsearch\ClientBuilder;
use Illuminate\Support\Facades\Artisan;

class RebuildElasticIndexes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'elastic:rebuild
                            {--force : Force the operation without confirmation}
                            {--skip-migrations : Skip running migrations after dropping indexes}
                            {--skip-import : Skip importing data after migration}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Drop all Elasticsearch indexes and rebuild them using elastic migrations';

    /**
     * All searchable models and their index names
     *
     * @var array
     */
    protected array $searchableModels = [
        Client::class => 'clients_v2',
        ClientContact::class => 'client_contacts_v2',
        Credit::class => 'credits_v2',
        Expense::class => 'expenses_v2',
        Invoice::class => 'invoices_v2',
        Project::class => 'projects_v2',
        PurchaseOrder::class => 'purchase_orders_v2',
        Quote::class => 'quotes_v2',
        RecurringInvoice::class => 'recurring_invoices_v2',
        Task::class => 'tasks_v2',
        Vendor::class => 'vendors_v2',
        VendorContact::class => 'vendor_contacts_v2',
    ];

    /**
     * Legacy index names that might still exist
     *
     * @var array
     */
    protected array $legacyIndexes = [
        'clients',
        'client_contacts',
        'credits',
        'expenses',
        'invoices',
        'invoices_index',
        'projects',
        'purchase_orders',
        'quotes',
        'recurring_invoices',
        'tasks',
        'vendors',
        'vendor_contacts',
    ];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('===========================================');
        $this->info('  Elasticsearch Index Rebuild Utility');
        $this->info('===========================================');
        $this->newLine();

        // Check if Elasticsearch is available
        if (!$this->checkElasticsearchConnection()) {
            $this->error('Cannot connect to Elasticsearch. Please check your configuration.');
            return self::FAILURE;
        }

        // Get confirmation unless --force is used
        if (!$this->option('force')) {
            $this->warn('This command will:');
            $this->warn('  1. Drop all existing Elasticsearch indexes');
            $this->warn('  2. Run elastic migrations to recreate indexes');
            $this->warn('  3. Re-import all searchable data');
            $this->newLine();
            $this->warn('This operation cannot be undone!');
            $this->newLine();

            if (!$this->confirm('Do you want to continue?', false)) {
                $this->info('Operation cancelled.');
                return self::SUCCESS;
            }
        }

        $this->newLine();

        // Step 1: Drop all indexes
        $this->info('[Step 1/3] Dropping all Elasticsearch indexes...');
        $this->dropAllIndexes();

        // Step 2: Run migrations
        if (!$this->option('skip-migrations')) {
            $this->newLine();
            $this->info('[Step 2/3] Running elastic migrations...');
            $this->runMigrations();
        } else {
            $this->warn('[Step 2/3] Skipped: elastic migrations');
        }

        // Step 3: Import data
        if (!$this->option('skip-import')) {
            $this->newLine();
            $this->info('[Step 3/3] Re-importing searchable data...');
            $this->importData();
        } else {
            $this->warn('[Step 3/3] Skipped: data import');
        }

        $this->newLine();
        $this->info('===========================================');
        $this->info('✓ Elasticsearch indexes rebuilt successfully!');
        $this->info('===========================================');

        return self::SUCCESS;
    }

    /**
     * Check if Elasticsearch is reachable
     */
    protected function checkElasticsearchConnection(): bool
    {
        try {
            $client = $this->getElasticsearchClient();
            $client->ping();
            $this->info('✓ Elasticsearch connection successful');
            return true;
        } catch (\Exception $e) {
            $this->error('✗ Elasticsearch connection failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get Elasticsearch client
     */
    protected function getElasticsearchClient()
    {
        $hosts = config('elastic.client.hosts', ['localhost:9200']);
        return ClientBuilder::create()->setHosts($hosts)->build();
    }

    /**
     * Drop all Elasticsearch indexes
     */
    protected function dropAllIndexes(): void
    {
        $client = $this->getElasticsearchClient();
        $droppedCount = 0;

        // Get all current indexes
        try {
            $indices = $client->cat()->indices(['format' => 'json']);
        } catch (\Exception $e) {
            $this->warn('Could not list indices: ' . $e->getMessage());
            $indices = [];
        }

        // Build list of all indexes to drop
        $indexesToDrop = array_merge(
            array_values($this->searchableModels),
            $this->legacyIndexes
        );

        // Drop each index
        foreach ($indexesToDrop as $indexName) {
            try {
                if ($client->indices()->exists(['index' => $indexName])) {
                    $client->indices()->delete(['index' => $indexName]);
                    $this->line("  ✓ Dropped index: {$indexName}");
                    $droppedCount++;
                } else {
                    $this->line("  - Index does not exist: {$indexName}", 'comment');
                }
            } catch (\Exception $e) {
                $this->warn("  ✗ Failed to drop index {$indexName}: " . $e->getMessage());
            }
        }

        // Also drop any other indexes that match our patterns
        foreach ($indices as $index) {
            $indexName = $index['index'];

            // Skip system indexes
            if (str_starts_with($indexName, '.')) {
                continue;
            }

            // Drop indexes that match our naming patterns but weren't in our list
            $patterns = ['clients', 'invoices', 'quotes', 'credits', 'expenses', 'vendors', 'projects', 'tasks', 'purchase_orders', 'recurring'];
            foreach ($patterns as $pattern) {
                if (str_contains($indexName, $pattern) && !in_array($indexName, $indexesToDrop)) {
                    try {
                        $client->indices()->delete(['index' => $indexName]);
                        $this->line("  ✓ Dropped additional index: {$indexName}");
                        $droppedCount++;
                    } catch (\Exception $e) {
                        $this->warn("  ✗ Failed to drop index {$indexName}: " . $e->getMessage());
                    }
                    break;
                }
            }
        }

        $this->info("\n  Total indexes dropped: {$droppedCount}");
    }

    /**
     * Run elastic migrations
     */
    protected function runMigrations(): void
    {
        $this->line('  Running: php artisan elastic:migrate');

        try {
            Artisan::call('elastic:migrate', [], $this->getOutput());
            $this->info('  ✓ Migrations completed successfully');
        } catch (\Exception $e) {
            $this->error('  ✗ Migration failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Import data for all searchable models
     */
    protected function importData(): void
    {
        $this->line('  Importing searchable data for all models...');
        $this->newLine();

        $totalImported = 0;

        foreach ($this->searchableModels as $modelClass => $indexName) {
            $modelName = class_basename($modelClass);

            try {
                $this->line("  Importing {$modelName}...");

                // Use Laravel Scout's import command
                Artisan::call('scout:import', [
                    'model' => $modelClass,
                ], $this->getOutput());

                // Get count of records
                $count = $modelClass::count();
                $this->info("    ✓ Imported {$count} {$modelName} records");
                $totalImported += $count;
            } catch (\Exception $e) {
                $this->error("    ✗ Failed to import {$modelName}: " . $e->getMessage());
            }
        }

        $this->newLine();
        $this->info("  Total records imported: {$totalImported}");
    }
}
