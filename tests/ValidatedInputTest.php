<?php declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\RuleSet;

it('returns a validated file from safe()->file()', function (): void {
    $avatar = UploadedFile::fake()->create('avatar.png', 10);

    $safe = RuleSet::from([
        'avatar' => FluentRule::file()->required(),
        'name' => FluentRule::string()->required(),
    ])->check(['avatar' => $avatar, 'name' => 'John'])->safe();

    expect($safe->file('avatar'))->toBe($avatar)
        ->and($safe->file('name'))->toBeNull()
        ->and($safe->file('name', $avatar))->toBe($avatar)
        ->and($safe->file('missing'))->toBeNull();
});

it('returns the validated files under a key from safe()->files()', function (): void {
    $first = UploadedFile::fake()->create('a.pdf', 10);
    $second = UploadedFile::fake()->create('b.pdf', 10);
    $avatar = UploadedFile::fake()->create('avatar.png', 10);

    $safe = RuleSet::from([
        'avatar' => FluentRule::file()->required(),
        'documents' => FluentRule::array()->required()->each(FluentRule::file()),
        'name' => FluentRule::string()->required(),
    ])->check(['avatar' => $avatar, 'documents' => [$first, $second], 'name' => 'John'])->safe();

    expect($safe->files('documents'))->toBe([$first, $second])
        ->and($safe->files('avatar'))->toBe([$avatar])
        ->and($safe->files('name'))
        ->toBeEmpty()
        ->and($safe->files('missing'))
        ->toBeEmpty();
});

it('leaves values that are not files out of safe()->files()', function (): void {
    $file = UploadedFile::fake()->create('a.pdf', 10);

    $safe = RuleSet::from([
        'items' => FluentRule::array()->required(),
    ])->check(['items' => ['skip' => 'text', 'keep' => $file]])->safe();

    expect($safe->files('items'))->toBe([$file]);
});
