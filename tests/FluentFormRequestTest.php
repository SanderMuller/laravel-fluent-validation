<?php

declare(strict_types=1);

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Fluent;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use SanderMuller\FluentValidation\FluentFormRequest;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\OptimizedValidator;

// =========================================================================
// Unit: OptimizedValidator fast check compilation
// =========================================================================

it('builds fast checks for simple string rules', function (): void {
    $checks = OptimizedValidator::buildFastChecks([
        'items.*.name' => 'string|required|max:255',
    ]);

    expect($checks)->toHaveKey('items.*.name');
    expect(($checks['items.*.name'])('hello'))->toBeTrue();
    expect(($checks['items.*.name'])(''))->toBeFalse();
    expect(($checks['items.*.name'])(null))->toBeFalse();
    expect(($checks['items.*.name'])(123))->toBeFalse();
});

it('builds fast checks for numeric rules', function (): void {
    $checks = OptimizedValidator::buildFastChecks([
        'items.*.qty' => 'numeric|required|min:1|max:100',
    ]);

    expect($checks)->toHaveKey('items.*.qty');
    expect(($checks['items.*.qty'])(50))->toBeTrue();
    expect(($checks['items.*.qty'])(0))->toBeFalse();
    expect(($checks['items.*.qty'])(101))->toBeFalse();
    expect(($checks['items.*.qty'])('not a number'))->toBeFalse();
});

it('builds fast checks for boolean rules', function (): void {
    $checks = OptimizedValidator::buildFastChecks([
        'items.*.active' => 'required|boolean',
    ]);

    expect($checks)->toHaveKey('items.*.active');
    expect(($checks['items.*.active'])(true))->toBeTrue();
    expect(($checks['items.*.active'])(false))->toBeTrue();
    expect(($checks['items.*.active'])(1))->toBeTrue();
    expect(($checks['items.*.active'])(0))->toBeTrue();
    expect(($checks['items.*.active'])('0'))->toBeTrue();
    expect(($checks['items.*.active'])('1'))->toBeTrue();
    expect(($checks['items.*.active'])('yes'))->toBeFalse();
});

it('builds fast checks for integer rules', function (): void {
    $checks = OptimizedValidator::buildFastChecks([
        'items.*.count' => 'required|integer|min:0',
    ]);

    expect($checks)->toHaveKey('items.*.count');
    expect(($checks['items.*.count'])(5))->toBeTrue();
    expect(($checks['items.*.count'])(0))->toBeTrue();
    expect(($checks['items.*.count'])(-1))->toBeFalse();
});

it('skips fast checks for object rules', function (): void {
    $checks = OptimizedValidator::buildFastChecks([
        'items.*.email' => ['string', 'required', new Unique('users')],
    ]);

    expect($checks)->not->toHaveKey('items.*.email');
});

it('skips fast checks for date comparison rules', function (): void {
    $checks = OptimizedValidator::buildFastChecks([
        'items.*.starts_at' => 'required|date|after:today',
    ]);

    expect($checks)->not->toHaveKey('items.*.starts_at');
});

it('skips fast checks for size and between rules', function (): void {
    $sizeCheck = OptimizedValidator::buildFastChecks([
        'items.*.code' => 'required|string|size:6',
    ]);
    expect($sizeCheck)->not->toHaveKey('items.*.code');

    $betweenCheck = OptimizedValidator::buildFastChecks([
        'items.*.score' => 'required|numeric|between:1,10',
    ]);
    expect($betweenCheck)->not->toHaveKey('items.*.score');
});

it('skips fast checks for array rules', function (): void {
    $checks = OptimizedValidator::buildFastChecks([
        'items.*.tags' => 'required|array',
    ]);

    expect($checks)->not->toHaveKey('items.*.tags');
});

it('skips fast checks for unknown rules', function (): void {
    $checks = OptimizedValidator::buildFastChecks([
        'items.*.code' => 'required|string|starts_with:ABC',
    ]);

    expect($checks)->not->toHaveKey('items.*.code');
});

