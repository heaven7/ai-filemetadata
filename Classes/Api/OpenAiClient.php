<?php

namespace Mfd\Ai\FileMetadata\Api;

use OpenAI;
use OpenAI\Client as OpenAiApiClient;
use Psr\Log\LoggerInterface;

readonly class OpenAiClient implements AiClientInterface
{
    protected const DEFAULT_MODEL = 'gpt-4-turbo';
    
    private OpenAiApiClient $openAiClient;
    private bool $available;

    public function __construct(
        private readonly LoggerInterface $logger,
        string $apiKey,
        string $organizationId,
        string $projectId,
        string $apiBaseUri,
        private readonly string $model,
        array $extraHeaders = []
    ) {
        if ($apiBaseUri === '') {
            $apiBaseUri = 'https://api.openai.com/v1/';
        }
        $builder = OpenAI::factory()
            ->withBaseUri($apiBaseUri)
            ->withApiKey($apiKey)
            ->withOrganization($organizationId)
            ->withHttpHeader('OpenAI-Project', $projectId);

        foreach ($extraHeaders as $header => $value) {
            $builder->withHttpHeader($header, $value);
        }

        $this->openAiClient = $builder->make();
        $this->available = true;
    }

    public function getName(): string
    {
        return 'OpenAI';
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

        $this->logger->info('Prompt: ' . $prompt);

        $modell = $this->model;
        if ($modell === '') {
            $modell = 'gpt-4o-mini';
        }

        $response = $this->openAiClient->chat()->create([
            'model' => $modell,
            'messages' => [
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
                ],
            ],
        ]);
        
        try {
            if (property_exists($response, 'usage') && $response->usage !== null) {
                $usageData = [
                    'prompt_tokens' => $response->usage->promptTokens ?? 0,
                    'completion_tokens' => $response->usage->completionTokens ?? 0,
                    'total_tokens' => $response->usage->totalTokens ?? 0
                ];
                
                try {
                    // Safely handle tokens details which might not always be available
                    if (property_exists($response->usage, 'promptTokensDetails') && $response->usage->promptTokensDetails !== null) {
                        $usageData['prompt_details'] = [
                            'cached_tokens' => $response->usage->promptTokensDetails->cachedTokens ?? 0,
                            'audio_tokens' => $response->usage->promptTokensDetails->audioTokens ?? 0,
                            'video_tokens' => $response->usage->promptTokensDetails->videoTokens ?? 0
                        ];
                    }
                    
                    if (property_exists($response->usage, 'completionTokensDetails') && $response->usage->completionTokensDetails !== null) {
                        $usageData['completion_details'] = [
                            'reasoning_tokens' => $response->usage->completionTokensDetails->reasoningTokens ?? 0,
                            'image_tokens' => $response->usage->completionTokensDetails->imageTokens ?? 0
                        ];
                    }
                } catch (\Throwable $e) {
                    $this->logger->debug('Error processing token details: ' . $e->getMessage());
                }
                
                $this->logger->debug('OpenAI Usage', $usageData);
            }
        } catch (\Exception $e) {
            $this->logger->debug('Could not log OpenAI usage data: ' . $e->getMessage());
        }

        if (!empty($response->choices) && ($choice = $response->choices[0])) {
            $this->logger->debug(print_r($choice, true));
            $message_content = str_replace(['**Beschreibung:** ', '**Alt-Text:** ', '**Alternative Text:** ' ], '', $choice->message->content);

            // Remove word count notes and similar remarks marked with *
            $cleanContent = preg_replace(
                '/\*\([^)]*\)\*/',
                '',
                $message_content
            );

            return trim($cleanContent,'"') ?? '';
        }


        throw new \UnexpectedValueException('Did not find any choices in the response');
    }
}
