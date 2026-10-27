#!/usr/bin/env bash
# Runs the integration tests in a throwaway WordPress (WordPress Playground).
# Requires Node.js. Usage: tests/run.sh
set -euo pipefail
cd "$(dirname "$0")/.."
rm -f tests/out/results.json
npx -y @wp-playground/cli@latest run-blueprint \
  --blueprint=tests/blueprint.json \
  --mount="$PWD:/wordpress/wp-content/plugins/tokenwarden" \
  --mount="$PWD/tests/fixtures/demo-caller:/wordpress/wp-content/plugins/demo-caller" \
  --mount="$PWD/tests/fixtures/mu-plugins:/wordpress/wp-content/mu-plugins" \
  --mount="$PWD/tests:/tests" \
  --mount="$PWD/tests/out:/out" > tests/out/playground.log 2>&1 || { tail -20 tests/out/playground.log; exit 1; }
# Second site: compatibility with the official AI plugin's Connector Approval.
rm -f tests/out/results-ai.json
npx -y @wp-playground/cli@latest run-blueprint \
  --blueprint=tests/blueprint-ai.json \
  --mount="$PWD:/wordpress/wp-content/plugins/tokenwarden" \
  --mount="$PWD/tests/fixtures/demo-caller:/wordpress/wp-content/plugins/demo-caller" \
  --mount="$PWD/tests/fixtures/mu-plugins:/wordpress/wp-content/mu-plugins" \
  --mount="$PWD/tests:/tests" \
  --mount="$PWD/tests/out:/out" > tests/out/playground-ai.log 2>&1 || { tail -20 tests/out/playground-ai.log; exit 1; }

node -e '
const a = require("./tests/out/results.json");
const b = require("./tests/out/results-ai.json");
const r = { passed: a.passed + b.passed, failed: a.failed + b.failed, results: [ ...a.results, ...b.results ] };
let group = "";
for (const t of r.results) {
  if (t.group !== group) { group = t.group; console.log("\n" + group); }
  console.log(`  ${t.ok ? "✓" : "✗"} ${t.test}` + (t.ok ? "" : "\n      " + JSON.stringify(t.detail)));
}
console.log(`\n${r.passed} passed, ${r.failed} failed`);
process.exit(r.failed ? 1 : 0);
'
