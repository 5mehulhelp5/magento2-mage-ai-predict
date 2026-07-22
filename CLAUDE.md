# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Module Overview

`Mageprince_MageAIPredict` is a Magento 2 admin extension that adds **AI-assisted demand forecasting and procurement alerts** — the "Predictive Inventory & Raw Material Forecaster". For every stock-bearing product it predicts next month's demand from historical sales, compares it against current stock, and tells the merchant **what to reorder (and by when)** or flags **overstock / dead stock**.

It is a **separate module that depends on `Mageprince_MageAI`** and reuses its `Helper\Data` provider credentials (OpenAI / Anthropic / Google Gemini), so the AI provider is configured in one place. Content generation (MageAI) and forecasting (this module) stay cleanly decoupled.

This module lives at `app/code/Mageprince/MageAIPredict/` inside the parent Magento project and has its own git repository — commit here, not at the Magento root.

### Core design principle
**LLMs are bad at raw numeric forecasting**, so the module is built in two layers:
1. **Statistical layer (deterministic PHP, no AI)** — computes the demand baseline with plain math (demand rate × seasonality × recent trend). Cheap, instant, reproducible.
2. **AI layer (the LLM)** — reasons *on top of* the baseline: it adjusts the number using trend/seasonality judgment and writes a plain-language recommendation. If the AI is disabled or fails, the module silently falls back to the baseline.

## Architecture

### Forecast run flow
1. Triggered by the **"Generate Forecasts Now"** button (`mageai_predict/forecast/generate`) or the **cron** (`Cron/GenerateForecasts.php`).
2. `Model/Forecast/ForecastRunner::run()` loads eligible products (enabled `simple` / `virtual` / `downloadable`), honoring the optional max-products limit.
3. `Model/Forecast/SalesDataProvider::getMonthlySeries()` fetches trailing-N-year monthly ordered quantities per product in one grouped SQL query (`sales_order_item` × `sales_order`, excluding canceled/closed).
4. For each product:
   - `Model/Forecast/BaselineCalculator::calculate()` produces the statistical baseline, seasonality index, trend factor, months-of-data and confidence.
   - `Model/Forecast/StockProvider::getQty()` reads current stock (legacy `StockRegistryInterface`).
   - **If AI is enabled and the product has ≥ 2 months of history**, `Model/Query/ForecastGenerator::adjust()` sends the baseline + context to the LLM and returns an adjusted forecast + confidence + rationale (JSON). On `null` (failure/disabled/insufficient data) the baseline is used.
   - `ForecastRunner::computeAlert()` derives reorder quantity, reorder-by date and status from forecast vs. stock.
5. Rows are upserted (one per product, unique on `product_id`) via `insertOnDuplicate`, flushed in batches of 200.

### AI request path (`Model/Query/ForecastGenerator.php`)
`adjust($context)` dispatches on `Mageprince\MageAI\Helper\Data::getProvider()`:
- **OpenAI** → `requestOpenAI()` → `POST {base_url}/v1/chat/completions` (adds `response_format: json_object` for `gpt-*` models).
- **Anthropic** → `requestAnthropic()` → `POST {base_url}/v1/messages` (`x-api-key`, `anthropic-version: 2023-06-01`).
- **Gemini** → `requestGemini()` → `POST {base_url}/v1beta/models/{model}:generateContent` (`x-goog-api-key`, `responseMimeType: application/json`).

All three request a JSON object `{"forecast": int, "confidence": "low|medium|high", "rationale": "…"}`. `parse()` strips code fences, extracts the JSON, validates it and clamps `forecast >= 0`. The entire method is wrapped in `try/catch` and returns `null` on any error (logged via `LoggerInterface`), so a single bad product never aborts the run. Temperature is a low `0.2`; `max_tokens` is `600`.

### Statistical baseline (`Model/Forecast/BaselineCalculator.php`)
- **Demand rate** = total units ÷ months in the span from first sale to target month (gaps count as zero-demand months).
- **Seasonality index** = average sales in the target calendar month ÷ observed average, clamped `[0.4, 3.0]`.
- **Trend factor** = last-3-months avg ÷ previous-3-months avg (needs ≥ 6 observed months), clamped `[0.5, 2.0]`.
- **Baseline** = `round(demandRate × seasonality × trend)`, floored at 0.
- **Confidence** = `high` (≥ 24 months), `medium` (≥ 12), else `low`.

### Alert logic (`ForecastRunner::computeAlert()`)
Given the effective forecast and current stock, with configurable lead time / safety-stock days / overstock multiplier:
- `dailyDemand = forecast / 30`; `reorderPoint = dailyDemand × (leadTime + safetyDays)`.
- `reorderQty = max(0, ceil(forecast + safetyUnits − stock))`.
- **Status**: `out_of_stock_risk` (stock ≤ reorder point → sets a `reorder_by_date`), `overstock` (no demand but stock on hand, or stock > forecast × multiplier), `reorder_soon` (reorderQty > 0), else `healthy`.

