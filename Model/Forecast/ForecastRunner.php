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

namespace Mageprince\MageAIPredict\Model\Forecast;

use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Mageprince\MageAIPredict\Helper\Config;
use Mageprince\MageAIPredict\Model\Forecast;
use Mageprince\MageAIPredict\Model\Query\ForecastGenerator;

/**
 * Orchestrates a full forecast run: for each stock-bearing product, compute the
 * statistical baseline, optionally refine it with the AI layer, derive reorder /
 * overstock alerts, and persist one current forecast row per product.
 */
class ForecastRunner
{
    private const PRODUCT_TYPES = [Type::TYPE_SIMPLE, Type::TYPE_VIRTUAL, 'downloadable'];
    private const DAYS_IN_MONTH = 30;

    /**
     * @var ProductCollectionFactory
     */
    private $productCollectionFactory;

    /**
     * @var SalesDataProvider
     */
    private $salesDataProvider;

    /**
     * @var BaselineCalculator
     */
    private $baselineCalculator;

    /**
     * @var StockProvider
     */
    private $stockProvider;

    /**
     * @var ForecastGenerator
     */
    private $forecastGenerator;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var DateTime
     */
    private $date;

    /**
     * @param ProductCollectionFactory $productCollectionFactory
     * @param SalesDataProvider $salesDataProvider
     * @param BaselineCalculator $baselineCalculator
     * @param StockProvider $stockProvider
     * @param ForecastGenerator $forecastGenerator
     * @param Config $config
     * @param ResourceConnection $resource
     * @param DateTime $date
     */
    public function __construct(
        ProductCollectionFactory $productCollectionFactory,
        SalesDataProvider $salesDataProvider,
        BaselineCalculator $baselineCalculator,
        StockProvider $stockProvider,
        ForecastGenerator $forecastGenerator,
        Config $config,
        ResourceConnection $resource,
        DateTime $date
    ) {
        $this->productCollectionFactory = $productCollectionFactory;
        $this->salesDataProvider = $salesDataProvider;
        $this->baselineCalculator = $baselineCalculator;
        $this->stockProvider = $stockProvider;
        $this->forecastGenerator = $forecastGenerator;
        $this->config = $config;
        $this->resource = $resource;
        $this->date = $date;
    }

    /**
     * Run the forecast for eligible products.
     *
     * @param int|null $limit overrides the configured max products when provided
     * @return int number of products forecast
     */
    public function run(?int $limit = null): int
    {
        $products = $this->productCollectionFactory->create();
        $products->addAttributeToSelect(['name', 'sku'])
            ->addFieldToFilter('type_id', ['in' => self::PRODUCT_TYPES])
            ->addAttributeToFilter('status', Status::STATUS_ENABLED);

        $max = $limit ?? $this->config->getMaxProducts();
        if ($max > 0) {
            $products->setPageSize($max)->setCurPage(1);
        }

        // Use getItems() (not getAllIds(), which ignores paging) so the limit is honored
        // for both the sales query and the processing loop.
        $items = $products->getItems();
        if (empty($items)) {
            return 0;
        }

        $productIds = [];
        foreach ($items as $product) {
            $productIds[] = (int) $product->getId();
        }

        $series = $this->salesDataProvider->getMonthlySeries($productIds, $this->config->getHistoryYears());
        $targetMonth = new \DateTime('first day of next month');
        $useAi = $this->config->isAiEnabled();

        $rows = [];
        foreach ($items as $product) {
            $productId = (int) $product->getId();
            $productSeries = $series[$productId] ?? [];

            $baseline = $this->baselineCalculator->calculate($productSeries, $targetMonth);
            $stock = $this->stockProvider->getQty($productId);

            $finalForecast = $baseline['baseline'];
            $confidence = $baseline['confidence'];
            $rationale = '';
            $aiAdjusted = 0;

            // Only spend an AI call where there is enough signal to reason about.
            if ($useAi && $baseline['months_of_data'] >= 2) {
                $aiResult = $this->forecastGenerator->adjust(
                    $this->buildAiContext($product, $productSeries, $baseline, $stock, $targetMonth)
                );
                if ($aiResult !== null) {
                    $finalForecast = $aiResult['forecast'];
                    $confidence = $aiResult['confidence'];
                    $rationale = $aiResult['rationale'];
                    $aiAdjusted = 1;
                }
            }

            $alert = $this->computeAlert($finalForecast, $stock);

            $rows[] = [
                'product_id'        => $productId,
                'sku'               => (string) $product->getSku(),
                'product_name'      => (string) $product->getName(),
                'period_start'      => $targetMonth->format('Y-m-01'),
                'baseline_forecast' => $baseline['baseline'],
                'final_forecast'    => $finalForecast,
                'ai_adjusted'       => $aiAdjusted,
                'ai_rationale'      => $rationale,
                'current_stock'     => $stock,
                'reorder_qty'       => $alert['reorder_qty'],
                'reorder_by_date'   => $alert['reorder_by_date'],
                'status'            => $alert['status'],
                'confidence'        => $confidence,
                'seasonality_index' => $baseline['seasonality_index'],
                'trend_factor'      => $baseline['trend_factor'],
                'months_of_data'    => $baseline['months_of_data'],
                'updated_at'        => $this->date->gmtDate(),
            ];

            // Flush periodically to bound memory on large catalogs.
            if (count($rows) >= 200) {
                $this->persist($rows);
                $rows = [];
            }
        }

        if (!empty($rows)) {
            $this->persist($rows);
        }

        return count($productIds);
    }

