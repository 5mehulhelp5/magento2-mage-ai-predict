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

namespace Mageprince\MageAIPredict\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Mageprince\MageAIPredict\Model\Forecast;

class ForecastStatus implements OptionSourceInterface
{
    /**
     * Status code => label map
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            Forecast::STATUS_OUT_OF_STOCK_RISK => __('Out-of-stock risk')->render(),
            Forecast::STATUS_REORDER_SOON      => __('Reorder soon')->render(),
            Forecast::STATUS_OVERSTOCK         => __('Overstock / dead stock')->render(),
            Forecast::STATUS_HEALTHY           => __('Healthy')->render(),
        ];
    }

    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->toArray() as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }
        return $options;
    }
}
