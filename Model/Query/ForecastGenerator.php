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
// phpcs:disable Generic.Files.LineLength

namespace Mageprince\MageAIPredict\Model\Query;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Mageprince\MageAI\Helper\Data as MageAiConfig;
use Mageprince\MageAIPredict\Helper\Config as PredictConfig;
use Psr\Log\LoggerInterface;

/**
 * Sends the statistical baseline + context to the configured LLM and asks it to
 * return an adjusted demand forecast as JSON. The LLM is used only for reasoning
 * over the numbers (trend/seasonality/context), never for raw calculation.
 *
 * Reuses Mageprince_MageAI provider credentials (API keys, base URLs, models) so
 * merchants configure their AI provider in one place.
 */
class ForecastGenerator
{
    private const ANTHROPIC_VERSION = '2023-06-01';
    private const MAX_TOKENS = 600;
    private const TEMPERATURE = 0.2;
    private const SYSTEM_PROMPT = 'You are an expert retail demand-planning analyst. You are given a product, its historical monthly sales, a statistical baseline forecast, seasonality and trend factors, and current stock. Produce a single demand forecast in units for the upcoming month. Respond with ONLY a compact JSON object of the exact form {"forecast": <non-negative integer>, "confidence": "low|medium|high", "rationale": "<one or two short sentences>"}. Do not wrap the JSON in markdown or add any text outside it.';

    /**
     * @var Curl
     */
    private $curl;

    /**
     * @var Json
     */
    private $json;

    /**
     * @var MageAiConfig
     */
    private $aiConfig;

    /**
     * @var PredictConfig
     */
    private $predictConfig;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Curl $curl
     * @param Json $json
     * @param MageAiConfig $aiConfig
     * @param PredictConfig $predictConfig
     * @param LoggerInterface $logger
     */
    public function __construct(
        Curl $curl,
        Json $json,
        MageAiConfig $aiConfig,
        PredictConfig $predictConfig,
        LoggerInterface $logger
    ) {
        $this->curl = $curl;
        $this->json = $json;
        $this->aiConfig = $aiConfig;
        $this->predictConfig = $predictConfig;
        $this->logger = $logger;
    }

