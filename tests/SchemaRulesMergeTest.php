<?php declare(strict_types=1);

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\FluentSchema;
use SanderMuller\FluentValidation\HasFluentRules;
use SanderMuller\FluentValidation\RuleSet;
use SanderMuller\FluentValidation\Tests\Fixtures\MergeRulesBaseRequest;
use SanderMuller\FluentValidation\Tests\Fixtures\MergeSchemaBaseRequest;
use SanderMuller\FluentValidation\Tests\Fixtures\ProvidesMergeSchemaViaTrait;

/**
 * When a FormRequest (across its class hierarchy) declares both schema() and
 * rules(), HasFluentRules merges the two instead of one shadowing the other.
 * The more-specific declaration wins each shared field — the deeper class in
 * the hierarchy, or a body definition over a trait import — so an abstract base
 * or trait can supply shared fields and a concrete request override/extend
 * them, exactly like a plain method override, while non-colliding fields from
 * both layers survive.
 */
function schemaBaseWithChildRules(): MergeSchemaBaseRequest
{
    return new class extends MergeSchemaBaseRequest {
        /** @return array<string, mixed> */
        public function rules(): array
        {
            return [
                'shared' => FluentRule::string()->required()->in(['child']),
                'child_only' => FluentRule::string()->required(),
            ];
        }
    };
}

function rulesBaseWithChildSchema(): MergeRulesBaseRequest
{
    return new class extends MergeRulesBaseRequest {
        /** @return array<string, mixed> */
        public function schema(FluentSchema $rules): array
        {
            return [
                'shared' => $rules->string()->required()->in(['child']),
                'child_only' => $rules->string()->required(),
            ];
        }
    };
}

function traitSchemaWithBodyRules(): FormRequest
{
    return new class extends FormRequest {
        use HasFluentRules;
        use ProvidesMergeSchemaViaTrait;

        /** @return array<string, mixed> */
        public function rules(): array
        {
            return [
                'shared' => FluentRule::string()->required()->in(['body']),
                'body_only' => FluentRule::string()->required(),
            ];
        }

        public function authorize(): bool
        {
            return true;
        }
    };
}

// --- base schema() + child rules(): child (more derived) wins -----------------

it('merges base schema() and child rules(), applying both layers', function (): void {
    $request = bootFormRequest(schemaBaseWithChildRules(), [
        'shared' => 'child',
        'base_only' => 'x',
        'child_only' => 'y',
    ]);

    $request->validateResolved();

    expect($request->validated())
        ->toHaveKeys(['shared', 'base_only', 'child_only'])
        ->and($request->validated()['shared'])->toBe('child');
});

it('lets the child rules() win the shared key over the base schema()', function (): void {
    // 'base' satisfies the base schema() (in:base) but not the child rules()
    // (in:child); the more-derived child wins, so this must fail.
    bootFormRequest(schemaBaseWithChildRules(), [
        'shared' => 'base',
        'base_only' => 'x',
        'child_only' => 'y',
    ])->validateResolved();
})->throws(ValidationException::class);

it('still enforces the base schema() layer through the child', function (): void {
    // base_only exists only on the base schema(); omitting it must fail,
    // proving the base layer is merged in rather than shadowed.
    bootFormRequest(schemaBaseWithChildRules(), [
        'shared' => 'child',
        'child_only' => 'y',
    ])->validateResolved();
})->throws(ValidationException::class);

// --- base rules() + child schema(): child (more derived) wins -----------------

it('merges base rules() and child schema(), applying both layers', function (): void {
    $request = bootFormRequest(rulesBaseWithChildSchema(), [
        'shared' => 'child',
        'base_only' => 'x',
        'child_only' => 'y',
    ]);

    $request->validateResolved();

    expect($request->validated())
        ->toHaveKeys(['shared', 'base_only', 'child_only'])
        ->and($request->validated()['shared'])->toBe('child');
});

it('lets the child schema() win the shared key over the base rules()', function (): void {
    bootFormRequest(rulesBaseWithChildSchema(), [
        'shared' => 'base',
        'base_only' => 'x',
        'child_only' => 'y',
    ])->validateResolved();
})->throws(ValidationException::class);

// --- trait provides schema(), body defines rules(): body wins -----------------

it('lets a body rules() win over a trait-provided schema()', function (): void {
    $request = bootFormRequest(traitSchemaWithBodyRules(), [
        'shared' => 'body',
        'trait_only' => 'x',
        'body_only' => 'y',
    ]);

    $request->validateResolved();

    expect($request->validated())
        ->toHaveKeys(['shared', 'trait_only', 'body_only'])
        ->and($request->validated()['shared'])->toBe('body');
});

it('fails a trait+body request when the body rules() rejects the shared value', function (): void {
    // 'trait' satisfies the trait schema() but not the body rules(); the body
    // definition is more specific than the trait import, so it wins → fail.
    bootFormRequest(traitSchemaWithBodyRules(), [
        'shared' => 'trait',
        'trait_only' => 'x',
        'body_only' => 'y',
    ])->validateResolved();
})->throws(ValidationException::class);

// --- RuleSet returns from either source merge as a union ----------------------

it('merges when both sources return a RuleSet', function (): void {
    $request = new class extends FormRequest {
        use HasFluentRules;

        public function schema(FluentSchema $rules): RuleSet
        {
            return RuleSet::from(['from_schema' => $rules->string()->required()]);
        }

        public function rules(): RuleSet
        {
            return RuleSet::from(['from_rules' => FluentRule::string()->required()]);
        }

        public function authorize(): bool
        {
            return true;
        }
    };

    $booted = bootFormRequest($request, ['from_schema' => 'a', 'from_rules' => 'b']);
    $booted->validateResolved();

    expect($booted->validated())->toHaveKeys(['from_schema', 'from_rules']);
});

// --- runtime parent::rules() composes with the merge --------------------------

it('composes a parent::rules() call within the merge', function (): void {
    // The child rules() pulls the base rules() through parent::rules() (which
    // resolves normally because the base still declares rules()), and the merge
    // then combines that with the child schema(). This pins the boundary: a
    // runtime parent:: call is fine — only renaming the base's rules() away
    // (a migration step) would break it.
    $request = new class extends MergeRulesBaseRequest {
        /** @return array<string, mixed> */
        public function rules(): array
        {
            return [
                ...parent::rules(),
                'child_only' => FluentRule::string()->required(),
            ];
        }

        /** @return array<string, mixed> */
        public function schema(FluentSchema $rules): array
        {
            return ['schema_only' => $rules->string()->required()];
        }
    };

    $booted = bootFormRequest($request, [
        'shared' => 'base',
        'base_only' => 'x',
        'child_only' => 'y',
        'schema_only' => 'z',
    ]);
    $booted->validateResolved();

    expect($booted->validated())->toHaveKeys(['shared', 'base_only', 'child_only', 'schema_only']);
});
