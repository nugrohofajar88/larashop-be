<?php

namespace App\Console\Commands;

use Ifsnop\Mysqldump\Mysqldump;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class BackupDatabase extends Command
{
    protected $signature = 'db:backup {--disk=gdrive} {--keep=14}';

    protected $description = 'Dump database (pure-PHP, gzip) lalu unggah ke storage off-site (default Google Drive). Tanpa mysqldump eksternal.';

    public function handle(): int
    {
        $db = config('database.connections.'.config('database.default'));
        $filename = 'db/larashop-'.now()->format('Y-m-d_His').'.sql.gz';
        $tmp = storage_path('app/'.basename($filename));

        // 1) Dump pure-PHP (PDO) + gzip — aman di host yang membatasi fungsi shell.
        try {
            $dump = new Mysqldump(
                'mysql:host='.$db['host'].';port='.($db['port'] ?? 3306).';dbname='.$db['database'],
                $db['username'],
                (string) $db['password'],
                ['compress' => Mysqldump::GZIP, 'add-drop-table' => true],
            );
            $dump->start($tmp);
        } catch (\Throwable $e) {
            $this->error('Dump gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        // 2) Unggah ke disk tujuan (off-site).
        $disk = (string) $this->option('disk');
        try {
            $stream = fopen($tmp, 'r');
            Storage::disk($disk)->put($filename, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        } catch (\Throwable $e) {
            @unlink($tmp);
            $this->error('Upload ke disk "'.$disk.'" gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        @unlink($tmp);

        // 3) Retensi: hapus backup lebih lama dari --keep hari.
        $this->prune($disk, (int) $this->option('keep'));

        $this->info('Backup sukses → '.$disk.':'.$filename);

        return self::SUCCESS;
    }

    protected function prune(string $disk, int $keepDays): void
    {
        if ($keepDays <= 0) {
            return;
        }

        $cutoff = now()->subDays($keepDays)->timestamp;

        foreach (Storage::disk($disk)->files('db') as $file) {
            try {
                if (Storage::disk($disk)->lastModified($file) < $cutoff) {
                    Storage::disk($disk)->delete($file);
                    $this->line('  dihapus (lebih dari '.$keepDays.' hari): '.$file);
                }
            } catch (\Throwable $e) {
                // Abaikan file yang gagal dibaca metadatanya.
            }
        }
    }
}
