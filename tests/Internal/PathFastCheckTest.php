<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Tests\Internal;

use Closure;
use LogicException;
use SanderMuller\FluentValidation\FastCheckCompiler;
use SanderMuller\FluentValidation\Internal\PathFastCheck;

it('gives the answer of the field checks for a value the number bounds do not decide', function (string $priceRule, mixed $price): void {
    $compile = static fn (string $rule): Closure => FastCheckCompiler::compile($rule) ?? throw new LogicException($rule);
    $checks = ['id' => $compile('required|integer|min:1'), 'price' => $compile($priceRule)];

    expect(PathFastCheck::eachItemAll('lines', $checks)(['lines' => [['id' => 1, 'price' => $price]]]))
        ->toBe($checks['price']($price));
})->with([
    'NAN, minimum only' => ['required|numeric|min:0.5', NAN],
    'INF, minimum only' => ['required|numeric|min:0.5', INF],
    'NAN, minimum and maximum' => ['required|numeric|min:0.5|max:9.5', NAN],
    'INF, minimum and maximum' => ['required|numeric|min:0.5|max:9.5', INF],
    '-INF, maximum only' => ['required|numeric|max:9.5', -INF],
]);
