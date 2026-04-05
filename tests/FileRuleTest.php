<?php declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use SanderMuller\FluentValidation\FluentRule;

// =========================================================================
// FileRule
// =========================================================================

it('compiles file rule with min and max', function (): void {
    expect(FluentRule::file()->compiledRules())->toBe('file');
    expect(FluentRule::file()->min(100)->compiledRules())->toBe('file|min:100');
    expect(FluentRule::file()->max(2048)->compiledRules())->toBe('file|max:2048');
    expect(FluentRule::file()->min(100)->max(2048)->compiledRules())->toBe('file|min:100|max:2048');
});

it('converts decimal and whitespace sizes to kilobytes', function (): void {
    expect(FluentRule::file()->max('1.5mb')->compiledRules())->toBe('file|max:1500');
    expect(FluentRule::file()->max(' 5mb ')->compiledRules())->toBe('file|max:5000');
});

it('compiles file rule with human-readable sizes', function (): void {
    expect(FluentRule::file()->max('5mb')->compiledRules())->toBe('file|max:5000');
    expect(FluentRule::file()->max('1gb')->compiledRules())->toBe('file|max:1000000');
    expect(FluentRule::file()->max('1tb')->compiledRules())->toBe('file|max:1000000000');
    expect(FluentRule::file()->max('512kb')->compiledRules())->toBe('file|max:512');
    expect(FluentRule::file()->between('1mb', '10mb')->compiledRules())->toBe('file|between:1000,10000');
});

it('file rule accepts plain numeric string as kilobytes', function (): void {
    expect(FluentRule::file()->max('2048')->compiledRules())->toBe('file|max:2048');
});

it('compiles file rule with between and exactly', function (): void {
    expect(FluentRule::file()->between(100, 2048)->compiledRules())->toBe('file|between:100,2048');
    expect(FluentRule::file()->exactly(512)->compiledRules())->toBe('file|size:512');
});

it('compiles file rule with extensions', function (): void {
    expect(FluentRule::file()->extensions('pdf', 'docx')->compiledRules())->toBe('file|extensions:pdf,docx');
});

it('compiles file rule with mimes', function (): void {
    expect(FluentRule::file()->mimes('jpg', 'png', 'pdf')->compiledRules())->toBe('file|mimes:jpg,png,pdf');
});

it('compiles file rule with mimetypes', function (): void {
    expect(FluentRule::file()->mimetypes('image/jpeg', 'image/png')->compiledRules())->toBe('file|mimetypes:image/jpeg,image/png');
});

it('compiles file rule with field modifiers', function (): void {
    expect(FluentRule::file()->required()->max(2048)->compiledRules())->toBe('file|required|max:2048');
    expect(FluentRule::file()->nullable()->compiledRules())->toBe('file|nullable');
});

it('validates file upload', function (): void {
    $file = UploadedFile::fake()->create('document.pdf', 100);
    $validator = makeValidator(['doc' => $file], ['doc' => FluentRule::file()->required()->max(2048)]);
    expect($validator->passes())->toBeTrue();
});

it('rejects non-file value', function (): void {
    $validator = makeValidator(['doc' => 'not-a-file'], ['doc' => FluentRule::file()->required()]);
    expect($validator->passes())->toBeFalse();
});

it('rejects file exceeding max size', function (): void {
    $file = UploadedFile::fake()->create('big.pdf', 3000);
    $validator = makeValidator(['doc' => $file], ['doc' => FluentRule::file()->required()->max(2048)]);
    expect($validator->passes())->toBeFalse();
});

it('validates file mimes at runtime', function (): void {
    $file = UploadedFile::fake()->create('doc.pdf', 100);
    $v = makeValidator(['doc' => $file], ['doc' => FluentRule::file()->required()->mimes('pdf')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['doc' => $file], ['doc' => FluentRule::file()->required()->mimes('jpg', 'png')]);
    expect($v->passes())->toBeFalse();
});

it('validates file extensions', function (): void {
    $file = UploadedFile::fake()->create('document.pdf', 100);
    $v = makeValidator(['doc' => $file], ['doc' => FluentRule::file()->required()->extensions('pdf', 'docx')]);
    expect($v->passes())->toBeTrue();

    $v = makeValidator(['doc' => $file], ['doc' => FluentRule::file()->required()->extensions('jpg', 'png')]);
    expect($v->passes())->toBeFalse();
});

// =========================================================================
// File — field modifier integration (framework parity)
// =========================================================================

it('file required rejects absent field', function (): void {
    $validator = makeValidator([], ['doc' => FluentRule::file()->required()]);
    expect($validator->passes())->toBeFalse();
});

it('file nullable passes with null', function (): void {
    $validator = makeValidator(['doc' => null], ['doc' => FluentRule::file()->nullable()]);
    expect($validator->passes())->toBeTrue();
});

it('file absent without required passes', function (): void {
    $validator = makeValidator([], ['doc' => FluentRule::file()->nullable()]);
    expect($validator->passes())->toBeTrue();
});

it('file bail stops on first failure', function (): void {
    $validator = makeValidator(
        ['doc' => 'not-a-file'],
        ['doc' => FluentRule::file()->bail()->max(2048)]
    );
    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->get('doc'))->toHaveCount(1);
});
