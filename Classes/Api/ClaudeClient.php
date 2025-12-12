<?php

namespace Mfd\Ai\FileMetadata\Api;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

class ClaudeClient implements AiClientInterface
{
    public function __construct(
        private RequestFactory $requestFactory,
        private LoggerInterface $logger,
        private string $apiKey,
        private string $model = 'claude-3-5-sonnet-20240620'
    ) {
        $this->available = !empty($apiKey);
    }

    private bool $available = false;

    public function getName(): string
    {
        return 'Anthropic Claude';
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

        $this->logger->info('Claude Prompt: ' . $prompt);

        $url = "https://api.anthropic.com/v1/messages";

        $payload = [
            'model' => $this->model,
            'max_tokens' => 1024,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'image',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => 'image/jpeg', // Assuming JPEG
                                'data' => base64_encode($image)
                            ]
                        ],
                        [
                            'type' => 'text',
                            'text' => $prompt
                        ]
                    ]
                ]
            ]
        ];

        try {
            $response = $this->requestFactory->request($url, 'POST', [
                'json' => $payload,
                'headers' => [
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json'
                ]
            ]);

            $content = $response->getBody()->getContents();
            $json = json_decode($content, true);

            if (isset($json['content'][0]['text'])) {
                $text = $json['content'][0]['text'];
                $this->logger->debug('Claude Response: ' . $text);

                // Remove word count notes and similar remarks marked with *
                $cleanContent = preg_replace(
                    '/\*\([^)]*\)\*/',
                    '',
                    $text
                );

                return trim($cleanContent);
            }

            $this->logger->error('Claude Error Response: ' . $content);
            throw new \UnexpectedValueException('Invalid response from Claude API');

        } catch (\Exception $e) {
            $this->logger->error('Claude Request Failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
