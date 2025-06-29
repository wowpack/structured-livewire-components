<?php

namespace Wowpack\StructuredLivewire;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Illuminate\Support\Facades\Config;
use ReflectionClass;
use ReflectionException;
use Livewire\Component;

class StructuredLivewireServiceProvider extends ServiceProvider
{
    private const CACHE_PREFIX = 'structured-livewire';
    private const CACHE_TTL = 3600; // 1 hour
    
    /**
     * Get the root path for Livewire components
     */
    private function getRootPath(): string
    {
        $directory = config('structured-livewire.livewire-components-directory');
        
        if (empty($directory)) {
            throw new \InvalidArgumentException('Livewire components directory not configured');
        }
        
        return Str::of($directory)->rtrim('/')->append('/')->toString();
    }

    /**
     * Build path with optional subdirectory
     */
    private function getPath(?string $directory = null): string
    {
        $path = $this->getRootPath();

        if (!empty($directory)) {
            $path = Str::of($directory)
                ->trim('/')
                ->prepend($path)
                ->append('/')
                ->toString();
        }

        return $path;
    }

    /**
     * Get all PHP files from directory with caching support
     */
    private function getFiles(?string $directory = null): Collection
    {
        $cacheKey = $this->getCacheKey('files', $directory);
        
        if ($this->shouldUseCache() && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }
        
        try {
            $path = $this->getPath($directory);
            
            if (!File::isDirectory(rtrim($path, '/'))) {
                Log::warning("Structured Livewire: Directory does not exist: {$path}");
                return collect();
            }

            $files = File::glob($path . '*.php');
            $filesFromSubdirectories = File::glob($path . '**/*.php');

            $result = Collection::make([...$files, ...$filesFromSubdirectories])
                ->filter(fn($file) => $this->isValidPhpFile($file))
                ->map(fn(string $value) => $this->getFileInfo($value, $directory))
                ->filter(fn($info) => $this->isValidLivewireComponent($info['class_name']));
                
            if ($this->shouldUseCache()) {
                Cache::put($cacheKey, $result, self::CACHE_TTL);
            }
            
            return $result;
            
        } catch (\Throwable $e) {
            Log::error("Structured Livewire: Error scanning directory {$directory}: " . $e->getMessage());
            return collect();
        }
    }

    /**
     * Extract file information and metadata
     */
    private function getFileInfo(string $path, ?string $directory): array
    {
        $name = File::name($path);
        $baseName = File::basename($path);

        $relativeDirectory = Str::of(File::dirname($path))
            ->append('/')
            ->replace($this->getPath($directory), '')
            ->trim('/');

        $title = $relativeDirectory->isEmpty() ? $name : $relativeDirectory->append('/' . $name);

        $className = $this->pathToClassName($path);

        return [
            'base_name' => $baseName,
            'name' => $name,
            'directory' => $relativeDirectory->toString(),
            'title' => $title->toString(),
            'tag' => $this->generateComponentTag($title->toString()),
            'class_name' => $className,
            'path' => $path,
        ];
    }

    /**
     * Convert file path to fully qualified class name
     */
    private function pathToClassName(string $path): string
    {
        return Str::of($path)
            ->replace(app_path(''), '')
            ->prepend('\\App')
            ->replace('/', '\\')
            ->rtrim('.php')
            ->toString();
    }

    /**
     * Generate component tag from title
     */
    private function generateComponentTag(string $title): string
    {
        return Str::of($title)
            ->replace('/', '.')
            ->kebab()
            ->toString();
    }

