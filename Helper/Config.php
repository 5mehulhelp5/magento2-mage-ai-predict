<?php
/**
 * Mageprince
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the mageprince.com license that is
 * available through the world-wide-web at this URL:
 * https://mageprince.com/end-user-license-agreement
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Mageprince
 * @package     Mageprince_MageAIPredict
 * @copyright   Copyright (c) Mageprince (https://mageprince.com/)
 * @license     https://mageprince.com/end-user-license-agreement
 */

namespace Mageprince\MageAIPredict\Helper;

use Magento\Framework\App\Helper\AbstractHelper;

class Config extends AbstractHelper
{
    public const XML_PATH_ENABLED = 'mageai_predict/general/enabled';
    public const XML_PATH_USE_AI = 'mageai_predict/general/use_ai';
    public const XML_PATH_HISTORY_YEARS = 'mageai_predict/forecast/history_years';
    public const XML_PATH_LEAD_TIME_DAYS = 'mageai_predict/forecast/lead_time_days';
    public const XML_PATH_SAFETY_STOCK_DAYS = 'mageai_predict/forecast/safety_stock_days';
    public const XML_PATH_OVERSTOCK_MULTIPLIER = 'mageai_predict/forecast/overstock_multiplier';
    public const XML_PATH_MAX_PRODUCTS = 'mageai_predict/forecast/max_products';
    public const XML_PATH_AI_PROMPT = 'mageai_predict/forecast/ai_prompt';
    public const XML_PATH_CRON_ENABLED = 'mageai_predict/cron/enabled';

    /**
     * Whether forecasting is enabled
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    /**
     * Whether the AI adjustment layer is enabled (baseline still runs when off)
     *
     * @return bool
     */
    public function isAiEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_USE_AI);
    }

    /**
     * Years of sales history to analyse
     *
     * @return int
     */
    public function getHistoryYears(): int
    {
        return max(1, (int) $this->scopeConfig->getValue(self::XML_PATH_HISTORY_YEARS));
    }

    /**
     * Supplier / restock lead time in days
     *
     * @return int
     */
    public function getLeadTimeDays(): int
    {
        return max(0, (int) $this->scopeConfig->getValue(self::XML_PATH_LEAD_TIME_DAYS));
    }

    /**
     * Safety-stock buffer expressed in days of demand
     *
     * @return int
     */
    public function getSafetyStockDays(): int
    {
        return max(0, (int) $this->scopeConfig->getValue(self::XML_PATH_SAFETY_STOCK_DAYS));
    }

    /**
     * Stock-to-demand multiplier above which a product is flagged as overstock
     *
     * @return float
     */
    public function getOverstockMultiplier(): float
    {
        $value = (float) $this->scopeConfig->getValue(self::XML_PATH_OVERSTOCK_MULTIPLIER);
        return $value > 0 ? $value : 3.0;
    }

    /**
     * Maximum number of products to process per run (0 = no limit)
     *
     * @return int
     */
    public function getMaxProducts(): int
    {
        return max(0, (int) $this->scopeConfig->getValue(self::XML_PATH_MAX_PRODUCTS));
    }

    /**
     * Extra merchant guidance appended to the AI forecasting instruction
     *
     * @return string
     */
    public function getAiPrompt(): string
    {
        return trim((string) $this->scopeConfig->getValue(self::XML_PATH_AI_PROMPT));
    }

    /**
     * Whether the scheduled forecast cron is enabled
     *
     * @return bool
     */
    public function isCronEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_CRON_ENABLED);
    }
}
