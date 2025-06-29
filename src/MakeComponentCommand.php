<?php

namespace Wowpack\StructuredLivewire;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use function Laravel\Prompts\select;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

class MakeComponentCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'make:structured-livewire 
                           {name? : The name of the component}
                           {--group= : The group to create the component in}
                           {--force : Overwrite existing component}
                           {--inline : Create an inline component}
                           {--test : Generate an accompanying test file}
                           {--pest : Generate an accompanying Pest test file}
                           {--stub= : Use a custom stub file}
                           {--preview : Preview the component structure without creating files}';

    /**
     * The console command description.
     */
    protected $description = 'Create a new Livewire component in a structured directory';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $this->validateConfiguration();

            $componentName = $this->getComponentName();
            $groupConfig = $this->getGroupConfiguration();
            $componentPath = $this->buildComponentPath($componentName, $groupConfig);

            if ($this->option('preview')) {
                $this->previewComponent($componentPath, $groupConfig);
                return self::SUCCESS;
            }

            if ($this->componentExists($componentPath) && !$this->shouldOverwrite()) {
                $this->warn('Component creation cancelled.');
                return self::SUCCESS;
            }

            $this->createComponent($componentPath, $componentName, $groupConfig);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to create component: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Validate the structured-livewire configuration
     */
    private function validateConfiguration(): void
    {
        $config = Config::get('structured-livewire');

        if (empty($config)) {
            throw new \RuntimeException(
                'Structured Livewire configuration not found. ' .
                    'Run: php artisan vendor:publish --tag=structured-livewire-config'
            );
        }

        if (empty($config['groups'])) {
            throw new \RuntimeException(
                'No groups configured in structured-livewire.groups. ' .
                    'Please configure at least one group in your config file.'
            );
        }

        foreach ($config['groups'] as $key => $group) {
            if (!isset($group['location'])) {
                throw new \RuntimeException("Group '{$key}' is missing required 'location' configuration.");
            }
        }
    }

    /**
     * Get component name from argument or prompt
     */
    private function getComponentName(): string
    {
        $name = $this->argument('name');

        if (empty($name)) {
            $name = text(
                label: 'What should the component be named?',
                placeholder: 'e.g., UserProfile, BlogPost, AdminDashboard',
                required: true,
                validate: fn(string $value) => $this->validateComponentName($value)
            );
        }

        return $this->formatComponentName($name);
    }

    /**
     * Validate component name
     */
    private function validateComponentName(string $name): ?string
    {
        if (empty(trim($name))) {
            return 'Component name is required.';
        }

        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9\/\-_]*$/', $name)) {
            return 'Component name must start with a letter and contain only letters, numbers, slashes, hyphens, and underscores.';
        }

        return null;
    }

    /**
     * Format component name to proper case
     */
    private function formatComponentName(string $name): string
    {
        return Str::of($name)
            ->trim()
            ->replace(['\\', '/'], '/')
            ->explode('/')
            ->map(fn($part) => Str::studly($part))
            ->implode('/');
    }

    /**
     * Get group configuration
     */
    private function getGroupConfiguration(): array
    {
        $groups = Config::get('structured-livewire.groups', []);
        $selectedGroup = $this->option('group');

        if (empty($selectedGroup)) {
            $groupOptions = [];
            foreach ($groups as $key => $group) {
                $description = isset($group['description']) ? " - {$group['description']}" : '';
                $groupOptions[$key] = "{$key} ({$group['location']}){$description}";
            }

            $selectedGroup = select(
                label: 'Which group should contain this component?',
                options: $groupOptions,
                default: array_key_first($groups)
            );
        }

        if (!isset($groups[$selectedGroup])) {
            throw new \InvalidArgumentException("Group '{$selectedGroup}' not found in configuration.");
        }

        return array_merge($groups[$selectedGroup], ['key' => $selectedGroup]);
    }

    /**
     * Build the full component path
     */
    private function buildComponentPath(string $componentName, array $groupConfig): string
    {
        $location = rtrim($groupConfig['location'], '/');
        return "{$location}/{$componentName}";
    }

    /**
     * Check if component already exists
     */
    private function componentExists(string $componentPath): bool
    {
        $baseDirectory = Config::get('structured-livewire.livewire-components-directory', app_path('Http/Livewire'));
        $fullPath = "{$baseDirectory}/{$componentPath}.php";

        return File::exists($fullPath);
    }

    /**
     * Ask if user wants to overwrite existing component
     */
    private function shouldOverwrite(): bool
    {
        if ($this->option('force')) {
            return true;
        }

        return confirm(
            label: 'A component with this name already exists. Do you want to overwrite it?',
            default: false
        );
    }

    /**
     * Preview component without creating files
     */
    private function previewComponent(string $componentPath, array $groupConfig): void
    {
        $this->info('🔍 <fg=cyan>Component Preview</fg=cyan>');
        $this->newLine();

        $baseDirectory = Config::get('structured-livewire.livewire-components-directory', app_path('Http/Livewire'));
        $fullPath = "{$baseDirectory}/{$componentPath}.php";
        $componentTag = $this->generateComponentTag($componentPath, $groupConfig);

        $this->table(
            ['Property', 'Value'],
            [
                ['Component Name', basename($componentPath)],
                ['Group', $groupConfig['key']],
                ['Location', $groupConfig['location']],
                ['Full Path', $fullPath],
                ['Component Tag', $componentTag],
                ['Blade View', $this->getBladeViewPath($componentPath, $groupConfig)],
                ['Test File', $this->getTestFilePath($componentPath)],
            ]
        );

        if (isset($groupConfig['suffix'])) {
            $this->info("📝 Component will be registered with suffix: <fg=yellow>{$groupConfig['suffix']}</fg=yellow>");
        }

        $this->info('✨ Run without --preview to create the component');
    }

    /**
     * Generate component tag
     */
    private function generateComponentTag(string $componentPath, array $groupConfig): string
    {
        $tag = Str::of($componentPath)
            ->replace('/', '.')
            ->kebab()
            ->toString();

        if (!empty($groupConfig['suffix'])) {
            $tag .= '-' . $groupConfig['suffix'];
        }

        return $tag;
    }

    /**
     * Get blade view path
     */
    private function getBladeViewPath(string $componentPath, array $groupConfig): string
    {
        $viewPath = Str::of($componentPath)
            ->replace('/', '.')
            ->kebab()
            ->toString();

        return "livewire.{$viewPath}";
    }

    /**
     * Get test file path
     */
    private function getTestFilePath(string $componentPath): string
    {
        return "tests/Feature/Livewire/" . str_replace('/', '/', $componentPath) . "Test.php";
    }

    /**
     * Create the component
     */
    private function createComponent(string $componentPath, string $componentName, array $groupConfig): void
    {
        $this->info("🚀 Creating component: <fg=green>{$componentName}</fg=green>");
        $this->newLine();

        // Build arguments for make:livewire command
        $makeArguments = ['name' => $componentPath];
        $makeOptions = [];

        // Pass through relevant options
        if ($this->option('force')) {
            $makeOptions['--force'] = true;
        }

        if ($this->option('inline')) {
            $makeOptions['--inline'] = true;
        }

        if ($this->option('test')) {
            $makeOptions['--test'] = true;
        }

        if ($this->option('pest')) {
            $makeOptions['--pest'] = true;
        }

        if ($this->option('stub')) {
            $makeOptions['--stub'] = $this->option('stub');
        }

        // Create the component
        $result = $this->call('make:livewire', $makeArguments, $makeOptions);

        if ($result === 0) {
            $this->showSuccessMessage($componentPath, $groupConfig);
        } else {
            throw new \RuntimeException('Failed to create Livewire component.');
        }
    }

    /**
     * Show success message with component details
     */
    private function showSuccessMessage(string $componentPath, array $groupConfig): void
    {
        $componentTag = $this->generateComponentTag($componentPath, $groupConfig);

        $this->newLine();
        $this->info('✅ <fg=green>Component created successfully!</fg=green>');
        $this->newLine();

        $this->info('📋 <fg=cyan>Component Details:</fg=cyan>');
        $this->info("   • Name: <fg=yellow>" . basename($componentPath) . "</fg=yellow>");
        $this->info("   • Group: <fg=yellow>{$groupConfig['key']}</fg=yellow>");
        $this->info("   • Tag: <fg=yellow><{$componentTag} /></fg=yellow>");

        if (!empty($groupConfig['suffix'])) {
            $this->info("   • Suffix: <fg=yellow>{$groupConfig['suffix']}</fg=yellow>");
        }

        $this->newLine();
        $this->comment('💡 Usage in Blade templates:');
        $this->comment("   <{$componentTag} />");
        $this->comment("   @livewire('{$componentTag}')");

        $this->newLine();
        $this->comment('🔄 Clear component cache if needed:');
        $this->comment('   php artisan structured-livewire:clear-cache');
    }
}
