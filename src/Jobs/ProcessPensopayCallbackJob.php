<?php

namespace Gamevault\Pensopay\Jobs;

use Lunar\Models\Transaction;
use Spatie\WebhookClient\Jobs\ProcessWebhookJob;

class ProcessPensopayCallbackJob extends ProcessWebhookJob
{
    public function handle()
    {
        \Illuminate\Support\Facades\Log::info('Pensopay webhook payload', [
            'payload' => $this->webhookCall->payload
        ]);
        $payload = $this->webhookCall->payload;

        /** @var Transaction $transaction */
        $transaction = Transaction::query()->where('reference', $payload['resource']['id'])->latest()->first();

        $this->storeTransaction($transaction, $payload);
    }

    private function storeTransaction(Transaction $previousTransaction, array $payload)
    {
        $paymentType = match ($payload['event']) {
            'payment.authorized' => 'intent',
            'payment.captured' => 'capture',
        };

        if (($paymentType == 'authorized' || $paymentType == 'intent') && !$previousTransaction->order->placed_at) {
            $previousTransaction->order->update([
                'placed_at' => now(),
            ]);
        }

        $previousTransaction->order->transactions()->create([
            'parent_transaction_id' => $previousTransaction->id,
            'success' => true,
            'type' => $paymentType,
            'driver' => 'pensopay',
            'amount' => $payload['resource']['amount'],
            'reference' => $payload['resource']['id'],
            'status' => $payload['event'],
            'notes' => '',
            'card_type' => $payload['resource']['payment_details']['brand'],
            'last_four' => $payload['resource']['payment_details']['last4'],
            'captured_at' => $payload['event'] == 'payment.captured' ? now() : null,
            'meta' => [
                'captured' => $payload['resource']['captured'],
                'refunded' => $payload['resource']['refunded'],
                'autocapture' => $payload['resource']['autocapture'],
                'testmode' => $payload['resource']['testmode'],
                'facilitator' => $payload['resource']['testmode'],
                'card_bin' => $payload['resource']['payment_details']['bin'],
                'exp_year' => $payload['resource']['payment_details']['exp_year'],
                'exp_month' => $payload['resource']['payment_details']['exp_month'],
                '3d_secure' => $payload['resource']['payment_details']['is_3d_secure'],
                'card_country' => $payload['resource']['payment_details']['country'],
                'is_corporate' => $payload['resource']['payment_details']['segment'],
                'customer_country' => $payload['resource']['payment_details']['customer_country'],
            ],
        ]);
    }
}
