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

use Magento\CatalogInventory\Api\StockRegistryInterface;

/**
 * Resolves the current stock quantity for a product.
 *
 * Uses the legacy StockRegistry, which remains a valid facade over MSI default
 * source stock in Magento 2.4.x and is sufficient for single-source stores.
 */
class StockProvider
{
    /**
     * @var StockRegistryInterface
     */
    private $stockRegistry;

    /**
     * @param StockRegistryInterface $stockRegistry
     */
    public function __construct(StockRegistryInterface $stockRegistry)
    {
        $this->stockRegistry = $stockRegistry;
    }

    /**
     * Current stock quantity for a product id.
     *
     * @param int $productId
     * @return float
     */
    public function getQty(int $productId): float
    {
        try {
            return (float) $this->stockRegistry->getStockItem($productId)->getQty();
        } catch (\Exception $e) {
            return 0.0;
        }
    }
}