it('handles nullable values in fast checks', function (): void {
    $checks = OptimizedValidator::buildFastChecks([
        'items.*.notes' => 'nullable|string|max:1000',
    ]);

    expect($checks)->toHaveKey('items.*.notes');
    expect(($checks['items.*.notes'])(null))->toBeTrue();
    expect(($checks['items.*.notes'])('some note'))->toBeTrue();
    expect(($checks['items.*.notes'])(str_repeat('x', 1001)))->toBeFalse();
});

it('handles in: values in fast checks', function (): void {
    $checks = OptimizedValidator::buildFastChecks([
        'items.*.role' => 'required|string|in:admin,editor,viewer',
    ]);

    expect($checks)->toHaveKey('items.*.role');
    expect(($checks['items.*.role'])('admin'))->toBeTrue();
    expect(($checks['items.*.role'])('editor'))->toBeTrue();
    expect(($checks['items.*.role'])('superadmin'))->toBeFalse();
});

it('builds fast checks for multiple patterns independently', function (): void {
    $checks = OptimizedValidator::buildFastChecks([
        'items.*.name' => 'string|required|max:255',
        'items.*.qty' => 'numeric|required|min:1',
        'items.*.email' => 'required|string|email', // not fast-checkable
    ]);

    expect($checks)->toHaveKey('items.*.name');
    expect($checks)->toHaveKey('items.*.qty');
    expect($checks)->toHaveKey('items.*.email');
});

// =========================================================================
// Regression: isset() with false values (the core bug we found)
// =========================================================================

it('does not skip validation for attributes that fail the fast check', function (): void {
    // This regression test covers the bug where isset($array['key']) returns
    // true for false values in PHP, causing all rules after the first to be
    // skipped for attributes that failed the fast check.
    $formRequest = createFluentFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->min(5),
            ]),
        ],
        data: [
            'items' => [
                ['name' => 'Jo'], // Should fail min:5
            ],
        ],
    );

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    // The fast check correctly identifies 'Jo' as failing (len 2 < min 5).
    // The bug was: after the fast check stored false, isset(false) returned true,
    // causing the required and min:5 rules to be skipped entirely.
    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->has('items.0.name'))->toBeTrue();
});

// =========================================================================
// Unit: FluentFormRequest createDefaultValidator
// =========================================================================

it('creates an OptimizedValidator from FluentFormRequest', function (): void {
    $formRequest = createFluentFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->min(2),
            ]),
        ],
        data: [
            'items' => [
                ['name' => 'John'],
                ['name' => 'Jane'],
            ],
        ],
    );

    $factory = app(Factory::class);
    /** @var Validator $validator */
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    expect($validator)->toBeInstanceOf(OptimizedValidator::class);
    expect($validator->passes())->toBeTrue();
    expect($validator->validated())->toHaveKeys(['items']);
});

it('validates correctly with fast-checkable wildcard rules', function (): void {
    $formRequest = createFluentFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string('Name')->required()->max(255),
                'qty' => FluentRule::numeric('Quantity')->required()->min(1),
            ]),
        ],
        data: [
            'items' => [
                ['name' => 'Widget', 'qty' => 5],
                ['name' => 'Gadget', 'qty' => 10],
            ],
        ],
    );

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    expect($validator->passes())->toBeTrue();
    expect($validator->validated()['items'])->toHaveCount(2);
});

it('reports errors correctly for invalid wildcard data', function (): void {
    $formRequest = createFluentFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->min(5),
            ]),
        ],
        data: [
            'items' => [
                ['name' => 'Jo'],
                ['name' => 'Valid Name'],
            ],
        ],
    );

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->keys())->toContain('items.0.name');
    expect($validator->errors()->keys())->not->toContain('items.1.name');
});

