<?php

namespace Mfd\Ai\FileMetadata\Api;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

class GeminiClient implements AiClientInterface
{
    public function __construct(
        private RequestFactory $requestFactory,
        private LoggerInterface $logger,
        private string $apiKey,
        private string $model = 'gemini-1.5-flash'
    ) {
        $this->available = !empty($apiKey);
    }

    private bool $available = false;

    public function getName(): string
    {
        return 'Google Gemini';
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function buildAltText(string $image, ?string $locale = null): string
    {
        $prompt = <<<'PROMPT'
Create an alternative text for this image to be used on websites for visually impaired people who cannot see the image.
Focus on the image's main content and ignore all elements in the image not relevant to understand its message.
The text should not exceed 50 words.
PROMPT;

        if ($locale) {
            $languageEnglishName = \Locale::getDisplayLanguage(\Locale::getPrimaryLanguage($locale), 'en');
            $prompt .= "\n Answer in {$languageEnglishName}.";
        }

        $this->logger->info('Gemini Prompt: ' . $prompt);

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                        [
                            'inline_data' => [
                                'mime_type' => 'image/jpeg', // Assuming JPEG as per OpenAiClient
                                'data' => base64_encode($image)
                            ]
                        ]
                    ]
                ]
            ]
        ];

        try {
            $response = $this->requestFactory->request($url, 'POST', [
                'json' => $payload,
                'headers' => ['Content-Type' => 'application/json']
            ]);

            $content = $response->getBody()->getContents();
            $json = json_decode($content, true);

            if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
                $text = $json['candidates'][0]['content']['parts'][0]['text'];
                $this->logger->debug('Gemini Response: ' . $text);

                // Remove word count notes and similar remarks marked with *
                $cleanContent = preg_replace(
                    '/\*\([^)]*\)\*/',
                    '',
                    $text
                );

                return trim($cleanContent);
            }

            $this->logger->error('Gemini Error Response: ' . $content);
            throw new \UnexpectedValueException('Invalid response from Gemini API');

        } catch (\Exception $e) {
            $this->logger->error('Gemini Request Failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
