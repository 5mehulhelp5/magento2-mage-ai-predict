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

namespace Mageprince\MageAIPredict\Block\Adminhtml\Forecast;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ResourceConnection;
use Mageprince\MageAIPredict\Model\Forecast;

/**
 * Summary cards shown above the forecast grid.
 */
class Summary extends Template
{
    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var array|null
     */
    private $stats;

    /**
     * @param Context $context
     * @param ResourceConnection $resource
     * @param array $data
     */
    public function __construct(Context $context, ResourceConnection $resource, array $data = [])
    {
        $this->resource = $resource;
        parent::__construct($context, $data);
    }

    /**
     * Aggregate counts and reorder units by status (lazy, cached per request).
     *
     * @return array
     */
    private function getStats(): array
    {
        if ($this->stats !== null) {
            return $this->stats;
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('mageai_predict_forecast');

        $this->stats = [
            'counts'         => [],
            'total'          => 0,
            'reorder_units'  => 0,
        ];

        if (!$connection->isTableExists($table)) {
            return $this->stats;
        }

        $select = $connection->select()
            ->from($table, [
                'status'        => 'status',
                'cnt'           => new \Zend_Db_Expr('COUNT(*)'),
                'reorder_units' => new \Zend_Db_Expr('SUM(reorder_qty)'),
            ])
            ->group('status');

        foreach ($connection->fetchAll($select) as $row) {
            $this->stats['counts'][$row['status']] = (int) $row['cnt'];
            $this->stats['total'] += (int) $row['cnt'];
            $this->stats['reorder_units'] += (int) $row['reorder_units'];
        }

        return $this->stats;
    }

    /**
     * Count of products in the given status.
     *
     * @param string $status
     * @return int
     */
    public function getStatusCount(string $status): int
    {
        return $this->getStats()['counts'][$status] ?? 0;
    }

    /**
     * Total number of forecast rows.
     *
     * @return int
     */
    public function getTotalProducts(): int
    {
        return $this->getStats()['total'];
    }

    /**
     * Total units recommended to reorder across all products.
     *
     * @return int
     */
    public function getReorderUnits(): int
    {
        return $this->getStats()['reorder_units'];
    }

    /**
     * Cards definition consumed by the template.
     *
     * @return array
     */
    public function getCards(): array
    {
        return [
            [
                'value' => $this->getStatusCount(Forecast::STATUS_OUT_OF_STOCK_RISK),
                'label' => __('Out-of-stock risk'),
                'hint'  => __('Reorder before you lose sales'),
                'class' => 'mp-card-critical',
            ],
            [
                'value' => $this->getStatusCount(Forecast::STATUS_REORDER_SOON),
                'label' => __('Reorder soon'),
                'hint'  => __('Approaching the reorder point'),
                'class' => 'mp-card-warning',
            ],
            [
                'value' => $this->getStatusCount(Forecast::STATUS_OVERSTOCK),
                'label' => __('Overstock / dead stock'),
                'hint'  => __('Capital tied up in inventory'),
                'class' => 'mp-card-info',
            ],
            [
                'value' => $this->getStatusCount(Forecast::STATUS_HEALTHY),
                'label' => __('Healthy'),
                'hint'  => __('No action needed'),
                'class' => 'mp-card-success',
            ],
            [
                'value' => $this->getReorderUnits(),
                'label' => __('Units to reorder'),
                'hint'  => __('Across all products'),
                'class' => 'mp-card-neutral',
            ],
        ];
    }
}
