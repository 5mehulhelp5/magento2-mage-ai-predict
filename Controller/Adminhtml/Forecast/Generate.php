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

namespace Mageprince\MageAIPredict\Controller\Adminhtml\Forecast;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Mageprince\MageAIPredict\Helper\Config;
use Mageprince\MageAIPredict\Model\Forecast\ForecastRunner;

class Generate extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Mageprince_MageAIPredict::forecast';

    /**
     * @var ForecastRunner
     */
    private $runner;

    /**
     * @var Config
     */
    private $config;

    /**
     * @param Action\Context $context
     * @param ForecastRunner $runner
     * @param Config $config
     */
    public function __construct(Action\Context $context, ForecastRunner $runner, Config $config)
    {
        $this->runner = $runner;
        $this->config = $config;
        parent::__construct($context);
    }

    /**
     * Run a forecast now and return to the grid.
     *
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();

        if (!$this->config->isEnabled()) {
            $this->messageManager->addErrorMessage(
                __('MageAI Predict is disabled. Enable it under Stores > Configuration.')
            );
            return $resultRedirect->setPath('*/*/index');
        }

        try {
            $count = $this->runner->run();
            $this->messageManager->addSuccessMessage(
                __('Demand forecast generated for %1 product(s).', $count)
            );
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(
                __('Forecast generation failed: %1', $e->getMessage())
            );
        }

        return $resultRedirect->setPath('*/*/index');
    }

    /**
     * @inheritDoc
     */
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed(self::ADMIN_RESOURCE);
    }
}
