# Tokenwarden

A WordPress plugin that sits between every plugin and the site's AI provider. It works through the WordPress 7.0 AI Client and gives the site owner:

- **Cost visibility:** spend per plugin and theme.
- **Budgets:** monthly limits, enforced before a request is sent.
- **Privacy:** personal data is redacted from prompts and restored in answers.
- **Brand brief:** one set of instructions added to every AI request.
- **Response caching:** repeated identical requests are answered from the site's own cache.

**User documentation:** [docs/](docs/README.md), with screenshots, an FAQ and troubleshooting. The WordPress.org listing text is [readme.txt](readme.txt).

**Model prices** come from the site owner's own edits first, then (opt-in) a daily download of OpenRouter's public model list (`includes/class-price-sync.php`), then the built-in table (`includes/class-pricing.php`). See [docs/costs-and-pricing.md](docs/costs-and-pricing.md#where-prices-come-from).

## How it works

The gateway needs no provider wrapping. It uses the AI Client hooks and the standard HTTP API:

| Hook | Used for |
|---|---|
| `wp_ai_client_prevent_prompt` | Attribute the caller (call stack); block paused or over-budget sources |
| `wp_ai_client_before_generate_result` | Start timing |
| `http_request_args` | Redact personal data and add the brand brief to provider requests |
| `pre_http_request` | Block over-budget direct calls; answer repeated requests from the cache |
| `http_response` | Restore redacted values in the provider's answer; store answers for the cache |
| `wp_ai_client_after_generate_result` | Record model, tokens and cost |
| `http_api_debug` | Record provider errors |

Code lives in `includes/`; the dashboard (React, built with `@wordpress/scripts`) lives in `src/`.

## Development

Requires Node.js. WordPress runs locally in [WordPress Playground](https://wordpress.github.io/wordpress-playground/), so PHP and Docker are not needed.

```bash
npm install
npm run build          # or: npm start (watch mode)
```

**Local site with sample data.** Starts WordPress on http://127.0.0.1:9400 with the plugin mounted, the Anthropic provider installed with a fake key, and 95 days of fictional sample traffic:

```bash
npx @wp-playground/cli@latest server --port=9400 --mount=.:/wordpress/wp-content/plugins/tokenwarden --blueprint=dev/blueprint.json --login
```

**Tests.** These are integration tests in a throwaway WordPress, using the real Anthropic provider plugin against a mock API:

```bash
tests/run.sh
```

**Lint.**

```bash
npm run lint:js
```

**Release zip.** Writes `dist/tokenwarden.zip`, using `.distignore` to choose the files:

```bash
bin/build-zip.sh
```

## WP-CLI

```bash
wp tokenwarden status      # month-to-date spend per source
wp tokenwarden seed        # fictional sample traffic for demos
wp tokenwarden reset       # clear the request log
```

## Filters

- `tokenwarden_provider_hosts`: add hosts for other providers, for example a self-hosted OpenAI-compatible server.
- `tokenwarden_default_prices`: change the default price table.
- `tokenwarden_attribution_skip`: plugin slugs to skip when attributing calls.
- `tokenwarden_recorded` (action): fires after each call is recorded.
