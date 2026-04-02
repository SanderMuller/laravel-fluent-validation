<?php

declare(strict_types=1);

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use SanderMuller\FluentValidation\ExpandsWildcards;
use SanderMuller\FluentValidation\Rule;

it('expands wildcards in a FormRequest', function (): void {
    $formRequestClass = new class extends FormRequest {
        use ExpandsWildcards;

        public function rules(): array
        {
            return [
                'items' => Rule::array()->required()->each([
                    'name' => Rule::string()->required()->min(2),
                ]),
            ];
        }

        public function authorize(): bool
        {
            return true;
        }
    };

    $request = Request::create('/test', 'POST', [
        'items' => [
            ['name' => 'John'],
            ['name' => 'Jane'],
        ],
    ]);

    $anonymousClass1ffb8a1c1ca5f7d3a87caf87f1793c9d = $formRequestClass::createFrom($request);
    $anonymousClass1ffb8a1c1ca5f7d3a87caf87f1793c9d->setContainer(app());
    $anonymousClass1ffb8a1c1ca5f7d3a87caf87f1793c9d->setRedirector(app('redirect'));

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($anonymousClass1ffb8a1c1ca5f7d3a87caf87f1793c9d);

    expect($validator->passes())->toBeTrue();
    expect($validator->validated())->toHaveKeys(['items']);
});

it('fails validation in a FormRequest with ExpandsWildcards', function (): void {
    $formRequestClass = new class extends FormRequest {
        use ExpandsWildcards;

        public function rules(): array
        {
            return [
                'items' => Rule::array()->required()->each([
                    'name' => Rule::string()->required()->min(5),
                ]),
            ];
        }

        public function authorize(): bool
        {
            return true;
        }
    };

    $request = Request::create('/test', 'POST', [
        'items' => [
            ['name' => 'Jo'],
        ],
    ]);

    $anonymousClass87d036ef859ecf39f240cad23bef1e44 = $formRequestClass::createFrom($request);
    $anonymousClass87d036ef859ecf39f240cad23bef1e44->setContainer(app());
    $anonymousClass87d036ef859ecf39f240cad23bef1e44->setRedirector(app('redirect'));

    $factory = app(Factory::class);
    $validator = (fn () => $this->createDefaultValidator($factory))->call($anonymousClass87d036ef859ecf39f240cad23bef1e44);

    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->keys())->toContain('items.0.name');
});
