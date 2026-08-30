/**
 * Single source of truth for the documentation order.
 *
 * Filenames keep their NN- prefix so GitHub renders docs/ in reading order;
 * `slug` strips the prefix so site URLs stay stable when pages are reordered.
 * `blurb` is what the end-of-page "Next" call to action shows, so it reads as
 * a reason to continue rather than a bare page title.
 */
export type DocPage = {
    file: string
    text: string
    blurb: string
}

export type DocSection = {
    text: string
    pages: DocPage[]
}

export const sections: DocSection[] = [
    {
        text: 'Getting started',
        pages: [
            {
                file: '01-why-this-package',
                text: 'Why this package?',
                blurb: 'DX, type safety, structure, and performance — and how it compares to Laravel\'s Rule class.',
            },
            {
                file: '02-installation',
                text: 'Installation',
                blurb: 'Require the package, add the trait, and check the PHP and Laravel versions you need.',
            },
            {
                file: '03-getting-started',
                text: 'Getting started',
                blurb: 'One form request, three fluent rules, and a validated payload.',
            },
            {
                file: '04-basic-usage',
                text: 'Basic usage',
                blurb: 'Write your first fluent rules in a form request, type rules(), and use the schema() builder.',
            },
            {
                file: '05-error-messages',
                text: 'Error messages',
                blurb: 'Attach labels and per-rule messages to the rule itself, with no separate arrays to keep in sync.',
            },
            {
                file: '06-array-validation',
                text: 'Array validation',
                blurb: 'Keep parent and child rules together with each() and children(), and nest arrays naturally.',
            },
        ],
    },
    {
        text: 'Digging deeper',
        pages: [
            {
                file: '07-extending-rules',
                text: 'Extending parent rules',
                blurb: 'Reshape inherited rules in child form requests with modifyEach and modifyChildren.',
            },
            {
                file: '08-livewire',
                text: 'Livewire',
                blurb: 'Use fluent rules in Livewire components with the HasFluentValidation trait.',
            },
            {
                file: '09-performance',
                text: 'Performance',
                blurb: 'O(n) wildcard expansion, fast-check closures, batched database checks, and the benchmarks.',
            },
            {
                file: '10-benchmarks',
                text: 'Benchmarks',
                blurb: 'The measured numbers, and the six rule sets behind them.',
            },
            {
                file: '11-ruleset',
                text: 'RuleSet',
                blurb: 'Build, compose, inspect, and validate rule collections, plus the escape hatches.',
            },
            {
                file: '12-validating',
                text: 'Validating with a RuleSet',
                blurb: 'validate() and check(), unknown-field handling, and named error bags.',
            },
            {
                file: '13-testing',
                text: 'Testing',
                blurb: 'Assert rules, RuleSets, form requests, and Livewire components with FluentRulesTester.',
            },
            {
                file: '14-rule-reference',
                text: 'Rule reference',
                blurb: 'Every rule type, modifier, conditional, and macro in one place.',
            },
        ],
    },
    {
        text: 'Migration and tooling',
        pages: [
            {
                file: '15-migration',
                text: 'Migrating to fluent validation',
                blurb: 'Migrate field by field, and let the Rector companion rewrite the bulk of it for you.',
            },
            {
                file: '16-static-analysis',
                text: 'Static analysis with PHPStan',
                blurb: 'Opt-in PHPStan rules that flag unbounded each() chains before they reach production.',
            },
            {
                file: '17-troubleshooting',
                text: 'Troubleshooting',
                blurb: 'Common issues and their solutions.',
            },
        ],
    },
]

/** Flat reading order — drives rewrites, the sidebar, and the "Next" call to action. */
export const pages: DocPage[] = sections.flatMap(section => section.pages)

export const slug = (file: string) => file.replace(/^\d+-/, '')

export const link = (file: string) => `/${slug(file)}`
