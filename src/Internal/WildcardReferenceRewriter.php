<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator as BaseValidator;
use ReflectionClass;
use ReflectionProperty;

/**
 * Rewrites `parent.*.field` references to the item-relative `field` for
 * per-item validation. Mirrors Laravel, which resolves wildcards only in the
 * parameters of dependent rules (`Validator::$dependentRules`, plus custom
 * rules registered with `Validator::extendDependent()`). Parameters of other
 * rules, such as `in:items.*.foo`, are literal values and stay unchanged.
 *
 * @internal Implementation detail of `RuleSet`.
 */
final class WildcardReferenceRewriter
{
    /**
     * Rules that Laravel reads through `parseDependentRuleParameters()`: the
     * first parameter is a field, the rest are values to compare against.
     * Every other dependent rule takes only fields.
     */
    private const VALUE_CONDITIONALS = [
        'AcceptedIf' => true, 'DeclinedIf' => true,
        'ExcludeIf' => true, 'ExcludeUnless' => true,
        'MissingIf' => true, 'MissingUnless' => true,
        'PresentIf' => true, 'PresentUnless' => true,
        'ProhibitedIf' => true, 'ProhibitedUnless' => true,
        'RequiredIf' => true, 'RequiredUnless' => true,
    ];

    /** @var array<string, int> studly rule names */
    private readonly array $dependentRules;

    public function __construct(private readonly string $prefix)
    {
        $this->dependentRules = array_flip(self::dependentRules());
    }

    public function rewrite(mixed $rule): mixed
    {
        if (is_string($rule)) {
            return $this->rewriteString($rule);
        }

        if (! is_array($rule)) {
            return $rule;
        }

        $rewritten = [];

        foreach ($rule as $part) {
            if (is_string($part)) {
                $part = $this->rewriteString($part);
            } elseif (is_array($part) && isset($part[0]) && is_string($part[0])) {
                // ['exclude_unless', 'items.*.type', 'chapter'] → ['exclude_unless', 'type', 'chapter']
                $part = [$part[0], ...$this->rewriteParameters($part[0], array_values(array_slice($part, 1)), quoted: false)];
            }

            $rewritten[] = $part;
        }

        return $rewritten;
    }

    /**
     * 'gte:items.*.start|in:items.*.foo' → 'gte:start|in:items.*.foo'.
     * Parts that are not rewritten implode back to the exact input, so a
     * regex token like `regex:/^(a|b)$/` keeps its pipe.
     */
    private function rewriteString(string $rule): string
    {
        if (! str_contains($rule, $this->prefix)) {
            return $rule;
        }

        $parts = [];

        foreach (explode('|', $rule) as $part) {
            $pieces = explode(':', $part, 2);

            if (isset($pieces[1])) {
                // Laravel reads string parameters as CSV, so a field may be quoted.
                $part = $pieces[0] . ':' . implode(',', $this->rewriteParameters($pieces[0], explode(',', $pieces[1]), quoted: true));
            }

            $parts[] = $part;
        }

        return implode('|', $parts);
    }

    /**
     * @template T
     *
     * @param  array<int, T>  $parameters
     * @return array<int, T|string>
     */
    private function rewriteParameters(string $rule, array $parameters, bool $quoted): array
    {
        $rule = Str::studly(trim($rule));

        if (! isset($this->dependentRules[$rule])) {
            return $parameters;
        }

        $fieldCount = isset(self::VALUE_CONDITIONALS[$rule]) ? 1 : count($parameters);

        foreach (array_slice($parameters, 0, $fieldCount, preserve_keys: true) as $index => $parameter) {
            if (is_string($parameter)) {
                $parameters[$index] = $this->stripPrefix($parameter, $quoted);
            }
        }

        return $parameters;
    }

    private function stripPrefix(string $value, bool $quoted): string
    {
        // CSV drops spaces and tabs before an opening quote.
        $lead = $quoted && preg_match('/\A[ \t]*"/', $value, $match) === 1 ? $match[0] : '';
        $field = substr($value, strlen($lead));

        return str_starts_with($field, $this->prefix)
            ? $lead . substr($field, strlen($this->prefix))
            : $value;
    }

    /**
     * The factory adds custom dependent extensions to each validator it
     * makes. A custom resolver may return a validator that is not a
     * `BaseValidator`; fall back to Laravel's built-in list then.
     *
     * @return list<string>
     */
    private static function dependentRules(): array
    {
        $validator = Validator::make([], []);

        /** @var list<string> $rules */
        $rules = $validator instanceof BaseValidator
            ? (new ReflectionProperty(BaseValidator::class, 'dependentRules'))->getValue($validator)
            : (new ReflectionClass(BaseValidator::class))->getDefaultProperties()['dependentRules'];

        return $rules;
    }
}