    /**
     * Return an AI-adjusted forecast or null on any failure (caller falls back to baseline).
     *
     * @param array $context
     * @return array{forecast:int,confidence:string,rationale:string}|null
     */
    public function adjust(array $context): ?array
    {
        try {
            $prompt = $this->buildUserPrompt($context);

            switch ($this->aiConfig->getProvider()) {
                case 'anthropic':
                    $raw = $this->requestAnthropic($prompt);
                    break;
                case 'gemini':
                    $raw = $this->requestGemini($prompt);
                    break;
                default:
                    $raw = $this->requestOpenAI($prompt);
            }

            return $this->parse($raw);
        } catch (\Throwable $e) {
            $this->logger->warning('MageAIPredict AI forecast failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Build the user prompt: JSON context + the merchant's extra guidance.
     *
     * @param array $context
     * @return string
     */
    private function buildUserPrompt(array $context): string
    {
        $guidance = $this->predictConfig->getAiPrompt();
        $prompt = 'Product and sales data:' . "\n" . $this->json->serialize($context);
        if ($guidance !== '') {
            $prompt .= "\n\nAdditional merchant guidance:\n" . $guidance;
        }
        return $prompt;
    }

    /**
     * Parse and validate the model's JSON response.
     *
     * @param string $raw
     * @return array{forecast:int,confidence:string,rationale:string}|null
     */
    private function parse(string $raw): ?array
    {
        $raw = $this->stripCodeFences($raw);

        // Be tolerant of stray text around the JSON object.
        if (preg_match('/\{.*\}/s', $raw, $m)) {
            $raw = $m[0];
        }

        try {
            $data = $this->json->unserialize($raw);
        } catch (\Exception $e) {
            $this->logger->warning('MageAIPredict AI forecast: could not decode JSON: ' . $raw);
            return null;
        }

        if (!is_array($data) || !isset($data['forecast']) || !is_numeric($data['forecast'])) {
            return null;
        }

        $confidence = strtolower((string) ($data['confidence'] ?? 'medium'));
        if (!in_array($confidence, ['low', 'medium', 'high'], true)) {
            $confidence = 'medium';
        }

        return [
            'forecast'   => max(0, (int) round((float) $data['forecast'])),
            'confidence' => $confidence,
            'rationale'  => trim((string) ($data['rationale'] ?? '')),
        ];
    }

    // -------------------------------------------------------------------------
    // Providers
    // -------------------------------------------------------------------------

    /**
     * @param string $prompt
     * @return string
     */
    private function requestOpenAI(string $prompt): string
    {
        $token = $this->aiConfig->getApiSecret();
        if (!$token) {
            throw new \RuntimeException('OpenAI API key not configured.');
        }

        $model = $this->aiConfig->getModel();
        $payload = [
            'model'       => $model,
            'temperature' => self::TEMPERATURE,
            'max_tokens'  => self::MAX_TOKENS,
            'messages'    => [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user',   'content' => $prompt],
            ],
        ];
        if (strpos($model, 'gpt') !== false) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $this->curl->setHeaders([
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ]);
        $this->curl->post($this->aiConfig->getApiBaseUrl() . '/v1/chat/completions', $this->json->serialize($payload));

        $response = $this->json->unserialize($this->curl->getBody());
        if (isset($response['error'])) {
            throw new \RuntimeException((string) ($response['error']['message'] ?? 'OpenAI error.'));
        }
        return (string) ($response['choices'][0]['message']['content'] ?? '');
    }

    /**
     * @param string $prompt
     * @return string
     */
    private function requestAnthropic(string $prompt): string
    {
        $token = $this->aiConfig->getAnthropicApiSecret();
        if (!$token) {
            throw new \RuntimeException('Anthropic API key not configured.');
        }

        $payload = [
            'model'       => $this->aiConfig->getAnthropicModel(),
            'max_tokens'  => self::MAX_TOKENS,
            'temperature' => self::TEMPERATURE,
            'system'      => self::SYSTEM_PROMPT,
            'messages'    => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        $this->curl->setHeaders([
            'Content-Type'      => 'application/json',
            'x-api-key'         => $token,
            'anthropic-version' => self::ANTHROPIC_VERSION,
        ]);
        $this->curl->post($this->aiConfig->getAnthropicBaseUrl() . '/v1/messages', $this->json->serialize($payload));

        $response = $this->json->unserialize($this->curl->getBody());
        if (isset($response['error'])) {
            throw new \RuntimeException((string) ($response['error']['message'] ?? 'Anthropic error.'));
        }
        return (string) ($response['content'][0]['text'] ?? '');
    }

    /**
     * @param string $prompt
     * @return string
     */
    private function requestGemini(string $prompt): string
    {
        $token = $this->aiConfig->getGeminiApiSecret();
        if (!$token) {
            throw new \RuntimeException('Gemini API key not configured.');
        }

        $payload = [
            'system_instruction' => [
                'parts' => [['text' => self::SYSTEM_PROMPT]],
            ],
            'contents' => [
                ['parts' => [['text' => $prompt]]],
            ],
            'generationConfig' => [
                'temperature'      => self::TEMPERATURE,
                'maxOutputTokens'  => self::MAX_TOKENS,
                'responseMimeType' => 'application/json',
            ],
        ];

        $this->curl->setHeaders([
            'Content-Type'   => 'application/json',
            'x-goog-api-key' => $token,
        ]);
        $model = $this->aiConfig->getGeminiModel();
        $url = $this->aiConfig->getGeminiBaseUrl() . '/v1beta/models/' . $model . ':generateContent';
        $this->curl->post($url, $this->json->serialize($payload));

        $response = $this->json->unserialize($this->curl->getBody());
        if (isset($response['error'])) {
            throw new \RuntimeException((string) ($response['error']['message'] ?? 'Gemini error.'));
        }
        return (string) ($response['candidates'][0]['content']['parts'][0]['text'] ?? '');
    }

    /**
     * Strip markdown code fences some models add despite instructions.
     *
     * @param string $content
     * @return string
     */
    private function stripCodeFences(string $content): string
    {
        $content = trim($content);
        $content = preg_replace('/^```[a-z]*\r?\n?/i', '', $content);
        $content = preg_replace('/\r?\n?```\s*$/i', '', $content);
        return trim($content);
    }
}
