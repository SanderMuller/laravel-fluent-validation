# Upgrade Guide

This document describes breaking and constraint-affecting changes between
releases and the steps to adopt them. For the full per-release log, see
[CHANGELOG.md](CHANGELOG.md).

## Upgrading to 1.33 from 1.32

### `HasFluentRules` now declares a `rules()` method

So a request that defines only `schema()` can still call `->rules()` (it returns
the builder's output), the `HasFluentRules` trait now declares:

```php
public function rules(): array|RuleSet
```

A `rules()` you define yourself still takes precedence, but its signature must be
compatible with the trait's, or PHP raises a "Declaration must be compatible"
fatal:

- `public function rules(): array` — no change (Laravel's default).
- `public function rules(): RuleSet` — no change.
- An untyped `public function rules()` — add a return type (`: array` or `: RuleSet`).
- A non-`public` `rules()` — make it `public`.

Most requests need no change.

## Upgrading to 1.28 from 1.27

### Laravel 11 support dropped

The supported framework constraint narrowed from
`illuminate/* ^11.0||^12.0||^13.0` to `^12.0||^13.0`. Every Laravel 11 release is
flagged by Packagist security advisories with no advisory-free patch — Composer's
advisory policy refuses to install any of them, so the package can no longer be
resolved or tested against Laravel 11.

**If you are on Laravel 12 or 13:** this is a drop-in update.

```bash
composer update sandermuller/laravel-fluent-validation
```

**If you are still on Laravel 11:** upgrade your application to Laravel 12 or
later first (see the [Laravel upgrade guide](https://laravel.com/docs/upgrade)),
then update this package. Laravel 11 is past security support; staying on it is
not a secure option regardless of this package.

No runtime code or public API changed in 1.28 — only the framework constraint.
