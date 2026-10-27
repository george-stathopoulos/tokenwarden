=== Tokenwarden – AI Cost & Privacy Control ===
Contributors: mindanticipation
Tags: ai, ai cost, budget, privacy, cache
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See what each plugin spends on AI, cap it with budgets and hourly limits, and see which plugins send personal data to AI providers.

== Description ==

More and more plugins use AI, either through the AI Client built into WordPress 7.0 or with their own API key. Your provider sends one bill, and it can't tell you which plugin spent it, or what customer data was sent along.

Tokenwarden sits between your plugins and the AI providers. It needs no setup in those plugins.

= What Tokenwarden can see =

* Plugins and themes that use the **WordPress AI Client** (`wp_ai_client_prompt()`).
* Plugins that call **Anthropic, OpenAI, Google, OpenRouter, xAI, Mistral, DeepSeek, Groq or Perplexity directly** with their own API key, through WordPress's HTTP functions.

It can't see plugins that send AI requests to their own service (many SEO and page builder plugins work this way), or that bypass WordPress's HTTP functions. Tokenwarden tells you this in its setup guide.

= See where the money goes =

* Every call it can see is traced to the plugin or theme that made it, with model, tokens, estimated cost and response time.
* Daily charts, a model mix, an activity heatmap, a searchable request log and a month-end forecast.

= Stop spending before it happens =

* A monthly budget per plugin or theme, and one for the whole site.
* An hourly call limit per plugin, against loops and spam waves.
* Pause any plugin's AI with one switch.
* When a limit is reached, the next call is stopped before anything is sent. The plugin gets an error instead of an answer, and the call is logged as blocked.
* Email alerts when a budget passes your threshold, when it is reached, when a plugin hits its hourly limit, and when a plugin's activity or spending is far above its normal level.

= Stop paying for the same answer twice =

* Turn on **response caching** for the plugins that send identical requests again and again, such as spam checks, classification or alt text. A repeated request is answered from your own database and nothing is sent to the provider.
* Tokenwarden recommends which plugins to cache, based on how often each one repeats itself, and shows what caching saved.
* Choose how long answers are kept (1 hour to 30 days) and a size limit. Caching is off for every plugin until you choose it, because some plugins expect a fresh answer every time.
* Cached answers never contain personal data that redaction removed.

= Know what personal data leaves your site =

* Each request is checked for email addresses, phone numbers, payment card numbers, IBANs, US Social Security numbers, optionally IP addresses, and your own terms. Tokenwarden records what kind was found and how much, never the values.
* Detection changes nothing. The Privacy page shows which plugins send personal data, and you choose where to turn on **redaction**: for those plugins, values are replaced with placeholders such as `[EMAIL_1]` before the request leaves your server, and put back in the answer.
* Redaction is off by default because some plugins need the real data. A spam checker can't judge an email address it can't see.
* Detection is pattern-based. It finds the formats above, not every name or address, and it doesn't read images or files.
* **AI data map:** download a spreadsheet of every plugin that used AI, with its providers, models, volume, cost, the personal data found and the controls that apply. It's useful for records of processing, risk assessments and client reports.

= Privacy =

Tokenwarden doesn't send your data anywhere. It stores a log of AI calls (source, model, token counts, estimated cost, timing, and the kinds of personal data found) in your own database. If you turn on response caching, the answers it reuses are stored in your database too, with redacted personal data left as placeholders, and deleted when they expire. Prompt and response text is only stored if you turn on excerpts for debugging. History is deleted after 90 days by default.

Tokenwarden works with WordPress's privacy tools. Tools → Export Personal Data includes a person's AI requests, Tools → Erase Personal Data anonymises them, and a suggested paragraph is added to the Privacy Policy Guide.

= How costs are calculated =

Costs are estimates: the token counts the provider reports for each call, multiplied by a price per model. Your provider's invoice is always the source of truth.

* **Streamed answers:** when a plugin reads a streamed answer itself (common for chatbots), the token counts aren't available to Tokenwarden. These calls are logged without a cost and don't count towards dollar budgets. Hourly call limits still apply to them.
* **Prices:** turn on "Update prices automatically" and Tokenwarden downloads current prices once a day from OpenRouter's public model list. It covers Anthropic, OpenAI, Google, xAI, Mistral and DeepSeek models, and every model on OpenRouter. With it off, the price table built into the plugin is used. Prices you enter yourself always take priority.

