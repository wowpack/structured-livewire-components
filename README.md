# Structured Livewire Components

A Laravel package to structure Livewire components in a more organized way.

## Installation

Require the package via Composer:

```bash
composer require wowpack/structured-livewire-components
```

## Configuration

Publish the configuration file using the following Artisan command:

```bash
php artisan vendor:publish --provider="Wowpack\StructuredLivewire\StructuredLivewireServiceProvider" --tag=config
```

This will publish the `structured-livewire.php` config file to your application's `config` directory.

## Configuration Options

The configuration file contains the following options:

- `livewire-components-directory`: The base directory where Livewire components are stored. Default is `app/Livewire`.
- `groups`: Define groups of components with their locations and suffixes.

Example:

```php
return [
    'livewire-components-directory' => app_path('Livewire'),
    'groups' => [
        'pages' => [
            'location' => 'Components',
            'suffix' => 'components',
        ],
        'components' => [
            'location' => 'Components',
            'suffix' => 'components',
        ],
    ]
];
```

## Usage

The package automatically registers Livewire components based on the configuration.

You can create structured Livewire components using the provided Artisan command:

```bash
php artisan livewire:structured-livewire-component {component-name}
```

You will be prompted to select the group for the component.

## Compatibility

This package supports Laravel versions 10, 11, and 12, and Livewire version 3.

## License

MIT License
