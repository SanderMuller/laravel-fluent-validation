<?php declare(strict_types=1);

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Validator;
use SanderMuller\FluentValidation\FluentSchema;
use SanderMuller\FluentValidation\HasFluentRules;
use SanderMuller\FluentValidation\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

require_once __DIR__ . '/../src/Testing/PestExpectations.php';

/**
 * @param array<string, mixed> $data
 * @param array<string, mixed> $rules
 */
function makeValidator(array $data, array $rules): Validator
{
    return new Validator(
        new Translator(new ArrayLoader(), 'en'),
        $data,
        $rules
    );
}

/**
 * @param array<string, mixed> $rules
 * @param array<array-key, mixed> $data
 */
function createFormRequest(array $rules, array $data): FormRequest
{
    $formRequest = new class extends FormRequest {
        use HasFluentRules;

        /** @var array<string, mixed> */
        public static array $testRules = [];

        /** @return array<string, mixed> */
        public function rules(): array
        {
            return self::$testRules;
        }

        public function authorize(): bool
        {
            return true;
        }
    };

    $formRequest::$testRules = $rules;

    return bootFormRequest($formRequest, $data);
}

/**
 * Build a FormRequest that defines its rules through the FluentSchema
 * builder via a schema() method, mirroring createFormRequest().
 *
 * @param  Closure(FluentSchema): array<string, mixed>  $schema
 * @param  array<array-key, mixed>  $data
 */
function createSchemaFormRequest(Closure $schema, array $data): FormRequest
{
    $formRequest = new class extends FormRequest {
        use HasFluentRules;

        /** @var Closure(FluentSchema): array<string, mixed> */
        public static Closure $testSchema;

        /** @return array<string, mixed> */
        public function schema(FluentSchema $rules): array
        {
            return (self::$testSchema)($rules);
        }

        public function authorize(): bool
        {
            return true;
        }
    };

    $formRequest::$testSchema = $schema;

    return bootFormRequest($formRequest, $data);
}

/**
 * Resolve a configured FormRequest instance against a fake POST request,
 * wiring the container and redirector the way the framework would.
 *
 * @param  array<array-key, mixed>  $data
 */
function bootFormRequest(FormRequest $formRequest, array $data): FormRequest
{
    $request = Request::create('/test', 'POST', $data);
    $instance = $formRequest::createFrom($request);
    $instance->setContainer(app());
    $instance->setRedirector(resolve(Redirector::class));

    return $instance;
}
