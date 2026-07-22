# Magento 2 MageAI Predict Extension

This Magento 2 extension is an **AI-powered Predictive Inventory & Raw Material Forecaster**. It analyses your store's **historical sales**, predicts **next month's demand** for every product, and turns that into clear, actionable advice: **what to reorder and by when**, and **what is overstocked or dead stock** tying up your capital. It uses **OpenAI (GPT)**, **Anthropic (Claude)**, or **Google Gemini** — reusing the AI provider you already configured in the **MageAI** extension.

❤️ This is a companion to the open-source [MageAI](https://github.com/mageprince/magento2-mage-ai) extension. The goal is the same: keep it fully open-source and continuously expand the ways AI can help run a Magento 2 store — this time on the operations side, helping merchants avoid lost sales from stock-outs and frozen cash from over-stocking. If you're into Magento, AI, or supply-chain, your ideas and contributions are always welcome. Let's build something useful together!

> **Why not just ask an LLM to predict the numbers?** Because LLMs are poor at raw numeric forecasting. This extension does the maths with a deterministic statistical engine, then uses the AI only for what it's genuinely good at — reasoning over trend/seasonality/context and explaining the recommendation in plain language. The AI can be turned off entirely for free, instant, statistics-only forecasts.

## Features
- **Demand forecast for the upcoming month** — per-product prediction from 2–3 years of sales history (moving average × seasonality × recent trend)
- **AI-adjusted forecasts + plain-language recommendations** — the LLM refines the statistical baseline and explains *why* in merchant-friendly language
- **Automated procurement alerts** — recommended **reorder quantity** and a **reorder-by date** based on your lead time and safety-stock settings
- **Overstock / dead-stock detection** — flags capital tied up in slow-moving inventory
- **Colour-coded admin grid** — status and confidence shown as badges, plus a summary dashboard (out-of-stock risk / reorder soon / overstock / healthy / total units to reorder)
- **Multi-provider AI support** — reuses the OpenAI / Anthropic / Gemini provider configured in MageAI (one place for API keys)
- **Optional AI layer** — disable it for a free, instant, statistics-only baseline (no API calls)
- **Scheduled forecasting (cron)** — keeps the grid current automatically; configurable schedule
- **Run on demand** — a **"Generate Forecasts Now"** button for an immediate refresh
- **Tunable** — configure history window, lead time, safety stock and overstock threshold to match your business

## Supported AI Providers

Provider, models and API keys are inherited from the **MageAI** extension configuration.

| Provider | Models |
|---|---|
| **Google Gemini** *(default)* | `gemini-2.5-flash`, `gemini-2.5-pro`, `gemini-1.5-flash`, `gemini-1.5-pro` |
| **OpenAI (ChatGPT)** | `gpt-4o`, `gpt-4o-mini`, `gpt-4-turbo`, `gpt-4`, `gpt-3.5-turbo` |
| **Anthropic (Claude)** | `claude-opus-4-5`, `claude-sonnet-4-6`, `claude-haiku-4-5` |

## Usage

### Setup
1. Make sure the **MageAI** extension is installed and its AI **provider + API key** are configured (`Stores > Configuration > Mageprince > MageAI`)
2. Go to `Stores > Configuration > Mageprince > MageAI Predict (Demand Forecast)`
3. Enable **Forecasting**, and choose whether to **Use AI Adjustment** (off = statistics only, no API calls)
4. Set your **Sales History (years)**, **Restock Lead Time**, **Safety Stock (days)** and **Overstock Threshold**
5. Optionally set a **Max Products Per Run** and enable the **Scheduled Forecast** with a cron expression
6. Save the configuration

### Generate a forecast
1. Open **MageAI Predict > Demand Forecast** from the admin menu
2. Click **"Generate Forecasts Now"** (or wait for the scheduled cron)
3. The grid fills with per-product forecasts, statuses, reorder recommendations and AI rationales

### Read the grid
| Column | Meaning |
|---|---|
| **Baseline Forecast** | Prediction from pure statistics (sales history only) |
| **Forecast (units)** | Final prediction after AI adjustment (falls back to baseline if AI is off/unavailable) |
| **Current Stock** | On-hand quantity right now |
| **Reorder Qty / Reorder By** | How many units to order, and the deadline to order them |
| **Status** | 🔴 Out-of-stock risk · 🟠 Reorder soon · 🔵 Overstock / dead stock · 🟢 Healthy |
| **Confidence** | How much history backs the forecast (high / medium / low) |
| **AI Recommendation** | Plain-language reasoning for the number |

> The AI is only called for products with **at least 2 months of sales history** — there's no point spending an API call on a product with nothing to reason about. Products below that threshold use the statistical baseline.

## How to Install

### Install via Composer

Run the following commands in your Magento 2 root folder:

```bash
composer require mageprince/magento2-mage-ai-predict
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy
```

> Requires the **MageAI** extension (`mageprince/magento2-mage-ai`), which is pulled in automatically as a dependency.

## Contribution
Contributions are highly welcome and encouraged! 🙌

This project is a personal open-source effort to bring the power of AI to Magento 2. Whether you want to:
- Add new features (weather/festival signals, dashboards, bill-of-materials forecasting)
- Improve the forecasting logic or prompts
- Fix bugs
- Help with translations or documentation
- Or just share feedback…

You're very welcome to join in.

**To contribute:**
1. Fork the repository
2. Create your feature branch: `git checkout -b feature/my-feature`
3. Commit your changes: `git commit -m 'Add new feature'`
4. Push to the branch: `git push origin feature/my-feature`
5. Create a new Pull Request

## Roadmap
- **v1 (current)** — statistical baseline, AI adjustment + rationale, reorder/overstock alerts, colour-coded grid + summary cards, scheduled cron
- **v1.1** — external signals (weather forecasts, regional festival/holiday calendar), dashboard widget, email/admin reorder notifications
- **v2** — bill-of-materials (BOM): explode finished-goods demand into raw-material requirements, with supplier lead-time tracking

## Admin Grid Screenshot

<img width="2878" height="1820" alt="mageprince-mageai-AI-Demand-Forecast-MageAI-Predict-Magento-Admin-07-22-2026_07_50_PM" src="https://github.com/user-attachments/assets/485b2591-64ef-4dc4-9f4e-c87c8f32797b" />

