<?php

namespace Mfd\Ai\FileMetadata\Api;

use OpenRouter\Client;
use Psr\Log\LoggerInterface;
use Mfd\Ai\FileMetadata\Api\AiClientInterface;

class OpenRouterClient implements AiClientInterface
{
    protected const DEFAULT_MODEL = 'gpt-4o-mini';
    
    private bool $available = false;
    private array $extraHeaders;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
        private readonly string $model,
        array $extraHeaders = []
    ) {
        $this->available = !empty($apiKey);
        $this->extraHeaders = $extraHeaders;
    }

    public function getName(): string
    {
        return 'OpenRouter';
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function buildAltText(string $image, ?string $locale = null): string
    {
        $prompt = <<<'GPT'
Create an alternative text for this image to be used on websites for visually impaired people who cannot see the image.
Focus on the image's main content and ignore all elements in the image not relevant to understand its message.
The text should not exceed 50 words.
GPT;

        if ($locale) {
            $languageEnglishName = \Locale::getDisplayLanguage(\Locale::getPrimaryLanguage($locale), 'en');
            $prompt .= "\n Answer in {$languageEnglishName}.";
        }

        $this->logger->info('OpenRouter Prompt: ' . $prompt);

        $modell = $this->model;
        if ($modell === '') {
            $modell = self::DEFAULT_MODEL;
        }

        try {
            $client = new Client($this->apiKey);

            $headers = array_merge(
                $this->extraHeaders,
                [
                    'HTTP-Referer' => $this->getSiteUrl(),
                    'X-Title' => 'TYPO3 AI File Metadata',
                ]
            );

            // OpenRouter expects "openai/gpt-4o-mini" format often for better routing, but let's stick to what's configured or default.
            // Ensure content structure is correct for multimodal request
            
            $messages = [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $prompt,
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => 'data:image/jpeg;base64,' . base64_encode($image),
                            ],
                        ],
                    ],
                ]
            ];

            // Debug log raw request params (without full image data to avoid huge logs)
            $debugMessages = $messages;
            $debugMessages[0]['content'][1]['image_url']['url'] = 'data:image/jpeg;base64,...(truncated)';
            $this->logger->debug('OpenRouter Request Params', ['model' => $modell, 'headers' => $headers, 'messages' => $debugMessages]);

            // The PHP SDK returns a raw JSON string according to its source, we need to decode it if the SDK doesn't do it automatically or check what it returns exactly.
            // Wait, look at SDK source provided earlier: "public function chat(...) : string { ... return $data['choices'][0]['message']['content'] ?? ''; }"
            // The SDK's chat method already returns the content string directly! It does NOT return the full response object with 'choices'.
            // Source: "return $data['choices'][0]['message']['content'] ?? '';"
            
            $responseContent = $client->chat($messages, $modell, $headers);
            
            $this->logger->debug('OpenRouter Raw Response Content: ' . $responseContent);

            if (!empty($responseContent)) {
                // Clean up potential formatting artifacts
                $cleanContent = preg_replace(
                    '/\\*\*Beschreibung:\\*\\* |\\*\\*Alt-Text:\\*\\* |\\*\\*Alternative Text:\\*\\*/',
                    '',
                    $responseContent
                );

                // Remove word count notes and similar remarks marked with *
                $cleanContent = preg_replace(
                    '/\*\([^)]*\)\*/',
                    '',
                    $cleanContent
                );

                return trim($cleanContent, '"') ?? '';
            }

            // If response is empty string, the SDK return empty string if choices are missing.
            throw new \UnexpectedValueException('OpenRouter returned empty content. Check API logs for details.');

        } catch (\Exception $e) {
            $this->logger->error('OpenRouter API Error: ' . $e->getMessage());
            throw new \RuntimeException('Failed to generate alt text via OpenRouter: ' . $e->getMessage(), 0, $e);
        }
    }

    private function getSiteUrl(): string
    {
        return \TYPO3\CMS\Core\Utility\GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST');
    }
}
