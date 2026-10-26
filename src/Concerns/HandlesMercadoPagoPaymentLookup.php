<?php

declare(strict_types=1);

namespace Abitech\Payments\Concerns;

use Abitech\Payments\DTO\WebhookResult;
use Abitech\Payments\Exceptions\PaymentGatewayException;
use Illuminate\Support\Carbon;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Net\MPSearchRequest;

/**
 * Consulta un pago de Mercado Pago por su id o por la referencia externa
 * del checkout, para conciliar el aviso que no llegó.
 */
trait HandlesMercadoPagoPaymentLookup
{
    use HandlesMercadoPagoWebhook;

    public function findPayment(?string $transactionId = null, ?string $externalReference = null): ?WebhookResult
    {
        if (blank($transactionId) && blank($externalReference)) {
            throw new PaymentGatewayException(
                "Se requiere el id del pago o la referencia externa para consultarlo.",
                422
            );
        }

        // El id de una preferencia no es un pago; convertido a entero sería otro.
        if (filled($transactionId) && ! ctype_digit($transactionId)) {
            throw new PaymentGatewayException(
                "El id de un pago de Mercado Pago es numérico; para un checkout use la referencia externa.",
                422
            );
        }

        $this->authenticate();
        $this->throttle($this->getGatewayName() . ':find_payment');

        $payment = $this->findWithRetry(fn () => filled($transactionId)
            ? json_decode(json_encode((new PaymentClient())->get((int) $transactionId)), true)
            : $this->preferredPaymentOf((string) $externalReference));

        return $payment === null ? null : $this->toMercadoPagoResult('payment', $payment);
    }

    /**
     * El pago aprobado de la referencia o, si no hay, el más reciente.
     *
     * @return array<string, mixed>|null
     */
    private function preferredPaymentOf(string $externalReference): ?array
    {
        $search = (new PaymentClient())->search(new MPSearchRequest(null, null, [
            'external_reference' => $externalReference,
            'sort' => 'date_created',
            'criteria' => 'desc',
        ]));

        $payments = collect(json_decode(json_encode($search->results ?? []), true))
            ->sortByDesc(fn (array $payment) => Carbon::parse($payment['date_created'])->getTimestamp());

        return $payments->firstWhere('status', 'approved') ?? $payments->first();
    }
}
