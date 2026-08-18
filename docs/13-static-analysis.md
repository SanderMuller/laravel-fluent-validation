# Static analysis with PHPStan

The companion package [`sandermuller/laravel-fluent-validation-phpstan`](https://github.com/sandermuller/laravel-fluent-validation-phpstan) ships PHPStan rules that flag misuse of this library in consumer projects. The flagship rule catches unbounded `FluentRule::array()->each(...)` / `FluentRule::list()->each(...)` chains, the classic N+1 / DoS footgun on per-item `exists()` or closure rules.

```bash
composer require --dev sandermuller/laravel-fluent-validation-phpstan
```

See the [phpstan-package README](https://github.com/sandermuller/laravel-fluent-validation-phpstan#rules) for the rule catalog, configuration (`namespaces`, `excludeNamespaces`) and escape hatches.
