<?php

namespace Gamevault\Pensopay\Services;

use Carbon\Carbon;
use DateTimeInterface;
use Gamevault\Pensopay\Enums\FacilitatorEnum;
use Gamevault\Pensopay\Responses\PaymentResponse;
use Illuminate\Http\Client\Response;
use Lunar\Models\Currency;
use Lunar\Models\Order;
use Lunar\Models\Transaction;

class PaymentService extends BaseClient
{
    /**
     * Get single payment by id
     */
    public function getPayment(int $paymentId): Response
    {
        $queryParams = [
            'payment' => $paymentId,
        ];

        return $this->pendingRequest->get($this->url(), $queryParams);
    }

    /**
     * Returns a paginated list of payments
     */
    public function getPayments(
        int $perPage = 15,
        int $page = 1,
        string $orderId = null,
        Currency $currency = null,
        DateTimeInterface|string $fromDate = null,
        DateTimeInterface|string $toDate = null
    ): Response {
        $queryParams = [
            'per_page' => $perPage,
            'page' => $page,
        ];

        if ($orderId != null) {
            $queryParams = array_merge($queryParams, [
                'order_id' => $orderId,
            ]);
        }

        if ($currency != null) {
            $queryParams = array_merge($queryParams, [
                'currency' => $currency->getAttributes()['code'],
            ]);
        }

        if ($fromDate != null) {
            $formattedDate = Carbon::parse($fromDate)->toIso8601String();

            $queryParams = array_merge($queryParams, [
                'date_from' => $formattedDate,
            ]);
        }

        if ($toDate != null) {
            $formattedDate = Carbon::parse($toDate)->toIso8601String();

            $queryParams = array_merge($queryParams, [
                'date_to' => $formattedDate,
            ]);
        }

        //Todo make class to store response.
        return $this->pendingRequest->get($this->url(), $queryParams);
    }

    /**
     * Create a new payment in the pending state, once the user has paid state will change to authorized and we'll send a callback
     */
    public function createPayment(
        Order $order,
        $successUrl = null,
        $cancelUrl = null,
        $callbackUrl = null,
    ): PaymentResponse {
        //Pensopay requires at least 4 characters in order id
        $orderId = $order->reference;
        if (config('pensopay.testmode')) {
            $orderId .= '_test';
        }

        $payload = [
            'order_id' => $orderId,
            'amount' => $order->total->value,
            'currency' => $order->currency_code,
            'testmode' => config('pensopay.testmode'),
            'autocapture' => false,
        ];

        if ($successUrl != null) {
            $payload = array_merge($payload, [
                'success_url' => $successUrl,
            ]);
        }

        if ($cancelUrl != null) {
            $payload = array_merge($payload, [
                'cancel_url' => $cancelUrl,
            ]);
        }

        if ($callbackUrl != null) {
            $payload = array_merge($payload, [
                'callback_url' => $callbackUrl,
            ]);
        }

        $response = $this->pendingRequest->post($this->url(), $payload);

        return new PaymentResponse($response);
    }

    public function refund(Transaction $transaction, int $amount): PaymentResponse
    {
        $payload = [
            'amount' => $amount,
        ];

        $url = sprintf($this->url().'/%s/refund', $transaction->reference);

        $response = $this->pendingRequest->post($url, $payload);

        return new PaymentResponse($response);
    }

    public function capture(Transaction $transaction, int $amount): PaymentResponse
    {
        $payload = [
            'amount' => $amount,
        ];

        $url = sprintf($this->url().'/%s/capture', $transaction->reference);

        $response = $this->pendingRequest->post($url, $payload);

        return new PaymentResponse($response);
    }

    public function url(): string
    {
        return '/payments';
    }
}
