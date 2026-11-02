<?php

namespace App\Console\Commands;

use App\Services\Migration\Gr5130HistoricalPaymentCorrection;
use Illuminate\Console\Command;

class CorrectGr5130HistoricalPayment extends Command
{
    protected $signature = 'billing:correct-gr5130 {--apply} {--confirm-owner} {--actor-id=} {--approve-fingerprint=} {--reason=}';
    protected $description = 'Preview the exact GR5130 discount-to-historical-payment correction; apply only with explicit approval';

    public function handle(Gr5130HistoricalPaymentCorrection $service): int
    {
        try {
            $result = $service->run($this->options());
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
