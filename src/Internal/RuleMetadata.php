<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Internal;

/**
 * Reads the labels and per-rule messages of rule objects.
 *
 * @internal Implementation detail of `RuleSet::extractMetadata()`.
 */
final class RuleMetadata
{
    /**
     * @param  array<array-key, mixed>  $rules
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    public static function extract(array $rules): array
    {
        $messages = [];
        $attributes = [];
        // Expanded wildcard fields share their rule objects, so an object
        // without a label or messages is asked once.
        $withoutMetadata = [];

        foreach (EscapedFieldNames::decode($rules) as $field => $rule) {
            // For mixed arrays like ['exclude', FluentRule::string('ID')],
            // look inside for rule objects with metadata.
            $objects = is_object($rule) ? [$rule] : (is_array($rule) ? array_filter($rule, is_object(...)) : []);

            foreach ($objects as $object) {
                $id = spl_object_id($object);

                if (! isset($withoutMetadata[$id]) && ! self::extractFromObject($object, (string) $field, $messages, $attributes)) {
                    $withoutMetadata[$id] = true;
                }
            }
        }

        return [$messages, $attributes];
    }

    /**
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     */
    private static function extractFromObject(object $object, string $field, array &$messages, array &$attributes): bool
    {
        $found = false;

        if (method_exists($object, 'getLabel')) {
            $label = $object->getLabel();

            if (is_string($label)) {
                $attributes[$field] = $label;
                $found = true;
            }
        }

        if (method_exists($object, 'getCustomMessages')) {
            /** @var array<string, string> $customMessages */
            $customMessages = $object->getCustomMessages();

            foreach ($customMessages as $ruleName => $message) {
                $messages[$ruleName === '' ? $field : $field . '.' . $ruleName] = $message;
                $found = true;
            }
        }

        return $found;
    }
}
