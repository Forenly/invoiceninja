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
use Illuminate\Support\Facades\DB;

class RebuildElasticIndexes extends Command
{
    protected $signature = 'elastic:rebuild
                            {--model= : Rebuild only a specific model (e.g., Invoice, Client)}
                            {--force : Force the operation without confirmation}
                            {--dry-run : Show what would be done without making changes}
                            {--chunk=500 : Number of records to import per chunk}
                            {--wait : Wait for our queued jobs to complete after each model (recommended for production)}
                            {--no-queue : Import synchronously instead of queueing (slower but safer)}';

    protected $description = 'Rebuild Elasticsearch indexes one at a time to minimize production impact';

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

    public function handle(): int
    {
        $this->info('===========================================');
        $this->info('  Elasticsearch Index Rebuild (One-by-One)');
        $this->info('===========================================');
        $this->newLine();

        if (!$this->checkElasticsearchConnection()) {
            $this->error('Cannot connect to Elasticsearch. Please check your configuration.');
            return self::FAILURE;
        }

        // Handle single model rebuild
        if ($modelName = $this->option('model')) {
            return $this->rebuildSingleModel($modelName);
        }

        // Dry run mode
        if ($this->option('dry-run')) {
            return $this->performDryRun();
        }

        // Get confirmation for full rebuild
        if (!$this->option('force')) {
            $this->warn('This command will rebuild ALL Elasticsearch indexes ONE AT A TIME:');
            $this->info('  • Each index will be dropped, migrated, and re-imported sequentially');
            $this->info('  • Search will be unavailable for each model during its rebuild');
            $this->info('  • Other models remain searchable while one rebuilds');
            
            if ($this->option('wait')) {
                $this->info('  • Will WAIT for our jobs to complete after each model (production-safe)');
            } else {
                $this->warn('  • WARNING: Jobs will queue up async (use --wait for production)');
            }
            
            if ($this->option('no-queue')) {
                $this->info('  • Using SYNCHRONOUS import (slower but guaranteed)');
            }
            
            $this->newLine();

            $totalRecords = $this->getTotalRecordCount();
            $this->warn("Total records to re-index: {$totalRecords}");
            $this->newLine();

            if (!$this->confirm('Do you want to rebuild all indexes?', false)) {
                $this->info('Operation cancelled.');
                return self::SUCCESS;
            }
        }

        $this->newLine();

        // Rebuild all models one by one
        $totalModels = count($this->searchableModels);
        $currentModel = 0;
        $startTime = now();

        foreach ($this->searchableModels as $modelClass => $indexName) {
            $currentModel++;
            $modelName = class_basename($modelClass);

            $this->newLine();
            $this->info("[{$currentModel}/{$totalModels}] Rebuilding {$modelName}...");
            $this->line("Index: {$indexName}");

            if (!$this->rebuildIndex($modelClass, $indexName)) {
                $this->error("Failed to rebuild {$modelName}. Stopping.");
                return self::FAILURE;
            }
        }

        $this->newLine();
        $duration = now()->diffForHumans($startTime, true);
        $this->info('✓ All indexes rebuilt successfully!');
        $this->info("Total time: {$duration}");
        return self::SUCCESS;
    }

    protected function rebuildSingleModel(string $modelName): int
    {
        // Find the model class
        $modelClass = null;
        foreach ($this->searchableModels as $class => $indexName) {
            if (class_basename($class) === $modelName) {
                $modelClass = $class;
                break;
            }
        }

        if (!$modelClass) {
            $this->error("Model '{$modelName}' not found.");
            $this->info('Available models: ' . implode(', ', array_map('class_basename', array_keys($this->searchableModels))));
            return self::FAILURE;
        }

        $indexName = $this->searchableModels[$modelClass];
        
        try {
            $recordCount = $modelClass::count();
        } catch (\Exception $e) {
            $this->warn("Could not count records: " . $e->getMessage());
            $recordCount = 0;
        }

        $this->newLine();
        $this->info("Rebuilding {$modelName}");
        $this->line("Index: {$indexName}");
        $this->line("Records: {$recordCount}");
        $this->line("Chunk size: {$this->option('chunk')}");
        $this->line("Mode: " . ($this->option('no-queue') ? 'Synchronous' : 'Queued'));
        $this->newLine();

        if (!$this->option('force') && !$this->option('dry-run')) {
            if (!$this->confirm('Continue with rebuild?', true)) {
                $this->info('Operation cancelled.');
                return self::SUCCESS;
            }
        }

        if ($this->option('dry-run')) {
            $this->info('DRY RUN - Would rebuild:');
            $this->line("  1. Drop index: {$indexName}");
            $this->line("  2. Run elastic:migrate for {$modelName}");
            $this->line("  3. Import {$recordCount} {$modelName} records");
            return self::SUCCESS;
        }

        $startTime = now();

        if ($this->rebuildIndex($modelClass, $indexName)) {
            $duration = now()->diffForHumans($startTime, true);
            $this->newLine();
            $this->info("✓ {$modelName} rebuilt successfully in {$duration}!");
            return self::SUCCESS;
        }

        $this->error("✗ Failed to rebuild {$modelName}");
        return self::FAILURE;
    }

