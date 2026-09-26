<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Privacy\AccountDataExporter;
use App\Support\AuditLogger;
use Illuminate\Console\Command;

/**
 * A subject access request for somebody who has no account.
 *
 * Same exporter as the account page; the difference is who runs it and
 * that the file lands on disk for a person to send by a channel the
 * requester controls. The email address alone finds their donations,
 * orders, registrations and applications — an account is not needed to
 * have given the foundation data.
 */
class ExportData extends Command
{
    protected $signature = 'scghf:export-data {email : The address the request came from} {--to= : Where to write the JSON; default storage/app/private/exports}';

    protected $description = 'Write everything held about an email address to a JSON file, for a subject access request';

    public function handle(AccountDataExporter $exporter, AuditLogger $audit): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        $user = User::withTrashed()->where('email', $email)->first()
            ?? (new User)->forceFill(['email' => $email, 'name' => null]);

        $data = $exporter->export($user);

        $dir = (string) ($this->option('to') ?: storage_path('app/private/exports'));

        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            $this->error("Could not create {$dir}.");

            return self::FAILURE;
        }

        $path = rtrim($dir, '/'.DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'export-'.preg_replace('/[^a-z0-9]+/', '-', $email).'-'.now()->format('Ymd-His').'.json';
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        chmod($path, 0600);

        $audit->record('privacy.exported', sprintf('A subject access export was produced for %s by the command line.', $email), $user->exists ? $user : null);

        $this->info("Written to {$path}. Send it only by a channel the requester controls, then delete it.");

        return self::SUCCESS;
    }
}
