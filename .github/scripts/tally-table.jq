# Builds the Tally section of the release body from the JSON files that
# `tally run --output` writes. Run with: jq -s -r -f tally-table.jq <dir>/*.json

def bench: .id | split("::")[0] | split("\\")[-1] | sub("Bench$"; "");
def method: .id | split("::")[1] | split("@")[0];
def size: .id | split("@")[1] | split("-")[-1] | tonumber;
def input: .id | split("@")[1] | split("-") | "\(.[-1]) \(.[0])";
def round1: . * 10 | round / 10;
def count: if . >= 1e6 then "\(. / 1e6 | round1)M" else "\(. / 1e3 | round1)K" end;
def bytes: if . >= 1048576 then "\(. / 1048576 | round1) MiB" else "\(. / 1024 | round1) KiB" end;

group_by(bench)
| map(
    (map(select(method == "benchGrowth")) | sort_by(size)) as $growth
    | (map(select(method == "benchValidate")) | first) as $validate
    | select($validate != null)
    | "| \($validate | bench) | \($validate | input) "
      + "| \($validate.metrics.instructions | count) "
      + "| \($validate.metrics.cycles | count) "
      + "| \($validate.metrics.memory | bytes) "
      + (if ($growth | length) > 1
         then "| ×\(($growth[-1].metrics.instructions / $growth[0].metrics.instructions) | round1) (\($growth[0] | size) → \($growth[-1] | size)) |"
         else "| – |" end)
  )
| [
    "### Instruction counts",
    "",
    "Counted with [Tally](https://github.com/phpstan/tally) on ubuntu-24.04 and PHP 8.4. Instruction counts do not depend on runner load.",
    "",
    "| Benchmark | Input | Instructions | CPU cycles | Memory | Instruction growth (input size) |",
    "|-----------|-------|-------------:|-----------:|-------:|-------------------:|"
  ] + .
| join("\n")
