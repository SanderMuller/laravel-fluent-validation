<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\Fixtures;

use Livewire\Component;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\HasFluentValidation;

/**
 * Livewire component fixture exercising the full `submit()` flow with a
 * guard clause + `addError()` branch + computed-state validation. Mirrors
 * the collectiq AppealPage shape: the value of testing the component
 * (rather than the RuleSet directly) is that the submit() method's
 * non-validation guards and addError side-effects also get exercised.
 *
 * @internal
 */
final class AppealLivewireComponent extends Component
{
    use HasFluentValidation;

    public string $type = '';

    public string $reason = '';

    public bool $rateLimited = false;

    public bool $quotaExceeded = false;

    public bool $modalOpen = false;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => FluentRule::string()->required()->in(['refund', 'access']),
            'reason' => FluentRule::string()->required()->min(10),
        ];
    }

    /** Multi-step: opening the modal unlocks the submit() flow. */
    public function openModal(): void
    {
        $this->modalOpen = true;
    }

    /**
     * Assert-auth action — mirrors the auth-aware action patterns
     * (policy gates, $user->isSuspended() checks inside mount()/actions).
     * Reads auth()->user() directly; surfaces a known error key when
     * no user is resolved.
     */
    public function requireAuthenticatedUser(): void
    {
        if (auth()->user() === null) {
            $this->addError('auth', 'Authenticated user required.');
        }
    }

    public function submit(): void
    {
        // Pre-validate guard — rate-limit branch returns BEFORE validate()
        // ever runs. Mirrors hihaho's CreateTranslatedCopy / collectiq's
        // AppealPage rate-limit pattern.
        if ($this->rateLimited) {
            $this->addError('reason', 'Too many requests. Try again later.');

            return;
        }

        $this->validate();

        // Post-validate guard — addError after a successful validate().
        // Mirrors hihaho's CreateApiToken quota-exceeded pattern.
        if ($this->quotaExceeded) {
            $this->addError('type', 'Quota exceeded for this action type.');

            return;
        }
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
