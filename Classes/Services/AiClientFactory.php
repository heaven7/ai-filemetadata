<?php

namespace Mfd\Ai\FileMetadata\Services;

use Mfd\Ai\FileMetadata\Api\AiClientInterface;
use Mfd\Ai\FileMetadata\Api\ClaudeClient;
use Mfd\Ai\FileMetadata\Api\GeminiClient;
use Mfd\Ai\FileMetadata\Api\OpenAiClient;
use Mfd\Ai\FileMetadata\Api\OpenRouterClient;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class AiClientFactory
{
    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly LoggerInterface $logger,
        private readonly RequestFactory $requestFactory
    ) {}

    public function getAvailableProviders(): array
    {
        return [
            'openai' => 'OpenAI',
            'gemini' => 'Google Gemini',
            'claude' => 'Anthropic Claude',
            'openrouter' => 'OpenRouter'
        ];
    }

    public function createClient(?string $forcedProvider = null): AiClientInterface
    {
        $config = $this->extensionConfiguration->get('ai_filemetadata');
        $provider = $forcedProvider ?? $config['provider'] ?? 'openai';

        return $this->doCreateClient($provider, $config);
    }

    public function buildAltText(string $image, ?string $locale = null): string
    {
        $config = $this->extensionConfiguration->get('ai_filemetadata');
        $primaryProvider = $config['provider'] ?? 'openai';

        $triedProviders = [];

        foreach (array_keys($this->getAvailableProviders()) as $providerKey) {
            try {
                $client = $this->doCreateClient($providerKey, $config);
                if ($client->isAvailable()) {
                    return $client->buildAltText($image, $locale);
                }
            } catch (\Exception $e) {
                $this->logger->warning("Provider {$providerKey} failed: " . $e->getMessage());
                $triedProviders[] = $providerKey;
            }
        }

        throw new \RuntimeException('All AI providers failed. Tried: ' . implode(', ', $triedProviders));
    }

    private function doCreateClient(string $provider, array $config): AiClientInterface
    {
        switch (strtolower($provider)) {
            case 'gemini':
                $model = $config['gemini_model'] ?? $config['model'] ?? 'gemini-1.5-flash';
                return new GeminiClient(
                    $this->requestFactory,
                    $this->logger,
                    $config['geminiApiKey'] ?? '',
                    $model
                );
            case 'claude':
                $model = $config['claude_model'] ?? $config['model'] ?? 'claude-3-5-sonnet-20240620';
                return new ClaudeClient(
                    $this->requestFactory,
                    $this->logger,
                    $config['claudeApiKey'] ?? '',
                    $model
                );
            case 'openrouter':
                $model = $config['openrouter_model'] ?? $config['model'] ?? 'gpt-4o-mini';
                return new OpenRouterClient(
                    $this->logger,
                    $config['openRouterApiKey'] ?? '',
                    $model,
                    [
                        'HTTP-Referer' => $this->getSiteUrl(),
                        'X-Title' => 'TYPO3 AI File Metadata',
                    ]
                );
            case 'openai':
            default:
                $model = $config['openai_model'] ?? $config['model'] ?? 'gpt-4o-mini';
                return new OpenAiClient(
                    $this->logger,
                    $config['apiKey'] ?? '',
                    $config['organizationId'] ?? '',
                    $config['projectId'] ?? '',
                    $config['apiBaseUri'] ?? '',
                    $model
                );
        }
    }

    private function getSiteUrl(): string
    {
        return \TYPO3\CMS\Core\Utility\GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST');
    }
}
