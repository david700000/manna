<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('orders:process-delayed')]
#[Description('Processes orders that have been paid for > 30 mins')]
class ProcessDelayedOrders extends Command
{
    public function handle()
    {
        \App\Models\Order::processDelayedOrders();
        $this->info('Processed delayed orders successfully.');
    }
}
