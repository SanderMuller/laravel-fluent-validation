import { readdirSync, readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

/**
 * Builds the Tally table on the benchmarks page from the numbers accepted in
 * benchmarks/*.php, so the page cannot drift from them. A page holds the
 * placeholder; the markdown renderer and llms-full.txt both replace it.
 */
export const TALLY_PLACEHOLDER = '<!-- tally-table -->'

const BENCHMARKS_DIR = fileURLToPath(new URL('../../benchmarks/', import.meta.url))

// #[ExpectedFor('items-500', instructions: '9.527M', ...)], then the benchmark
// method it belongs to. The lookahead skips any further attributes without
// consuming them, so a second ExpectedFor on the same method matches too.
const EXPECTED_FOR = /#\[ExpectedFor\(\s*'([^']+)',\s*instructions:\s*'([^']+)'[^\]]*\](?=(?:\s*#\[[^\]]*\])*\s*public function (bench\w+))/g

// Runs FluentRule objects inside Laravel's own validator, so it has no native
// counterpart and does not measure RuleSet or a form request.
const NOT_COMPARED = ['RuleObjectBench.php']

const UNITS: Record<string, number> = { '': 1, K: 1e3, M: 1e6, B: 1e9, T: 1e12 }

interface Row {
    scenario: string
    size: string
    fluent: string
    native?: string
}

function count(value: string): number {
    const match = /^([\d.]+)([KMBT]?)$/.exec(value.replace(/[_\s]/g, ''))
    if (match === null) {
        throw new Error(`Unknown Tally instruction count: ${value}`)
    }

    return parseFloat(match[1]) * UNITS[match[2]]
}

/** ActivityLogBench.php → "Activity log" */
function scenarioName(file: string): string {
    const words = file.replace(/Bench\.php$/, '').split(/(?=[A-Z])/)

    return [words[0], ...words.slice(1).map(word => word.toLowerCase())].join(' ')
}

/** items-500 → "500 items" */
function sizeLabel(label: string): string {
    const [noun, amount] = label.split('-')

    return amount === undefined ? label : `${amount} ${noun}`
}

function rows(): Row[] {
    const result: Row[] = []

    for (const file of readdirSync(BENCHMARKS_DIR).filter(name => name.endsWith('Bench.php') && ! NOT_COMPARED.includes(name)).sort()) {
        const source = readFileSync(BENCHMARKS_DIR + file, 'utf8')
        const fluent = new Map<string, string>()
        const native = new Map<string, string>()

        const matches = [...source.matchAll(EXPECTED_FOR)]

        // A format the pattern does not know must fail the build, not drop a row.
        if (matches.length !== source.split('#[ExpectedFor(').length - 1) {
            throw new Error(`Could not read every #[ExpectedFor] in ${file}`)
        }

        for (const [, label, instructions, method] of matches) {
            if (method === 'benchValidate') {
                fluent.set(label, instructions)
            } else if (method === 'benchNative') {
                native.set(label, instructions)
            }
        }

        // One row per dataset, so a ratio only compares counts of the same size.
        for (const [label, instructions] of fluent) {
            result.push({ scenario: scenarioName(file), size: sizeLabel(label), fluent: instructions, native: native.get(label) })
        }
    }

    if (result.length === 0) {
        throw new Error(`No #[ExpectedFor] numbers found in ${BENCHMARKS_DIR}`)
    }

    return result
}

export function tallyTable(): string {
    const lines = [
        '| Scenario | Size | Fluent validation | Native Laravel | Fewer instructions |',
        '|---|---|--:|--:|--:|',
    ]

    for (const row of rows()) {
        const ratio = row.native === undefined ? '—' : `${Math.round(count(row.native) / count(row.fluent))}x`
        lines.push(`| ${row.scenario} | ${row.size} | ${row.fluent} | ${row.native ?? '—'} | ${ratio} |`)
    }

    return lines.join('\n')
}

export function withTallyTable(markdown: string): string {
    return markdown.includes(TALLY_PLACEHOLDER) ? markdown.replace(TALLY_PLACEHOLDER, tallyTable()) : markdown
}