    protected function performDryRun(): int
    {
        $this->info('DRY RUN - The following would be rebuilt:');
        $this->newLine();

        foreach ($this->searchableModels as $modelClass => $indexName) {
            $modelName = class_basename($modelClass);
            
            try {
                $recordCount = $modelClass::count();
            } catch (\Exception $e) {
                $recordCount = 0;
            }

            $this->line("• {$modelName}");
            $this->line("  Index: {$indexName}");
            $this->line("  Records: {$recordCount}");
            $this->newLine();
        }

        $this->info('No changes made (dry run mode)');
        return self::SUCCESS;
    }

    protected function rebuildIndex(string $modelClass, string $indexName): bool
    {
        $modelName = class_basename($modelClass);
        $client = $this->getElasticsearchClient();

        try {
            // Step 1: Drop the index (with safe existence check)
            $this->line("  [1/3] Dropping index {$indexName}...");
            
            try {
                $indexExists = $client->indices()->exists(['index' => $indexName]);
                
                if ($indexExists) {
                    try {
                        $client->indices()->delete(['index' => $indexName]);
                        $this->info("    ✓ Index dropped");
                    } catch (\Exception $deleteException) {
                        $this->warn("    ⚠ Failed to delete index: " . $deleteException->getMessage());
                        $this->line("    - Continuing with migration...", 'comment');
                    }
                } else {
                    $this->line("    - Index does not exist (will be created)", 'comment');
                }
            } catch (\Exception $existsException) {
                $this->warn("    ⚠ Could not check index existence: " . $existsException->getMessage());
                $this->line("    - Continuing with migration...", 'comment');
            }

            // Step 2: Run migration for this specific index
            $this->line("  [2/3] Running elastic migration...");
            
            try {
                Artisan::call('elastic:migrate', [], $this->getOutput());
                $this->info("    ✓ Migration completed");
            } catch (\Exception $migrateException) {
                $this->error("    ✗ Migration failed: " . $migrateException->getMessage());
                return false;
            }

            // Step 3: Import data
            $this->line("  [3/3] Importing {$modelName} data...");
            
            try {
                $recordCount = $modelClass::count();
            } catch (\Exception $countException) {
                $this->warn("    ⚠ Could not count records: " . $countException->getMessage());
                $recordCount = 0;
            }

            if ($recordCount > 0) {
                try {
                    if ($this->option('no-queue')) {
                        // Synchronous import - process in chunks
                        $this->line("    - Using synchronous import (no queue)", 'comment');
                        $this->importSynchronously($modelClass, $recordCount);
                    } else {
                        // Queue-based import with tracking
                        $this->importWithQueueTracking($modelClass, $recordCount);
                    }
                    $this->info("    ✓ Import completed for {$recordCount} records");
                } catch (\Exception $importException) {
                    $this->error("    ✗ Import failed: " . $importException->getMessage());
                    return false;
                }
            } else {
                $this->line("    - No records to import", 'comment');
            }

            return true;

        } catch (\Exception $e) {
            $this->error("    ✗ Unexpected error: " . $e->getMessage());
            $this->line("    Stack trace: " . $e->getTraceAsString());
            return false;
        }
    }
    
    protected function importSynchronously(string $modelClass, int $totalRecords): void
    {
        $chunkSize = (int) $this->option('chunk');
        $chunks = ceil($totalRecords / $chunkSize);
        $processed = 0;
        
        $this->line("    - Processing {$chunks} chunks of {$chunkSize} records each", 'comment');
        
        $modelClass::chunk($chunkSize, function ($models) use (&$processed, $totalRecords) {
            $models->searchable();
            $processed += $models->count();
            $percentage = round(($processed / $totalRecords) * 100);
            $this->line("    - Indexed {$processed}/{$totalRecords} ({$percentage}%)", 'comment');
        });
    }
    
