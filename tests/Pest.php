<?php declare(strict_types=1);

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Validator;
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

    $request = Request::create('/test', 'POST', $data);
    $instance = $formRequest::createFrom($request);
    $instance->setContainer(app());
    $instance->setRedirector(resolve(Redirector::class));

    return $instance;
}
