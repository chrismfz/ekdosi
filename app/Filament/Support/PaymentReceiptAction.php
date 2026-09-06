<?php

namespace App\Filament\Support;

use App\Models\Payment;
use App\Services\PaymentReceiptRenderer;
use Filament\Actions\Action;

/**
 * The «Απόδειξη είσπραξης» download action — one definition shared by the flat
 * Πληρωμές list and the per-invoice payments tab, so the label/guard/streaming
 * never drift between them. Incoming payments only (a refund is money out).
 */
class PaymentReceiptAction
{
    public static function make(): Action
    {
        return Action::make('receipt')
            ->label('Απόδειξη')
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->visible(fn (Payment $record): bool => ! $record->isRefund())
            ->action(function (Payment $record) {
                // Render up-front so an error surfaces as a notification, not a
                // half-streamed corrupt download (same as ViewInvoice/ViewQuote).
                $pdf = app(PaymentReceiptRenderer::class)->render($record);
                $name = 'apodeixi-'.($record->reference ?: $record->id).'.pdf';

                return response()->streamDownload(fn () => print ($pdf), $name, ['Content-Type' => 'application/pdf']);
            });
    }
}
