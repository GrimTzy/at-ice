<?php

namespace App\Console\Commands;

use App\Support\ArchiveExpiry;
use App\Support\Settings;
use Illuminate\Console\Command;

// php artisan archive:expire : deletes the personal data of employees deactivated
// over a month ago, then applies archive.expiry_action (the scheduler also runs
// both once a day).
class ExpireArchive extends Command
{
    protected $signature = 'archive:expire';

    protected $description = 'Delete the personal data of employees deactivated over a month ago, then anonymize or purge those past the retention period';

    public function handle(): int
    {
        $purged = 0;
        foreach (ArchiveExpiry::dueForPersonalPurge()->get() as $employee) {
            ArchiveExpiry::purgePersonal($employee);
            $purged++;
        }
        $this->info("Personal data deleted: {$purged} employee(s).");

        $action = Settings::get('archive.expiry_action');
        $count = ArchiveExpiry::run();
        $this->info($action === 'REVIEW' ? 'archive.expiry_action is REVIEW: nothing was changed.' : "{$action}: {$count} employee(s).");

        return self::SUCCESS;
    }
}
