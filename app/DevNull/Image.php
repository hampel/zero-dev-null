<?php

namespace App\DevNull;

/**
 * An image to attach to a commit, read once: the bytes hashed into the signed payload are the
 * bytes uploaded, so a file changing on disk between the two cannot make them disagree.
 *
 * The checks here only refuse what /dev/null would refuse anyway, before anything is signed or
 * sent. The server has the final word - it also limits each side to 6,000 pixels, which is not
 * checked here.
 */
final readonly class Image
{
    public const int MAX_IMAGES = 3;

    // The official script's limit, in bytes; the server's own is "10mb".
    public const int MAX_BYTES = 10_240_000;

    private function __construct(
        public string $path,
        public string $bytes,
        public string $type,
    ) {}

    public static function fromFile(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new DevNullException("Could not read the image {$path}.");
        }

        if ((int) filesize($path) > self::MAX_BYTES) {
            throw new DevNullException("The image {$path} is larger than 10 MB.");
        }

        $bytes = (string) file_get_contents($path);

        // By content, as the server decides, not by extension.
        $type = match (true) {
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => 'image/png',
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            default => throw new DevNullException("The image {$path} is not a JPEG, PNG or WebP file."),
        };

        return new self($path, $bytes, $type);
    }

    public function sha256(): string
    {
        return hash('sha256', $this->bytes);
    }
}
