# For developers

## How Tokenwarden works

Tokenwarden does not wrap or replace AI provider plugins. It uses hooks from the WordPress 7.0 AI Client and the standard HTTP API.

**Calls through the WordPress AI Client:**

| Step | Hook | What Tokenwarden does |
|---|---|---|
| 1 | `wp_ai_client_prevent_prompt` (filter) | Works out which plugin or theme is calling, from the call stack. Returns `true` to block the call if the source is paused, over its hourly limit, or a budget is reached. |
| 2 | `wp_ai_client_before_generate_result` (action) | Starts the timer and marks a generation in flight. |
| 3 | `http_request_args` (filter) | For POST requests to a known provider host: checks the JSON body for personal data. Replaces it only if the source's policy has `redact` on. |
| 4 | `http_response` (filter) | Puts replaced values back into the provider's response body. |
| 5 | `wp_ai_client_after_generate_result` (action) | Records model, provider, token usage, estimated cost and timing. |
| — | `http_api_debug` (action) | Records provider errors (HTTP 4xx/5xx) and network failures. |

**Direct calls** (a plugin calling a provider with its own key through the WordPress HTTP API, with no AI Client generation in flight):

| Step | Hook | What Tokenwarden does |
|---|---|---|
| 1 | `http_request_args` (filter) | For text generation requests to a known provider host: attributes the call from the call stack, checks it for personal data and redacts if the policy says so. |
| 2 | `pre_http_request` (filter, priority 7) | Returns a `WP_Error` (`tokenwarden_blocked`) if the source is paused, over its hourly limit or over a budget. Nothing is sent. |
| 3 | `http_response` (filter, priority 6) | Records model and token usage from the answer (plain JSON or a streamed body), with the estimated cost. |
| — | `http_api_debug` (action) | Records provider errors. |

When a plugin reads a streamed answer itself (for example with its own `CURLOPT_WRITEFUNCTION`), the body WordPress hands back is empty, so the call is logged without tokens or cost, with a note. Calls made with other HTTP libraries, or from the browser, aren't visible.

Notes:

- **Blocked `is_supported_*()` checks are not logged.** When a source is blocked, `wp_ai_client_prompt()->is_supported_*()` returns `false` silently, so plugins can hide their AI features without filling the log.
- **Where the code lives:**
  - `includes/class-gateway.php`: the hooks;
  - `includes/class-redactor.php`: personal data detection and redaction;
  - `includes/class-provider-adapters.php`: provider request formats;
  - `includes/class-ledger.php`: storage and monthly totals;
  - `includes/class-stats.php`: dashboard numbers.

## Filters and actions

### `tokenwarden_provider_hosts`

Maps API hosts to provider IDs. Requests to these hosts are checked for personal data, and text-generation requests made directly by plugins are recorded and can be blocked. Defaults: `api.anthropic.com`, `api.openai.com`, `generativelanguage.googleapis.com`, `openrouter.ai`, `api.x.ai`, `api.mistral.ai`, `api.deepseek.com`, `api.groq.com`, `api.perplexity.ai`.

```php
add_filter( 'tokenwarden_provider_hosts', function ( $hosts ) {
	$hosts['llm.internal.example.com'] = 'self-hosted';
	return $hosts;
} );
```

### Adding a provider

Add its host with `tokenwarden_provider_hosts`, as above.

- **Detection and redaction** apply to every POST request to that host, except model listings (paths ending in `/models`).
- **Direct calls** are recorded, and can be blocked, when the URL path matches one of these text generation formats:

| Path ends with | Format |
|---|---|
| `/v1/messages` | Anthropic Messages |
| `/responses` | OpenAI Responses |
| `/chat/completions` | OpenAI-compatible chat (most self-hosted and gateway servers) |
| `:generateContent` / `:streamGenerateContent` | Google Gemini |

### `tokenwarden_price_source_url`

The URL of the price list used by automatic price updates. It defaults to OpenRouter's public model list. A replacement must return the same format: `{ "data": [ { "id": "provider/model", "pricing": { "prompt": "<USD per token>", "completion": "<USD per token>" } } ] }`.

### `tokenwarden_default_prices`

Changes the built-in price table: USD per million tokens, as `[input, output]`. Prices saved in **Settings → Model prices** still take priority.

```php
add_filter( 'tokenwarden_default_prices', function ( $prices ) {
	$prices['my-fine-tuned-model'] = array( 3.0, 12.0 );
	return $prices;
} );
```

### `tokenwarden_attribution_skip`

Plugin slugs to skip when working out who made a call. Use it for your own "middleman" plugins that make AI calls on behalf of other plugins, so the call is attributed to the plugin further up the call stack.

```php
add_filter( 'tokenwarden_attribution_skip', function ( $slugs ) {
	$slugs[] = 'my-ai-helper-library';
	return $slugs;
} );
```

### `tokenwarden_record_row` (filter)

Adjusts a call's row just before it is saved, for example to mark a call answered from your own cache: `cached` = 1, the cost moved into `saved`, and `cost` = 0. Cached calls show as "From cache" in the dashboard.

### `tokenwarden_local_providers` (filter)

Provider IDs whose calls cost nothing because the model runs locally. Defaults to `webllm`.

### `tokenwarden_connector_approval_active`, `tokenwarden_docs_url`, `tokenwarden_support_url` (filters)