it('handles mixed fluent and string rules', function (): void {
    $formRequest = createFluentFormRequest(
        rules: [
            'title' => 'required|string|max:255',
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->min(2),
            ]),
        ],
        data: [
            'title' => 'My Import',
            'items' => [
                ['name' => 'John'],
            ],
        ],
    );

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    expect($validator->passes())->toBeTrue();
    expect($validator->validated())->toHaveKeys(['title', 'items']);
});

it('works with non-fast-checkable rules falling through to Laravel', function (): void {
    $formRequest = createFluentFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->each([
                'email' => FluentRule::email()->required(),
            ]),
        ],
        data: [
            'items' => [
                ['email' => 'valid@example.com'],
                ['email' => 'not-an-email'],
            ],
        ],
    );

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->has('items.1.email'))->toBeTrue();
    // Valid email should not produce a false positive.
    expect($validator->errors()->has('items.0.email'))->toBeFalse();
});

it('extracts labels and messages correctly', function (): void {
    $formRequest = createFluentFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string('Full Name')->required()->message('Name is required'),
            ]),
        ],
        data: [
            'items' => [
                ['name' => ''],
            ],
        ],
    );

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->first('items.0.name'))->toBe('Name is required');
});

it('validated() returns all wildcard data even when fast-checked', function (): void {
    $formRequest = createFluentFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->max(255),
                'qty' => FluentRule::numeric()->required()->min(1),
            ]),
        ],
        data: [
            'items' => [
                ['name' => 'A', 'qty' => 1],
                ['name' => 'B', 'qty' => 2],
                ['name' => 'C', 'qty' => 3],
            ],
        ],
    );

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    expect($validator->passes())->toBeTrue();

    $validated = $validator->validated();
    expect($validated['items'])->toHaveCount(3);
    expect($validated['items'][0]['name'])->toBe('A');
    expect($validated['items'][2]['qty'])->toBe(3);
});

it('works with after() hooks on passing validation', function (): void {
    $formRequest = createFluentFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->max(255),
            ]),
        ],
        data: [
            'items' => [
                ['name' => 'Valid'],
            ],
        ],
    );

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    $afterCalled = false;
    $validator->after(function () use (&$afterCalled): void {
        $afterCalled = true;
    });

    expect($validator->passes())->toBeTrue();
    expect($afterCalled)->toBeTrue();
});

it('after() hooks can add errors that cause validation to fail', function (): void {
    $formRequest = createFluentFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->max(255),
            ]),
        ],
        data: [
            'items' => [
                ['name' => 'Valid'],
            ],
        ],
    );

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    $validator->after(function (Validator $v): void {
        $v->errors()->add('items', 'Custom cross-field error from after hook');
    });

    // All rules pass, but after() added an error.
    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->first('items'))->toBe('Custom cross-field error from after hook');
});

it('handles cross-field wildcard references correctly', function (): void {
    $formRequest = createFluentFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->each([
                'start' => FluentRule::numeric()->required(),
                'end' => FluentRule::numeric()->required()->greaterThanOrEqualTo('items.*.start'),
            ]),
        ],
        data: [
            'items' => [
                ['start' => 1, 'end' => 5],
                ['start' => 10, 'end' => 3],
            ],
        ],
    );

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->has('items.1.end'))->toBeTrue();
    // The valid pair (start=1, end=5) should not error.
    expect($validator->errors()->has('items.0.end'))->toBeFalse();
    expect($validator->errors()->has('items.0.start'))->toBeFalse();
});

it('handles rules without wildcards', function (): void {
    $formRequest = createFluentFormRequest(
        rules: [
            'name' => FluentRule::string('Name')->required()->max(255),
            'email' => FluentRule::email('Email')->required(),
        ],
        data: [
            'name' => 'John',
            'email' => 'john@example.com',
        ],
    );

    $factory = app(Factory::class);
    /** @var Validator $validator */
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    // No wildcard rules — returns a plain Validator, not OptimizedValidator.
    expect($validator)->not->toBeInstanceOf(OptimizedValidator::class);
    expect($validator)->toBeInstanceOf(Validator::class);
    expect($validator->passes())->toBeTrue();
    expect($validator->validated())->toHaveKeys(['name', 'email']);
});

