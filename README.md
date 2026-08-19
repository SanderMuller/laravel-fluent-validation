[![Laravel Fluent Validation](docs/public/header.png)](https://sandermuller.github.io/laravel-fluent-validation/)

# Fluent validation rule builders for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sandermuller/laravel-fluent-validation.svg?style=flat-square)](https://packagist.org/packages/sandermuller/laravel-fluent-validation)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/sandermuller/laravel-fluent-validation/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/sandermuller/laravel-fluent-validation/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub PHPStan Action Status](https://img.shields.io/github/actions/workflow/status/sandermuller/laravel-fluent-validation/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/sandermuller/laravel-fluent-validation/actions?query=workflow%3Aphpstan+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/sandermuller/laravel-fluent-validation.svg?style=flat-square)](https://packagist.org/packages/sandermuller/laravel-fluent-validation)
[![License](https://img.shields.io/github/license/sandermuller/laravel-fluent-validation.svg?style=flat-square)](LICENSE.md)
[![Laravel Compatibility](https://badge.laravel.cloud/badge/sandermuller/laravel-fluent-validation?style=flat)](https://packagist.org/packages/sandermuller/laravel-fluent-validation)

Write Laravel validation rules with IDE autocompletion instead of memorizing string syntax. Each rule type exposes only the methods that apply to it: `FluentRule::string()` won't offer `digits()`, `FluentRule::date()` won't offer `mimes()`. `each()` and `children()` keep parent and child rules in one place instead of scattered across dot-notation keys. For large arrays, the `HasFluentRules` trait makes wildcard validation [up to 160x faster](https://sandermuller.github.io/laravel-fluent-validation/performance#benchmarks).

```php
// Before
'name'         => 'required|string|min:2|max:255',
'email'        => ['required', 'email', Rule::unique('users')->ignore($id)],
'role'         => Rule::when($isAdmin, 'required|string|in:admin,editor'),
'items'        => 'array',
'items.*.id'   => 'required|integer|exists:items,id',
'items.*.name' => 'required|string|max:255',

// After
'name'  => FluentRule::string('Full Name')->required()->min(2)->max(255),
'email' => FluentRule::email('Email')->required()->unique('users', 'email', fn ($r) => $r->ignore($id)),
'role'  => FluentRule::string()->when($isAdmin, fn ($r) => $r->required()->in(['admin', 'editor'])),
'items' => FluentRule::array()->each([
    'id'   => FluentRule::integer()->required()->exists('items', 'id'),
    'name' => FluentRule::string()->required()->max(255),
]),
```

> **Migrating an existing codebase?** Jump straight to [Migrating to fluent validation](https://sandermuller.github.io/laravel-fluent-validation/migration); a companion package automates the bulk of the rewrite.

## Installation

You can install the package via composer:

```bash
composer require sandermuller/laravel-fluent-validation
```

Requires PHP 8.2+ and Laravel 12+. See [Installation](https://sandermuller.github.io/laravel-fluent-validation/installation) for AI-assisted development with [Laravel Boost](https://github.com/laravel/boost), and [UPGRADING.md](UPGRADING.md) when upgrading from an older release.

## Usage

Add the `HasFluentRules` trait to your form request:

```php
use Illuminate\Foundation\Http\FormRequest;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\HasFluentRules;

class StorePostRequest extends FormRequest
{
    use HasFluentRules;

    public function rules(): array
    {
        return [
            'title'    => FluentRule::string('Title')->required()->min(2)->max(255),
            'email'    => FluentRule::email('Email')->required()->unique('users'),
            'date'     => FluentRule::date('Publish Date')->required()->afterToday(),
            'avatar'   => FluentRule::image()->nullable()->max('2mb'),
            'tags'     => FluentRule::array(label: 'Tags')->required()->each(
                              FluentRule::string()->max(50)
                          ),
            'password' => FluentRule::password()->required()->mixedCase()->numbers(),
        ];
    }
}
```

The label `'Title'` replaces `:attribute` in error messages. You get "The Title field is required" instead of "The title field is required", without a separate `attributes()` array.

See [Basic usage](https://sandermuller.github.io/laravel-fluent-validation/basic-usage) for the `schema()` builder, typing your `rules()` return, and using fluent rules outside form requests.

## Documentation

Read the full documentation at **[sandermuller.github.io/laravel-fluent-validation](https://sandermuller.github.io/laravel-fluent-validation/)**.

**Getting started**
- [Why this package?](https://sandermuller.github.io/laravel-fluent-validation/why-this-package) — DX, type safety, structure, performance, and how it compares to Laravel's `Rule` class
- [Installation](https://sandermuller.github.io/laravel-fluent-validation/installation)
- [Basic usage](https://sandermuller.github.io/laravel-fluent-validation/basic-usage) — Form Requests, typing `rules()`, the `schema()` builder, other contexts
- [Error messages](https://sandermuller.github.io/laravel-fluent-validation/error-messages) — labels, per-rule messages
- [Array validation](https://sandermuller.github.io/laravel-fluent-validation/array-validation) — `each()`, `children()`, nesting

**Digging deeper**
- [Extending parent rules](https://sandermuller.github.io/laravel-fluent-validation/extending-rules) — child form requests, `modifyEach`, `modifyChildren`, returning a `RuleSet`
- [Livewire](https://sandermuller.github.io/laravel-fluent-validation/livewire) — `HasFluentValidation` trait, Filament workaround
- [Performance](https://sandermuller.github.io/laravel-fluent-validation/performance) — O(n) wildcards, pre-evaluation, fast-check closures, batched DB, benchmarks
- [RuleSet](https://sandermuller.github.io/laravel-fluent-validation/ruleset) — build, compose, inspect, validate, escape hatches, method reference
- [Testing](https://sandermuller.github.io/laravel-fluent-validation/testing) — `FluentRulesTester`, Pest expectations
- [Rule reference](https://sandermuller.github.io/laravel-fluent-validation/rule-reference) — all types, modifiers, conditionals, macros

**Migration and tooling**
- [Migrating to fluent validation](https://sandermuller.github.io/laravel-fluent-validation/migration) — incremental migration and the automated [Rector companion](https://github.com/sandermuller/laravel-fluent-validation-rector)
- [Static analysis with PHPStan](https://sandermuller.github.io/laravel-fluent-validation/static-analysis) — opt-in [PHPStan rules package](https://github.com/sandermuller/laravel-fluent-validation-phpstan) that flags unbounded `each()` chains
- [Troubleshooting](https://sandermuller.github.io/laravel-fluent-validation/troubleshooting) — common issues and solutions

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Security vulnerabilities

Please review [our security policy](SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Sander Muller](https://github.com/sandermuller)
- [All Contributors](../../contributors)

## License

MIT License. Please see [License File](LICENSE.md) for more information.