    /**
     * Register components for a specific location
     */
    private function registerComponents(?string $location = null, ?string $suffix = null): void
    {
        $components = $this->getFiles($location);
        
        if ($components->isEmpty()) {
            Log::info("Structured Livewire: No components found in location: " . ($location ?? 'root'));
            return;
        }

        $registered = 0;
        $failed = 0;

        $components->each(function (array $data) use ($suffix, &$registered, &$failed) {
            try {
                $tag = $data['tag'] . (!empty($suffix) ? '-' . $suffix : '');
                
                if (Livewire::hasComponent($tag)) {
                    Log::warning("Structured Livewire: Component '{$tag}' already registered, skipping");
                    return;
                }
                
                Livewire::component($tag, $data['class_name']);
                $registered++;
                
                Log::debug("Structured Livewire: Registered component '{$tag}' -> {$data['class_name']}");
                
            } catch (\Throwable $e) {
                $failed++;
                Log::error("Structured Livewire: Failed to register component {$data['class_name']}: " . $e->getMessage());
            }
        });

        Log::info("Structured Livewire: Registered {$registered} components" . ($failed > 0 ? ", {$failed} failed" : ""));
    }

    /**
     * Validate if file is a valid PHP file
     */
    private function isValidPhpFile(string $path): bool
    {
        return File::exists($path) && 
               Str::endsWith($path, '.php') && 
               File::isReadable($path);
    }

    /**
     * Check if class is a valid Livewire component
     */
    private function isValidLivewireComponent(string $className): bool
    {
        try {
            if (!class_exists($className)) {
                return false;
            }
            
            $reflection = new ReflectionClass($className);
            
            return $reflection->isSubclassOf(Component::class) && 
                   !$reflection->isAbstract() && 
                   $reflection->isInstantiable();
                   
        } catch (ReflectionException $e) {
            Log::debug("Structured Livewire: Invalid component class {$className}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate cache key
     */
    private function getCacheKey(string $type, ?string $directory = null): string
    {
        $key = self::CACHE_PREFIX . '-' . $type;
        if ($directory) {
            $key .= '-' . md5($directory);
        }
        return $key;
    }

    /**
     * Determine if caching should be used
     */
    private function shouldUseCache(): bool
    {
        return config('structured-livewire.cache_enabled', app()->environment('production'));
    }

    /**
     * Clear all cached data
     */
    public function clearCache(): void
    {
        $keys = Cache::get(self::CACHE_PREFIX . '-keys', []);
        
        foreach ($keys as $key) {
            Cache::forget($key);
        }
        
        Cache::forget(self::CACHE_PREFIX . '-keys');
        Log::info('Structured Livewire: Cache cleared');
    }

    /**
     * Validate configuration
     */
    private function validateConfig(): void
    {
        $config = config('structured-livewire');
        
        if (empty($config['livewire-components-directory'])) {
            throw new \InvalidArgumentException('structured-livewire.livewire-components-directory must be configured');
        }
        
        if (!is_array($config['groups'] ?? [])) {
            throw new \InvalidArgumentException('structured-livewire.groups must be an array');
        }
        
        foreach ($config['groups'] as $index => $group) {
            if (!is_array($group) || !isset($group['location'])) {
                throw new \InvalidArgumentException("structured-livewire.groups.{$index} must have a 'location' key");
            }
        }
    }

    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/structured-livewire.php',
            'structured-livewire'
        );

        try {
            $this->validateConfig();
            
            $groups = Config::get('structured-livewire.groups', []);

            if (empty($groups)) {
                Log::info('Structured Livewire: No groups configured, registering components from root directory');
                $this->registerComponents();
            } else {
                foreach ($groups as $group) {
                    $this->registerComponents(
                        $group['location'] ?? null, 
                        $group['suffix'] ?? null
                    );
                }
            }
            
        } catch (\Throwable $e) {
            Log::error('Structured Livewire: Registration failed: ' . $e->getMessage());
            
            if (!app()->environment('production')) {
                throw $e;
            }
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/structured-livewire.php' => config_path('structured-livewire.php'),
        ], 'structured-livewire-config');

        // Register console commands if running in console
        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeComponentCommand::class,
                ClearCacheCommand::class,
            ]);
        }
    }
}