<?php declare(strict_types=1);

use Illuminate\Validation\Rules\Email;
use SanderMuller\FluentValidation\FluentRule;

// =========================================================================
// Phase 1 verification — messageFor() binding is stable per factory.
//
// These tests prove the error-lookup key resolves deterministically for
// each factory. They must pass BEFORE $lastConstraint is seeded in the
// factory constructor, so that seeding is grounded in verified behaviour.
// =========================================================================

it('StringRule: messageFor("string") surfaces on failing string rule', function (): void {
    $v = makeValidator(
        ['name' => 123],
        ['name' => FluentRule::string()->messageFor('string', 'Must be text.')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('name'))->toBe('Must be text.');
});

it('NumericRule: messageFor("numeric") surfaces on failing numeric rule', function (): void {
    $v = makeValidator(
        ['age' => 'abc'],
        ['age' => FluentRule::numeric()->messageFor('numeric', 'Must be a number.')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('age'))->toBe('Must be a number.');
});

it('BooleanRule: messageFor("boolean") surfaces on failing boolean rule', function (): void {
    $v = makeValidator(
        ['active' => 'notabool'],
        ['active' => FluentRule::boolean()->messageFor('boolean', 'Must be a boolean.')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('active'))->toBe('Must be a boolean.');
});

it('AcceptedRule: messageFor("accepted") surfaces on failing accepted rule', function (): void {
    $v = makeValidator(
        ['tos' => 'no'],
        ['tos' => FluentRule::accepted()->messageFor('accepted', 'You must accept.')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('tos'))->toBe('You must accept.');
});

it('DeclinedRule: messageFor("declined") surfaces on failing declined rule', function (): void {
    $v = makeValidator(
        ['opt_out' => 'yes'],
        ['opt_out' => FluentRule::declined()->messageFor('declined', 'You must decline.')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('opt_out'))->toBe('You must decline.');
});

it('FileRule: messageFor("file") surfaces on failing file rule', function (): void {
    $v = makeValidator(
        ['avatar' => 'notafile'],
        ['avatar' => FluentRule::file()->messageFor('file', 'Must be a file.')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('avatar'))->toBe('Must be a file.');
});

it('ImageRule: messageFor("image") surfaces on failing image rule', function (): void {
    $v = makeValidator(
        ['picture' => 'notanimage'],
        ['picture' => FluentRule::image()->messageFor('image', 'Must be an image.')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('picture'))->toBe('Must be an image.');
});

it('ArrayRule: messageFor("array") surfaces on failing array rule', function (): void {
    $v = makeValidator(
        ['items' => 'notarray'],
        ['items' => FluentRule::array()->messageFor('array', 'Must be an array.')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('items'))->toBe('Must be an array.');
});

it('EmailRule: messageFor("email") surfaces on failing email rule (plain branch)', function (): void {
    $v = makeValidator(
        ['contact' => 'notanemail'],
        ['contact' => FluentRule::email(defaults: false)->messageFor('email', 'Must be valid email.')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('contact'))->toBe('Must be valid email.');
});

it('EmailRule: messageFor("email") surfaces on failing email:modes branch', function (): void {
    $v = makeValidator(
        ['contact' => 'notanemail'],
        ['contact' => FluentRule::email()->rfcCompliant()->messageFor('email', 'Must be valid email.')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('contact'))->toBe('Must be valid email.');
});

it('EmailRule: messageFor("email") surfaces on failing Email::default() object branch', function (): void {
    $original = Email::$defaultCallback;
    // Register a non-recursive default. Email::default() reads $defaultCallback
    // via a closure that constructs a fresh Email — don't call Email::default()
    // from within the callback or it recurses.
    Email::defaults(fn () => (new Email())->rfcCompliant());

    try {
        $v = makeValidator(
            ['contact' => 'notanemail'],
            ['contact' => FluentRule::email()->messageFor('email', 'Must be valid email.')]
        );

        expect($v->passes())->toBeFalse()
            ->and($v->errors()->first('contact'))->toBe('Must be valid email.');
    } finally {
        Email::$defaultCallback = $original;
    }
});

it('PasswordRule: messageFor("password") surfaces on failing Password rule object', function (): void {
    $v = makeValidator(
        ['password' => 'short'],
        ['password' => FluentRule::password(8)->messageFor('password', 'Password too weak.')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('password'))->toBe('Password too weak.');
});

// =========================================================================
// Phase 1 seeded behaviour — `->message()` binds to the factory's implicit
// rule without requiring `messageFor(key, ...)`.
// =========================================================================

it('StringRule: ->message() after factory binds to the implicit string rule', function (): void {
    $v = makeValidator(
        ['name' => 123],
        ['name' => FluentRule::string()->message('Must be text.')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('name'))->toBe('Must be text.');
});

it('NumericRule: ->message() after factory binds to the implicit numeric rule', function (): void {
    $v = makeValidator(
        ['age' => 'abc'],
        ['age' => FluentRule::numeric()->message('Must be a number.')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('age'))->toBe('Must be a number.');
});

it('BooleanRule: ->message() after factory binds to the implicit boolean rule', function (): void {
    $v = makeValidator(
        ['active' => 'notabool'],
        ['active' => FluentRule::boolean()->message('Must be a boolean.')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('active'))->toBe('Must be a boolean.');
});

it('AcceptedRule: ->message() after factory binds to the implicit accepted rule', function (): void {
    $v = makeValidator(
        ['tos' => 'no'],
        ['tos' => FluentRule::accepted()->message('You must accept.')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('tos'))->toBe('You must accept.');
});

it('DeclinedRule: ->message() after factory binds to the implicit declined rule', function (): void {
    $v = makeValidator(
        ['opt_out' => 'yes'],
        ['opt_out' => FluentRule::declined()->message('You must decline.')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('opt_out'))->toBe('You must decline.');
});

it('FileRule: ->message() after factory binds to the implicit file rule', function (): void {
    $v = makeValidator(
        ['avatar' => 'notafile'],
        ['avatar' => FluentRule::file()->message('Must be a file.')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('avatar'))->toBe('Must be a file.');
});

it('ImageRule: ->message() after factory binds to the implicit image rule', function (): void {
    $v = makeValidator(
        ['picture' => 'notanimage'],
        ['picture' => FluentRule::image()->message('Must be an image.')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('picture'))->toBe('Must be an image.');
});

it('ArrayRule: ->message() after factory binds to the implicit array rule', function (): void {
    $v = makeValidator(
        ['items' => 'notarray'],
        ['items' => FluentRule::array()->message('Must be an array.')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('items'))->toBe('Must be an array.');
});

it('EmailRule: ->message() after factory binds to the implicit email rule', function (): void {
    $v = makeValidator(
        ['contact' => 'notanemail'],
        ['contact' => FluentRule::email(defaults: false)->message('Must be valid email.')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('contact'))->toBe('Must be valid email.');
});

it('PasswordRule: ->message() after factory binds to the implicit password rule', function (): void {
    $v = makeValidator(
        ['password' => 'short'],
        ['password' => FluentRule::password(8)->message('Password too weak.')]
    );

    expect($v->passes())->toBeFalse()
        ->and($v->errors()->first('password'))->toBe('Password too weak.');
});

it('FieldRule: ->message() still throws because no implicit constraint', function (): void {
    FluentRule::field()->message('should throw');
})->throws(LogicException::class, 'message() must be called after a rule method');
