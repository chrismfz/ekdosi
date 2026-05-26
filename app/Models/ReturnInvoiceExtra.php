<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-line return-quantity tracking for credit/return invoices.
 * Mirrors legacy RETURN_INVOICE_EXTRAS.
 *
 * Three quantity columns: qty_given (original delivery), qty_sent
 * (subsequent shipment if any), qty_returned (actually returned).
 * Used by the return-invoice workflow in legacy FInvoiceReturn.cpp;
 * the new return flow lands well after the read-only view PR.
 */
class ReturnInvoiceExtra extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'invoice_line_id',
        'qty_given',
        'qty_returned',
        'qty_sent',
    ];

    protected function casts(): array
    {
        return [
            'qty_given' => 'decimal:3',
            'qty_returned' => 'decimal:3',
            'qty_sent' => 'decimal:3',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class);
    }
}
