# Caching

When a plugin sends exactly the same AI request again (the same spam check, the same FAQ answer, the same alt-text request), you pay the provider again for an answer you already have. **Response caching** stores the first answer in your own database and reuses it, so the repeat costs nothing and returns instantly.

Caching is part of Tokenwarden. Nothing is locked, and no account or key is needed.

## Turning it on

Caching is **off for every plugin** until you choose it, because some plugins expect a fresh answer every time (for example a "regenerate" button).

1. Open **Tokenwarden → Caching**.
2. Switch on the plugins you want cached. Plugins that repeat themselves often are sorted to the top, with what caching could save based on the last 30 days.
3. Click **Save**.

Good candidates are spam checks, classification, alt text and FAQ-style answers. Don't cache content generators that should give a different answer each time.

## Settings

| Setting | Meaning |
|---|---|
| **Cache responses** | Master switch. Off means nothing is cached or served, but stored answers are kept until they expire. |
| **Keep answers for** | 1 hour, 24 hours, 7 days or 30 days. After that, the next identical request goes to the provider again and refreshes the cache. |
| **Which sources** | **Selected sources** (default) or **All sources**. |
| **Maximum cache size** | 25, 50, 100 or 250 MB. The least recently used answers are removed first. |

## What counts as the same request

The request must be identical: same provider endpoint, same model and same text, after Tokenwarden has applied redaction and your brand brief. Only successful text answers of up to 1 MB are cached. Errors are never cached.

## Personal data

Redaction runs before caching, so a cached answer holds placeholders such as `[EMAIL_1]`, not the personal data. When an answer is reused, the placeholders are replaced with the values from the current request.

## What you see

- **Caching page:** money saved in the last 30 days, calls answered from the cache and the hit rate, and cache size.
- **Requests page:** cached calls are marked **From cache**, cost nothing and show what they saved.
- **Budgets:** a cached call doesn't count towards a plugin's spend. Hourly call limits still count it.

## Clearing the cache

**Caching → Clear cache** deletes every cached answer. Do this after changing a plugin's settings or content that its answers depend on. Uninstalling Tokenwarden removes the cache table.

Cached answers are stored in the table `{prefix}tokenwarden_cache` and never leave your server.
