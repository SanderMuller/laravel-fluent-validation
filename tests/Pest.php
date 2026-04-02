<?php

declare(strict_types=1);

use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Validator;
use SanderMuller\FluentValidation\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

function makeValidator(array $data, array $rules): Validator
{
    return new Validator(
        new Translator(new ArrayLoader(), 'en'),
        $data,
        $rules
    );
}
