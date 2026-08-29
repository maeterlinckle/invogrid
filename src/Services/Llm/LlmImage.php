<?php

declare(strict_types=1);

namespace App\Services\Llm;

use RuntimeException;

/**
 * One page image on its way to a vision model.
 *
 * Reads and base64-encodes lazily, because a caller assembling a list of
 * fifteen pages should not hold fifteen encoded copies in memory before it has
 * decided whether the request is even going to be made.
 */
final class LlmImage
{
    public function __construct(
        public readonly string $path,
        public readonly int $pageNumber,
    ) {
    }

    public function mediaType(): string
    {
        $size = @getimagesize($this->path);

        if ($size === false) {
            throw new RuntimeException('Not an image: ' . basename($this->path));
        }

        return (string) $size['mime'];
    }

    public function base64(): string
    {
        $bytes = @file_get_contents($this->path);

        if ($bytes === false) {
            throw new RuntimeException('Could not read ' . basename($this->path));
        }

        return base64_encode($bytes);
    }

    public function bytes(): int
    {
        return is_file($this->path) ? (int) filesize($this->path) : 0;
    }

    /**
     * What this page costs to send.
     *
     * Base64 is four bytes out for every three in, which is the number that
     * matters against a provider's request-size limit — not the size on disk.
     */
    public function encodedBytes(): int
    {
        return (int) ceil($this->bytes() / 3) * 4;
    }
}