    protected function importWithQueueTracking(string $modelClass, int $recordCount): void
    {
        $chunkSize = (int) $this->option('chunk');
        $expectedJobCount = ceil($recordCount / $chunkSize);
        
        // Get baseline queue size BEFORE dispatching our jobs
        $queueName = config('scout.queue.queue', 'scout');
        $connection = config('scout.queue.connection', config('queue.default'));
        
        try {
            $baselineJobCount = $this->getPendingJobCount($connection, $queueName);
        } catch (\Exception $e) {
            $baselineJobCount = 0;
            $this->line("    - Cannot track queue baseline: " . $e->getMessage(), 'comment');
        }
        
        $this->line("    - Baseline queue size: {$baselineJobCount} jobs", 'comment');
        $this->line("    - Dispatching ~{$expectedJobCount} import jobs (chunks of {$chunkSize})", 'comment');
        
        // Dispatch the import jobs
        Artisan::call('scout:import', [
            'model' => $modelClass,
            '--chunk' => $chunkSize,
        ], $this->getOutput());
        
        $this->line("    - Jobs dispatched to queue", 'comment');
        
        // If --wait flag is set, monitor OUR jobs
        if ($this->option('wait')) {
            $this->waitForOurJobsToComplete($connection, $queueName, $baselineJobCount, $expectedJobCount);
        }
    }
    
    protected function waitForOurJobsToComplete(
        string $connection, 
        string $queueName, 
        int $baselineJobCount,
        int $expectedJobCount
    ): void {
        $this->newLine();
        $this->line("  Waiting for our {$expectedJobCount} jobs to complete...");
        $this->line("  (Queue may have other jobs - we're tracking the delta)", 'comment');
        
        $maxWaitSeconds = 600; // 10 minutes max
        $startTime = time();
        $lastReportedDelta = -1;
        $stableCount = 0;
        $targetJobCount = $baselineJobCount; // We want to return to baseline (or lower)
        
        while ((time() - $startTime) < $maxWaitSeconds) {
            try {
                $currentJobCount = $this->getPendingJobCount($connection, $queueName);
                $delta = $currentJobCount - $baselineJobCount;
                
                // If current count is at or below baseline, our jobs are done
                if ($currentJobCount <= $baselineJobCount) {
                    $this->info("  ✓ Our jobs completed (queue: {$currentJobCount}, baseline: {$baselineJobCount})");
                    return;
                }
                
                // Report progress if delta changed
                if ($delta !== $lastReportedDelta) {
                    $this->line("    - Our jobs remaining: ~{$delta} (total queue: {$currentJobCount})", 'comment');
                    $lastReportedDelta = $delta;
                    $stableCount = 0;
                } else {
                    $stableCount++;
                }
                
                // If queue size is stable at a reasonable level for 30 seconds, assume we're done
                // (handles case where other jobs are being added at similar rate)
                if ($stableCount >= 15 && $delta <= $expectedJobCount) {
                    $this->info("  ✓ Queue stabilized with {$delta} jobs above baseline - assuming complete");
                    return;
                }
                
                sleep(2);
            } catch (\Exception $e) {
                $this->warn("  ⚠ Could not check queue status: " . $e->getMessage());
                $this->line("    - Waiting 10 seconds before continuing...", 'comment');
                sleep(10);
                return;
            }
        }
        
        try {
            $finalCount = $this->getPendingJobCount($connection, $queueName);
            $this->warn("  ⚠ Timeout after {$maxWaitSeconds}s (queue: {$finalCount}, baseline: {$baselineJobCount})");
        } catch (\Exception $e) {
            $this->warn("  ⚠ Timeout after {$maxWaitSeconds}s - continuing");
        }
    }
    
    protected function getPendingJobCount(string $connection, string $queueName): int
    {
        $driver = config("queue.connections.{$connection}.driver");
        
        switch ($driver) {
            case 'database':
                return DB::table(config("queue.connections.{$connection}.table", 'jobs'))
                    ->where('queue', $queueName)
                    ->count();
                
            case 'redis':
                $redis = app('redis')->connection(config("queue.connections.{$connection}.connection"));
                $prefix = config('database.redis.options.prefix', '');
                return $redis->llen($prefix . 'queues:' . $queueName);
                
            case 'sync':
                return 0;
                
            default:
                throw new \Exception("Cannot check queue size for driver: {$driver}");
        }
    }

    protected function getTotalRecordCount(): int
    {
        $total = 0;
        foreach ($this->searchableModels as $modelClass => $indexName) {
            try {
                $total += $modelClass::count();
            } catch (\Exception $e) {
                // Skip if model fails to count
            }
        }
        return $total;
    }

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

    protected function getElasticsearchClient()
    {
        return ClientBuilder::fromConfig(config('elastic.client.connections.default'));
    }
}
