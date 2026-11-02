<?php

namespace App\Console\Commands;

use App\Services\Migration\Gr5130HistoricalPaymentReversal;
use Illuminate\Console\Command;

class ReverseGr5130HistoricalPayment extends Command
{
    protected $signature = 'billing:reverse-gr5130 {--apply} {--confirm-owner} {--actor-id=} {--approve-fingerprint=} {--reason=}';
    protected $description = 'Preview or explicitly authorize atomic reversal of both parts of the GR5130 correction';

    public function handle(Gr5130HistoricalPaymentReversal $service): int
    {
        try {
            $this->line(json_encode($service->run($this->options()), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
