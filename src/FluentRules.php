<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation;

use Attribute;

/**
 * Marks a method as containing Laravel validation rules that should be
 * detected by the fluent-validation Rector rules during migration.
 *
 * By default, the Rector rules scan methods named `rules()`. Classes that
 * define rules in a differently-named method (e.g. `rulesWithoutPrefix()`
 * on a custom `FluentValidator` subclass used for JSON import) can mark
 * the method with this attribute to include it in the conversion pass:
 *
 *     class JsonImportValidator extends FluentValidator
 *     {
 *         #[FluentRules]
 *         public function rulesWithoutPrefix(): array
 *         {
 *             return [
 *                 'name' => ['required', 'string', 'max:255'],
 *             ];
 *         }
 *     }
 *
 * The attribute has no runtime effect — it exists only for the Rector
 * rules to detect during migration. Safe to leave in place after migrating.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class FluentRules {}
