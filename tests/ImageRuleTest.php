<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rules\Dimensions;
use SanderMuller\FluentValidation\FluentRule;

// =========================================================================
// ImageRule
// =========================================================================

it('compiles image rule', function (): void {
    expect(FluentRule::image()->compiledRules())->toBe('image');
    expect(FluentRule::image()->max(5120)->compiledRules())->toBe('image|max:5120');
    expect(FluentRule::image()->required()->max('5mb')->compiledRules())->toBe('image|required|max:5120');
});

it('compiles image rule with allowSvg', function (): void {
    expect(FluentRule::image()->allowSvg()->compiledRules())->toBe('image:allow_svg');
});

it('allowSvg preserves field modifiers set before it', function (): void {
    $compiled = FluentRule::image()->required()->allowSvg()->compiledRules();
    expect($compiled)->toBe('image:allow_svg|required');
});

it('compiles image rule with minWidth and maxWidth', function (): void {
    $compiled = FluentRule::image()->minWidth(100)->maxWidth(1920)->compiledRules();
    expect($compiled)->toBeArray();
    expect($compiled[0])->toBe('image');
    expect($compiled)->toHaveCount(3); // image + 2 Dimensions objects
});

it('compiles image rule with width and height', function (): void {
    $compiled = FluentRule::image()->width(800)->height(600)->compiledRules();
    expect($compiled)->toBeArray();
    expect($compiled[0])->toBe('image');
});

it('compiles image rule with minHeight and maxHeight', function (): void {
    $compiled = FluentRule::image()->minHeight(100)->maxHeight(1080)->compiledRules();
    expect($compiled)->toBeArray();
    expect($compiled[0])->toBe('image');
});

it('compiles image rule with string ratio', function (): void {
    $compiled = FluentRule::image()->ratio('16/9')->compiledRules();
    expect($compiled)->toBeArray();
    expect($compiled[0])->toBe('image');
});

it('compiles image rule with float ratio', function (): void {
    $compiled = FluentRule::image()->ratio(1.5)->compiledRules();
    expect($compiled)->toBeArray();
    expect($compiled[0])->toBe('image');
});

it('compiles image rule with Dimensions instance', function (): void {
    $dimensions = new Dimensions(['min_width' => 200, 'ratio' => 1.0]);
    $compiled = FluentRule::image()->dimensions($dimensions)->compiledRules();
    expect($compiled)->toBeArray();
    expect($compiled)->toContain($dimensions);
});

it('validates image upload', function (): void {
    $image = UploadedFile::fake()->image('photo.jpg', 100, 100);
    $validator = makeValidator(['photo' => $image], ['photo' => FluentRule::image()->required()->max(2048)]);
    expect($validator->passes())->toBeTrue();
});

it('rejects non-image file as image', function (): void {
    $file = UploadedFile::fake()->create('document.pdf', 100);
    $validator = makeValidator(['photo' => $file], ['photo' => FluentRule::image()->required()]);
    expect($validator->passes())->toBeFalse();
});

it('image inherits file methods', function (): void {
    expect(FluentRule::image()->extensions('jpg', 'png')->mimes('jpg', 'png')->max(2048)->compiledRules())
        ->toBe('image|extensions:jpg,png|mimes:jpg,png|max:2048');
});

// =========================================================================
// Image — field modifier integration (framework parity)
// =========================================================================

it('image required rejects absent field', function (): void {
    $validator = makeValidator([], ['avatar' => FluentRule::image()->required()]);
    expect($validator->passes())->toBeFalse();
});

it('image nullable passes with null', function (): void {
    $validator = makeValidator(['avatar' => null], ['avatar' => FluentRule::image()->nullable()]);
    expect($validator->passes())->toBeTrue();
});
