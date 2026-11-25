<?php declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use SanderMuller\FluentValidation\Internal\DateLikePaths;

it('reads a string as a date exactly when Laravel does', function (string $value): void {
    // The body of Laravel's ValidatesAttributes::getDateTime() for a string.
    try {
        $laravel = (bool) @Date::parse($value);
    } catch (Exception) {
        $laravel = false;
    }

    expect(DateLikePaths::parses($value))->toBe($laravel);
})->with([
    'a date' => '2025-01-01',
    'a field name' => 'start_date',
    'a path' => 'events.0.start_date',
    'a path that strtotime reads' => 'x.now.from',
    'a path with a numeric key that strtotime reads' => 'x.1.0.from',
    'a path with a time zone key' => 'apr.utc.from',
    'an empty string' => '',
    'a timestamp' => '@0',
    'a decimal number' => '1.5',
    'a negative number' => '-5',
    'an exponent' => '1e3',
    'a fifteen-digit number' => '123456789012345',
    'a leading space' => ' now',
    'a trailing space' => 'now ',
    'just now in a path' => 'x.just now.from',
    'at' => 'tomorrow at noon',
    'after tomorrow' => 'after tomorrow',
    'before yesterday' => 'before yesterday',
    'next with a time' => 'next 5pm',
    'last with an hour' => 'last 10h',
]);
