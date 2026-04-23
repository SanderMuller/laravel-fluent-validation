<?php declare(strict_types=1);

use SanderMuller\FluentValidation\FluentRule;

// =========================================================================
// Phase 3e — `message:` on FluentRule static factories. Every factory
// threading to an implicit constraint (string/numeric/email/uuid/…)
// writes customMessages under its defining key.
// =========================================================================

dataset('phase3e_type_factories', [
    // (factory, expected customMessages)
    'string' => [
        fn () => FluentRule::string(message: 'x'),
        ['string' => 'x'],
    ],
    'numeric' => [
        fn () => FluentRule::numeric(message: 'x'),
        ['numeric' => 'x'],
    ],
    'integer' => [
        fn () => FluentRule::integer(message: 'x'),
        ['integer' => 'x'],
    ],
    'boolean' => [
        fn () => FluentRule::boolean(message: 'x'),
        ['boolean' => 'x'],
    ],
    'accepted' => [
        fn () => FluentRule::accepted(message: 'x'),
        ['accepted' => 'x'],
    ],
    'declined' => [
        fn () => FluentRule::declined(message: 'x'),
        ['declined' => 'x'],
    ],
    'array' => [
        fn () => FluentRule::array(message: 'x'),
        ['array' => 'x'],
    ],
    'file' => [
        fn () => FluentRule::file(message: 'x'),
        ['file' => 'x'],
    ],
    'image' => [
        fn () => FluentRule::image(message: 'x'),
        ['image' => 'x'],
    ],
    'email' => [
        fn () => FluentRule::email(message: 'x'),
        ['email' => 'x'],
    ],
    // password omitted — PasswordRule doesn't accept `message:` on the factory.
    // L11 emits failures under sub-keys (password.letters, password.mixed, …),
    // not a bare `password` key. Users route via `messageFor('password.letters', '…')`.
]);

dataset('phase3e_shortcut_factories', [
    'url' => [
        fn () => FluentRule::url(message: 'x'),
        ['url' => 'x'],
    ],
    'uuid' => [
        fn () => FluentRule::uuid(message: 'x'),
        ['uuid' => 'x'],
    ],
    'ulid' => [
        fn () => FluentRule::ulid(message: 'x'),
        ['ulid' => 'x'],
    ],
    'ip' => [
        fn () => FluentRule::ip(message: 'x'),
        ['ip' => 'x'],
    ],
    'ipv4' => [
        fn () => FluentRule::ipv4(message: 'x'),
        ['ipv4' => 'x'],
    ],
    'ipv6' => [
        fn () => FluentRule::ipv6(message: 'x'),
        ['ipv6' => 'x'],
    ],
    'macAddress' => [
        fn () => FluentRule::macAddress(message: 'x'),
        ['mac_address' => 'x'],
    ],
    'json' => [
        fn () => FluentRule::json(message: 'x'),
        ['json' => 'x'],
    ],
    'timezone' => [
        fn () => FluentRule::timezone(message: 'x'),
        ['timezone' => 'x'],
    ],
    'hexColor' => [
        fn () => FluentRule::hexColor(message: 'x'),
        ['hex_color' => 'x'],
    ],
    'activeUrl' => [
        fn () => FluentRule::activeUrl(message: 'x'),
        ['active_url' => 'x'],
    ],
    'regex' => [
        fn () => FluentRule::regex('/^[a-z]+$/', message: 'x'),
        ['regex' => 'x'],
    ],
    'list' => [
        fn () => FluentRule::list(message: 'x'),
        ['list' => 'x'],
    ],
]);

it('Phase 3e type factories: message: writes to the factory defining key', function (
    Closure $inline,
    array $expected,
): void {
    expect($inline()->getCustomMessages())->toBe($expected);
})->with('phase3e_type_factories');

it('Phase 3e shortcut factories: message: writes to the final method key', function (
    Closure $inline,
    array $expected,
): void {
    expect($inline()->getCustomMessages())->toBe($expected);
})->with('phase3e_shortcut_factories');

// =========================================================================
// Factory `message:` coexists with instance method `message:` (different keys).
// =========================================================================

