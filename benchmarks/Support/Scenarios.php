<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Benchmarks\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Orchestra\Testbench\Foundation\Application;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\RuleSet;

/**
 * Rules and data for the Tally benchmarks. Each method builds new objects, so
 * the warm-up call and the counted call share no rule objects.
 */
final class Scenarios
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (! self::$booted) {
            Application::create();
            self::$booted = true;
        }
    }

    /**
     * Validates the rules against the data, and ignores a failure.
     *
     * @param  array<string, mixed>  $rules
     * @param  array<string, mixed>  $data
     */
    public static function validate(array $rules, array $data): void
    {
        try {
            RuleSet::from($rules)->validate($data);
        } catch (ValidationException) {
        }
    }

    /**
     * Validates with Laravel's own validator, and ignores a failure.
     *
     * @param  array<string, mixed>  $rules
     * @param  array<string, mixed>  $data
     */
    public static function validateNative(array $rules, array $data): void
    {
        try {
            Validator::make($data, $rules)->validate();
        } catch (ValidationException) {
        }
    }

    /** @return array<string, mixed> */
    public static function productRules(): array
    {
        return [
            'products' => FluentRule::array()->required()->each([
                'sku' => FluentRule::string()->required()->max(50)->regex('/^SKU-/'),
                'name' => FluentRule::string()->required()->min(2)->max(255),
                'price' => FluentRule::numeric()->required()->min(0),
                'quantity' => FluentRule::numeric()->required()->integer()->min(0),
                'category' => FluentRule::string()->required()->in(['electronics', 'clothing', 'food', 'books', 'toys']),
                'active' => FluentRule::boolean()->required(),
                'tags' => FluentRule::string()->nullable()->max(50),
            ]),
        ];
    }

    /**
     * @param  int  $invalidEvery  makes every nth item fail its price rule, 0 for none
     * @return array<string, mixed>
     */
    public static function productData(int $count, int $invalidEvery = 0): array
    {
        return ['products' => array_map(static fn (int $i): array => [
            'sku' => "SKU-{$i}",
            'name' => "Product {$i}",
            'price' => $invalidEvery > 0 && $i % $invalidEvery === 0 ? -1 : round(9.99 + ($i % 100) * 0.5, 2),
            'quantity' => $i % 1000,
            'category' => ['electronics', 'clothing', 'food', 'books', 'toys'][$i % 5],
            'active' => $i % 2 === 0,
            'tags' => 'tag-' . ($i % 20),
        ], range(1, $count))];
    }

    /** @return array<string, mixed> */
    public static function orderRules(): array
    {
        return [
            'orders' => FluentRule::array()->required()->each([
                'order_number' => FluentRule::string()->required()->alphaDash()->min(5),
                'status' => FluentRule::string()->required()->in(['pending', 'processing', 'shipped']),
                'line_items' => FluentRule::array()->required()->min(1)->each([
                    'product_id' => FluentRule::numeric()->required()->integer()->min(1),
                    'quantity' => FluentRule::numeric()->required()->integer()->min(1),
                    'price' => FluentRule::numeric()->required()->min(0.01),
                ]),
            ]),
        ];
    }

    /** @return array<string, mixed> */
    public static function orderData(int $orders, int $lineItemsPerOrder = 5): array
    {
        return ['orders' => array_map(static fn (int $i): array => [
            'order_number' => "ORD-{$i}",
            'status' => ['pending', 'processing', 'shipped'][$i % 3],
            'line_items' => array_map(static fn (int $j): array => [
                'product_id' => ($i * 10 + $j) % 9999 + 1,
                'quantity' => $j + 1,
                'price' => round(10.00 + $j * 2.5, 2),
            ], range(1, $lineItemsPerOrder)),
        ], range(1, $orders))];
    }

    /** @return array<string, mixed> */
    public static function eventRules(): array
    {
        return [
            'events' => FluentRule::array()->required()->each([
                'name' => FluentRule::string()->required()->min(3)->max(255),
                'start_date' => FluentRule::date()->required()->after('2025-01-01'),
                'end_date' => FluentRule::date()->required()->after('events.*.start_date'),
                'registration_deadline' => FluentRule::date()->required()->before('events.*.start_date'),
            ]),
        ];
    }

    /**
     * The event rules as rule objects under flat wildcard keys, the shape that
     * RuleSet::toArray() gives a plain Validator::make().
     *
     * @return array<string, mixed>
     */
    public static function eventRuleObjects(): array
    {
        return [
            'events' => FluentRule::array()->required(),
            'events.*.name' => FluentRule::string()->required()->min(3)->max(255),
            'events.*.start_date' => FluentRule::date()->required()->after('2025-01-01'),
            'events.*.end_date' => FluentRule::date()->required()->after('events.*.start_date'),
            'events.*.registration_deadline' => FluentRule::date()->required()->before('events.*.start_date'),
        ];
    }

    /** @return array<string, mixed> */
    public static function eventData(int $count): array
    {
        $day = static fn (int $i): string => str_pad((string) ($i % 28 + 1), 2, '0', STR_PAD_LEFT);

        return ['events' => array_map(static fn (int $i): array => [
            'name' => "Event {$i}",
            'start_date' => '2025-06-' . $day($i),
            'end_date' => '2025-07-' . $day($i),
            'registration_deadline' => '2025-05-' . $day($i),
        ], range(1, $count))];
    }

    /** @return array<string, mixed> */
    public static function tagRules(): array
    {
        return [
            'tags' => FluentRule::array()->required()->each(FluentRule::string()->required()->max(50)),
        ];
    }

    /** @return array<string, mixed> */
    public static function tagData(int $count): array
    {
        return ['tags' => array_map(static fn (int $i): string => 'tag-' . $i, range(1, $count))];
    }

    private const INTERACTION_TYPES = ['button', 'hotspot', 'scroll_area', 'image', 'chapter', 'menu', 'frame', 'text', 'iframe'];

    /** @return array<string, mixed> */
    public static function conditionalRules(): array
    {
        $visual = ['exclude_unless', 'interactions.*.type', 'button', 'hotspot', 'image', 'text', 'iframe'];
        $boxed = ['exclude_unless', 'interactions.*.type', 'button', 'hotspot', 'text'];
        $chapter = ['exclude_unless', 'interactions.*.type', 'chapter'];

        return [
            'interactions' => 'required|array|min:1',
            'interactions.*.type' => ['required', 'string', Rule::in(self::INTERACTION_TYPES)],
            'interactions.*.title' => ['nullable', 'string'],
            'interactions.*.start_time' => ['required', 'numeric', 'min:0'],
            'interactions.*.end_time' => ['required', 'numeric', 'gte:interactions.*.start_time'],
            'interactions.*.position' => ['bail', ['exclude_unless', 'interactions.*.type', 'chapter', 'menu'], 'string'],
            'interactions.*.should_start_collapsed' => [$chapter, 'boolean'],
            'interactions.*.should_pause_when_shown' => [['exclude_unless', 'interactions.*.type', 'chapter', 'menu'], 'nullable', 'boolean'],
            'interactions.*.should_not_use_time' => [['exclude_unless', 'interactions.*.type', 'menu'], 'nullable', 'boolean'],
            'interactions.*.text' => [['exclude_unless', 'interactions.*.type', 'button', 'hotspot', 'text'], 'nullable', 'string'],
            'interactions.*.should_fade_volume' => ['boolean'],
            'interactions.*.sound_url' => ['bail', ['exclude_unless', 'interactions.*.type', 'button', 'hotspot', 'image'], 'nullable', 'string'],
            'interactions.*.should_fade_in' => [['exclude_unless', 'interactions.*.type', 'button', 'hotspot', 'image', 'text'], 'boolean'],
            'interactions.*.image_url' => ['bail', ['exclude_unless', 'interactions.*.type', 'image', 'hotspot'], ['required_if', 'interactions.*.type', 'image'], 'nullable', 'string'],
            'interactions.*.style.top' => [$visual, 'string'],
            'interactions.*.style.left' => [$visual, 'string'],
            'interactions.*.style.background_color' => [$boxed, 'string'],
            'interactions.*.style.border_radius' => ['bail', ['exclude_unless', 'interactions.*.type', 'button', 'hotspot'], 'nullable', 'numeric', 'integer', 'between:0,360'],
            'interactions.*.style.border' => [$boxed, 'nullable', 'array'],
            'interactions.*.style.border.width' => ['nullable', 'numeric', 'integer', 'min:0'],
            'interactions.*.style.border.style' => ['nullable', 'string', Rule::in(['solid', 'dashed', 'dotted', 'none'])],
            'interactions.*.action' => ['nullable', 'array'],
            'interactions.*.action.type' => ['nullable', 'string', Rule::in(['link', 'time', 'video', 'none'])],
            'interactions.*.action.link' => ['nullable', 'string'],
            'interactions.*.chapters' => [$chapter, 'array', 'min:1', 'max:16'],
            'interactions.*.chapters.*.title' => [$chapter, 'required', 'string'],
            'interactions.*.chapters.*.start_time' => [$chapter, 'required', 'numeric', 'min:0'],
            'interactions.*.chapters.*.sort_order' => [$chapter, 'required', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, mixed> */
    public static function conditionalData(int $count): array
    {
        $types = self::INTERACTION_TYPES;

        return ['interactions' => array_map(static fn (int $i): array => [
            'type' => $types[$i % count($types)],
            'title' => "Interaction {$i}",
            'start_time' => $i * 10,
            'end_time' => $i * 10 + 5,
            'position' => 'bottom',
            'should_start_collapsed' => false,
            'should_pause_when_shown' => false,
            'should_not_use_time' => false,
            'text' => '<p>Sample text</p>',
            'should_fade_volume' => false,
            'sound_url' => null,
            'should_fade_in' => true,
            'image_url' => $types[$i % count($types)] === 'image' ? 'https://example.com/image.png' : null,
            'style' => [
                'top' => '10%', 'left' => '20%', 'background_color' => '#ff0000', 'border_radius' => 5,
                'border' => ['width' => 1, 'style' => 'solid'],
            ],
            'action' => ['type' => 'link', 'link' => 'https://example.com'],
            'chapters' => $types[$i % count($types)] === 'chapter' ? array_map(static fn (int $j): array => [
                'title' => "Chapter {$j}", 'start_time' => $j * 5, 'sort_order' => $j,
            ], range(1, 4)) : [],
        ], range(1, $count))];
    }

    private const LOG_ACTIONS = ['open', 'close', 'scroll', 'lock', 'unlock', 'zoom', 'share', 'comment', 'error', 'print', 'select', 'copy', 'paste', 'undo', 'redo'];

    /**
     * An activity log where most fields pair requiredIf with excludeUnless on
     * the same sibling action field.
     *
     * @return array<string, mixed>
     */
    public static function activityLogRules(): array
    {
        $action = 'entries.*.action';

        return [
            'entries' => FluentRule::array()->required()->max(500)->each([
                'action' => FluentRule::string()->required()->in(self::LOG_ACTIONS),
                'email' => FluentRule::email(defaults: false)->nullable()->requiredIf($action, 'share')->excludeUnless($action, 'share'),
                'is_active' => FluentRule::boolean()->nullable()->requiredIf($action, 'lock', 'unlock', 'scroll')->excludeUnless($action, 'lock', 'unlock', 'scroll'),
                'is_automatic' => FluentRule::boolean()->nullable(),
                'scroll_from' => FluentRule::numeric()->nullable()->requiredIf($action, 'scroll')->excludeUnless($action, 'scroll')->between(0, 100000),
                'scroll_to' => FluentRule::numeric()->nullable()->requiredIf($action, 'scroll')->excludeUnless($action, 'scroll')->between(0, 100000),
                'timestamp' => FluentRule::numeric()->nullable()->min(1_700_000_000),
                'comment_id' => FluentRule::uuid()->nullable()->requiredIf($action, 'comment')->excludeUnless($action, 'comment'),
                'position' => FluentRule::numeric()->nullable()->between(0, 100000),
                'zoom' => FluentRule::numeric()->bail()->nullable()->requiredIf($action, 'zoom')->excludeUnless($action, 'zoom'),
                'error_details' => FluentRule::array()->bail()->nullable()->requiredIf($action, 'error')->excludeUnless($action, 'error')->children([
                    'message' => FluentRule::string()->bail()->nullable(),
                    'params' => FluentRule::array()->bail()->nullable(),
                    'level' => FluentRule::string()->bail()->nullable(),
                ]),
            ]),
        ];
    }

    /**
     * Mostly scroll entries, as a real log has, with every rare action present.
     *
     * @return array<string, mixed>
     */
    public static function activityLogData(int $count): array
    {
        $common = ['scroll', 'scroll', 'scroll', 'scroll', 'lock', 'zoom', 'open', 'close', 'select', 'copy'];
        $rare = ['share', 'comment', 'error'];

        return ['entries' => array_map(static function (int $i) use ($common, $rare): array {
            $action = $i % 25 === 0 ? $rare[intdiv($i, 25) % 3] : $common[$i % 10];

            return [
                'action' => $action,
                'email' => $action === 'share' ? "user{$i}@example.com" : null,
                'is_active' => in_array($action, ['lock', 'scroll'], true) ? $i % 2 === 0 : null,
                'is_automatic' => $i % 7 === 0,
                'scroll_from' => $action === 'scroll' ? $i * 3 : null,
                'scroll_to' => $action === 'scroll' ? $i * 3 + 40 : null,
                'timestamp' => 1_750_000_000 + $i,
                'comment_id' => $action === 'comment' ? sprintf('00000000-0000-4000-8000-%012d', $i) : null,
                'position' => $i * 3,
                'zoom' => $action === 'zoom' ? 1.5 : null,
                'error_details' => $action === 'error' ? ['message' => 'Load failed', 'params' => ['page' => $i], 'level' => 'warning'] : null,
            ];
        }, range(1, $count))];
    }

    private const SURVEY_TYPES = ['open', 'single', 'multiple', 'scale', 'info'];

    /**
     * A survey import with conditionals on the question type and, inside the
     * nested answers, on each answer's action type.
     *
     * @return array<string, mixed>
     */
    public static function surveyRules(): array
    {
        $type = 'questions.*.type';
        $actionType = 'questions.*.answers.*.action.type';

        return [
            'questions' => FluentRule::array()->required()->each([
                'type' => FluentRule::string()->required()->in(self::SURVEY_TYPES),
                'title' => FluentRule::string()->required()->max(255),
                'text' => FluentRule::string()->nullable(),
                'start_time' => FluentRule::numeric()->required()->min(0),
                'end_time' => FluentRule::numeric()->nullable()->min(0),
                'is_required' => FluentRule::boolean()->required(),
                'counts_points_per_answer' => FluentRule::boolean()->nullable(),
                'max_length' => FluentRule::integer()->nullable()->min(1)->excludeUnless($type, 'open'),
                'placeholder' => FluentRule::string()->nullable()->max(255)->excludeUnless($type, 'open'),
                'min_value' => FluentRule::integer()->nullable()->excludeUnless($type, 'scale'),
                'max_value' => FluentRule::integer()->nullable()->excludeUnless($type, 'scale'),
                'image_url' => FluentRule::string()->nullable()->max(2048),
                'image_fit' => FluentRule::string()->nullable()->in(['cover', 'contain'])->excludeIf('questions.*.image_url', 'null'),
                'answers' => FluentRule::array()->nullable()->requiredIf($type, 'single', 'multiple')->prohibitedUnless($type, 'single', 'multiple')->max(20)->each([
                    'text' => FluentRule::string()->nullable(),
                    'is_correct' => FluentRule::boolean()->required(),
                    'points' => FluentRule::integer()->bail()->excludeUnless('questions.*.counts_points_per_answer', 'true')->nullable()->min(0),
                    'explanation' => FluentRule::string()->nullable(),
                    'action' => FluentRule::array()->required()->children([
                        'type' => FluentRule::string()->required()->in(['none', 'jump', 'link']),
                        'target_id' => FluentRule::integer()->bail()->excludeUnless($actionType, 'jump')->nullable()->min(1),
                        'link' => FluentRule::string()->bail()->excludeUnless($actionType, 'link')->nullable()->max(2048),
                        'time' => FluentRule::numeric()->nullable()->min(0),
                    ]),
                ]),
            ]),
        ];
    }

    /** @return array<string, mixed> */
    public static function surveyData(int $count, int $answersPerQuestion = 4): array
    {
        $types = self::SURVEY_TYPES;
        $actions = ['none', 'jump', 'link', 'none'];

        return ['questions' => array_map(static function (int $i) use ($types, $actions, $answersPerQuestion): array {
            $type = $types[$i % count($types)];
            $hasAnswers = in_array($type, ['single', 'multiple'], true);

            return [
                'type' => $type,
                'title' => "Question {$i}",
                'text' => '<p>Question text</p>',
                'start_time' => $i * 10,
                'end_time' => $i * 10 + 8,
                'is_required' => $i % 2 === 0,
                'counts_points_per_answer' => $i % 3 === 0,
                'max_length' => 500,
                'placeholder' => 'Your answer',
                'min_value' => 1,
                'max_value' => 10,
                'image_url' => $i % 4 === 0 ? "https://example.com/{$i}.png" : null,
                'image_fit' => 'cover',
                'answers' => $hasAnswers ? array_map(static fn (int $j): array => [
                    'text' => "Answer {$j}",
                    'is_correct' => $j === 1,
                    'points' => $j,
                    'explanation' => null,
                    'action' => [
                        'type' => $actions[$j % 4],
                        'target_id' => $j + 1,
                        'link' => 'https://example.com/next',
                        'time' => $j * 2,
                    ],
                ], range(1, $answersPerQuestion)) : null,
            ];
        }, range(1, $count))];
    }
}
