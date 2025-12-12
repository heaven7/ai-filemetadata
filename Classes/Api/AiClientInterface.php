<?php

namespace Mfd\Ai\FileMetadata\Api;

interface AiClientInterface
{
    public function buildAltText(string $image, ?string $locale = null): string;
    
    public function getName(): string;
    
    public function isAvailable(): bool;
}