// =========================================================================
// Edge cases: empty/missing data
// =========================================================================

it('handles empty wildcard arrays gracefully', function (): void {
    $formRequest = createFluentFormRequest(
        rules: [
            'items' => FluentRule::array()->each([
                'name' => FluentRule::string()->required(),
            ]),
        ],
        data: [
            'items' => [],
        ],
    );

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    // Empty array with no 'required' on parent — passes. No fast checks invoked.
    expect($validator->passes())->toBeTrue();
});

it('fails required on empty wildcard arrays', function (): void {
    $formRequest = createFluentFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required(),
            ]),
        ],
        data: [
            'items' => [],
        ],
    );

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    // Laravel's required rule fails on empty arrays (count < 1).
    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->has('items'))->toBeTrue();
});

it('handles missing wildcard parent gracefully', function (): void {
    $formRequest = createFluentFormRequest(
        rules: [
            'title' => FluentRule::string()->required(),
            'items' => FluentRule::array()->each([
                'name' => FluentRule::string()->required(),
            ]),
        ],
        data: [
            'title' => 'Hello',
            // 'items' is missing entirely
        ],
    );

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    // Items is not required, so missing is fine. No fast checks invoked.
    expect($validator->passes())->toBeTrue();
    // validated() should include title but not items.
    $validated = $validator->validated();
    expect($validated)->toHaveKey('title');
    expect($validated)->not->toHaveKey('items');
});

// =========================================================================
// Edge cases: passes() called multiple times
// =========================================================================

it('resets fast check caches when passes() is called multiple times', function (): void {
    $formRequest = createFluentFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->max(255),
            ]),
        ],
        data: [
            'items' => [
                ['name' => 'Valid'],
            ],
        ],
    );

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    // First call — should pass.
    expect($validator->passes())->toBeTrue();

    // Second call — should still pass (caches are reset).
    expect($validator->passes())->toBeTrue();
});

it('resets caches correctly when passes() transitions from pass to fail', function (): void {
    $formRequest = createFluentFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->min(3),
            ]),
        ],
        data: [
            'items' => [
                ['name' => 'Valid'],
            ],
        ],
    );

    $factory = app(Factory::class);
    /** @var OptimizedValidator $validator */
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    expect($validator->passes())->toBeTrue();

    // Change data to invalid — passes() must re-evaluate fast checks.
    $validator->setData(['items' => [['name' => 'No']]]);
    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->has('items.0.name'))->toBeTrue();
});

it('resets caches correctly when passes() transitions from fail to pass', function (): void {
    $formRequest = createFluentFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->min(3),
            ]),
        ],
        data: [
            'items' => [
                ['name' => 'No'],
            ],
        ],
    );

    $factory = app(Factory::class);
    /** @var OptimizedValidator $validator */
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->has('items.0.name'))->toBeTrue();

    // Change data to valid — errors must clear and fast checks re-evaluate.
    $validator->setData(['items' => [['name' => 'Valid']]]);
    expect($validator->passes())->toBeTrue();
    expect($validator->errors()->isEmpty())->toBeTrue();
});

// =========================================================================
// Edge cases: bail rule interaction
// =========================================================================

it('respects bail rule when fast check fails and falls through to Laravel', function (): void {
    $formRequest = createFluentFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->bail()->required()->min(3)->max(255),
            ]),
        ],
        data: [
            'items' => [
                ['name' => ''], // Fails required — bail should stop at first error
            ],
        ],
    );

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    expect($validator->passes())->toBeFalse();
    // With bail, only one error should be reported (not both required and min:3).
    expect($validator->errors()->get('items.0.name'))->toHaveCount(1);
});

