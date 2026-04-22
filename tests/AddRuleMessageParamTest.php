<?php declare(strict_types=1);

use Illuminate\Validation\Rules\In;
use SanderMuller\FluentValidation\Rules\Concerns\HasFieldModifiers;

// =========================================================================
// Phase 2 — addRule($rules, ?$message) extension. Unit-level tests that
// call the protected addRule directly via a test-local exposer class, so
// Phase 2 can be verified before Phase 3 wires $message through public
// rule methods.
// =========================================================================

function exposeAddRule(): object
{
    return new class {
        use HasFieldModifiers;

        /** @var list<string> */
        protected array $constraints = [];

        protected ?string $compiledCache = null;

        /**
         * @param  array<int, string>|string|object  $rules
         */
        public function callAddRule(array|string|object $rules, ?string $message = null): static
        {
            return $this->addRule($rules, $message);
        }

        /**
         * Stub to satisfy `HasFieldModifiers::compileConditionalBranch`'s
         * return-type inference; unused by these tests.
         */
        public function compiledRules(): string
        {
            return '';
        }
    };
}

it('writes customMessages[lastConstraint] when $message is set (string rule)', function (): void {
    $rule = exposeAddRule()->callAddRule('min:2', 'Too short!');

    expect($rule->getCustomMessages())->toBe(['min' => 'Too short!']);
});

it('is a no-op for customMessages when $message is null', function (): void {
    $rule = exposeAddRule()->callAddRule('min:2');

    expect($rule->getCustomMessages())->toBeEmpty();
});

it('writes under class-basename key when given a rule object with explicit match', function (): void {
    $rule = exposeAddRule()->callAddRule(new In(['admin', 'user']), 'Pick one.');

    expect($rule->getCustomMessages())->toBe(['in' => 'Pick one.']);
});

it('throws LogicException when $message is set but no rule name resolves', function (): void {
    exposeAddRule()->callAddRule([], 'orphan');
})->throws(LogicException::class, 'message parameter has no rule to bind to');

it('last-write-wins when same rule key is messaged twice', function (): void {
    $rule = exposeAddRule()
        ->callAddRule('min:2', 'First')
        ->callAddRule('min:5', 'Second');

    expect($rule->getCustomMessages())->toBe(['min' => 'Second']);
});

it('coexists with messages for different rule keys', function (): void {
    $rule = exposeAddRule()
        ->callAddRule('required', 'Name required.')
        ->callAddRule('min:2', 'Too short.');

    expect($rule->getCustomMessages())->toBe([
        'required' => 'Name required.',
        'min' => 'Too short.',
    ]);
});
