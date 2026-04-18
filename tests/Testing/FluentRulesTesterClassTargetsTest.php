<?php declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\AssertionFailedError;
use SanderMuller\FluentValidation\Testing\FluentRulesTester;
use SanderMuller\FluentValidation\Tests\Fixtures\AuthorizedEachFluentFormRequest;
use SanderMuller\FluentValidation\Tests\Fixtures\ExampleFluentValidator;
use SanderMuller\FluentValidation\Tests\Fixtures\UnauthorizedFluentFormRequest;

// =========================================================================
// FormRequest class-string — authorized path
// =========================================================================

it('validates an authorized FormRequest with valid data', function (): void {
    FluentRulesTester::for(AuthorizedEachFluentFormRequest::class)
        ->with([
            'items' => [
                ['name' => 'Widget', 'qty' => 5],
                ['name' => 'Gadget', 'qty' => 10],
            ],
        ])
        ->passes();
});

it('reports each()-level errors from a FormRequest', function (): void {
    FluentRulesTester::for(AuthorizedEachFluentFormRequest::class)
        ->with([
            'items' => [
                ['name' => 'OK', 'qty' => 1],
                ['name' => '', 'qty' => 0],
            ],
        ])
        ->fails()
        ->failsWith('items.1.name', 'required')
        ->failsWith('items.1.qty', 'min');
});

it('returns validated data from a passing FormRequest', function (): void {
    $validated = FluentRulesTester::for(AuthorizedEachFluentFormRequest::class)
        ->with([
            'items' => [
                ['name' => 'Widget', 'qty' => 3],
            ],
        ])
        ->validated();

    expect($validated)->toHaveKey('items')
        ->and($validated['items'])->toHaveCount(1);
});

// =========================================================================
// FormRequest class-string — unauthorized path
// =========================================================================

it('records AuthorizationException from an unauthorized FormRequest', function (): void {
    FluentRulesTester::for(UnauthorizedFluentFormRequest::class)
        ->with(['name' => 'Ada'])
        ->fails()
        ->assertUnauthorized();
});

it('assertUnauthorized fails when the request authorized successfully', function (): void {
    expect(static function (): void {
        FluentRulesTester::for(AuthorizedEachFluentFormRequest::class)
            ->with(['items' => [['name' => 'OK', 'qty' => 1]]])
            ->assertUnauthorized();
    })->toThrow(AssertionFailedError::class);
});

// =========================================================================
// FluentValidator class-string — with variadic ctor args
// =========================================================================

it('validates a FluentValidator subclass without ctor args', function (): void {
    FluentRulesTester::for(ExampleFluentValidator::class)
        ->with(['name' => 'anything'])
        ->passes();
});

it('forwards variadic ctor args to a FluentValidator subclass', function (): void {
    FluentRulesTester::for(ExampleFluentValidator::class, 'SKU-')
        ->with(['name' => 'SKU-123'])
        ->passes();

    FluentRulesTester::for(ExampleFluentValidator::class, 'SKU-')
        ->with(['name' => 'nope'])
        ->fails()
        ->failsWith('name', 'starts_with');
});

it('returns validated data from a passing FluentValidator', function (): void {
    $validated = FluentRulesTester::for(ExampleFluentValidator::class)
        ->with(['name' => 'Ada'])
        ->validated();

    expect($validated)->toBe(['name' => 'Ada']);
});

// =========================================================================
// Lazy-validation contract — still applies to class-string targets
// =========================================================================

it('raises LogicException when passes() runs before with() on FormRequest target', function (): void {
    expect(static function (): void {
        FluentRulesTester::for(AuthorizedEachFluentFormRequest::class)->passes();
    })->toThrow(LogicException::class);
});

it('raises LogicException when passes() runs before with() on FluentValidator target', function (): void {
    expect(static function (): void {
        FluentRulesTester::for(ExampleFluentValidator::class)->passes();
    })->toThrow(LogicException::class);
});

it('raises LogicException when assertUnauthorized() runs before with()', function (): void {
    expect(static function (): void {
        FluentRulesTester::for(UnauthorizedFluentFormRequest::class)->assertUnauthorized();
    })->toThrow(LogicException::class);
});

// =========================================================================
// Validated::validated() throws on failure for class-string targets too
// =========================================================================

it('validated() throws ValidationException after a FormRequest fails', function (): void {
    expect(static function (): void {
        FluentRulesTester::for(AuthorizedEachFluentFormRequest::class)
            ->with(['items' => [['name' => '', 'qty' => 0]]])
            ->validated();
    })->toThrow(ValidationException::class);
});

it('validated() throws ValidationException after a FluentValidator fails', function (): void {
    expect(static function (): void {
        FluentRulesTester::for(ExampleFluentValidator::class, 'SKU-')
            ->with(['name' => 'wrong'])
            ->validated();
    })->toThrow(ValidationException::class);
});
