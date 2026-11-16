<?php declare(strict_types=1);

namespace SanderMuller\FluentValidation\Benchmarks\Support;

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
}
