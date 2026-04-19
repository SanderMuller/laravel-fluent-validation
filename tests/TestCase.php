<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests;

use Illuminate\Contracts\Config\Repository;
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
     *
     * Fallback-only: we set a deterministic test-only key ONLY when no key is
     * already configured. Any subclass test that configures its own key
     * (directly or via a trait) keeps it — we don't silently clobber
     * key-sensitive behavior in key-dependent assertions.
     */
    protected function defineEnvironment($app): void
    {
        $config = $app->make(Repository::class);

        if ($config->get('app.key') === null || $config->get('app.key') === '') {
            $config->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        }
    }
}
