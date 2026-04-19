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
// failsOnly() — exactly one field failed
// =========================================================================

it('failsOnly passes when exactly one field failed', function (): void {
    FluentRulesTester::for([
        'email' => FluentRule::email()->required(),
        'name' => FluentRule::string()->required(),
    ])
        ->with(['name' => 'Ada', 'email' => ''])
        ->failsOnly('email');
});

it('failsOnly with rule key checks the specific failed rule', function (): void {
    FluentRulesTester::for([
        'name' => FluentRule::string()->required()->min(5),
        'email' => FluentRule::email(),
    ])
        ->with(['name' => 'Jo', 'email' => 'a@b.test'])
        ->failsOnly('name', 'min');
});

it('failsOnly raises when other fields also failed', function (): void {
    expect(static function (): void {
        FluentRulesTester::for([
            'name' => FluentRule::string()->required(),
            'email' => FluentRule::email()->required(),
        ])
            ->with(['name' => '', 'email' => ''])
            ->failsOnly('name');
    })->toThrow(AssertionFailedError::class);
});

it('failsOnly raises when validation passed', function (): void {
    expect(static function (): void {
        FluentRulesTester::for([
            'name' => FluentRule::string()->required(),
        ])
            ->with(['name' => 'Ada'])
            ->failsOnly('name');
    })->toThrow(AssertionFailedError::class);
});

it('failsOnly raises when the rule key does not match', function (): void {
    expect(static function (): void {
        FluentRulesTester::for([
            'name' => FluentRule::string()->required()->min(5),
        ])
            ->with(['name' => 'Jo'])
            ->failsOnly('name', 'email');
    })->toThrow(AssertionFailedError::class);
});

it('raises LogicException when failsOnly() is called before with()', function (): void {
    expect(static function (): void {
        FluentRulesTester::for([
            'name' => FluentRule::string()->required(),
        ])->failsOnly('name');
    })->toThrow(LogicException::class);
});

// =========================================================================
// doesNotFailOn() — negative field-set assertion
// =========================================================================

it('doesNotFailOn passes when listed fields did not fail', function (): void {
    FluentRulesTester::for([
        'name' => FluentRule::string()->required(),
        'email' => FluentRule::email()->required(),
        'age' => FluentRule::numeric()->required(),
    ])
        ->with(['name' => 'Ada', 'email' => 'a@b.test', 'age' => null])
        ->fails()
        ->doesNotFailOn('name', 'email');
});

it('doesNotFailOn passes when validation entirely passed', function (): void {
    FluentRulesTester::for([
        'name' => FluentRule::string()->required(),
    ])
        ->with(['name' => 'Ada'])
        ->doesNotFailOn('name');
});

it('doesNotFailOn raises when one of the listed fields failed', function (): void {
    expect(static function (): void {
        FluentRulesTester::for([
            'name' => FluentRule::string()->required(),
            'email' => FluentRule::email()->required(),
        ])
            ->with(['name' => '', 'email' => ''])
            ->doesNotFailOn('name', 'age');
    })->toThrow(AssertionFailedError::class);
});

it('doesNotFailOn with no arguments is a no-op (passes)', function (): void {
    FluentRulesTester::for([
        'email' => FluentRule::email()->required(),
    ])
        ->with(['email' => ''])
        ->fails()
        ->doesNotFailOn();
});

it('raises LogicException when doesNotFailOn() is called before with()', function (): void {
    expect(static function (): void {
        FluentRulesTester::for([
            'name' => FluentRule::string()->required(),
        ])->doesNotFailOn('name');
    })->toThrow(LogicException::class);
});

// =========================================================================
// failsWithAny() — inclusive prefix match
// =========================================================================

it('failsWithAny matches an exact field key', function (): void {
    FluentRulesTester::for([
        'amount' => FluentRule::numeric()->required(),
    ])
        ->with(['amount' => null])
        ->failsWithAny('amount');
});

it('failsWithAny matches a dotted descendant when the prefix itself has no error', function (): void {
    FluentRulesTester::for([
        'actions' => FluentRule::array()->required()->each([
            'payload' => FluentRule::array()->required()->children([
                'stars' => FluentRule::numeric()->required()->min(1),
            ]),
        ]),
    ])
        ->with([
            'actions' => [
                ['payload' => ['stars' => 0]],
            ],
        ])
        ->failsWithAny('actions.0.payload');
});

it('failsWithAny matches both exact and descendant errors in the same bag', function (): void {
    // Both `amount` (top-level required) and `amount.value` (nested rule) could fail.
    // failsWithAny should pass if EITHER path produces a key.
    FluentRulesTester::for([
        'amount' => FluentRule::array()->required()->children([
            'currency' => FluentRule::string()->required(),
            'value' => FluentRule::numeric()->required()->min(1),
        ]),
    ])
        ->with(['amount' => ['currency' => 'EUR', 'value' => 0]])
        ->failsWithAny('amount');
});

it('failsWithAny does NOT match free-floating substrings', function (): void {
    // `someOther.payload.x` failure must NOT satisfy failsWithAny('payload')
    // (would be the (b) substring footgun the spec rejects).
    expect(static function (): void {
        FluentRulesTester::for([
            'someOther' => FluentRule::array()->required()->children([
                'payload' => FluentRule::array()->required()->children([
                    'x' => FluentRule::numeric()->required()->min(1),
                ]),
            ]),
        ])
            ->with(['someOther' => ['payload' => ['x' => 0]]])
            ->failsWithAny('payload');
    })->toThrow(AssertionFailedError::class);
});

it('failsWithAny raises an AssertionFailedError when no matching keys exist', function (): void {
    expect(static function (): void {
        FluentRulesTester::for([
            'name' => FluentRule::string()->required(),
            'email' => FluentRule::email()->required(),
        ])
            ->with(['name' => '', 'email' => ''])
            ->failsWithAny('age');
    })->toThrow(AssertionFailedError::class);
});

it('failsWithAny raises when validation passed', function (): void {
    expect(static function (): void {
        FluentRulesTester::for([
            'name' => FluentRule::string()->required(),
        ])
            ->with(['name' => 'Ada'])
            ->failsWithAny('name');
    })->toThrow(AssertionFailedError::class);
});

it('raises LogicException when failsWithAny() is called before with()', function (): void {
    expect(static function (): void {
        FluentRulesTester::for([
            'amount' => FluentRule::numeric()->required(),
        ])->failsWithAny('amount');
    })->toThrow(LogicException::class);
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
