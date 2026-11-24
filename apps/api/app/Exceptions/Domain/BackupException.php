<?php

namespace App\Exceptions\Domain;

/** Thrown when a backup, its verification or a restore cannot go on; every message is translated. */
class BackupException extends BusinessRuleViolationException
{
    public static function keyMissing(): self
    {
        return new self(__('exceptions.backup_key_missing'));
    }

    public static function keyInvalid(): self
    {
        return new self(__('exceptions.backup_key_invalid'));
    }

    public static function decryptionFailed(): self
    {
        return new self(__('exceptions.backup_decryption_failed'));
    }

    public static function manifestMissing(string $file): self
    {
        return new self(__('exceptions.backup_manifest_missing', ['file' => $file]));
    }

    public static function manifestInvalid(string $file): self
    {
        return new self(__('exceptions.backup_manifest_invalid', ['file' => $file]));
    }

    public static function hashMismatch(string $file): self
    {
        return new self(__('exceptions.backup_hash_mismatch', ['file' => $file]));
    }

    public static function fileMissing(string $file): self
    {
        return new self(__('exceptions.backup_file_missing', ['file' => $file]));
    }

    public static function noneFound(string $directory): self
    {
        return new self(__('exceptions.backup_none_found', ['directory' => $directory]));
    }

    public static function liveDatabaseGuard(string $database): self
    {
        return new self(__('exceptions.backup_live_database_guard', ['database' => $database]));
    }

    public static function targetExists(string $database): self
    {
        return new self(__('exceptions.backup_target_exists', ['database' => $database]));
    }

    public static function invalidDatabaseName(string $database): self
    {
        return new self(__('exceptions.backup_invalid_database_name', ['database' => $database]));
    }

    public static function toolFailed(string $tool, string $detail): self
    {
        return new self(__('exceptions.backup_tool_failed', ['tool' => $tool, 'detail' => $detail]));
    }
}
