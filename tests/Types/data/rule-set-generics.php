<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\Types\Data;

use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\RuleSet;
use SanderMuller\FluentValidation\Validated;

use function PHPStan\Testing\assertType;

/**
 * @param  RuleSet<array{a: int}>  $set
 * @param  array<string, mixed>  $data
 */
function narrowed(RuleSet $set, array $data): void
{
    $result = $set->check($data);
    assertType('SanderMuller\FluentValidation\Validated<array{a: int}>', $result);
    assertType('array{a: int}', $result->validated());
    assertType('array{a: int}', $set->validate($data));

    assertType('array{a: int}', $set->failOnUnknownFields()->validate($data));
    assertType('array{a: int}', $set->dropUnknownFields()->validate($data));
    assertType('array{a: int}', $set->stopOnFirstFailure()->validate($data));
    assertType('array{a: int}', $set->withBag('bag')->check($data)->validated());
    assertType('array{a: int}', $set->when(true, static fn (RuleSet $s): RuleSet => $s)->validate($data));
    assertType('array{a: int}', $set->unless(false, static fn (RuleSet $s): RuleSet => $s)->validate($data));

    assertType('array<string, mixed>', $set->field('b', FluentRule::string())->validate($data));
    assertType('array<string, mixed>', $set->merge(['b' => 'string'])->validate($data));
    assertType('array<string, mixed>', $set->only('a')->validate($data));
    assertType('array<string, mixed>', $set->except('a')->validate($data));
    assertType('array<string, mixed>', $set->put('b', 'string')->validate($data));
    assertType('array<string, mixed>', $set->modify('a', static fn (mixed $rule): mixed => $rule)->validate($data));
    assertType('array<string, mixed>', $set->modifyEach('a', [])->validate($data));
    assertType('array<string, mixed>', $set->modifyChildren('a', [])->validate($data));
}

/** @param  array<string, mixed>  $data */
function defaults(array $data): void
{
    assertType('SanderMuller\FluentValidation\RuleSet<array<string, mixed>>', RuleSet::make());
    assertType('SanderMuller\FluentValidation\RuleSet<array<string, mixed>>', RuleSet::from([]));
    assertType('array<string, mixed>', RuleSet::from(['a' => 'integer'])->validate($data));
    assertType('array<string, mixed>', RuleSet::from(['a' => 'integer'])->check($data)->validated());
}

/** Bare class names keep resolving to the default shape in consumer signatures. */
function bare(RuleSet $set): Validated
{
    assertType('array<string, mixed>', $set->check([])->validated());

    return $set->check([]);
}
