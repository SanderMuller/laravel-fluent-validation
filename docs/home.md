---
layout: home

hero:
  name: Fluent Validation
  text: Laravel validation rules your IDE understands
  tagline: Typed, chainable rule builders with co-located array rules, labels and messages attached to the rule itself, and wildcard validation up to 160x faster.
  image:
    src: /logo.svg
    alt: Laravel Fluent Validation
  actions:
    - theme: brand
      text: Why this package?
      link: /why-this-package
    - theme: alt
      text: Installation
      link: /installation
    - theme: alt
      text: Getting started
      link: /getting-started

features:
  - title: Your IDE knows the rules
    details: "One typed builder per rule category: string, integer, date, email, file. Each exposes only the methods that apply, and autocompletion replaces the string-syntax cheat sheet."
    link: /why-this-package#one-builder-per-type
  - title: Co-located array rules
    details: each() and children() keep parent and child rules in one place instead of scattered across dot-notation keys. Nested arrays nest naturally.
    link: /array-validation
  - title: Up to 160x faster
    details: O(n) wildcard expansion, fast-check closures, batched database checks, and rule-parse memoization on every optimized entry point.
    link: /performance
  - title: Labels and messages on the rule
    details: The label and the custom error message travel with the rule definition, so there is no separate messages() or attributes() array to drift out of sync.
    link: /error-messages
  - title: Automated migration
    details: "A companion Rector package rewrites existing string and Rule object validation. 448 files converted in real-world testing with zero regressions."
    link: /migration
  - title: First-class testing
    details: FluentRulesTester asserts rules, RuleSets, FormRequests, custom Validators, and Livewire components without standing up the HTTP kernel.
    link: /testing
---

## From strings to fluent

```php
// Before
'name'         => 'required|string|min:2|max:255',
'email'        => ['required', 'email', Rule::unique('users')->ignore($id)],
'items'        => 'array',
'items.*.id'   => 'required|integer|exists:items,id',
'items.*.name' => 'required|string|max:255',

// After
'name'  => FluentRule::string('Full Name')->required()->min(2)->max(255),
'email' => FluentRule::email('Email')->required()->unique('users', 'email', fn ($r) => $r->ignore($id)),
'items' => FluentRule::array()->each([
    'id'   => FluentRule::integer()->required()->exists('items', 'id'),
    'name' => FluentRule::string()->required()->max(255),
]),
```

Install with Composer and add one trait to your form request. Existing string rules keep working while you migrate field by field.

```bash
composer require sandermuller/laravel-fluent-validation
```

## Where to next

<HomeNextSteps />
