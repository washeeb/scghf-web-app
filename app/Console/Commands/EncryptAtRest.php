<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SafeguardingCheck;
use App\Models\Volunteer;
use App\Models\VolunteerApplication;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Encrypt the rows that were written before the `encrypted` casts existed.
 *
 * Run once after the Phase 12 deploy. Idempotent: a value that already
 * decrypts is left alone, so running it twice — or after a crash halfway
 * — is safe. It goes row by row through the model so the cast does the
 * encrypting; nothing here knows the cipher.
 */
class EncryptAtRest extends Command
{
    protected $signature = 'scghf:encrypt-at-rest {--execute : Write the encrypted values rather than only counting}';

    protected $description = 'Encrypt sensitive columns written before encryption at rest was switched on';

    /** @var array<class-string<Model>, array<int, string>> */
    private const COLUMNS = [
        VolunteerApplication::class => ['next_of_kin_name', 'next_of_kin_phone', 'referees', 'disclosed_convictions'],
        SafeguardingCheck::class => ['reference'],
        Volunteer::class => ['concern_note'],
    ];

    public function handle(): int
    {
        $total = 0;

        foreach (self::COLUMNS as $class => $columns) {
            /** @var Model $model */
            $model = new $class;
            $table = $model->getTable();
            $key = $model->getKeyName();
            $pending = 0;

            DB::table($table)
                ->select([$key, ...$columns])
                ->orderBy($key)
                ->chunk(200, function ($rows) use ($class, $columns, &$pending): void {
                    foreach ($rows as $row) {
                        $updates = [];

                        foreach ($columns as $column) {
                            $raw = $row->{$column};

                            if ($raw === null || $raw === '' || $this->decrypts($raw)) {
                                continue;
                            }

                            $updates[$column] = $column === 'referees' ? (json_decode((string) $raw, true) ?? []) : (string) $raw;
                        }

                        if ($updates === []) {
                            continue;
                        }

                        $pending++;

                        if ($this->option('execute')) {
                            /*
                             * A fresh instance does the encrypting through the
                             * cast, and the raw ciphertext is written back with
                             * the query builder. Hydrating the real row would
                             * make Eloquent try to decrypt the plaintext it is
                             * about to replace, and refuse.
                             */
                            $encrypted = (new $class)->forceFill($updates)->getAttributes();
                            $keyName = (new $class)->getKeyName();

                            DB::table((new $class)->getTable())
                                ->where($keyName, $row->{$keyName})
                                ->update(array_intersect_key($encrypted, $updates));
                        }
                    }
                });

            $this->line(sprintf('%s: %d row(s) %s', $table, $pending, $this->option('execute') ? 'encrypted' : 'still in clear'));
            $total += $pending;
        }

        if (! $this->option('execute') && $total > 0) {
            $this->warn('DRY RUN — nothing written. Add --execute to encrypt.');
        }

        return self::SUCCESS;
    }

    private function decrypts(mixed $raw): bool
    {
        try {
            Crypt::decryptString((string) $raw);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
}
