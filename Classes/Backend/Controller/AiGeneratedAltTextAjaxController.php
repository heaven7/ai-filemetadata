<?php

namespace Mfd\Ai\FileMetadata\Backend\Controller;

use Mfd\Ai\FileMetadata\Api\AiClientInterface;
use Mfd\Ai\FileMetadata\Domain\Model\FileMetadata;
use Mfd\Ai\FileMetadata\Domain\Repository\FileMetadataRepository;
use Mfd\Ai\FileMetadata\Services\AiClientFactory;
use Mfd\Ai\FileMetadata\Services\ConfigurationService;
use Mfd\Ai\FileMetadata\Services\FalAdapter;
use Mfd\Ai\FileMetadata\Sites\SiteLanguageProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Controller\AbstractFormEngineAjaxController;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use Psr\Log\LoggerInterface;

#[AsController]
class AiGeneratedAltTextAjaxController extends AbstractFormEngineAjaxController
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly AiClientFactory $aiClientFactory,
        private readonly FileMetadataRepository $fileMetadataRepository,
        private readonly ConfigurationService $configurationService,
        private readonly SiteLanguageProvider $languageProvider,
        private readonly FalAdapter $falAdapter,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new \Psr\Log\NullLogger();
    }

    public function suggestAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->checkRequest($request);

        $queryParameters = $request->getParsedBody();
        if (empty($queryParameters)) {
            $queryParameters = json_decode((string)$request->getBody(), true) ?? [];
        }
        $queryParameters = $queryParameters ?? [];

        $tableName = (string)($queryParameters['tableName'] ?? '');
        $languageId = (int)($queryParameters['language'] ?? 0);
        $recordId = (int)($queryParameters['recordId'] ?? 0);

        if ($tableName === 'sys_file_metadata') {
            /** @var FileMetadata $metadata */
            $metadata = $this->fileMetadataRepository->findByUid($recordId);

            $file = $metadata->getFile()->getOriginalResource();
            if (!in_array($file->getExtension(), ['png', 'jpg', 'jpeg', 'gif', 'webp'])) {
                return new JsonResponse([
                    'text' => '',
                ]);
            }

            $falLanguages = $this->getLanguageMappingForFile($file);
            $locale = $falLanguages[$languageId] ?? null;

            $altText = $this->aiClientFactory->buildAltText(
                $this->falAdapter->resizeImage($file)->getContents(),
                $locale
            );
            
            return new JsonResponse([
                'text' => $altText,
            ]);
        }

        throw new \InvalidArgumentException(
            "Unexpected record from table \"{$tableName}\"",
            1722538736
        );
    }

    /**
     * @throws \InvalidArgumentException
     */
    protected function checkRequest(ServerRequestInterface $request): bool
    {
        $this->logger->debug('Raw Body:', ['content' => (string)$request->getBody()]);
        $this->logger->debug('Query Params:', $request->getQueryParams());
        $this->logger->debug('Parsed Body:', $request->getParsedBody() ?? []);

        $queryParameters = $request->getParsedBody();
        if (empty($queryParameters)) {
            $queryParameters = json_decode((string)$request->getBody(), true) ?? [];
        }

        $components = [
            $queryParameters['tableName'] ?? '',
            $queryParameters['pageId'] ?? '',
            $queryParameters['recordId'] ?? '',
            $queryParameters['language'] ?? 0,
            $queryParameters['fieldName'] ?? '',
            $queryParameters['command'] ?? '',
            $queryParameters['parentPageId'] ?? 0,
        ];
        $stringToHash = implode('', $components);
        
        $expectedHash = GeneralUtility::hmac($stringToHash, self::class);
        
        $this->logger->debug('CheckRequest Components:', $components);
        $this->logger->debug('CheckRequest String:', ['string' => $stringToHash]);
        $this->logger->debug('Expected Hash: ' . $expectedHash);
        $this->logger->debug('Received Signature: ' . ($queryParameters['signature'] ?? 'NULL'));
        
        if (!hash_equals($expectedHash, $queryParameters['signature'])) {
            throw new \InvalidArgumentException(
                'HMAC could not be verified. Expected: ' . $expectedHash . ', Received: ' . ($queryParameters['signature'] ?? 'NULL') . ', String: ' . $stringToHash,
                1535137045
            );
        }

        return true;
    }

    private function getLanguageMappingForFile(File $file): array
    {
        return $this->configurationService->getLanguageMappingForFile($file) ?? $this->languageProvider->getFalLanguages();
    }
}
