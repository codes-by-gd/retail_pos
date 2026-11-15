<?php

namespace App\Modules\Settings\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Gzipped database backups in storage/app/backups (outside the web root).
 * MySQL: mysqldump --single-transaction (no locks while the shop is billing); the password is passed
 * through the environment, never on the command line. SQLite: the database file. Restore from the CLI.
 */
class BackupService
{
    public const PATTERN = '/^pos-\d{8}-\d{6}\.(sql|sqlite)\.gz$/';

    public function directory(): string
    {
        $dir = config('backups.path') ?? storage_path('app/backups');   // overridable (tests)
        File::ensureDirectoryExists($dir, 0750);

        return $dir;
    }

    /** @return list<array{name: string, size: int, created_at: string}> newest first */
    public function list(): array
    {
        return collect(File::files($this->directory()))
            ->filter(fn ($f) => preg_match(self::PATTERN, $f->getFilename()))
            ->map(fn ($f) => ['name' => $f->getFilename(), 'size' => $f->getSize(), 'created_at' => Carbon::createFromTimestamp($f->getMTime())->toIso8601String()])
            ->sortByDesc('name')->values()->all();
    }

    /** Full path of an existing backup, or null (rejects anything that isn't one of ours — no path traversal). */
    public function path(string $name): ?string
    {
        if (! preg_match(self::PATTERN, $name)) {
            return null;
        }
        $path = $this->directory().DIRECTORY_SEPARATOR.$name;

        return is_file($path) ? $path : null;
    }

    /** @return string file name of the new backup */
    public function create(): string
    {
        $config = DB::connection()->getConfig();
        $driver = DB::connection()->getDriverName();
        $stamp = now()->format('Ymd-His');

        return match ($driver) {
            'mysql', 'mariadb' => $this->mysql($config, "pos-{$stamp}.sql.gz"),
            'sqlite' => $this->sqlite($config, "pos-{$stamp}.sqlite.gz"),
            default => throw new \DomainException("Backups are not supported for the {$driver} database."),
        };
    }

    public function delete(string $name): bool
    {
        $path = $this->path($name);

        return $path ? File::delete($path) : false;
    }

    /** Deletes backups older than $days days. @return int deleted */
    public function prune(int $days): int
    {
        $cutoff = now()->subDays(max(1, $days))->getTimestamp();
        $deleted = 0;
        foreach (File::files($this->directory()) as $f) {
            if (preg_match(self::PATTERN, $f->getFilename()) && $f->getMTime() < $cutoff) {
                $deleted += (int) File::delete($f->getPathname());
            }
        }

        return $deleted;
    }

    private function mysql(array $c, string $name): string
    {
        $target = $this->directory().DIRECTORY_SEPARATOR.$name;
        $process = new Process(array_values(array_filter([
            'mysqldump', '--single-transaction', '--quick', '--routines', '--no-tablespaces', '--default-character-set=utf8mb4',
            '--host='.($c['host'] ?? '127.0.0.1'), '--port='.($c['port'] ?? 3306), '--user='.($c['username'] ?? 'root'),
            $c['database'],
        ])), null, ['MYSQL_PWD' => (string) ($c['password'] ?? '')], null, 3600);

        $gz = gzopen($target.'.part', 'wb6');
        $process->run(function ($type, $buffer) use ($gz) {
            if ($type === Process::OUT) {
                gzwrite($gz, $buffer);
            }
        });
        gzclose($gz);

        if (! $process->isSuccessful()) {
            File::delete($target.'.part');
            throw new \DomainException('mysqldump failed: '.trim($process->getErrorOutput() ?: 'exit code '.$process->getExitCode()));
        }
        rename($target.'.part', $target);

        return $name;
    }

    private function sqlite(array $c, string $name): string
    {
        $db = $c['database'] ?? '';
        if ($db === ':memory:' || ! is_file($db)) {
            throw new \DomainException('This SQLite database is not a file and cannot be backed up.');
        }
        $target = $this->directory().DIRECTORY_SEPARATOR.$name;
        $in = fopen($db, 'rb');
        $gz = gzopen($target, 'wb6');
        while (! feof($in)) {
            gzwrite($gz, fread($in, 1 << 20));
        }
        fclose($in);
        gzclose($gz);

        return $name;
    }
}
