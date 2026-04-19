<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests;

use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
        ];
    }

    /**
     * Livewire's `Testable::call()` renders a Blade view under the hood; the
     * view encryption pipeline requires `app.key`. Local dev envs usually have
     * APP_KEY set, but Testbench's default CI env does not — every Livewire
     * test fails with "No application encryption key has been specified."
     * Setting a deterministic test-only key here fixes CI without adding a
     * workflow-level env var, and stays idempotent for local runs that already
     * have an app key configured.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
    }
}