it('bail prevents closure from running when earlier rule fails', function (): void {
    $closureCalled = false;

    $formRequest = createFluentFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->max(255),
                'user_id' => FluentRule::numeric()->bail()->required()->integer()
                    ->rule(function (string $attribute, mixed $value, Closure $fail) use (&$closureCalled): void {
                        $closureCalled = true;
                    }),
            ]),
        ],
        data: [
            'items' => [
                ['name' => 'Valid', 'user_id' => 'not-a-number'],
            ],
        ],
    );

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->has('items.0.user_id'))->toBeTrue();
    // Closure added via ->rule() compiles after string constraints, so bail +
    // numeric stops validation before the closure runs.
    expect($closureCalled)->toBeFalse();
    // The fast-checkable name field should have no errors.
    expect($validator->errors()->has('items.0.name'))->toBeFalse();
});

it('closure runs when bail rules pass and value is valid', function (): void {
    $closureCalled = false;
    $closureValue = null;

    $formRequest = createFluentFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->max(255),
                'user_id' => FluentRule::numeric()->bail()->required()->integer()
                    ->rule(function (string $attribute, mixed $value, Closure $fail) use (&$closureCalled, &$closureValue): void {
                        $closureCalled = true;
                        $closureValue = $value;
                    }),
            ]),
        ],
        data: [
            'items' => [
                ['name' => 'Valid', 'user_id' => 42],
            ],
        ],
    );

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    expect($validator->passes())->toBeTrue();
    // All bail rules passed — closure should be called with the value.
    expect($closureCalled)->toBeTrue();
    expect($closureValue)->toBe(42);
});

it('fast check does not interfere with non-fast-checkable fields in same group', function (): void {
    $closureCalled = false;

    $formRequest = createFluentFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->max(255),  // fast-checkable
                'score' => FluentRule::numeric()->required()->rule(function (string $attribute, mixed $value, Closure $fail) use (&$closureCalled): void {
                    $closureCalled = true;
                    if ($value > 100) {
                        $fail('Score must be 100 or less.');
                    }
                }),
            ]),
        ],
        data: [
            'items' => [
                ['name' => 'Alice', 'score' => 150],
            ],
        ],
    );

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    expect($validator->passes())->toBeFalse();
    // Closure ran and added a custom error.
    expect($closureCalled)->toBeTrue();
    expect($validator->errors()->has('items.0.score'))->toBeTrue();
    // Fast-checked name field should pass without interference.
    expect($validator->errors()->has('items.0.name'))->toBeFalse();
});

// =========================================================================
// Edge cases: multiple wildcard groups
// =========================================================================

it('handles multiple independent wildcard groups', function (): void {
    $formRequest = createFluentFormRequest(
        rules: [
            'users' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->max(255),
            ]),
            'products' => FluentRule::array()->required()->each([
                'title' => FluentRule::string()->required()->max(255),
                'price' => FluentRule::numeric()->required()->min(0),
            ]),
        ],
        data: [
            'users' => [
                ['name' => 'Alice'],
                ['name' => 'Bob'],
            ],
            'products' => [
                ['title' => 'Widget', 'price' => 9.99],
                ['title' => '', 'price' => -1], // Both fields invalid
            ],
        ],
    );

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->keys())->not->toContain('users.0.name');
    expect($validator->errors()->keys())->not->toContain('users.1.name');
    expect($validator->errors()->keys())->toContain('products.1.title');
    expect($validator->errors()->keys())->toContain('products.1.price');
});

// =========================================================================
// Edge cases: scalar each() rules
// =========================================================================

it('handles scalar each() rules', function (): void {
    $formRequest = createFluentFormRequest(
        rules: [
            'tags' => FluentRule::array()->required()->each(
                FluentRule::string()->required()->max(50),
            ),
        ],
        data: [
            'tags' => ['php', 'laravel', 'validation'],
        ],
    );

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    expect($validator->passes())->toBeTrue();
    expect($validator->validated()['tags'])->toHaveCount(3);
});