    /**
     * Build the compact context object sent to the AI layer.
     *
     * @param \Magento\Catalog\Model\Product $product
     * @param array<string,float> $series
     * @param array $baseline
     * @param float $stock
     * @param \DateTimeInterface $targetMonth
     * @return array
     */
    private function buildAiContext($product, array $series, array $baseline, float $stock, \DateTimeInterface $targetMonth): array
    {
        ksort($series);
        // Cap history to the most recent 24 months to keep the token count low.
        if (count($series) > 24) {
            $series = array_slice($series, -24, null, true);
        }

        return [
            'product_name'        => (string) $product->getName(),
            'sku'                 => (string) $product->getSku(),
            'target_month'        => $targetMonth->format('F Y'),
            'monthly_sales'       => $series,
            'baseline_forecast'   => $baseline['baseline'],
            'avg_monthly_demand'  => $baseline['avg_monthly'],
            'seasonality_index'   => $baseline['seasonality_index'],
            'trend_factor'        => $baseline['trend_factor'],
            'months_of_data'      => $baseline['months_of_data'],
            'current_stock'       => $stock,
        ];
    }

    /**
     * Derive reorder quantity, reorder-by date and status from forecast vs. stock.
     *
     * @param int $forecastQty units expected to sell next month
     * @param float $stock current on-hand
     * @return array{reorder_qty:int,reorder_by_date:?string,status:string}
     */
    private function computeAlert(int $forecastQty, float $stock): array
    {
        $leadTime = $this->config->getLeadTimeDays();
        $safetyDays = $this->config->getSafetyStockDays();
        $overstockMultiplier = $this->config->getOverstockMultiplier();

        $dailyDemand = $forecastQty / self::DAYS_IN_MONTH;
        $safetyUnits = $dailyDemand * $safetyDays;
        $reorderPoint = $dailyDemand * ($leadTime + $safetyDays);

        $reorderQty = (int) max(0, ceil($forecastQty + $safetyUnits - $stock));

        $reorderByDate = null;
        $status = Forecast::STATUS_HEALTHY;

        if ($forecastQty <= 0) {
            // No predicted demand but stock on hand => capital tied up in dead stock.
            $status = $stock > 0 ? Forecast::STATUS_OVERSTOCK : Forecast::STATUS_HEALTHY;
        } elseif ($stock <= $reorderPoint) {
            $status = Forecast::STATUS_OUT_OF_STOCK_RISK;
            $daysOfStock = $dailyDemand > 0 ? $stock / $dailyDemand : 0;
            $orderInDays = (int) max(0, floor($daysOfStock - $leadTime));
            $reorderByDate = $this->date->gmtDate('Y-m-d', strtotime('+' . $orderInDays . ' days'));
        } elseif ($stock > $forecastQty * $overstockMultiplier) {
            $status = Forecast::STATUS_OVERSTOCK;
        } elseif ($reorderQty > 0) {
            $status = Forecast::STATUS_REORDER_SOON;
        }

        return [
            'reorder_qty'     => $reorderQty,
            'reorder_by_date' => $reorderByDate,
            'status'          => $status,
        ];
    }

    /**
     * Upsert forecast rows keyed by product_id.
     *
     * @param array $rows
     * @return void
     */
    private function persist(array $rows): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('mageai_predict_forecast');

        $connection->insertOnDuplicate(
            $table,
            $rows,
            [
                'sku',
                'product_name',
                'period_start',
                'baseline_forecast',
                'final_forecast',
                'ai_adjusted',
                'ai_rationale',
                'current_stock',
                'reorder_qty',
                'reorder_by_date',
                'status',
                'confidence',
                'seasonality_index',
                'trend_factor',
                'months_of_data',
                'updated_at',
            ]
        );
    }
}
