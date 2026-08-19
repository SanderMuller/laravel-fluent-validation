import { h } from 'vue'
import DefaultTheme from 'vitepress/theme'
import HomeNextSteps from './HomeNextSteps.vue'
import NextPageCta from './NextPageCta.vue'
import './custom.css'

export default {
    extends: DefaultTheme,

    // Every guide page ends with the same "read this next" card. The default
    // footer keeps the previous-page link; `docFooter.next` is off so the card
    // owns the forward direction.
    Layout: () => h(DefaultTheme.Layout, null, {
        'doc-footer-before': () => h(NextPageCta),
    }),

    enhanceApp({ app }) {
        app.component('HomeNextSteps', HomeNextSteps)
    },
}