== External services ==

This plugin can connect to one external service, and only if you turn it on.

**OpenRouter public model list** (`https://openrouter.ai/api/v1/models`). Used to keep the model price table current, so cost estimates stay accurate. It is off until you turn on "Update prices automatically" in the setup guide or under Settings → Model prices. When on, Tokenwarden downloads the list once a day, and when you click "Update now". The request is a plain download: it sends no site address, user data, usage data or API keys (the user agent is "Tokenwarden/version"). OpenRouter's [terms of service](https://openrouter.ai/terms) and [privacy policy](https://openrouter.ai/privacy).

Tokenwarden recognises requests to AI providers by their web address (for example api.anthropic.com, api.openai.com or generativelanguage.googleapis.com) so it can attribute and count other plugins' calls. It never contacts those providers itself and never sends them any request of its own.

Tokenwarden never sends your prompts, AI responses or usage data anywhere. Your plugins' AI calls go only to the providers they already use, as they would without Tokenwarden.

== Installation ==

1. Install and activate Tokenwarden.
2. A short setup guide opens. It helps you connect an AI provider if you use the WordPress AI Client, set a budget and an hourly limit, and choose alerts.
3. Calls appear on the Tokenwarden dashboard as soon as a plugin uses AI.

Want to look around first? Turn on **Demo data** at the top right of Tokenwarden. Sample data is kept in a separate sandbox and never touches your real data or settings.

== Frequently Asked Questions ==

= Which plugins does it work with? =

Plugins that use the WordPress AI Client, and plugins that call Anthropic, OpenAI, Google, OpenRouter, xAI, Mistral, DeepSeek, Groq or Perplexity directly through WordPress's HTTP functions. It doesn't see plugins that send AI requests to their own servers, or that use their own HTTP code.

= What happens when a plugin reaches a limit? =

Its next AI call is stopped before anything is sent. A plugin using the AI Client receives the error WordPress uses when AI is unavailable. A plugin calling a provider directly receives an HTTP error that says "Blocked by Tokenwarden". The call is logged as blocked. Budgets reset on the 1st of each month; hourly limits as soon as the last hour's count drops.

= Why does a call show no cost? =

Either the model has no price yet (add it under Settings → Model prices, or turn on automatic price updates), or the plugin read a streamed answer itself, so the token counts weren't available. The request details say which.

= Are the costs exact? =

They are estimates from the token counts each provider reports and the price table under Settings. They don't include discounts such as prompt caching or batch pricing, taxes or minimum charges. Your provider's invoice is the source of truth.

= Does Tokenwarden change my plugins' requests? =

Not unless you turn on redaction for a plugin. Detection only reads the request.

= Can it catch names in prompts? =

Not automatically. Add the names that matter to you, such as key customers or staff, as custom terms on the Privacy page.

= Which answers does the cache reuse? =

Only a request that is exactly the same as an earlier one: same provider, model and text. Only successful answers are kept, and only for the plugins you choose under Caching.

= Does it slow down AI calls? =

Not noticeably. Before each call it reads the month's total and the last hour's count, and checks the request text. After each call it writes one row to the database.

= Does it work with the AI plugin's Connector Approval? =

Yes. Tokenwarden never calls AI itself, so it never needs approval. It logs the calls Connector Approval blocks and shows which plugins are waiting for approval.

= Where do I find help? =

Click **Help** at the top right of Tokenwarden for guides, answers, troubleshooting and a glossary. Hover any ⓘ icon for a quick explanation.

== Screenshots ==

1. Overview: spend by plugin, month-end forecast, budget status and what needs attention.
2. Sources: budgets, hourly limits and policies for each plugin and theme.
3. Requests: every call with model, tokens, cost, route and the personal data it contained.
4. Privacy: which plugins send personal data, with a redaction switch for each, and the AI data map download.
5. Caching: which plugins to cache, how long to keep answers, and what caching saved.
6. Settings: site-wide budget, hourly limit, alerts, logging and model prices.

== Changelog ==

= 2.0.0 =
* First public release.
