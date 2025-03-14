<?php

namespace Gamevault\Pensopay;

use Lunar\Models\Transaction;
use Lunar\Events\PaymentAttemptEvent;
use Lunar\PaymentTypes\AbstractPayment;
use Gamevault\Pensopay\Enums\FacilitatorEnum;
use Gamevault\Pensopay\Enums\PaymentStateEnum;
use Gamevault\Pensopay\Services\PaymentService;
use Gamevault\Pensopay\Responses\PaymentResponse;
use Lunar\Base\DataTransferObjects\PaymentRefund;
use Lunar\Base\DataTransferObjects\PaymentCapture;
use Lunar\Base\DataTransferObjects\PaymentAuthorize;
use Lunar\Exceptions\DisallowMultipleCartOrdersException;

class PensopayPaymentType extends AbstractPayment
{
    public function __construct(protected PaymentService $paymentService)
    {
    }

    public function authorize(): PaymentAuthorize
    {
        if ($this->order && $this->order->placed_at) {
            return null;
        }

        try {
            $this->order = $this->cart->createOrder();
        } catch (DisallowMultipleCartOrdersException $e) {
            $failure = new PaymentAuthorize(
                success: false,
                message: $e->getMessage(),
                orderId: $this->order?->id,
                paymentType: 'PensoPay'
            );
            PaymentAttemptEvent::dispatch($failure);

            return $failure;
        }

        $paymentResponse = $this->paymentService->createPayment(
            $this->order,
            route('checkout.continue', ['id' => $this->order->id]),
            route('checkout.view', ['id' => $this->order->id]),
            route('webhook-client-pensopay-webhook'),
        );

        if (in_array($paymentResponse->getState(), [
            PaymentStateEnum::Rejected,
            PaymentStateEnum::Canceled,
        ])) {
            return new PaymentAuthorize(
                success: false,
                message: 'Something is broken',
            );
        }

        $this->storeTransaction($paymentResponse);

        if ($paymentResponse->isSuccessful()) {
            $this->order->update([
                'placed_at' => now(),
            ]);
        }

        if ($this->cart) {
            if (! $this->cart->meta) {
                $this->cart->update([
                    'meta' => [
                        'payment_intent' => $paymentResponse->getId(),
                    ],
                ]);
            } else {
                $this->cart->meta->payment_intent = $paymentResponse->getId();
                $this->cart->save();
            }
        }

        return new PaymentAuthorize(true, $paymentResponse->getLink());
    }

    public function refund(Transaction $transaction, int $amount, $notes = null): PaymentRefund
    {
        $paymentResponse = $this->paymentService->refund($transaction, $amount);

        if (! $paymentResponse->isSuccessful()) {
            return new PaymentRefund(
                success: false,
                message: 'Something went wrong'
            );
        }

        $this->storeTransaction($paymentResponse, $transaction->id);

        return new PaymentRefund(true);
    }

    public function capture(Transaction $transaction, $amount = 0): PaymentCapture
    {
        $paymentResponse = $this->paymentService->capture($transaction, $amount);

        if (! $paymentResponse->isSuccessful()) {
            return new PaymentCapture(
                success: false,
                message: 'Something went wrong'
            );
        }

        $this->storeTransaction($paymentResponse, $transaction);

        return new PaymentCapture(true);
    }

    private function storeTransaction(PaymentResponse $paymentResponse, ?Transaction $parentTransaction = null)
    {
        $paymentType = match ($paymentResponse->getState()) {
            'pending', 'authorized' => 'intent',
            'captured' => 'capture',
            'refunded' => 'refund',
        };

        $data = [
            'success' => $paymentResponse->isSuccessful(),
            'type' => $paymentType,
            'driver' => 'pensopay',
            'amount' => $paymentResponse->getAmount(),
            'reference' => $paymentResponse->getId(),
            'status' => $paymentResponse->getState(),
            'notes' => null,
            'card_type' => 'pensopay',
            'last_four' => null,
            'captured_at' => $paymentResponse->isSuccessful() ? ($paymentResponse->getState() == 'captured' ? now() : null) : null,
            'meta' => [
                'urls' => [
                    'link' => $paymentResponse->getLink(),
                    'callback_url' => $paymentResponse->getCallbackUrl(),
                    'success_url' => $paymentResponse->getSuccessUrl(),
                    'cancel_url' => $paymentResponse->getCancelUrl(),
                ],
                'captured' => $paymentResponse->getCaptured(),
                'refunded' => $paymentResponse->getRefunded(),
                //'expires_at' => $paymentResponse->getExpiresAt(),
                'pensopay_reference' => $paymentResponse->getReference(),
                'autocapture' => $paymentResponse->isAutoCapture(),
                'testmode' => $paymentResponse->isTestMode(),
                'facilitator' => $paymentResponse->getFacilitator(),
            ],
        ];

        if ($parentTransaction != null) {
            $data = array_merge($data, [
                'parent_transaction_id' => $parentTransaction->id,
            ]);

            $parentTransaction->order->transactions()->create($data);
        } else {
            $this->order->transactions()->create($data);
        }
    }
}
