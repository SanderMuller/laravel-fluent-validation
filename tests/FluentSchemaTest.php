<?php declare(strict_types=1);

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Traits\Macroable;
use Illuminate\Validation\Rules\AnyOf;
use Illuminate\Validation\ValidationException;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\FluentSchema;
use SanderMuller\FluentValidation\HasFluentRules;
use SanderMuller\FluentValidation\Rules\StringRule;
use SanderMuller\FluentValidation\RuleSet;
use SanderMuller\FluentValidation\Tests\Fixtures\TestStringEnum;

/**
 * Snapshot the fully prepared output (compiled rules + custom messages +
 * attribute labels) of a single field, so two rule builders can be compared
 * for exact equivalence — catching argument-order/forwarding mistakes the
 * reflection signature check cannot see.
 *
 * @return array{rules: array<string, mixed>, messages: array<string, string>, attributes: array<string, string>}
 */
function preparedSnapshot(mixed $rule): array
{
    $prepared = RuleSet::from(['field' => $rule])->prepare(['field' => 'value']);

    return [
        'rules' => $prepared->rules,
        'messages' => $prepared->messages,
        'attributes' => $prepared->attributes,
    ];
}

/**
 * A FormRequest that defines BOTH schema() and rules() — used to pin the
 * precedence contract (schema() wins).
 *
 * @param  array<array-key, mixed>  $data
 */
function bootDualFormRequest(array $data): FormRequest
{
    $formRequest = new class extends FormRequest {
        use HasFluentRules;

        /** @return array<string, mixed> */
        public function schema(FluentSchema $rules): array
        {
            return ['value' => $rules->string()->required()->in(['from-schema'])];
        }

        /** @return array<string, mixed> */
        public function rules(): array
        {
            return ['value' => FluentRule::string()->required()->in(['from-rules'])];
        }

        public function authorize(): bool
        {
            return true;
        }
    };

    return bootFormRequest($formRequest, $data);
}

/**
 * Names that come from the Macroable trait, not the factory surface — they
 * are intentionally not mirrored on FluentSchema (macros are forwarded via
 * __call instead).
 *
 * @return list<string>
 */
function macroableMethodNames(): array
{
    return array_map(
        static fn (ReflectionMethod $m): string => $m->getName(),
        (new ReflectionClass(Macroable::class))->getMethods(),
    );
}

/** @return list<ReflectionMethod> */
function fluentRuleFactoryMethods(): array
{
    $skip = macroableMethodNames();

    return array_values(array_filter(
        (new ReflectionClass(FluentRule::class))->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC),
        static fn (ReflectionMethod $m): bool => ! in_array($m->getName(), $skip, true),
    ));
}

// =========================================================================
// Parity — FluentSchema mirrors FluentRule one-to-one
// =========================================================================

it('exposes an instance method for every FluentRule factory', function (ReflectionMethod $factory): void {
    $schema = new ReflectionClass(FluentSchema::class);

    expect($schema->hasMethod($factory->getName()))
        ->toBeTrue("FluentSchema is missing a delegate for FluentRule::{$factory->getName()}()");

    $mirror = $schema->getMethod($factory->getName());

    expect($mirror->isPublic())->toBeTrue("FluentSchema::{$factory->getName()}() must be public")
        ->and($mirror->isStatic())
        ->toBeFalse("FluentSchema::{$factory->getName()}() must be an instance method");
})->with(fluentRuleFactoryMethods());

it('matches the return type of every FluentRule factory', function (ReflectionMethod $factory): void {
    $mirror = (new ReflectionClass(FluentSchema::class))->getMethod($factory->getName());

    expect((string) $mirror->getReturnType())
        ->toBe((string) $factory->getReturnType(), "FluentSchema::{$factory->getName()}() return type drifted");
})->with(fluentRuleFactoryMethods());

