<?php

namespace App\Console\Commands;

use App\Jobs\ExpireHolds;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExpireHoldsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'holds:expire';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire all expired holds and update related orders';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Dispatching hold expiry job...');

        ExpireHolds::dispatch();

        $this->info('Hold expiry job dispatched successfully.');

        Log::info('Hold expiry command executed', [
            'command' => 'holds:expire',
            'timestamp' => now()->toISOString(),
        ]);

        return self::SUCCESS;
    }
}