it('reports errors for invalid scalar each() items', function (): void {
    $formRequest = createFluentFormRequest(
        rules: [
            'tags' => FluentRule::array()->required()->each(
                FluentRule::string()->required()->min(3),
            ),
        ],
        data: [
            'tags' => ['php', 'ok', 'hi'], // 'ok' and 'hi' fail min:3
        ],
    );

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->has('tags.1'))->toBeTrue();
    expect($validator->errors()->has('tags.2'))->toBeTrue();
    expect($validator->errors()->has('tags.0'))->toBeFalse();
});

// =========================================================================
// Edge cases: nested wildcards
// =========================================================================

it('handles nested each() rules', function (): void {
    $formRequest = createFluentFormRequest(
        rules: [
            'orders' => FluentRule::array()->required()->each([
                'items' => FluentRule::array()->required()->each([
                    'qty' => FluentRule::numeric()->required()->integer()->min(1),
                ]),
            ]),
        ],
        data: [
            'orders' => [
                ['items' => [['qty' => 2], ['qty' => 5]]],
                ['items' => [['qty' => 1]]],
            ],
        ],
    );

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    expect($validator->passes())->toBeTrue();
});

it('reports errors in nested each() rules', function (): void {
    $formRequest = createFluentFormRequest(
        rules: [
            'orders' => FluentRule::array()->required()->each([
                'items' => FluentRule::array()->required()->each([
                    'qty' => FluentRule::numeric()->required()->integer()->min(1),
                ]),
            ]),
        ],
        data: [
            'orders' => [
                ['items' => [['qty' => 2], ['qty' => 0]]], // qty=0 fails min:1
                ['items' => [['qty' => 1]]],
            ],
        ],
    );

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->has('orders.0.items.1.qty'))->toBeTrue();
});

// =========================================================================
// Edge cases: mix of fast-checkable and non-fast-checkable in same group
// =========================================================================

it('fast-checks eligible fields while falling through for ineligible ones in the same group', function (): void {
    $formRequest = createFluentFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->max(255),  // fast-checkable
                'email' => FluentRule::email()->required(),             // NOT fast-checkable (email rule)
            ]),
        ],
        data: [
            'items' => [
                ['name' => 'Alice', 'email' => 'alice@example.com'],
                ['name' => 'Bob', 'email' => 'not-an-email'],
            ],
        ],
    );

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    expect($validator->passes())->toBeFalse();
    // names are valid (fast-checked), email on item 1 fails (Laravel fallback)
    expect($validator->errors()->keys())->toContain('items.1.email');
    expect($validator->errors()->keys())->not->toContain('items.0.name');
    expect($validator->errors()->keys())->not->toContain('items.1.name');
});

// =========================================================================
// Edge cases: stopOnFirstFailure
// =========================================================================

it('respects stopOnFirstFailure with fast-checked attributes', function (): void {
    $formRequest = createFluentFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->max(255),
                'qty' => FluentRule::numeric()->required()->min(1),
            ]),
        ],
        data: [
            'items' => [
                ['name' => '', 'qty' => 0],   // Both fields invalid
                ['name' => '', 'qty' => -1],   // Both fields invalid
            ],
        ],
    );

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);
    $validator->stopOnFirstFailure();

    expect($validator->passes())->toBeFalse();
    // With stopOnFirstFailure, should have errors for only the first failing attribute.
    expect($validator->errors()->count())->toBeLessThanOrEqual(2);
});

// =========================================================================
// Edge cases: children() rules (non-wildcard nesting)
// =========================================================================

it('handles children() rules without wildcards', function (): void {
    $formRequest = createFluentFormRequest(
        rules: [
            'address' => FluentRule::field()->children([
                'street' => FluentRule::string()->required()->max(255),
                'city' => FluentRule::string()->required()->max(100),
            ]),
        ],
        data: [
            'address' => [
                'street' => '123 Main St',
                'city' => 'Springfield',
            ],
        ],
    );

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    expect($validator->passes())->toBeTrue();
    expect($validator->validated()['address']['street'])->toBe('123 Main St');
});