- `tokenwarden_connector_approval_active` overrides the detection of the AI plugin's Connector Approval feature.
- `tokenwarden_docs_url` sets the "Full documentation" link on the Help page.
- `tokenwarden_support_url` sets the "Get support" link on the Help page.

### Demo mode

Demo mode is per user (user meta `tokenwarden_demo_mode`) and only applies inside Tokenwarden's own REST requests. While it applies:
- `Tokenwarden_Ledger::table()` returns `{prefix}tokenwarden_demo_requests`;
- settings come from `tokenwarden_demo_settings`;
- month totals use `tokenwarden_demo_spend_*` options.

Run code against the sandbox with `Tokenwarden_Demo::run( $callback )`.

### `tokenwarden_recorded` (action)

Fires after each call is logged, with the row ID and the row's values. Use it to forward usage to your own monitoring.

```php
add_action( 'tokenwarden_recorded', function ( $id, $row ) {
	if ( 'error' === $row['status'] ) {
		error_log( "AI call failed for {$row['source']}: {$row['note']}" );
	}
}, 10, 2 );
```

`$row` keys:
- `created_at`, `source`, `user_id`, `provider`, `model`, `capability`, `status` (`ok`, `blocked` or `error`);
- `input_tokens`, `output_tokens`, `cost`, `priced`, `latency_ms`;
- `channel` (`ai_client` or `direct`), `pii` (kinds and counts found, for example `email:2,phone:1`), `redactions` (items replaced);
- `note`, `prompt_excerpt`, `response_excerpt`.

## Source IDs

Sources are identified as `type:slug`:

| Example | Meaning |
|---|---|
| `plugin:woocommerce` | A plugin, by its folder name (or file name for single-file plugins) |
| `theme:twentytwentysix` | A theme |
| `mu-plugin:custom-ai` | A must-use plugin |
| `core` | No plugin or theme found in the call stack |

## WP-CLI

| Command | What it does |
|---|---|
| `wp tokenwarden status` | Month-to-date spend per source, with budgets and paused state |
| `wp tokenwarden seed [--days=45] [--per-day=140] [--seed=7] [--demo]` | Adds fictional sample traffic to the **live** log (for development and screenshots), or to the demo sandbox with `--demo`. Site owners should use the dashboard's Demo data switch instead. |
| `wp tokenwarden reset [--yes]` | Deletes the request log and month-to-date totals. Settings are kept. |

## REST API

The dashboard uses these routes under `/wp-json/tokenwarden/v1`. All of them require the `manage_options` capability.

| Method | Route | Purpose |
|---|---|---|
| GET | `/overview?days=30` | Everything on the Overview page |
| GET | `/sources?days=30` | Sources with spend, forecast, policy and status |
| POST | `/sources` | Update one source's policy: `{ "source": "plugin:x", "policy": { "budget": 10, "rate_limit": 300, "paused": false, "redact": false } }` |
| GET | `/requests?page=1&per_page=25&source=&status=&model=&pii=` | The request log, plus the source and model lists for the filters |
| GET | `/data-map?days=90` | The AI data map: per source, providers, models, routes, volume, cost, personal data found and controls |
| DELETE | `/requests` | Clear the request log |
| GET / POST | `/settings` | Read or update settings; the POST body is a partial settings object |
| POST | `/redact-preview` | `{ "text": "…", "redaction": { … } }` returns the redacted text and counts |

## Building from source

Requires Node.js. PHP and Docker are not needed: development uses [WordPress Playground](https://wordpress.github.io/wordpress-playground/).

```bash
npm install
npm run build        # or: npm start (watch mode)
npm run lint:js
```

**Local site with sample data:**

```bash
npx @wp-playground/cli@latest server --port=9400 \
  --mount=.:/wordpress/wp-content/plugins/tokenwarden \
  --blueprint=dev/blueprint.json --login
```

**Integration tests.** `tests/run.sh` runs them in a throwaway WordPress, with the real Anthropic provider plugin talking to a mock API. Note: parts of the suite still describe the 1.x behaviour (brand brief, redaction on by default) and need updating before they pass.

**Documentation screenshots.** Run with the local site above running; uses your installed Chrome:

```bash
node dev/screenshots.mjs
```

**Release zip:**

```bash
bin/build-zip.sh     # writes dist/tokenwarden.zip
```

## Release checklist

1. **Review the built-in model prices** against each provider's official price page. They're the fallback when automatic updates are off or a model isn't in the live list:
   - update the table in `includes/class-pricing.php`;
   - set `Tokenwarden_Pricing::CHECKED` to today's date;
   - add any new models.
2. **Bump the version** in `tokenwarden.php` (header and `TOKENWARDEN_VERSION`) and in the `Stable tag` line of `readme.txt`.
3. **Add a changelog entry** in `readme.txt`. Mention the price review, for example "Model prices checked 2026-12-01; added gpt-5.6".
4. **Run `npm run lint:js` and the Plugin Check plugin** against the zip (and `tests/run.sh` once it's updated).
5. **Run `bin/build-zip.sh`**, and refresh the screenshots if the interface changed.
6. **Publish a GitHub release** with the zip attached, so the website's download button (which links to the latest release) serves it.

Sites with automatic price updates stay current on their own. For everyone else, plan a release every few months that refreshes the built-in table. The dashboard warns users when built-in prices are more than 120 days old.
