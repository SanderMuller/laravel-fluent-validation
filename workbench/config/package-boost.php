<?php declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Excluded Laravel Boost Guidelines
    |--------------------------------------------------------------------------
    |
    | Keys listed here are merged into `boost.guidelines.exclude`, so Laravel
    | Boost skips them when composing AGENTS.md / CLAUDE.md. The defaults
    | strip guidelines that target application development (Inertia,
    | Livewire, Filament, deployments, etc.) and have no bearing on
    | package development with Testbench.
    |
    | Keys are matched exactly against the keys produced by Boost's
    | GuidelineComposer (e.g. `laravel/core`, `livewire/v3`, `herd`).
    |
    */

    'excluded_boost_guidelines' => [
        'deployments',
        'foundation',
        'herd',
        'sail',
        'laravel/style',
        'laravel/api',
        'laravel/localization',
        'inertia-laravel/core',
        'inertia-react/core',
        'inertia-react/v1',
        'inertia-react/v2',
        'inertia-svelte/core',
        'inertia-svelte/v1',
        'inertia-svelte/v2',
        'inertia-vue/core',
        'inertia-vue/v1',
        'inertia-vue/v2',
        'livewire/core',
        'livewire/v2',
        'livewire/v3',
        'livewire/v4',
        'volt/core',
        'volt/v1',
        'fluxui-free/core',
        'fluxui-pro/core',
        'folio/core',
        'folio/v1',
        'pennant/core',
        'pennant/v1',
        'wayfinder/core',
        'filament/core',
        'filament/v3',
        'filament/v4',
        'nightwatch/core',
        'pulse/core',
        'pulse/v1',
        'tailwindcss/core',
        'tailwindcss/v3',
        'tailwindcss/v4',
        'vite/core',
    ],

];
