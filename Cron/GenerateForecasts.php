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

namespace Mageprince\MageAIPredict\Cron;

use Mageprince\MageAIPredict\Helper\Config;
use Mageprince\MageAIPredict\Model\Forecast\ForecastRunner;
use Psr\Log\LoggerInterface;

/**
 * Scheduled forecast regeneration so the grid stays current without on-click AI cost.
 */
class GenerateForecasts
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var ForecastRunner
     */
    private $runner;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Config $config
     * @param ForecastRunner $runner
     * @param LoggerInterface $logger
     */
    public function __construct(Config $config, ForecastRunner $runner, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->runner = $runner;
        $this->logger = $logger;
    }

    /**
     * Run the forecast if both the module and its cron are enabled.
     *
     * @return void
     */
    public function execute(): void
    {
        if (!$this->config->isEnabled() || !$this->config->isCronEnabled()) {
            return;
        }

        try {
            $count = $this->runner->run();
            $this->logger->info(sprintf('MageAIPredict: generated forecasts for %d products.', $count));
        } catch (\Throwable $e) {
            $this->logger->error('MageAIPredict cron failed: ' . $e->getMessage());
        }
    }
}