### Admin grid
- `Controller/Adminhtml/Forecast/Index.php` renders the page; layout `mageai_predict_forecast_index.xml` adds a **summary-cards block** (`Block/Adminhtml/Forecast/Summary.php`) above the `uiComponent` listing and the module CSS.
- `view/adminhtml/ui_component/mageai_predict_forecast_listing.xml` — the grid. Data source is registered in `etc/di.xml` as a `SearchResult` virtualType bound to `mageai_predict_forecast`.
- **Status** and **Confidence** columns use custom JS column components (`web/js/grid/columns/status.js`, `confidence.js`) that render coloured badges via `web/template/grid/cells/badge.html` + `web/css/forecast-grid.css`.
- The **"Generate Forecasts Now"** button is defined in the listing `<settings><buttons>` (same mechanism as the CMS page grid's "Add New").

### Key files
| File | Role |
|---|---|
| `Helper/Config.php` | All `mageai_predict/…` config reads (enabled, use-AI, history years, lead time, safety stock, overstock multiplier, max products, AI guidance prompt, cron) |
| `Model/Forecast/ForecastRunner.php` | Orchestrator: products → baseline → stock → optional AI → alerts → upsert |
| `Model/Forecast/SalesDataProvider.php` | Historical monthly ordered-qty per product (grouped SQL) |
| `Model/Forecast/BaselineCalculator.php` | Deterministic statistical baseline (no AI) |
| `Model/Forecast/StockProvider.php` | Current stock via `StockRegistryInterface` |
| `Model/Query/ForecastGenerator.php` | LLM call (OpenAI/Anthropic/Gemini), JSON in/out, graceful fallback |
| `Model/Forecast.php`, `Model/ResourceModel/Forecast.php`, `.../Forecast/Collection.php` | CRUD model for `mageai_predict_forecast` |
| `Model/Source/ForecastStatus.php` | Status option source (grid select filter + labels) |
| `Cron/GenerateForecasts.php` | Scheduled run (gated by module + cron enabled flags) |
| `Controller/Adminhtml/Forecast/Index.php` | Grid page (`mageai_predict/forecast/index`) |
| `Controller/Adminhtml/Forecast/Generate.php` | Run-now action (`mageai_predict/forecast/generate`) |
| `Block/Adminhtml/Forecast/Summary.php` | Summary dashboard cards (grouped count/reorder query) |

### DI / extension points
- `etc/module.xml` sequences `Mageprince_MageAI` (and Catalog/Sales/CatalogInventory/Backend/Ui) so this module loads after them.
- `etc/di.xml` registers the grid collection as a `SearchResult` virtualType and adds it to `Magento\Framework\View\Element\UiComponent\DataProvider\CollectionFactory`.
- The AI layer has **no JS**; provider switching is entirely server-side via MageAI's `Helper\Data`. Adding a provider to MageAI automatically makes it available here.
- Badge rendering is DOM/knockout only — no core template overrides.

## Development commands

Run these from inside the Magento PHP container (`reward shell`, or `docker exec <project>-php-fpm-1 bash -c '…'` if `reward shell -c` swallows stdout):

```bash
# After any PHP change
bin/magento cache:flush

# After changing etc/ XML, db_schema, or adding new classes
bin/magento setup:upgrade
bin/magento setup:di:compile

# After changing JS / HTML templates / CSS (admin caches these)
rm -rf pub/static/adminhtml/* var/view_preprocessed/*
bin/magento cache:flush
```

## Configuration paths

All stored under `mageai_predict/` scope (`etc/config.xml` defaults, `etc/adminhtml/system.xml` UI, section `mageai_predict` in the `mageprince` tab):

| Path | Default | Purpose |
|---|---|---|
| `mageai_predict/general/enabled` | `1` | Master switch |
| `mageai_predict/general/use_ai` | `1` | Enable the AI adjustment layer (off = baseline only, no API calls) |
| `mageai_predict/forecast/history_years` | `3` | Years of sales history to analyse |
| `mageai_predict/forecast/lead_time_days` | `14` | Restock/raw-material lead time |
| `mageai_predict/forecast/safety_stock_days` | `7` | Safety buffer in days of demand |
| `mageai_predict/forecast/overstock_multiplier` | `3` | Flag overstock above this × monthly demand |
| `mageai_predict/forecast/max_products` | `0` | Max products per run (`0` = no limit) |
| `mageai_predict/forecast/ai_prompt` | _(guidance text)_ | Extra merchant instructions appended to the AI prompt |
| `mageai_predict/cron/enabled` | `1` | Enable the scheduled run |
| `mageai_predict/cron/schedule` | `0 3 * * *` | Cron expression (`config_path` in `crontab.xml`) |

**AI provider config is inherited from `Mageprince_MageAI`** (`mageai/api/*`) — provider, base URLs, API keys and models. This module does not duplicate them.

### Data table
`mageai_predict_forecast` (declarative `etc/db_schema.xml`) — one upserted row per product, unique on `product_id`. Columns: `baseline_forecast`, `final_forecast`, `ai_adjusted`, `ai_rationale`, `current_stock`, `reorder_qty`, `reorder_by_date`, `status`, `confidence`, `seasonality_index`, `trend_factor`, `months_of_data`, `period_start`, timestamps.

## ACL resources

| Resource | Purpose |
|---|---|
| `Mageprince_MageAIPredict::forecast` | View the grid + run "Generate Forecasts Now" — gates both controllers |
| `Mageprince_MageAIPredict::configuration` | View/edit module config in Stores > Configuration |

## Roadmap

- **v1 (current)**: statistical baseline, AI adjustment + rationale, reorder/overstock alerts, coloured grid + summary cards, scheduled cron.
- **v1.1**: external signals (weather, festival/holiday calendar), dashboard widget, email/admin notifications — this is where the AI's value grows, because an LLM can reason over unstructured context that pure statistics cannot.
- **v2**: bill-of-materials (BOM) → explode finished-goods demand into raw-material requirements (the "raw material" forecasting), supplier lead-time tracking.
