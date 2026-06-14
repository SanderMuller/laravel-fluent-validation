<?php declare(strict_types=1);

use SanderMuller\FluentValidation\RuleSet;

// Coverage for RuleSet::separateRules() wildcard-key parsing (spec 003).
//
// OUTCOME — guard added. Phase 1 found that a key containing '*' with no '.*'
// segment computes an empty parent and is then silently dropped by
// validateWildcardGroups (no validation runs — invalid data passes). That covers
// the typo 'items*' (for 'items.*') and the root-level forms '*' / '*.foo'. Root
// wildcards are not part of the typed API surface anyway — RuleSet::check() takes
// `array<string, mixed>`, not a root list — so every well-formed wildcard key
// contains '.*'. separateRules() now fail-fasts on any '*' outside a '.*' segment
// with a corrective hint, turning a silent validation bypass into a loud error.
// The guard runs while separating rule KEYS, before data is read, so the throw
// fires regardless of the data passed.

// --- Valid shapes: validate, and match native Laravel ------------------------

it('validates nested wildcard fields (addresses.*.postcode) like native Laravel', function (): void {
    $data = ['addresses' => [['postcode' => '']]];
    $rules = 'required|string|min:5';

    $fluent = RuleSet::from(['addresses.*.postcode' => $rules])->check($data)->errors()->toArray();

    expect($fluent)->toHaveKey('addresses.0.postcode')
        ->and(validator($data, ['addresses.*.postcode' => $rules])->fails())->toBeTrue();
});

it('validates scalar-each (items.*) like native Laravel', function (): void {
    $data = ['items' => ['']];
    $rules = 'required|string|min:5';

    $fluent = RuleSet::from(['items.*' => $rules])->check($data)->fails();

    expect($fluent)->toBeTrue()
        ->and($fluent)->toBe(validator($data, ['items.*' => $rules])->fails());
});

it('does not throw on well-formed wildcard keys', function (): void {
    expect(fn () => RuleSet::from(['items.*.name' => 'string'])->check(['items' => [['name' => 'x']]]))
        ->not->toThrow(InvalidArgumentException::class)
        ->and(fn () => RuleSet::from(['items.*' => 'string'])->check(['items' => ['x']]))
        ->not->toThrow(InvalidArgumentException::class);
});

// --- Malformed shapes: fail fast instead of silently dropping ----------------

it('throws a helpful error on the malformed `items*` typo (missing dot)', function (): void {
    expect(fn () => RuleSet::from(['items*' => 'required|string'])->check([]))
        ->toThrow(InvalidArgumentException::class, "Did you mean 'items.*'?");
});

it('throws on a root-level `*` key (root wildcards are unsupported)', function (): void {
    expect(fn () => RuleSet::from(['*' => 'required|string'])->check([]))
        ->toThrow(InvalidArgumentException::class, 'Malformed wildcard rule key');
});

it('throws on a root-level `*.foo` key', function (): void {
    expect(fn () => RuleSet::from(['*.foo' => 'required|string'])->check([]))
        ->toThrow(InvalidArgumentException::class, 'Malformed wildcard rule key');
});
