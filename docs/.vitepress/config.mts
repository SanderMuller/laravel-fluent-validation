import { writeFileSync } from 'node:fs'
import { readFile } from 'node:fs/promises'
import { resolve } from 'node:path'
import { defineConfig } from 'vitepress'
import { link, pages, sections } from './pages'

const SITE_URL = 'https://sandermuller.github.io/laravel-fluent-validation'
const DESCRIPTION = 'Fluent validation rule builders for Laravel with IDE autocompletion, co-located array rules, and up to 160x faster wildcard validation.'

export default defineConfig({
    title: 'Laravel Fluent Validation',
    description: DESCRIPTION,
    base: '/laravel-fluent-validation/',
    cleanUrls: true,
    lastUpdated: true,

    sitemap: {
        // Trailing slash required: routes resolve against this URL, and
        // without it the base path segment is dropped from every entry.
        hostname: `${SITE_URL}/`,
    },

    // llms.txt (https://llmstxt.org): a machine-readable index plus the full
    // markdown corpus, generated from the same page list as the sidebar so it
    // cannot drift from the site.
    async buildEnd(siteConfig) {
        const index = [
            '# Laravel Fluent Validation',
            '',
            `> ${DESCRIPTION}`,
            '',
        ]
        for (const section of sections) {
            index.push(`## ${section.text}`, '')
            for (const page of section.pages) {
                index.push(`- [${page.text}](${SITE_URL}${link(page.file)}): ${page.blurb}`)
            }
            index.push('')
        }
        writeFileSync(resolve(siteConfig.outDir, 'llms.txt'), index.join('\n'))

        const sources = await Promise.all(
            pages.map(page => readFile(resolve(siteConfig.srcDir, `${page.file}.md`), 'utf8')),
        )
        writeFileSync(resolve(siteConfig.outDir, 'llms-full.txt'), sources.join('\n\n---\n\n'))
    },

    head: [
        ['link', { rel: 'icon', type: 'image/svg+xml', href: '/laravel-fluent-validation/logo.svg' }],
        ['meta', { name: 'theme-color', content: '#FF2D20' }],
        ['meta', { property: 'og:type', content: 'website' }],
        ['meta', { property: 'og:title', content: 'Laravel Fluent Validation' }],
        ['meta', { property: 'og:description', content: 'Fluent validation rule builders for Laravel with IDE autocompletion, co-located array rules, and up to 160x faster wildcard validation.' }],
        ['meta', { property: 'og:image', content: 'https://sandermuller.github.io/laravel-fluent-validation/header.png' }],
        ['meta', { name: 'twitter:card', content: 'summary_large_image' }],
        ['meta', { name: 'twitter:image', content: 'https://sandermuller.github.io/laravel-fluent-validation/header.png' }],
    ],

    // README.md is the GitHub-facing folder index; the site's home is home.md.
    srcExclude: ['README.md'],

    rewrites: {
        'home.md': 'index.md',
        ...Object.fromEntries(pages.map(page => [`${page.file}.md`, `${page.file.replace(/^\d+-/, '')}.md`])),
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
            { text: 'Releases', link: 'https://github.com/SanderMuller/laravel-fluent-validation/releases' },
            { text: 'Packagist', link: 'https://packagist.org/packages/sandermuller/laravel-fluent-validation' },
        ],

        sidebar: sections.map(section => ({
            text: section.text,
            items: section.pages.map(page => ({ text: page.text, link: link(page.file) })),
        })),

        socialLinks: [
            { icon: 'github', link: 'https://github.com/SanderMuller/laravel-fluent-validation' },
        ],

        docFooter: {
            next: false,
        },

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
