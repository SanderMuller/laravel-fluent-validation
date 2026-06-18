<?php declare(strict_types=1);

use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\RuleSet;

// =========================================================================
// A regex rule whose pattern contains a literal `|` (e.g. `regex:/^(a|b)$/`)
// must survive compilation. compiledRules() used to pipe-join all-stringifiable
// rules into one string, which Laravel's parser then split on `|`, corrupting
// the pattern (preg "No ending delimiter"). Compilation now falls back to array
// form when any token contains `|`, so Laravel parses each rule intact.
// =========================================================================

it('compiledRules returns array form (not a pipe string) when a rule contains a pipe', function (): void {
    $compiled = FluentRule::field()->required()->rule('regex:/^(foo|bar)$/')->compiledRules();

    expect($compiled)->toBeArray()
        ->and($compiled)->toContain('regex:/^(foo|bar)$/')
        ->and($compiled)->toContain('required');
});

it('compiledRules still pipe-joins when no rule contains a pipe', function (): void {
    expect(FluentRule::field()->required()->rule('string')->compiledRules())
        ->toBe('required|string');
});

it('object-form regex with a pipe validates like native (flat field)', function (): void {
    foreach (['foo' => true, 'bar' => true, 'zzz' => false] as $code => $shouldPass) {
        $fails = RuleSet::from(['code' => FluentRule::field()->required()->rule('regex:/^(foo|bar)$/')])
            ->check(['code' => $code])
            ->fails();

        expect($fails)->toBe(! $shouldPass, "code={$code}");
    }
});

it('object-form regex with a pipe validates like native (wildcard item)', function (): void {
    $native = validator(
        ['items' => [['code' => 'zzz']]],
        ['items.*.code' => ['required', 'regex:/^(foo|bar)$/']],
    )->errors()->keys();
    sort($native);

    $fluent = RuleSet::from([
        'items.*.code' => FluentRule::field()->required()->rule('regex:/^(foo|bar)$/'),
    ])->check(['items' => [['code' => 'zzz']]])->errors()->keys();
    sort($fluent);

    expect($fluent)->toBe($native)->and($fluent)->toBe(['items.0.code']);
});

it('object-form regex with a pipe works alongside a conditional rule on a wildcard item', function (): void {
    // Exercises the ItemRuleCompiler::stripConditionalTuples join path: after the
    // exclude_unless tuple is stripped, the remaining regex token must not be
    // pipe-joined-then-split.
    $rules = [
        'items.*.type' => ['required', 'string'],
        'items.*.code' => FluentRule::field()
            ->excludeUnless('items.*.type', 'keep')
            ->rule('regex:/^(foo|bar)$/'),
    ];

    $errors = RuleSet::from($rules)->check(['items' => [['type' => 'keep', 'code' => 'zzz']]])->errors()->keys();

    expect($errors)->toBe(['items.0.code']); // included (type=keep), regex intact, 'zzz' fails
});
