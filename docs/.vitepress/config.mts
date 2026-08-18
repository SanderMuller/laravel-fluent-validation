import { defineConfig } from 'vitepress'

// Filenames keep their NN- prefix so GitHub renders docs/ in reading order;
// rewrites strip the prefix so site URLs stay stable when pages are reordered.
const pages = [
    '01-why-this-package',
    '02-installation',
    '03-basic-usage',
    '04-error-messages',
    '05-array-validation',
    '06-extending-rules',
    '07-livewire',
    '08-performance',
    '09-ruleset',
    '10-testing',
    '11-rule-reference',
    '12-migration',
    '13-static-analysis',
    '14-troubleshooting',
]

const link = (page: string) => `/${page.replace(/^\d+-/, '')}`

export default defineConfig({
    title: 'Laravel Fluent Validation',
    description: 'Fluent validation rule builders for Laravel with IDE autocompletion, co-located array rules, and up to 160x faster wildcard validation.',
    base: '/laravel-fluent-validation/',
    cleanUrls: true,
    lastUpdated: true,

    head: [
        ['link', { rel: 'icon', type: 'image/svg+xml', href: '/laravel-fluent-validation/logo.svg' }],
        ['meta', { name: 'theme-color', content: '#FF2D20' }],
    ],

    // README.md is the GitHub-facing folder index; the site's home is home.md.
    srcExclude: ['README.md'],

    rewrites: {
        'home.md': 'index.md',
        ...Object.fromEntries(pages.map(page => [`${page}.md`, `${page.replace(/^\d+-/, '')}.md`])),
    },

    markdown: {
        // Markdown links target the NN-prefixed source files so they work on
        // GitHub; strip the prefix at render time to match the rewritten routes.
        config(md) {
            const defaultRender = md.renderer.rules.link_open
                ?? ((tokens, idx, options, _env, self) => self.renderToken(tokens, idx, options))
            md.renderer.rules.link_open = (tokens, idx, options, env, self) => {
                const href = tokens[idx].attrGet('href')
                if (href && /^(\.\/)?\d+-/.test(href)) {
                    tokens[idx].attrSet('href', href.replace(/^(\.\/)?\d+-/, '$1'))
                }
                return defaultRender(tokens, idx, options, env, self)
            }
        },
    },

    themeConfig: {
        logo: '/logo.svg',

        nav: [
            { text: 'Guide', link: link('01-why-this-package') },
            { text: 'Rule reference', link: link('11-rule-reference') },
            { text: 'Packagist', link: 'https://packagist.org/packages/sandermuller/laravel-fluent-validation' },
        ],

        sidebar: [
            {
                text: 'Getting started',
                items: [
                    { text: 'Why this package?', link: link('01-why-this-package') },
                    { text: 'Installation', link: link('02-installation') },
                    { text: 'Basic usage', link: link('03-basic-usage') },
                    { text: 'Error messages', link: link('04-error-messages') },
                    { text: 'Array validation', link: link('05-array-validation') },
                ],
            },
            {
                text: 'Digging deeper',
                items: [
                    { text: 'Extending parent rules', link: link('06-extending-rules') },
                    { text: 'Livewire', link: link('07-livewire') },
                    { text: 'Performance', link: link('08-performance') },
                    { text: 'RuleSet', link: link('09-ruleset') },
                    { text: 'Testing', link: link('10-testing') },
                    { text: 'Rule reference', link: link('11-rule-reference') },
                ],
            },
            {
                text: 'Migration and tooling',
                items: [
                    { text: 'Migrating to fluent validation', link: link('12-migration') },
                    { text: 'Static analysis with PHPStan', link: link('13-static-analysis') },
                    { text: 'Troubleshooting', link: link('14-troubleshooting') },
                ],
            },
        ],

        socialLinks: [
            { icon: 'github', link: 'https://github.com/SanderMuller/laravel-fluent-validation' },
        ],

        editLink: {
            pattern: 'https://github.com/SanderMuller/laravel-fluent-validation/edit/main/docs/:path',
            text: 'Edit this page on GitHub',
        },

        search: {
            provider: 'local',
        },

        outline: {
            level: [2, 3],
        },
    },
})
