<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessStripeWebhook;
use App\Models\ProcessedWebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('billing.webhook.secret');
        if ($secret === '') {
            return response()->json(['error' => 'Webhook secret is not configured'], 403);
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $secret,
                (int) config('billing.webhook.tolerance'),
            );
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return response()->json(['error' => 'Invalid signature'], 403);
        }

        // Idempotency: the unique event_id means a redelivered event is acknowledged but never re-run.
        try {
            $record = ProcessedWebhookEvent::create([
                'event_id' => $event->id,
                'type' => $event->type,
                'payload' => $event->toArray(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['status' => 'duplicate']);
        }

        ProcessStripeWebhook::dispatch($record->id);

        return response()->json(['status' => 'queued']);
    }
}
