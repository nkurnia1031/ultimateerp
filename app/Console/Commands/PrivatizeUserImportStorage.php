<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class PrivatizeUserImportStorage extends Command
{
    protected $signature = 'user-imports:privatize-storage';

    protected $description = 'Move existing user import files from public uploads to private storage.';

    private const LEGACY_DIRECTORIES = [
        'user-imports',
        'user-import-staging',
        'expense-imports',
        'solar-imports',
        'bonus-ayak-import-previews',
        'temp/journal_imports',
        'vendor-loading-imports',
        'heavy-equipment-rate-imports',
    ];

    public function handle(): int
    {
        $public = Storage::disk('local');
        $private = Storage::disk('user_imports');
        $paths = collect(self::LEGACY_DIRECTORIES)
            ->flatMap(fn (string $directory) => $public->allFiles($directory))
            ->merge(collect($public->files('temp'))->filter(fn (string $path) => preg_match('/^temp\/\d+_.+\.(?:csv|xls|xlsx)$/i', $path) === 1))
            ->unique()->values();

        try {
            foreach ($paths as $path) {
                $this->copyAndVerify($public, $private, $path);
            }

            foreach ($paths as $path) {
                if ($public->exists($path) && ! $public->delete($path)) {
                    throw new RuntimeException('File publik gagal dihapus setelah diverifikasi: '.$path);
                }
            }
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($paths->count().' file import dipindahkan ke storage privat.');

        return self::SUCCESS;
    }

    private function copyAndVerify($public, $private, string $path): void
    {
        if ($private->exists($path)) {
            if ($this->checksum($public, $path) !== $this->checksum($private, $path)) {
                throw new RuntimeException('File sumber dan privat berbeda; file publik dipertahankan untuk pemeriksaan: '.$path);
            }

            return;
        }

        $source = $public->readStream($path);
        if (! is_resource($source)) {
            throw new RuntimeException('File publik tidak dapat dibaca: '.$path);
        }

        $temporaryPath = $path.'.privatizing-'.Str::uuid();
        try {
            if (! $private->writeStream($temporaryPath, $source)) {
                throw new RuntimeException('File tidak dapat disalin ke storage privat: '.$path);
            }
        } finally {
            fclose($source);
        }

        if ($this->checksum($public, $path) !== $this->checksum($private, $temporaryPath)) {
            $private->delete($temporaryPath);
            throw new RuntimeException('Salinan tidak lolos verifikasi checksum: '.$path);
        }

        if ($private->exists($path) || ! $private->move($temporaryPath, $path)) {
            $private->delete($temporaryPath);
            throw new RuntimeException('File privat tidak dapat dipasang pada tujuan: '.$path);
        }
    }

    private function checksum($disk, string $path): string
    {
        $checksum = hash_file('sha256', $disk->path($path));
        if ($checksum === false) {
            throw new RuntimeException('File tidak dapat diverifikasi: '.$path);
        }

        return $checksum;
    }
}