it('matches the parameter signature of every FluentRule factory', function (ReflectionMethod $factory): void {
    $mirror = (new ReflectionClass(FluentSchema::class))->getMethod($factory->getName());

    $signature = static fn (ReflectionMethod $m): array => array_map(
        static fn (ReflectionParameter $p): string => sprintf(
            '%s $%s%s',
            (string) $p->getType(),
            $p->getName(),
            $p->isDefaultValueAvailable() ? '=' . var_export($p->getDefaultValue(), true) : '',
        ),
        $m->getParameters(),
    );

    expect($signature($mirror))
        ->toBe($signature($factory), "FluentSchema::{$factory->getName()}() signature drifted");
})->with(fluentRuleFactoryMethods());

it('has no instance methods beyond the FluentRule factory surface', function (): void {
    $factoryNames = array_map(static fn (ReflectionMethod $m): string => $m->getName(), fluentRuleFactoryMethods());

    $extra = array_filter(
        (new ReflectionClass(FluentSchema::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn (ReflectionMethod $m): bool => $m->getName() !== '__call' && ! in_array($m->getName(), $factoryNames, true),
    );

    expect($extra)->toBe([], 'FluentSchema declares a method with no FluentRule counterpart');
});

// =========================================================================
// Behaviour — delegates produce identical rule objects
// =========================================================================

it('builds the same rule object as the static factory', function (): void {
    $schema = new FluentSchema();

    expect($schema->string('Name')->required()->max(255)->compiledRules())
        ->toBe(FluentRule::string('Name')->required()->max(255)->compiledRules());
});

it('returns the concrete typed rule from a starter', function (): void {
    expect((new FluentSchema())->string())->toBeInstanceOf(StringRule::class);
});

it('forwards every non-default argument identically to FluentRule', function (Closure $viaSchema, Closure $viaStatic): void {
    expect(preparedSnapshot($viaSchema(new FluentSchema())))
        ->toEqual(preparedSnapshot($viaStatic()));
})->with([
    'string label+message' => [
        fn (FluentSchema $s): mixed => $s->string('Full Name', 'msg')->required(),
        fn (): mixed => FluentRule::string('Full Name', 'msg')->required(),
    ],
    'integer strict' => [
        fn (FluentSchema $s): mixed => $s->integer('Age', 'msg', true)->required(),
        fn (): mixed => FluentRule::integer('Age', 'msg', true)->required(),
    ],
    'email no-defaults' => [
        fn (FluentSchema $s): mixed => $s->email('Email', false, 'msg')->required(),
        fn (): mixed => FluentRule::email('Email', false, 'msg')->required(),
    ],
    'password min+flags' => [
        fn (FluentSchema $s): mixed => $s->password(12, 'Password', false)->mixedCase(),
        fn (): mixed => FluentRule::password(12, 'Password', false)->mixedCase(),
    ],
    'array keys+label' => [
        fn (FluentSchema $s): mixed => $s->array(['a', 'b'], 'Items', 'msg')->required(),
        fn (): mixed => FluentRule::array(['a', 'b'], 'Items', 'msg')->required(),
    ],
    'regex pattern+label' => [
        fn (FluentSchema $s): mixed => $s->regex('/^SKU-/', 'Sku', 'msg')->required(),
        fn (): mixed => FluentRule::regex('/^SKU-/', 'Sku', 'msg')->required(),
    ],
    'enum type+label' => [
        fn (FluentSchema $s): mixed => $s->enum(TestStringEnum::class, null, 'Status', 'msg')->required(),
        fn (): mixed => FluentRule::enum(TestStringEnum::class, null, 'Status', 'msg')->required(),
    ],
    'dateTime label+message' => [
        fn (FluentSchema $s): mixed => $s->dateTime('Starts At', 'msg')->required(),
        fn (): mixed => FluentRule::dateTime('Starts At', 'msg')->required(),
    ],
    // The remaining delegates take a uniform (label, message) signature. Running
    // each one proves it forwards to the RIGHT FluentRule factory — a wrong
    // target (e.g. url() calling uuid()) survives the signature parity check but
    // produces a different compiled rule here.
    'numeric' => [fn (FluentSchema $s): mixed => $s->numeric('L', 'm')->required(), fn (): mixed => FluentRule::numeric('L', 'm')->required()],
    'date' => [fn (FluentSchema $s): mixed => $s->date('L', 'm')->required(), fn (): mixed => FluentRule::date('L', 'm')->required()],
    'boolean' => [fn (FluentSchema $s): mixed => $s->boolean('L', 'm')->required(), fn (): mixed => FluentRule::boolean('L', 'm')->required()],
    'accepted' => [fn (FluentSchema $s): mixed => $s->accepted('L', 'm')->required(), fn (): mixed => FluentRule::accepted('L', 'm')->required()],
    'declined' => [fn (FluentSchema $s): mixed => $s->declined('L', 'm')->required(), fn (): mixed => FluentRule::declined('L', 'm')->required()],
    'file' => [fn (FluentSchema $s): mixed => $s->file('L', 'm')->required(), fn (): mixed => FluentRule::file('L', 'm')->required()],
    'image' => [fn (FluentSchema $s): mixed => $s->image('L', 'm')->required(), fn (): mixed => FluentRule::image('L', 'm')->required()],
    'url' => [fn (FluentSchema $s): mixed => $s->url('L', 'm')->required(), fn (): mixed => FluentRule::url('L', 'm')->required()],
    'uuid' => [fn (FluentSchema $s): mixed => $s->uuid('L', 'm')->required(), fn (): mixed => FluentRule::uuid('L', 'm')->required()],
    'ulid' => [fn (FluentSchema $s): mixed => $s->ulid('L', 'm')->required(), fn (): mixed => FluentRule::ulid('L', 'm')->required()],
    'ip' => [fn (FluentSchema $s): mixed => $s->ip('L', 'm')->required(), fn (): mixed => FluentRule::ip('L', 'm')->required()],
    'ipv4' => [fn (FluentSchema $s): mixed => $s->ipv4('L', 'm')->required(), fn (): mixed => FluentRule::ipv4('L', 'm')->required()],
    'ipv6' => [fn (FluentSchema $s): mixed => $s->ipv6('L', 'm')->required(), fn (): mixed => FluentRule::ipv6('L', 'm')->required()],
    'macAddress' => [fn (FluentSchema $s): mixed => $s->macAddress('L', 'm')->required(), fn (): mixed => FluentRule::macAddress('L', 'm')->required()],
    'json' => [fn (FluentSchema $s): mixed => $s->json('L', 'm')->required(), fn (): mixed => FluentRule::json('L', 'm')->required()],
    'timezone' => [fn (FluentSchema $s): mixed => $s->timezone('L', 'm')->required(), fn (): mixed => FluentRule::timezone('L', 'm')->required()],
    'hexColor' => [fn (FluentSchema $s): mixed => $s->hexColor('L', 'm')->required(), fn (): mixed => FluentRule::hexColor('L', 'm')->required()],
    'activeUrl' => [fn (FluentSchema $s): mixed => $s->activeUrl('L', 'm')->required(), fn (): mixed => FluentRule::activeUrl('L', 'm')->required()],
    'list' => [fn (FluentSchema $s): mixed => $s->list('L', 'm')->required(), fn (): mixed => FluentRule::list('L', 'm')->required()],
    'field' => [fn (FluentSchema $s): mixed => $s->field('L')->required(), fn (): mixed => FluentRule::field('L')->required()],
]);

it('forwards anyOf to FluentRule when AnyOf is available', function (): void {
    if (! class_exists(AnyOf::class)) {
        $this->markTestSkipped('AnyOf requires Laravel 13+.');
    }

    expect((new FluentSchema())->anyOf([FluentRule::string(), FluentRule::integer()]))
        ->toBeInstanceOf(AnyOf::class);
})->skip(! class_exists(AnyOf::class), 'AnyOf requires Laravel 13+.');

// =========================================================================
// RuleSet::define()
// =========================================================================

it('validates through RuleSet::define', function (): void {
    $validated = RuleSet::define(fn (FluentSchema $rules): array => [
        'name' => $rules->string('Full Name')->required()->min(2)->max(255),
        'email' => $rules->email()->required(),
        'items' => $rules->array()->required()->each([
            'id' => $rules->integer()->required(),
            'name' => $rules->string()->required(),
        ]),
    ])->validate([
        'name' => 'Sander',
        'email' => 'sander@example.com',
        'items' => [['id' => 1, 'name' => 'Widget']],
    ]);

    expect($validated)->toMatchArray(['name' => 'Sander', 'email' => 'sander@example.com']);
});

it('throws through RuleSet::define on invalid data', function (): void {
    RuleSet::define(fn (FluentSchema $rules): array => [
        'name' => $rules->string()->required()->min(2),
    ])->validate(['name' => 'x']);
})->throws(ValidationException::class);

// =========================================================================
// FormRequest schema() hook
// =========================================================================

it('drives a FormRequest through schema()', function (): void {
    $request = createSchemaFormRequest(
        fn (FluentSchema $rules): array => [
            'title' => $rules->string()->required()->max(10),
        ],
        ['title' => 'hello'],
    );

    $request->validateResolved();

    expect($request->validated())->toMatchArray(['title' => 'hello']);
});

it('fails a FormRequest through schema() on invalid data', function (): void {
    $request = createSchemaFormRequest(
        fn (FluentSchema $rules): array => [
            'title' => $rules->string()->required()->max(3),
        ],
        ['title' => 'too long'],
    );

    $request->validateResolved();
})->throws(ValidationException::class);

it('lets schema() win a same-class collision with rules()', function (): void {
    // Both methods are declared in one class body on the same field, so neither
    // is more derived; the genuine tie resolves to schema().
    $request = bootDualFormRequest(['value' => 'from-schema']);

    $request->validateResolved();

    expect($request->validated())->toMatchArray(['value' => 'from-schema']);
});

it('applies schema() over rules() on a shared key, rejecting a rules()-only value', function (): void {
    // schema() and rules() both define `value` in one class body; schema() wins
    // the tie, so a value only rules() would accept must fail. rules() is merged
    // rather than ignored — SchemaRulesMergeTest covers the non-colliding fields.
    bootDualFormRequest(['value' => 'from-rules'])->validateResolved();
})->throws(ValidationException::class);

it('does not hijack an unrelated schema() method that lacks a FluentSchema parameter', function (): void {
    $formRequest = new class extends FormRequest {
        use HasFluentRules;

        /** A coincidental, non-validation schema() — must be left untouched. */
        public function schema(): string
        {
            return 'not-a-ruleset';
        }

        /** @return array<string, mixed> */
        public function rules(): array
        {
            return ['value' => FluentRule::string()->required()->in(['from-rules'])];
        }

        public function authorize(): bool
        {
            return true;
        }
    };

    $request = bootFormRequest($formRequest, ['value' => 'from-rules']);

    $request->validateResolved();

    expect($request->validated())->toMatchArray(['value' => 'from-rules']);
});

// =========================================================================
// Macro forwarding via __call
// =========================================================================

it('forwards macros to FluentRule through __call', function (): void {
    FluentRule::macro('slug', fn (): StringRule => FluentRule::string()->regex('/^[a-z0-9-]+$/'));

    // Exercise the forwarding method directly — the unknown macro name stays
    // off PHPStan's radar while still routing FluentSchema -> FluentRule.
    $rule = (new FluentSchema())->__call('slug', []);

    $this->assertInstanceOf(StringRule::class, $rule);

    FluentRule::flushMacros();
});
