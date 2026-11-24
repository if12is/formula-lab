<?php

namespace App\Support\DTOs;

/** What a backup file claims about itself: its SHA-256, sizes, source database and the row counts of the key tables at dump time. */
final readonly class BackupManifest
{
    public const VERSION = 1;

    public const CIPHER = 'xchacha20poly1305-secretstream';

    public const DUMP_FORMAT = 'pg_dump-custom';

    /** @param  array<string, int>  $tables  Row count per key table. */
    public function __construct(
        public string $file,
        public string $createdAt,
        public string $database,
        public string $sha256,
        public int $encryptedBytes,
        public int $plainBytes,
        public array $tables,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['file'],
            (string) $data['created_at'],
            (string) $data['database'],
            (string) $data['sha256'],
            (int) $data['encrypted_bytes'],
            (int) $data['plain_bytes'],
            array_map(intval(...), (array) $data['tables']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $tables = $this->tables;
        ksort($tables);

        return [
            'version' => self::VERSION,
            'file' => $this->file,
            'created_at' => $this->createdAt,
            'database' => $this->database,
            'cipher' => self::CIPHER,
            'dump_format' => self::DUMP_FORMAT,
            'sha256' => $this->sha256,
            'encrypted_bytes' => $this->encryptedBytes,
            'plain_bytes' => $this->plainBytes,
            'tables' => $tables,
        ];
    }

    /** The exact text the manifest MAC covers. */
    public function signedText(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
