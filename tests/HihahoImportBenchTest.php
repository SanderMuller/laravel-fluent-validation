<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule as LaravelRule;
use SanderMuller\FluentValidation\RuleSet;
use SanderMuller\FluentValidation\WildcardExpander;

/**
 * Simulates the hihaho JsonInteractionImportValidator complexity:
 * - 300 interactions with ~30 wildcard patterns each
 * - Heavy use of exclude_unless, required_if (conditional rules)
 * - Nested wildcards: *.chapters.*.title
 * - Custom rule objects mixed with string rules
 * - Cross-field references: gte:*.start_time
 */
it('benchmarks hihaho-level import validation', function (): void {
    $interactionTypes = ['button', 'hotspot', 'scroll_area', 'image', 'chapter', 'menu', 'frame', 'text', 'iframe'];

    // Generate 300 realistic interactions
    $interactions = array_map(fn (int $i): array => [
        'type' => $interactionTypes[$i % count($interactionTypes)],
        'title' => "Interaction {$i}",
        'start_time' => $i * 10,
        'end_time' => $i * 10 + 5,
        'position' => 'bottom',
        'should_start_collapsed' => false,
        'should_collapse_after_menu_item_click' => true,
        'should_pause_when_shown' => false,
        'should_not_use_time' => false,
        'should_use_menu_layout' => false,
        'text' => '<p>Sample text</p>',
        'text_stroke' => null,
        'should_fade_volume' => false,
        'sound_url' => null,
        'should_enable_sound' => false,
        'should_fade_in' => true,
        'should_fade_out' => true,
        'image_url' => $interactionTypes[$i % count($interactionTypes)] === 'image' ? 'https://example.com/image.png' : null,
        'style' => [
            'top' => '10%',
            'left' => '20%',
            'height' => '30%',
            'width' => '40%',
            'background_color' => '#ff0000',
            'border_radius' => 5,
            'padding_top' => 10,
            'padding_bottom' => 10,
            'border' => ['width' => 1, 'style' => 'solid', 'color' => '#000000'],
        ],
        'action' => [
            'type' => 'link',
            'link' => 'https://example.com',
            'time' => 0,
        ],
        'attributes' => [
            'show_indicator' => true,
            'indicator_color' => '#00ff00',
            'blinking_speed' => 'normal',
            'options' => ['menu_button_location' => 'top', 'menu_button_name' => 'Menu'],
        ],
        'chapters' => $i % count($interactionTypes) === 4 ? array_map(fn (int $j): array => [
            'title' => "Chapter {$j}",
            'title_short' => null,
            'start_time' => $j * 5,
            'end_time' => $j * 5 + 4,
            'sort_order' => $j,
        ], range(1, 4)) : [],
    ], range(1, 100));

    $data = ['interactions' => $interactions];

    // Build rules that match hihaho's complexity: ~30 wildcard patterns,
    // conditional rules, nested wildcards, custom rule objects
    $rules = [
        'interactions' => 'required|array|min:1',
        'interactions.*.type' => ['required', 'string', LaravelRule::in($interactionTypes)],
        'interactions.*.title' => ['nullable', 'string'],
        'interactions.*.start_time' => ['required', 'numeric', 'min:0'],
        'interactions.*.end_time' => ['required', 'numeric', 'gte:interactions.*.start_time'],
        'interactions.*.position' => ['bail', ['exclude_unless', 'interactions.*.type', 'chapter', 'menu'], 'string'],
        'interactions.*.should_start_collapsed' => [['exclude_unless', 'interactions.*.type', 'chapter'], 'boolean'],
        'interactions.*.should_collapse_after_menu_item_click' => [['exclude_unless', 'interactions.*.type', 'chapter'], 'boolean'],
        'interactions.*.should_pause_when_shown' => [['exclude_unless', 'interactions.*.type', 'chapter', 'menu'], 'nullable', 'boolean'],
        'interactions.*.should_not_use_time' => [['exclude_unless', 'interactions.*.type', 'menu'], 'nullable', 'boolean'],
        'interactions.*.should_use_menu_layout' => [['exclude_unless', 'interactions.*.type', 'chapter'], 'nullable', 'boolean'],
        'interactions.*.text' => [['exclude_unless', 'interactions.*.type', 'button', 'hotspot', 'text'], 'nullable', 'string'],
        'interactions.*.text_stroke' => [['exclude_unless', 'interactions.*.type', 'text'], 'nullable', 'string'],
        'interactions.*.should_fade_volume' => ['boolean'],
        'interactions.*.sound_url' => ['bail', ['exclude_unless', 'interactions.*.type', 'button', 'hotspot', 'image'], 'nullable', 'string'],
        'interactions.*.should_enable_sound' => [['exclude_unless', 'interactions.*.type', 'button', 'hotspot', 'image'], 'boolean'],
        'interactions.*.should_fade_in' => [['exclude_unless', 'interactions.*.type', 'button', 'hotspot', 'image', 'text'], 'boolean'],
        'interactions.*.should_fade_out' => [['exclude_unless', 'interactions.*.type', 'button', 'hotspot', 'image', 'text'], 'boolean'],
        'interactions.*.image_url' => ['bail', ['exclude_unless', 'interactions.*.type', 'image', 'hotspot'], ['required_if', 'interactions.*.type', 'image'], 'nullable', 'string'],
        // Style rules
        'interactions.*.style.top' => [['exclude_unless', 'interactions.*.type', 'button', 'hotspot', 'image', 'text', 'iframe'], 'string'],
        'interactions.*.style.left' => [['exclude_unless', 'interactions.*.type', 'button', 'hotspot', 'image', 'text', 'iframe'], 'string'],
        'interactions.*.style.height' => [['exclude_unless', 'interactions.*.type', 'button', 'hotspot', 'image', 'text', 'iframe'], 'string'],
        'interactions.*.style.width' => [['exclude_unless', 'interactions.*.type', 'button', 'hotspot', 'image', 'text', 'iframe'], 'string'],
        'interactions.*.style.background_color' => [['exclude_unless', 'interactions.*.type', 'button', 'hotspot', 'text'], 'string'],
        'interactions.*.style.border_radius' => ['bail', ['exclude_unless', 'interactions.*.type', 'button', 'hotspot'], 'nullable', 'numeric', 'integer', 'between:0,360'],
        'interactions.*.style.padding_top' => [['exclude_unless', 'interactions.*.type', 'button', 'hotspot', 'text'], 'nullable', 'numeric', 'integer'],
        'interactions.*.style.padding_bottom' => [['exclude_unless', 'interactions.*.type', 'button', 'hotspot', 'text'], 'nullable', 'numeric', 'integer'],
        'interactions.*.style.border' => [['exclude_unless', 'interactions.*.type', 'button', 'hotspot', 'text'], 'nullable', 'array'],
        'interactions.*.style.border.width' => ['nullable', 'numeric', 'integer', 'min:0'],
        'interactions.*.style.border.style' => ['nullable', 'string', LaravelRule::in(['solid', 'dashed', 'dotted', 'none'])],
        'interactions.*.style.border.color' => ['nullable', 'string'],
        // Action rules
        'interactions.*.action' => ['nullable', 'array'],
        'interactions.*.action.type' => ['nullable', 'string', LaravelRule::in(['link', 'time', 'video', 'none'])],
        'interactions.*.action.link' => ['nullable', 'string'],
        'interactions.*.action.time' => ['nullable', 'numeric'],
        // Attribute rules
        'interactions.*.attributes' => ['nullable', 'array'],
        'interactions.*.attributes.show_indicator' => ['nullable', 'boolean'],
        'interactions.*.attributes.indicator_color' => ['nullable', 'string'],
        'interactions.*.attributes.blinking_speed' => ['nullable', 'string', LaravelRule::in(['slow', 'normal', 'fast', 'none'])],
        'interactions.*.attributes.options' => ['nullable', 'array'],
        'interactions.*.attributes.options.menu_button_location' => ['nullable', 'string'],
        'interactions.*.attributes.options.menu_button_name' => ['nullable', 'string'],
        // Chapters (nested wildcard)
        'interactions.*.chapters' => [['exclude_unless', 'interactions.*.type', 'chapter'], 'array', 'min:1', 'max:16'],
        'interactions.*.chapters.*.title' => [['exclude_unless', 'interactions.*.type', 'chapter'], 'required', 'string'],
        'interactions.*.chapters.*.title_short' => [['exclude_unless', 'interactions.*.type', 'chapter'], 'nullable', 'string'],
        'interactions.*.chapters.*.start_time' => [['exclude_unless', 'interactions.*.type', 'chapter'], 'required', 'numeric', 'min:0'],
        'interactions.*.chapters.*.end_time' => [['exclude_unless', 'interactions.*.type', 'chapter'], 'required', 'numeric'],
        'interactions.*.chapters.*.sort_order' => [['exclude_unless', 'interactions.*.type', 'chapter'], 'required', 'integer', 'min:0'],
    ];

    // ── Warmup ──
    Validator::make($data, $rules)->validate();
    $ruleSet = RuleSet::from($rules);
    [$expanded, $ia] = $ruleSet->expand($data);
    $compiled = RuleSet::compile($expanded);
    $v = Validator::make($data, $compiled);
    (new ReflectionProperty($v, 'implicitAttributes'))->setValue($v, $ia);
    $v->validate();

    // ── Benchmark ──
    $iterations = 3;
    $nativeTimes = [];
    $expandedTimes = [];
    $validateTimes = [];
    $wildcardExpanderTimes = [];

    $allPatterns = array_keys(array_filter($rules, fn (string $k): bool => str_contains($k, '*'), ARRAY_FILTER_USE_KEY));

    for ($iter = 0; $iter < $iterations; ++$iter) {
        // Native Laravel
        $t = hrtime(true);
        Validator::make($data, $rules)->validate();
        $nativeTimes[] = (hrtime(true) - $t) / 1e6;

        // Pre-expanded via RuleSet (big validator)
        $t = hrtime(true);
        $ruleSet = RuleSet::from($rules);
        [$expanded, $ia] = $ruleSet->expand($data);
        $compiled = RuleSet::compile($expanded);
        $v = Validator::make($data, $compiled);
        (new ReflectionProperty($v, 'implicitAttributes'))->setValue($v, $ia);
        $v->validate();
        $expandedTimes[] = (hrtime(true) - $t) / 1e6;

        // RuleSet::validate() — per-item with conditional rule rewriting
        $t = hrtime(true);
        RuleSet::from($rules)->validate($data);
        $validateTimes[] = (hrtime(true) - $t) / 1e6;

        // Just WildcardExpander (expansion only)
        $t = hrtime(true);
        foreach ($allPatterns as $allPattern) {
            WildcardExpander::expand($allPattern, $data);
        }

        $wildcardExpanderTimes[] = (hrtime(true) - $t) / 1e6;
    }

    sort($nativeTimes);
    sort($expandedTimes);
    sort($validateTimes);
    sort($wildcardExpanderTimes);

    $nativeMedian = $nativeTimes[1];
    $expandedMedian = $expandedTimes[1];
    $validateMedian = $validateTimes[1];
    $expanderMedian = $wildcardExpanderTimes[1];

    fprintf(STDERR, "\n  === Hihaho-level import (100 interactions, %d wildcard patterns) ===\n\n", count($allPatterns));
    fprintf(STDERR, "  Native Laravel:               %7.2fms\n", $nativeMedian);
    fprintf(STDERR, "  Pre-expanded (big validator):  %7.2fms (%.1fx)\n", $expandedMedian, $nativeMedian / $expandedMedian);
    fprintf(STDERR, "  RuleSet::validate() per-item:  %7.2fms (%.1fx)\n", $validateMedian, $nativeMedian / $validateMedian);
    fprintf(STDERR, "  WildcardExpander only:         %7.2fms (expansion cost, no validation)\n", $expanderMedian);

    expect($validateMedian)->toBeLessThan($nativeMedian);
})->group('benchmark');
