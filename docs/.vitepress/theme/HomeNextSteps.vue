<script setup lang="ts">
import { computed } from 'vue'
import { withBase } from 'vitepress'
import { pages, slug } from '../pages'

// The reading order a first-time visitor should follow from the home page.
const stepFiles = ['02-installation', '03-basic-usage', '05-array-validation']

const steps = computed(() => stepFiles.map((file, index) => {
    const page = pages.find(entry => entry.file === file)

    if (!page) {
        throw new Error(`Home page step "${file}" is missing from the documentation page list.`)
    }

    return { ...page, step: `Step ${index + 1}`, href: withBase(`/${slug(page.file)}`) }
}))
</script>

<template>
    <div class="doc-cta-grid">
        <a v-for="step in steps" :key="step.file" class="doc-cta-card" :href="step.href">
            <span class="doc-cta-label">{{ step.step }}</span>
            <span class="doc-cta-title">{{ step.text }}</span>
            <span class="doc-cta-text">{{ step.blurb }}</span>
        </a>
    </div>

    <div class="doc-cta-footer">
        <a class="doc-cta-button" :href="withBase('/why-this-package')">Read why this package exists</a>
        <span class="doc-cta-note">
            Already have rules to convert? See
            <a :href="withBase('/migration')">Migrating to fluent validation</a>.
        </span>
    </div>
</template>
