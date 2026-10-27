# FAQ

## General

### What does Tokenwarden do?
It sits between your plugins and the AI providers they use:
- it shows which plugin made each AI call and what it cost;
- it stops spending with monthly budgets, hourly call limits and a pause switch, before anything is sent;
- it shows which plugins send personal data (emails, phone numbers, card numbers, IBANs, US SSNs and your custom terms) to AI providers, and can replace that data with placeholders for the plugins you choose;
- it alerts you when a budget or limit is reached, or when a plugin's activity is far above normal.

### Which plugins does it work with?
- Plugins and themes that use the **WordPress AI Client** (`wp_ai_client_prompt()`), built into WordPress 7.0.
- Plugins that call **Anthropic, OpenAI, Google, OpenRouter, xAI, Mistral, DeepSeek, Groq or Perplexity directly** with their own API key, through WordPress's HTTP functions (for example AI Engine).

The plugins themselves need no changes or settings.

### Which AI calls can't Tokenwarden see?
- Plugins that send AI requests to **their own service**, which then calls an AI provider. Many SEO, page builder and form plugins with built-in AI work this way.
- Calls made with raw cURL, Guzzle or a provider's SDK, which bypass WordPress's HTTP functions.
- Calls made from the browser (JavaScript) straight to a provider.

Those calls aren't logged, priced, limited or checked. If a plugin you use doesn't appear on the Sources page after you used its AI features, it's probably one of these.

### Does Tokenwarden need its own API key or account?
No. There is no Tokenwarden account or service. Plugins keep using the keys they already have.

### Does it slow down AI calls?
Not noticeably. Before each call, Tokenwarden reads the month's total, counts the last hour's calls and checks the request text. After each call it writes one row to the database. These take milliseconds; AI providers usually take seconds to answer.

### Does it work on multisite?
Each site keeps its own log, budgets and settings. There is no network-wide view.

## Costs, budgets and limits

### Are the costs exact?
No, they are estimates: the provider's token counts × the price table. They don't include caching or batch discounts, taxes or plan fees. See [How costs are calculated](costs-and-pricing.md).

### Why does a call show no cost?
Either the model has no price yet, or the plugin read a streamed answer itself (common for chatbots), so the token counts weren't available to Tokenwarden. The request details say which. Streamed calls without a cost don't count towards dollar budgets, but they do count towards hourly limits.

### How are model prices kept up to date?
Turn on **Update prices automatically** under **Settings → Model prices** (the setup guide offers it too). Tokenwarden downloads current prices once a day from OpenRouter's public model list: Anthropic, OpenAI, Google, xAI, Mistral and DeepSeek models, plus every model on OpenRouter. Nothing about your site is sent. With it off, the built-in prices are used.

### What does the price download send?
Nothing about your site. It's a plain request for a public file, without your site address, usage data or keys. It's the only outside request Tokenwarden makes, and only when you turn it on.

### My provider changed a price, or I have a negotiated rate. What do I do?
Edit the price under **Settings → Model prices**. Your price applies to new calls immediately and survives plugin updates. Past calls keep their recorded cost.

### What happens when a plugin reaches a budget or limit?
Its next AI calls are stopped before anything is sent, so they cost nothing.
- A plugin using the AI Client gets the error WordPress gives when AI is unavailable; plugins that check first may hide their AI buttons.
- A plugin calling a provider directly gets an HTTP error that says *Blocked by Tokenwarden* and the reason.

The calls are logged as **Blocked**. A budget block lifts on the 1st of next month or when you raise the budget. An hourly limit lifts as soon as the last hour's count drops.

### Should I use a budget or an hourly limit?
Both. A budget caps money, but only for calls with a known cost. An hourly limit caps the number of calls, including streamed ones, and stops loops and spam waves within the hour. Set the limit well above a plugin's normal use.

### Could a budget be exceeded slightly?
Yes, by part of one call. A budget is checked before each call, and a call's exact cost is only known after it finishes. So the call that crosses the line still completes; the next one is blocked.

### When do budgets reset?
At midnight on the 1st of each month, in your site's timezone.