it('reports errors in children() rules', function (): void {
    $formRequest = createFluentFormRequest(
        rules: [
            'address' => FluentRule::field()->children([
                'street' => FluentRule::string()->required()->max(255),
                'city' => FluentRule::string()->required()->max(100),
            ]),
        ],
        data: [
            'address' => [
                'street' => '',
                'city' => 'Springfield',
            ],
        ],
    );

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->has('address.street'))->toBeTrue();
    // Valid sibling should not have errors.
    expect($validator->errors()->has('address.city'))->toBeFalse();
});

// =========================================================================
// Edge cases: sometimes() dynamic rules
// =========================================================================

it('works with sometimes() rules added after validator creation', function (): void {
    $formRequest = createFluentFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->max(255),
                'type' => FluentRule::string()->required()->in(['book', 'dvd']),
            ]),
        ],
        data: [
            'items' => [
                ['name' => 'The Matrix', 'type' => 'dvd', 'isbn' => '1234'],
                ['name' => 'Dune', 'type' => 'book', 'isbn' => '5678'],
            ],
        ],
    );

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    // Add a dynamic rule via sometimes().
    $validator->sometimes('items.*.isbn', 'required|string|min:10', fn (Fluent $input, Fluent $item): bool => $item->type === 'book');

    expect($validator->passes())->toBeFalse();
    // isbn '5678' is too short (min:10) for the book item.
    expect($validator->errors()->has('items.1.isbn'))->toBeTrue();
    // The dvd item's isbn should not be checked (sometimes() condition is false for dvd).
    expect($validator->errors()->has('items.0.isbn'))->toBeFalse();
    // No errors on fields unrelated to the sometimes() rule.
    expect($validator->errors()->has('items.0.name'))->toBeFalse();
    expect($validator->errors()->has('items.1.name'))->toBeFalse();
});

// =========================================================================
// Edge cases: date fast check (fast-checkable without comparisons)
// =========================================================================

it('builds fast checks for plain date rules', function (): void {
    $checks = OptimizedValidator::buildFastChecks([
        'items.*.created_at' => 'required|date',
    ]);

    expect($checks)->toHaveKey('items.*.created_at');
    expect(($checks['items.*.created_at'])('2024-01-15'))->toBeTrue();
    expect(($checks['items.*.created_at'])(null))->toBeFalse();
});

// =========================================================================
// Edge cases: accepted/declined fast check
// =========================================================================

it('fast checks accepted and declined rules', function (): void {
    $acceptedCheck = OptimizedValidator::buildFastChecks([
        'items.*.agreed' => 'required|accepted',
    ]);
    expect($acceptedCheck)->toHaveKey('items.*.agreed');
    expect(($acceptedCheck['items.*.agreed'])(true))->toBeTrue();
    expect(($acceptedCheck['items.*.agreed'])('yes'))->toBeTrue();
    expect(($acceptedCheck['items.*.agreed'])('on'))->toBeTrue();
    expect(($acceptedCheck['items.*.agreed'])(false))->toBeFalse();
    expect(($acceptedCheck['items.*.agreed'])('no'))->toBeFalse();

    $declinedCheck = OptimizedValidator::buildFastChecks([
        'items.*.declined' => 'required|declined',
    ]);
    expect($declinedCheck)->toHaveKey('items.*.declined');
    expect(($declinedCheck['items.*.declined'])(false))->toBeTrue();
    expect(($declinedCheck['items.*.declined'])('no'))->toBeTrue();
    expect(($declinedCheck['items.*.declined'])('off'))->toBeTrue();
    expect(($declinedCheck['items.*.declined'])(true))->toBeFalse();
    expect(($declinedCheck['items.*.declined'])('yes'))->toBeFalse();
});

// =========================================================================
// Edge cases: many items with sparse errors
// =========================================================================

