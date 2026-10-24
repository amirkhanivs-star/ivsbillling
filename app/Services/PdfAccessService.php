<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class PdfAccessService
{
    /**
     * Get or deterministically generate the access key for a specific resource type and ID.
     */
    public function getAccessKey(string $type, int $id): string
    {
        return Cache::rememberForever("pdf_key:{$type}:{$id}", function () use ($type, $id) {
            return substr(hash('sha256', "pdf-{$type}-{$id}-" . config('app.key')), 0, 16);
        });
    }

    /**
     * Regenerate the access key for a specific resource, immediately invalidating previous links.
     */
    public function regenerateAccessKey(string $type, int $id): string
    {
        Cache::forget("pdf_revoked:{$type}:{$id}");
        $newKey = Str::random(16);
        Cache::forever("pdf_key:{$type}:{$id}", $newKey);
        return $newKey;
    }

    /**
     * Revoke access for a specific resource.
     */
    public function revokeAccess(string $type, int $id): void
    {
        Cache::forever("pdf_key:{$type}:{$id}", 'revoked_' . Str::random(16));
        Cache::forever("pdf_revoked:{$type}:{$id}", true);
    }

    /**
     * Check if a given access key is valid and not revoked for the resource.
     */
    public function isAccessValid(string $type, int $id, ?string $key = null): bool
    {
        if (Cache::get("pdf_revoked:{$type}:{$id}", false)) {
            return false;
        }

        if ($key !== null) {
            $currentKey = $this->getAccessKey($type, $id);
            return hash_equals($currentKey, $key);
        }

        return true;
    }

    /**
     * Generate permanent public signed URL for an invoice PDF.
     */
    public function getInvoicePdfUrl(int $invoiceId): string
    {
        $key = $this->getAccessKey('invoice', $invoiceId);
        return URL::signedRoute('invoices.signed_pdf', [
            'invoice' => $invoiceId,
            'k' => $key,
        ]);
    }

    /**
     * Generate permanent public signed URL for a family consolidated challan PDF.
     */
    public function getFamilyChallanPdfUrl(int $familyId): string
    {
        $key = $this->getAccessKey('family', $familyId);
        return URL::signedRoute('families.signed_challan_pdf', [
            'family' => $familyId,
            'k' => $key,
        ]);
    }

    /**
     * Generate permanent public signed URL for a payment receipt PDF.
     */
    public function getReceiptPdfUrl(int $paymentId): string
    {
        $key = $this->getAccessKey('receipt', $paymentId);
        return URL::signedRoute('receipts.signed_pdf', [
            'payment' => $paymentId,
            'k' => $key,
        ]);
    }
}
