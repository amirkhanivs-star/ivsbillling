<?php

namespace App\Services;

class FeeImportService
{
    public function normalizeCurrency(?string $val): string
    {
        $c = strtoupper(trim(str_replace(['`', "'", '"', ' '], '', (string)$val)));
        if (in_array($c, ['SAR', 'SAFR', 'SAT', 'SR', 'SAR`'])) return 'SAR';
        if (in_array($c, ['AED', 'SAE', 'DHS', 'DIRHAM', 'AED`'])) return 'AED';
        if (in_array($c, ['RS', 'PKR', 'RUPEES', 'RS.'])) return 'PKR';
        if (in_array($c, ['QAR', 'QR'])) return 'QAR';
        if (in_array($c, ['USD', '$'])) return 'USD';
        if (in_array($c, ['OMR', 'RO'])) return 'OMR';
        if (in_array($c, ['BHD', 'BD'])) return 'BHD';
        return 'UNRESOLVED';
    }

    public function importOutstanding(string $filePath): array
    {
        throw new \RuntimeException('Direct-to-ledger outstanding fee imports are disabled during Phase 1 Recovery Protection.');
    }

    public function importCollection(string $filePath): array
    {
        throw new \RuntimeException('Direct-to-ledger historical collection imports are disabled during Phase 1 Recovery Protection.');
    }

    public function clearFeeData(): void
    {
        throw new \RuntimeException('Dangerous operation disabled: raw database ledger truncation is locked for Phase 1 Recovery Protection.');
    }

    public function clearAllData(): void
    {
        throw new \RuntimeException('Dangerous operation disabled: mass database purge is locked for Phase 1 Recovery Protection.');
    }
}
