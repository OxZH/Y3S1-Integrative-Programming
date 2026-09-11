<?php
// Interchangeable checkout algorithm for a payment method. Author: Khor Zhi Hong

declare(strict_types=1);

namespace App\Domain\Payment;

use DomainException;

interface PaymentMethodStrategy
{
    public function code(): string;

    public function label(): string;

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     * @throws DomainException
     */
    public function validate(array $input): array;

    /**
     * @param array<string,mixed> $normalized
     * @return array{
     *     paymentMethod:string,
     *     payerName:string,
     *     accountMask:string,
     *     providerLabel:string,
     *     methodDetailJson:array<string,mixed>
     * }
     */
    public function snapshot(array $normalized): array;

    /**
     * Metadata the checkout view can use to describe fields for this method.
     *
     * @return array<int,array<string,mixed>>
     */
    public function formFields(): array;
}
