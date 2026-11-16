# Hubbub AI Hub

WordPress plugin behind the AI tools on [searchenginehubbub.com/tools](https://searchenginehubbub.com/tools/):

- **AI Readiness Checker** `[hubbub_ai_checker]`
- **AI Crawler Directory**, bot verifier and robots.txt builder `[hubbub_ai_crawlers]`
- **AI Ads Opportunities** `[hubbub_ai_ads]`
- **Tools landing page** `[hubbub_ai_tools]`
- JSON API under `/wp-json/hubbub/v1/` (`check`, `crawlers`, `verify`, `robots`, `ads`, `citations`, `insights`, `about`, `llms`) and an MCP server at `/wp-json/hubbub/v1/mcp`.

The styling inherits the active theme's colours and fonts (`--wp--preset--*`).

## Data

Outside sources: Trakkr open data (CC BY 4.0, figures quoted in the JSON files), each company's published crawler IP lists (fetched live by `/verify`, cached 12 hours) and live Cloudflare Radar chart embeds (CC BY 4.0; Radar's API data is CC BY-NC, so it is not used).

`hubbub-ai-hub/data/*.json` ship with the plugin. A private page with the slug `hubbub-data-ai-ads` or `hubbub-data-crawlers` holding newer JSON overrides them, so data can be refreshed without a release.

## Releases and updates

Bump `Version:` in `hubbub-ai-hub/hubbub-ai-hub.php` and push to `main`. The workflow runs the tests, builds `hubbub-ai-hub.zip` and publishes a release. Installed sites check the latest release every few hours and auto-update.

If this repository is private, the site needs a read-only token in `wp-config.php`:

```php
define( 'HUBBUB_AI_HUB_GITHUB_TOKEN', 'github_pat_…' );
```

## Tests

```
php tests/test.php
```
