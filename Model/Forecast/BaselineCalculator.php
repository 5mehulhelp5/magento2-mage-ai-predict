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

/**
 * Deterministic statistical baseline: demand rate x seasonality x recent trend.
 *
 * This is intentionally NOT an LLM. Raw numeric forecasting is done here with
 * plain math; the AI layer only reasons on top of these numbers.
 */
class BaselineCalculator
{
    private const SEASONALITY_MIN = 0.4;
    private const SEASONALITY_MAX = 3.0;
    private const TREND_MIN = 0.5;
    private const TREND_MAX = 2.0;

    /**
     * Compute the baseline forecast for the target month from a monthly series.
     *
     * @param array<string, float> $series ['YYYY-MM' => qty, ...]
     * @param \DateTimeInterface $targetMonth first day of the month being forecast
     * @return array{
     *     baseline:int,
     *     seasonality_index:float,
     *     trend_factor:float,
     *     months_of_data:int,
     *     confidence:string,
     *     avg_monthly:float
     * }
     */
    public function calculate(array $series, \DateTimeInterface $targetMonth): array
    {
        $monthsOfData = count($series);

        if ($monthsOfData === 0) {
            return [
                'baseline'          => 0,
                'seasonality_index' => 1.0,
                'trend_factor'      => 1.0,
                'months_of_data'    => 0,
                'confidence'        => 'low',
                'avg_monthly'       => 0.0,
            ];
        }

        ksort($series);
        $values = array_values($series);
        $total = array_sum($values);

        // Demand rate across the full span (gaps count as zero-demand months).
        $spanMonths = $this->spanMonths(array_key_first($series), $targetMonth);
        $baseRate = $total / max(1, $spanMonths);

        // Average across months that actually had sales (used to normalise seasonality).
        $observedAvg = $total / $monthsOfData;

        $seasonalityIndex = $this->seasonalityIndex($series, (int) $targetMonth->format('n'), $observedAvg);
        $trendFactor = $this->trendFactor($values);

        $baseline = (int) round($baseRate * $seasonalityIndex * $trendFactor);
        $baseline = max(0, $baseline);

        return [
            'baseline'          => $baseline,
            'seasonality_index' => round($seasonalityIndex, 3),
            'trend_factor'      => round($trendFactor, 3),
            'months_of_data'    => $monthsOfData,
            'confidence'        => $this->confidence($monthsOfData),
            'avg_monthly'       => round($baseRate, 2),
        ];
    }

    /**
     * Number of calendar months from the first observed month up to (not incl.) the target month.
     *
     * @param string $firstKey 'YYYY-MM'
     * @param \DateTimeInterface $targetMonth
     * @return int
     */
    private function spanMonths(string $firstKey, \DateTimeInterface $targetMonth): int
    {
        [$year, $month] = array_map('intval', explode('-', $firstKey));
        $first = (new \DateTime())->setDate($year, $month, 1)->setTime(0, 0);
        $target = (new \DateTime())->setDate(
            (int) $targetMonth->format('Y'),
            (int) $targetMonth->format('n'),
            1
        )->setTime(0, 0);

        $diff = $first->diff($target);
        return max(1, ($diff->y * 12) + $diff->m);
    }

    /**
     * Seasonality index for the target calendar month vs. the observed average.
     *
     * @param array<string, float> $series
     * @param int $targetCalMonth 1-12
     * @param float $observedAvg
     * @return float
     */
    private function seasonalityIndex(array $series, int $targetCalMonth, float $observedAvg): float
    {
        if ($observedAvg <= 0) {
            return 1.0;
        }

        $monthValues = [];
        foreach ($series as $ym => $qty) {
            $calMonth = (int) substr($ym, 5, 2);
            if ($calMonth === $targetCalMonth) {
                $monthValues[] = $qty;
            }
        }

        if (empty($monthValues)) {
            return 1.0;
        }

        $seasonalAvg = array_sum($monthValues) / count($monthValues);
        $index = $seasonalAvg / $observedAvg;

        return max(self::SEASONALITY_MIN, min(self::SEASONALITY_MAX, $index));
    }

    /**
     * Recent trend: last 3 observed months vs. the 3 before them.
     *
     * @param float[] $values chronological
     * @return float
     */
    private function trendFactor(array $values): float
    {
        if (count($values) < 6) {
            return 1.0;
        }

        $last3 = array_slice($values, -3);
        $prev3 = array_slice($values, -6, 3);

        $lastAvg = array_sum($last3) / 3;
        $prevAvg = array_sum($prev3) / 3;

        if ($prevAvg <= 0) {
            return 1.0;
        }

        $trend = $lastAvg / $prevAvg;
        return max(self::TREND_MIN, min(self::TREND_MAX, $trend));
    }

    /**
     * Confidence bucket driven by how much history we have.
     *
     * @param int $monthsOfData
     * @return string
     */
    private function confidence(int $monthsOfData): string
    {
        if ($monthsOfData >= 24) {
            return 'high';
        }
        if ($monthsOfData >= 12) {
            return 'medium';
        }
        return 'low';
    }
}