it('correctly identifies the specific invalid item among many valid ones', function (): void {
    $items = [];
    for ($i = 0; $i < 50; ++$i) {
        $items[] = ['name' => 'Valid Name ' . $i, 'qty' => $i + 1];
    }

    // Make item 37 invalid.
    $items[37] = ['name' => '', 'qty' => 0];

    $formRequest = createFluentFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->max(255),
                'qty' => FluentRule::numeric()->required()->min(1),
            ]),
        ],
        data: ['items' => $items],
    );

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->has('items.37.name'))->toBeTrue();
    expect($validator->errors()->has('items.37.qty'))->toBeTrue();
    // Ensure no false positives on valid items.
    expect($validator->errors()->has('items.0.name'))->toBeFalse();
    expect($validator->errors()->has('items.36.name'))->toBeFalse();
    expect($validator->errors()->has('items.38.name'))->toBeFalse();
});

// =========================================================================
// Edge cases: factory resolver restoration
// =========================================================================

it('restores the factory resolver after creating the validator', function (): void {
    $formRequest = createFluentFormRequest(
        rules: [
            'items' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required(),
            ]),
        ],
        data: ['items' => [['name' => 'Test']]],
    );

    $factory = app(Factory::class);
    (fn () => $this->createDefaultValidator($factory))->call($formRequest);

    // The factory should still create standard Validators after FluentFormRequest is done.
    $standardValidator = $factory->make(['x' => 'y'], ['x' => 'required']);
    expect($standardValidator)->toBeInstanceOf(Validator::class);
    expect($standardValidator)->not->toBeInstanceOf(OptimizedValidator::class);
});

// =========================================================================
// Integration: full HTTP request through the framework
// =========================================================================

it('validates a POST request through FluentFormRequest', function (): void {
    Route::post('/test-fluent-form-request', function (Request $request) {
        $formRequest = createFluentFormRequest(
            rules: [
                'items' => FluentRule::array()->required()->each([
                    'name' => FluentRule::string()->required()->min(2),
                ]),
            ],
            data: $request->all(),
        );

        $factory = app(Factory::class);
        $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

        return response()->json($validator->validated());
    });

    $response = $this->postJson('/test-fluent-form-request', [ // @phpstan-ignore method.notFound
        'items' => [
            ['name' => 'John'],
            ['name' => 'Jane'],
        ],
    ]);

    $response->assertOk();
    $response->assertJsonCount(2, 'items');
});

it('returns 422 for invalid data through FluentFormRequest', function (): void {
    Route::post('/test-fluent-form-request-fail', function (Request $request) {
        $formRequest = createFluentFormRequest(
            rules: [
                'items' => FluentRule::array()->required()->each([
                    'name' => FluentRule::string()->required()->min(5),
                ]),
            ],
            data: $request->all(),
        );

        $factory = app(Factory::class);
        $validator = (fn () => $this->createDefaultValidator($factory))->call($formRequest);

        if ($validator->fails()) {
            throw new ValidationException($validator); // @phpstan-ignore argument.type
        }

        return response()->json($validator->validated());
    });

    $response = $this->postJson('/test-fluent-form-request-fail', [ // @phpstan-ignore method.notFound
        'items' => [
            ['name' => 'Jo'],
        ],
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['items.0.name']);
});

// =========================================================================
// Helper
// =========================================================================

/**
 * @param array<string, mixed> $rules
 * @param array<array-key, mixed> $data
 */
function createFluentFormRequest(array $rules, array $data): FormRequest
{
    $formRequest = new class extends FluentFormRequest {
        /** @var array<string, mixed> */
        public static array $testRules = [];

        /** @return array<string, mixed> */
        public function rules(): array
        {
            return self::$testRules;
        }

        public function authorize(): bool
        {
            return true;
        }
    };

    $formRequest::$testRules = $rules;

    $request = Request::create('/test', 'POST', $data);
    $instance = $formRequest::createFrom($request);
    $instance->setContainer(app());
    $instance->setRedirector(app('redirect'));

    return $instance;
}
