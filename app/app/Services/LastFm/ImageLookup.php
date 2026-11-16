<?php

declare(strict_types=1);

namespace App\Services\LastFm;

final class ImageLookup
{
    private function __construct(
        public readonly ImageOutcome $status,
        public readonly ?string $url = null,
    ) {}

    public static function found(string $url): self
    {
        return LastFmPlaceholders::matches($url) ? self::none() : new self(ImageOutcome::Stored, $url);
    }

    public static function none(): self
    {
        return new self(ImageOutcome::Placeholder);
    }

    public static function failed(): self
    {
        return new self(ImageOutcome::Failed);
    }

    public function isFound(): bool
    {
        return $this->status === ImageOutcome::Stored && $this->url !== null;
    }
}
