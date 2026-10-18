<?php

use App\Billing\InvoiceSummary;
use Laravel\Cashier\Invoice;
use Stripe\Invoice as StripeInvoice;

function cashierInvoice(array $attributes = []): Invoice
{
    $team = ownerWithTeam()->currentTeam;
    $team->forceFill(['stripe_id' => 'cus_1'])->save();

    return new Invoice($team, StripeInvoice::constructFrom(array_merge([
        'id' => 'in_1', 'object' => 'invoice', 'customer' => 'cus_1', 'number' => 'ACME-0007', 'status' => 'paid',
        'created' => 1760000000, 'currency' => 'usd', 'total' => 2900, 'subtotal' => 2900, 'amount_due' => 0,
        'hosted_invoice_url' => 'https://invoice.stripe.com/i/acct/in_1',
        'invoice_pdf' => 'https://pay.stripe.com/invoice/in_1/pdf',
    ], $attributes)));
}

it('summarises a Stripe invoice for the billing page', function () {
    $summary = InvoiceSummary::fromCashier(cashierInvoice());

    expect($summary->toArray())->toBe([
        'id' => 'in_1',
        'number' => 'ACME-0007',
        'date' => '2025-10-09T08:53:20+00:00',
        'total' => '$29.00',
        'status' => 'paid',
        'hosted_url' => 'https://invoice.stripe.com/i/acct/in_1',
        'pdf_url' => 'https://pay.stripe.com/invoice/in_1/pdf',
    ]);
});

it('copes with an open invoice that has no number or PDF yet', function () {
    $summary = InvoiceSummary::fromCashier(cashierInvoice(['number' => null, 'status' => 'open', 'invoice_pdf' => null, 'hosted_invoice_url' => null]));

    expect($summary->number)->toBeNull()->and($summary->pdfUrl)->toBeNull()->and($summary->hostedUrl)->toBeNull()
        ->and($summary->status)->toBe('open');
});
