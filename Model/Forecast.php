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

namespace Mageprince\MageAIPredict\Model;

use Magento\Framework\Model\AbstractModel;
use Mageprince\MageAIPredict\Model\ResourceModel\Forecast as ForecastResource;

class Forecast extends AbstractModel
{
    public const STATUS_HEALTHY = 'healthy';
    public const STATUS_REORDER_SOON = 'reorder_soon';
    public const STATUS_OUT_OF_STOCK_RISK = 'out_of_stock_risk';
    public const STATUS_OVERSTOCK = 'overstock';

    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init(ForecastResource::class);
    }
}
