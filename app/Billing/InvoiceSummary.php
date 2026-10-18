<?php

namespace App\Billing;

use Laravel\Cashier\Invoice;

/** What the billing page shows for one invoice, independent of the payment provider. */
final readonly class InvoiceSummary
{
    public function __construct(
        public string $id,
        public ?string $number,
        public string $date,
        public string $total,
        public string $status,
        public ?string $hostedUrl,
        public ?string $pdfUrl,
    ) {}

    public static function fromCashier(Invoice $invoice): self
    {
        $stripe = $invoice->asStripeInvoice();

        return new self(
            id: (string) $stripe->id,
            number: $stripe->number,
            date: $invoice->date()->toIso8601String(),
            total: $invoice->total(),
            status: (string) $stripe->status,
            hostedUrl: $stripe->hosted_invoice_url,
            pdfUrl: $stripe->invoice_pdf,
        );
    }

    /** @return array{id: string, number: string|null, date: string, total: string, status: string, hosted_url: string|null, pdf_url: string|null} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'date' => $this->date,
            'total' => $this->total,
            'status' => $this->status,
            'hosted_url' => $this->hostedUrl,
            'pdf_url' => $this->pdfUrl,
        ];
    }
}