it('factory message: and chained method message: coexist under separate keys', function (): void {
    $rule = FluentRule::email(message: 'Invalid email.')
        ->required(message: 'Email is required.')
        ->max(255, message: 'Too long.');

    expect($rule->getCustomMessages())->toBe([
        'email' => 'Invalid email.',
        'required' => 'Email is required.',
        'max' => 'Too long.',
    ]);
});

it('factory message: can be overridden by later messageFor() on same key', function (): void {
    $rule = FluentRule::email(message: 'First.')->messageFor('email', 'Second.');

    expect($rule->getCustomMessages())->toBe(['email' => 'Second.']);
});

it('factory message: and label coexist without interfering', function (): void {
    $rule = FluentRule::email('Email Address', message: 'Invalid.');

    expect($rule->getLabel())->toBe('Email Address')
        ->and($rule->getCustomMessages())->toBe(['email' => 'Invalid.']);
});

it('two chained ->message() calls on the factory key produce last-write-wins', function (): void {
    $rule = FluentRule::email()->message('First.')->message('Second.');

    expect($rule->getCustomMessages())->toBe(['email' => 'Second.']);
});

// =========================================================================
// Deferred factories (DateRule) — `message:` not accepted; sanity that
// the existing signature still works without the param.
// =========================================================================

it('date factory does not accept message:; use messageFor or ->message() after a method', function (): void {
    $rule = FluentRule::date()->before('2026-12-31')->message('Too late.');

    expect($rule->getCustomMessages())->toBe(['before' => 'Too late.']);
});

// =========================================================================
// Live-validator smoke for factory-level messages.
// =========================================================================

it('FluentRule::email(message: ...) surfaces in live validation', function (): void {
    $v = makeValidator(
        ['contact' => 'notanemail'],
        ['contact' => FluentRule::email(defaults: false, message: 'Must be valid email.')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('contact'))->toBe('Must be valid email.');
});

it('FluentRule::uuid(message: ...) surfaces in live validation', function (): void {
    $v = makeValidator(
        ['token' => 'notuuid'],
        ['token' => FluentRule::uuid(message: 'Must be a UUID.')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('token'))->toBe('Must be a UUID.');
});

it('FluentRule::integer(message: ...) surfaces in live validation', function (): void {
    // 1.5 passes `numeric` but fails `integer`, so the integer-bound message fires.
    $v = makeValidator(
        ['age' => 1.5],
        ['age' => FluentRule::integer(message: 'Must be whole number.')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('age'))->toBe('Must be whole number.');
});

it('FluentRule::string(message: ...) surfaces in live validation', function (): void {
    $v = makeValidator(
        ['name' => ['array-not-string']],
        ['name' => FluentRule::string(message: 'Must be text.')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('name'))->toBe('Must be text.');
});

it('FluentRule::numeric(message: ...) surfaces in live validation', function (): void {
    $v = makeValidator(
        ['score' => 'abc'],
        ['score' => FluentRule::numeric(message: 'Must be a number.')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('score'))->toBe('Must be a number.');
});

it('FluentRule::array(message: ...) surfaces in live validation', function (): void {
    $v = makeValidator(
        ['tags' => 'not-an-array'],
        ['tags' => FluentRule::array(message: 'Must be a list.')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('tags'))->toBe('Must be a list.');
});

it('FluentRule::file(message: ...) surfaces in live validation', function (): void {
    $v = makeValidator(
        ['upload' => 'not-a-file'],
        ['upload' => FluentRule::file(message: 'Must be a file.')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('upload'))->toBe('Must be a file.');
});

it('FluentRule::image(message: ...) surfaces in live validation', function (): void {
    $v = makeValidator(
        ['avatar' => 'not-an-image'],
        ['avatar' => FluentRule::image(message: 'Must be an image.')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('avatar'))->toBe('Must be an image.');
});

it('FluentRule::boolean(message: ...) surfaces in live validation', function (): void {
    $v = makeValidator(
        ['agree' => 'maybe'],
        ['agree' => FluentRule::boolean(message: 'Must be true or false.')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('agree'))->toBe('Must be true or false.');
});
