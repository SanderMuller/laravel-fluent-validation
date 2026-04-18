<?php declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\AssertionFailedError;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\RuleSet;
use SanderMuller\FluentValidation\Testing\FluentRulesTester;

// =========================================================================
// Targets: array of rules
// =========================================================================

it('passes against an array of rules', function (): void {
    FluentRulesTester::for([
        'email' => FluentRule::email()->required(),
    ])->with(['email' => 'a@b.test'])->passes();
});

it('fails against an array of rules', function (): void {
    FluentRulesTester::for([
        'email' => FluentRule::email()->required(),
    ])->with(['email' => 'not-an-email'])->fails();
});

// =========================================================================
// Targets: RuleSet instance
// =========================================================================

it('passes against a RuleSet instance', function (): void {
    $ruleSet = RuleSet::make()->field('name', FluentRule::string()->required()->min(2));

    FluentRulesTester::for($ruleSet)->with(['name' => 'Ada'])->passes();
});

it('fails against a RuleSet instance', function (): void {
    $ruleSet = RuleSet::make()->field('name', FluentRule::string()->required()->min(5));

    FluentRulesTester::for($ruleSet)->with(['name' => 'Jo'])->fails();
});

// =========================================================================
// Targets: single ValidationRule (FluentRule builder)
// =========================================================================

it('wraps a single ValidationRule under the "value" key', function (): void {
    FluentRulesTester::for(FluentRule::string()->required()->min(3))
        ->with(['value' => 'hello'])
        ->passes();

    FluentRulesTester::for(FluentRule::string()->required()->min(3))
        ->with(['value' => 'hi'])
        ->fails();
});

// =========================================================================
// failsWith()
// =========================================================================

it('asserts a field has any error via failsWith without rule name', function (): void {
    FluentRulesTester::for([
        'email' => FluentRule::email()->required(),
    ])->with(['email' => ''])->failsWith('email');
});

it('asserts a field failed a specific rule (lowercase input)', function (): void {
    FluentRulesTester::for([
        'email' => FluentRule::email()->required(),
    ])->with(['email' => ''])->failsWith('email', 'required');
});

it('asserts a field failed a specific rule (Studly input)', function (): void {
    FluentRulesTester::for([
        'name' => FluentRule::string()->required()->min(5),
    ])->with(['name' => 'Jo'])->failsWith('name', 'Min');
});

it('failsWith raises an AssertionFailedError when the rule key does not match', function (): void {
    expect(static function (): void {
        FluentRulesTester::for([
            'name' => FluentRule::string()->required()->min(5),
        ])->with(['name' => 'Jo'])->failsWith('name', 'email');
    })->toThrow(AssertionFailedError::class);
});

it('failsWith raises when the field has no error', function (): void {
    expect(static function (): void {
        FluentRulesTester::for([
            'name' => FluentRule::string()->required(),
        ])->with(['name' => 'OK'])->failsWith('name');
    })->toThrow(AssertionFailedError::class);
});

// =========================================================================
// failsWithMessage()
// =========================================================================

it('asserts a field failed with a rendered translation message', function (): void {
    FluentRulesTester::for([
        'email' => FluentRule::email()->required(),
    ])
        ->with(['email' => ''])
        ->failsWithMessage('email', 'validation.required', ['attribute' => 'email']);
});

it('asserts a field failed with a translation message including replacements', function (): void {
    FluentRulesTester::for([
        'name' => FluentRule::string()->required()->min(5),
    ])
        ->with(['name' => 'Jo'])
        ->failsWithMessage('name', 'validation.min.string', ['attribute' => 'name', 'min' => 5]);
});

it('asserts a field failed with a translation message that respects rule labels', function (): void {
    FluentRulesTester::for([
        'name' => FluentRule::string('Full Name')->required(),
    ])
        ->with(['name' => ''])
        ->failsWithMessage('name', 'validation.required', ['attribute' => 'Full Name']);
});

it('failsWithMessage raises an AssertionFailedError when the rendered message does not match', function (): void {
    expect(static function (): void {
        FluentRulesTester::for([
            'name' => FluentRule::string()->required(),
        ])
            ->with(['name' => ''])
            ->failsWithMessage('name', 'validation.email', ['attribute' => 'name']);
    })->toThrow(AssertionFailedError::class);
});

it('failsWithMessage raises when the field has no error', function (): void {
    expect(static function (): void {
        FluentRulesTester::for([
            'name' => FluentRule::string()->required(),
        ])
            ->with(['name' => 'OK'])
            ->failsWithMessage('name', 'validation.required', ['attribute' => 'name']);
    })->toThrow(AssertionFailedError::class);
});

it('raises LogicException when failsWithMessage() is called before with()', function (): void {
    expect(static function (): void {
        FluentRulesTester::for([
            'email' => FluentRule::email()->required(),
        ])->failsWithMessage('email', 'validation.required');
    })->toThrow(LogicException::class);
});

// =========================================================================
// with() reuse across data sets
// =========================================================================

it('reuses the same tester across multiple data sets', function (): void {
    $tester = FluentRulesTester::for([
        'qty' => FluentRule::numeric()->required()->min(1),
    ]);

    $tester->with(['qty' => 5])->passes();
    $tester->with(['qty' => 0])->fails();
    $tester->with(['qty' => 10])->passes();
});

// =========================================================================
// errors() and validated() escape hatches
// =========================================================================

it('exposes the MessageBag via errors()', function (): void {
    $errors = FluentRulesTester::for([
        'email' => FluentRule::email()->required(),
    ])->with(['email' => 'bad'])->errors();

    expect($errors->has('email'))->toBeTrue();
});

it('returns validated data when validation passes', function (): void {
    $validated = FluentRulesTester::for([
        'name' => FluentRule::string()->required(),
    ])->with(['name' => 'Ada'])->validated();

    expect($validated)->toBe(['name' => 'Ada']);
});

it('throws ValidationException from validated() when validation failed', function (): void {
    expect(static function (): void {
        FluentRulesTester::for([
            'email' => FluentRule::email()->required(),
        ])->with(['email' => 'bad'])->validated();
    })->toThrow(ValidationException::class);
});

// =========================================================================
// Lazy-validation contract: assertion before with() raises LogicException
// =========================================================================

it('raises LogicException when passes() is called before with()', function (): void {
    expect(static function (): void {
        FluentRulesTester::for([
            'email' => FluentRule::email()->required(),
        ])->passes();
    })->toThrow(LogicException::class);
});

it('raises LogicException when errors() is called before with()', function (): void {
    expect(static function (): void {
        FluentRulesTester::for([
            'email' => FluentRule::email()->required(),
        ])->errors();
    })->toThrow(LogicException::class);
});

it('raises LogicException when validated() is called before with()', function (): void {
    expect(static function (): void {
        FluentRulesTester::for([
            'email' => FluentRule::email()->required(),
        ])->validated();
    })->toThrow(LogicException::class);
});

it('raises LogicException when fails() is called before with()', function (): void {
    expect(static function (): void {
        FluentRulesTester::for([
            'email' => FluentRule::email()->required(),
        ])->fails();
    })->toThrow(LogicException::class);
});

it('raises LogicException when failsWith() is called before with()', function (): void {
    expect(static function (): void {
        FluentRulesTester::for([
            'email' => FluentRule::email()->required(),
        ])->failsWith('email');
    })->toThrow(LogicException::class);
});
