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

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Reads historical monthly sales quantities per product from the sales tables.
 */
class SalesDataProvider
{
    /**
     * Order item product types that carry stock-bearing quantities we forecast.
     */
    private const COUNTED_TYPES = ['simple', 'virtual', 'downloadable'];

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var DateTime
     */
    private $date;

    /**
     * @param ResourceConnection $resource
     * @param DateTime $date
     */
    public function __construct(ResourceConnection $resource, DateTime $date)
    {
        $this->resource = $resource;
        $this->date = $date;
    }

    /**
     * Build a [productId => ['YYYY-MM' => qty, ...]] map of monthly ordered quantities.
     *
     * @param int[] $productIds
     * @param int $years
     * @return array<int, array<string, float>>
     */
    public function getMonthlySeries(array $productIds, int $years): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if (empty($productIds)) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $itemTable = $this->resource->getTableName('sales_order_item');
        $orderTable = $this->resource->getTableName('sales_order');

        $from = $this->date->gmtDate('Y-m-d 00:00:00', strtotime('-' . $years . ' years'));

        $series = [];
        // Chunk to keep the IN() list and result set manageable on large catalogs.
        foreach (array_chunk($productIds, 500) as $chunk) {
            $select = $connection->select()
                ->from(
                    ['oi' => $itemTable],
                    [
                        'product_id' => 'oi.product_id',
                        'ym'         => new \Zend_Db_Expr("DATE_FORMAT(o.created_at, '%Y-%m')"),
                        'qty'        => new \Zend_Db_Expr('SUM(oi.qty_ordered)'),
                    ]
                )
                ->join(['o' => $orderTable], 'o.entity_id = oi.order_id', [])
                ->where('oi.product_id IN (?)', $chunk)
                ->where('oi.product_type IN (?)', self::COUNTED_TYPES)
                ->where('o.created_at >= ?', $from)
                ->where('o.state NOT IN (?)', ['canceled', 'closed'])
                ->group(['oi.product_id', 'ym']);

            foreach ($connection->fetchAll($select) as $row) {
                $productId = (int) $row['product_id'];
                $series[$productId][$row['ym']] = (float) $row['qty'];
            }
        }

        return $series;
    }
}