### What are spike alerts?
An hourly check emails you (once a day per plugin) when a plugin's last hour has at least 20 calls and five times its usual hourly calls, or its last day cost at least $1 and four times its usual daily spend. A plugin needs three days of history first. Spike alerts don't block anything.

### What is the forecast?
Spend so far this month plus the last 7 days' daily average for each remaining day. A source is **On pace to exceed** when its forecast is over its budget, so you can act before it's blocked. See [Forecasts](costs-and-pricing.md#forecasts).

### Can I pause a plugin's AI without deactivating the plugin?
Yes. On **Sources**, open its **Policy** and turn on **Pause AI**. The rest of the plugin keeps working.

## Privacy

### Does Tokenwarden change my plugins' requests?
Not unless you turn on redaction for a plugin. By default it only checks requests for personal data and records what kind it found.

### What personal data does it look for?
- **On by default:** email addresses, phone numbers, payment card numbers (checked with the card checksum), IBANs (checked with the IBAN checksum) and US Social Security numbers.
- **Optional:** IP addresses.
- **Your own list:** any custom terms you add.

See [Privacy](privacy.md).

### Why isn't redaction on by default?
Because some plugins need the real data to work. A spam checker can't judge an email address it can't see. The Privacy page shows which plugins send personal data, so you can turn redaction on where it makes sense.

### Are names removed?
Not automatically: detecting names reliably would need an outside service. Add important names (customers, staff) as **custom terms**.

### What is the AI data map?
A spreadsheet you can download from the Privacy page: every plugin that used AI, its providers and models, volume, cost, the kinds of personal data found and the controls that apply. It's useful for records of processing, risk assessments and client reports. It never contains the personal data itself.

### Does Tokenwarden store my prompts?
No, not unless you turn on **Store prompt and response excerpts** under **Settings → Logging**. By default it stores only facts about each call: source, model, tokens, cost, timing and the kinds of personal data found.

### Does Tokenwarden send any data anywhere?
No. Everything stays in your WordPress database. Tokenwarden doesn't phone home or track usage. Its only outside request is the optional daily price download, which sends nothing about your site.

### Does Tokenwarden work with WordPress's personal data export and erase tools?
Yes. **Tools → Export Personal Data** includes the person's AI requests, and **Tools → Erase Personal Data** anonymises them while keeping costs for your budgets. Tokenwarden also suggests text for your privacy policy. See [Your data](data.md#personal-data-and-privacy-laws).

### Is the dashboard accessible?
It's built to work with the keyboard and screen readers: details panels keep focus until you close them with **Esc**, each chart has a **Table** view, status is shown with text and icons rather than colour alone, and animations stop when your system asks for reduced motion. Screens are checked with an automated accessibility checker in light and dark mode. Automated checks don't catch everything, so if something doesn't work with your assistive technology, please tell us.

## Managing data

### How do I start over?
Under **Settings → Data**, click **Clear log**, then **Click again to confirm**. This deletes the request log and resets this month's spend. Settings are kept.

### What does uninstalling remove?
Deleting the plugin removes its database table, settings and scheduled tasks. Deactivating keeps everything. See [Your data](data.md#deactivating-and-uninstalling).

### Can I try Tokenwarden with sample data?
Yes, even on a live site. Turn on the **Demo data** switch at the top right of Tokenwarden. Sample data lives in a separate sandbox and never touches your real data or settings. See [Explore with demo data](getting-started.md#explore-with-demo-data).

### Does Tokenwarden need approval in the AI plugin's Connector Approval?
No. Tokenwarden never calls AI itself. It logs other plugins' calls that Connector Approval blocks, and lists the plugins waiting for approval. See [Connector Approval](getting-started.md#connector-approval).

### Can I use a local AI model?
Tokenwarden logs calls through [AI Provider for WebLLM](https://github.com/ProgressPlanner/ai-provider-for-webllm), which runs a small model in an open dashboard tab, at $0. It's only practical for development sites and experiments; see [Local AI with WebLLM](local-ai.md) for its limits.
