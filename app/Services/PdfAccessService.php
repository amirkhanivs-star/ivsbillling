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
     * Generate secure HMAC path token for clean URL routing (no query parameters).
     */
    public function getSecurePathToken(string $type, int $id): string
    {
        $key = $this->getAccessKey($type, $id);
        return substr(hash_hmac('sha256', "clean-pdf:{$type}:{$id}:{$key}", (string)config('app.key')), 0, 32);
    }

    /**
     * Check if a given path token is valid and not revoked.
     */
    public function isPathTokenValid(string $type, int $id, ?string $token): bool
    {
        if (empty($token) || Cache::get("pdf_revoked:{$type}:{$id}", false)) {
            return false;
        }

        $expected = $this->getSecurePathToken($type, $id);
        return hash_equals($expected, $token);
    }

    /**
     * Generate permanent public URL for an invoice PDF.
     */
    public function getInvoicePdfUrl(int $invoiceId): string
    {
        $token = $this->getSecurePathToken('invoice', $invoiceId);
        return url("/public/invoices/{$invoiceId}/{$token}/Monthly-Fee-Invoice.pdf");
    }

    /**
     * Generate permanent public URL for a family consolidated challan PDF.
     */
    public function getFamilyChallanPdfUrl(int $familyId): string
    {
        $token = $this->getSecurePathToken('family', $familyId);
        return url("/public/challans/{$familyId}/{$token}/Monthly-Fee-Invoice.pdf");
    }

    /**
     * Generate permanent public URL for a payment receipt PDF.
     */
    public function getReceiptPdfUrl(int $paymentId): string
    {
        $token = $this->getSecurePathToken('receipt', $paymentId);
        return url("/public/receipts/{$paymentId}/{$token}/Paid-Receipt.pdf");
    }
}

