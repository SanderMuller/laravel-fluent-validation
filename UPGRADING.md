# Upgrade Guide

This document describes breaking and constraint-affecting changes between
releases and the steps to adopt them. For the full per-release log, see
[CHANGELOG.md](CHANGELOG.md).

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
