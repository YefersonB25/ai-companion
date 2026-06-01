<?php

namespace App\Console\Commands;

use App\Models\License;
use Illuminate\Console\Command;

class ExpireLicenses extends Command
{
    protected $signature   = 'licenses:expire';
    protected $description = 'Marks all past-due active licenses as expired';

    public function handle(): void
    {
        $count = License::where('status', 'active')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);

        $this->info("Expired {$count} license(s).");
    }
}
