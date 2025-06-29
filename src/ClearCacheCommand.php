<?php

namespace Wowpack\StructuredLivewire;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

class ClearCacheCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'structured-livewire:clear-cache 
                           {--force : Force cache clearing without confirmation}
                           {--group= : Clear cache for specific group only}
                           {--stats : Show cache statistics before clearing}';

    /**
     * The console command description.
     */
    protected $description = 'Clear the Structured Livewire component discovery cache';

    /**
     * Cache key prefix
     */
    private const CACHE_PREFIX = 'structured-livewire';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            if ($this->option('stats')) {
                $this->showCacheStats();
                
                if (!$this->shouldProceed()) {
                    return self::SUCCESS;
                }
            }

            $specificGroup = $this->option('group');
            
            if ($specificGroup) {
                $this->clearGroupCache($specificGroup);
            } else {
                $this->clearAllCache();
            }

            return self::SUCCESS;
            
        } catch (\Throwable $e) {
            $this->error('Failed to clear cache: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Show cache statistics
     */
    private function showCacheStats(): void
    {
        $this->info('📊 <fg=cyan>Structured Livewire Cache Statistics</fg=cyan>');
        $this->newLine();

        $cacheKeys = $this->getAllCacheKeys();
        
        if (empty($cacheKeys)) {
            $this->warn('No cache entries found.');
            return;
        }

        $this->table(
            ['Cache Key', 'Size (entries)', 'Type'],
            collect($cacheKeys)->map(function ($key) {
                $data = Cache::get($key);
                $size = is_countable($data) ? count($data) : (is_string($data) ? strlen($data) : 1);
                $type = $this->getCacheKeyType($key);
                
                return [
                    Str::limit($key, 50),
                    $size,
                    $type
                ];
            })->toArray()
        );

        $this->newLine();
        $this->info("Total cache entries: <fg=yellow>{$this->countTotalCacheEntries($cacheKeys)}</fg=yellow>");
        $this->newLine();
    }

    /**
     * Get all cache keys related to structured livewire
     */
    private function getAllCacheKeys(): array
    {
        // Get the master key list if it exists
        $masterKeys = Cache::get(self::CACHE_PREFIX . '-keys', []);
        
        // Also try to find keys by pattern (fallback)
        $patternKeys = $this->findCacheKeysByPattern();
        
        return array_unique(array_merge($masterKeys, $patternKeys));
    }

    /**
     * Find cache keys by pattern (fallback method)
     */
    private function findCacheKeysByPattern(): array
    {
        $keys = [];
        $groups = Config::get('structured-livewire.groups', []);
        
        // Add common cache keys
        $keys[] = self::CACHE_PREFIX . '-files';
        
        // Add group-specific cache keys
        foreach ($groups as $group) {
            if (isset($group['location'])) {
                $keys[] = self::CACHE_PREFIX . '-files-' . md5($group['location']);
            }
        }
        
        // Filter to only existing keys
        return array_filter($keys, fn($key) => Cache::has($key));
    }

    /**
     * Get cache key type for display
     */
    private function getCacheKeyType(string $key): string
    {
        if (Str::contains($key, '-files-')) {
            return 'Group Files';
        } elseif (Str::contains($key, '-files')) {
            return 'Root Files';
        } elseif (Str::contains($key, '-keys')) {
            return 'Key Registry';
        }
        
        return 'Unknown';
    }

    /**
     * Count total cache entries
     */
    private function countTotalCacheEntries(array $cacheKeys): int
    {
        return collect($cacheKeys)->sum(function ($key) {
            $data = Cache::get($key);
            return is_countable($data) ? count($data) : 1;
        });
    }

    /**
     * Check if user wants to proceed
     */
    private function shouldProceed(): bool
    {
        if ($this->option('force')) {
            return true;
        }

        return $this->confirm('Do you want to proceed with clearing the cache?', true);
    }

    /**
     * Clear cache for a specific group
     */
    private function clearGroupCache(string $groupName): void
    {
        $groups = Config::get('structured-livewire.groups', []);
        $targetGroup = collect($groups)->firstWhere('location', $groupName);
        
        if (!$targetGroup) {
            $availableGroups = collect($groups)->pluck('location')->implode(', ');
            $this->error("Group '{$groupName}' not found.");
            
            if (!empty($availableGroups)) {
                $this->info("Available groups: {$availableGroups}");
            }
            
            return;
        }

        $this->info("🧹 Clearing cache for group: <fg=yellow>{$groupName}</fg=yellow>");
        
        $groupCacheKey = self::CACHE_PREFIX . '-files-' . md5($groupName);
        
        if (Cache::has($groupCacheKey)) {
            $entriesCount = count(Cache::get($groupCacheKey, []));
            Cache::forget($groupCacheKey);
            
            // Update master keys list
            $this->updateMasterKeysList($groupCacheKey, 'remove');
            
            $this->info("✅ Cleared {$entriesCount} cached entries for group '{$groupName}'");
        } else {
            $this->warn("No cache found for group '{$groupName}'");
        }
    }

    /**
     * Clear all cache entries
     */
    private function clearAllCache(): void
    {
        $this->info('🧹 <fg=cyan>Clearing all Structured Livewire cache...</fg=cyan>');
        
        $cacheKeys = $this->getAllCacheKeys();
        
        if (empty($cacheKeys)) {
            $this->warn('No cache entries found to clear.');
            return;
        }

        $totalEntries = $this->countTotalCacheEntries($cacheKeys);
        $clearedKeys = 0;

        $progressBar = $this->output->createProgressBar(count($cacheKeys));
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        $progressBar->setMessage('Starting...');

        foreach ($cacheKeys as $key) {
            $progressBar->setMessage("Clearing: " . Str::limit($key, 30));
            Cache::forget($key);
            $clearedKeys++;
            $progressBar->advance();
        }

        $progressBar->setMessage('Completed!');
        $progressBar->finish();
        
        $this->newLine(2);
        $this->info("✅ Successfully cleared <fg=green>{$clearedKeys}</fg=green> cache keys containing <fg=green>{$totalEntries}</fg=green> total entries");
        
        // Show cache size reduction if possible
        $this->showCacheCleanupSummary($totalEntries);
    }

    /**
     * Update the master keys list
     */
    private function updateMasterKeysList(string $key, string $action = 'add'): void
    {
        $masterKey = self::CACHE_PREFIX . '-keys';
        $keys = Cache::get($masterKey, []);
        
        if ($action === 'add' && !in_array($key, $keys)) {
            $keys[] = $key;
            Cache::put($masterKey, $keys, 86400); // 24 hours
        } elseif ($action === 'remove') {
            $keys = array_filter($keys, fn($k) => $k !== $key);
            
            if (!empty($keys)) {
                Cache::put($masterKey, array_values($keys), 86400);
            } else {
                Cache::forget($masterKey);
            }
        }
    }

    /**
     * Show cache cleanup summary
     */
    private function showCacheCleanupSummary(int $totalEntries): void
    {
        $this->newLine();
        $this->info('📋 <fg=cyan>Cache Cleanup Summary:</fg=cyan>');
        $this->info("   • Component discovery cache cleared");
        $this->info("   • {$totalEntries} cached component entries removed");
        $this->info("   • Next component discovery will rebuild cache");
        
        if (app()->environment('production')) {
            $this->warn('💡 Consider running this during low-traffic periods in production');
        }
        
        $this->newLine();
        $this->comment('You can verify the cache was cleared by running:');
        $this->comment('  php artisan structured-livewire:clear-cache --stats');
    }
}