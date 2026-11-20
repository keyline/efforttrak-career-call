<?php

namespace App\Jobs;

use App\Services\CareerResultDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Submodule: result-delivery (DELIVERY-01). Sends one prepared outbox event. */
class DeliverCareerResult implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $deliveryId) {}

    public function handle(CareerResultDelivery $delivery): void
    {
        $delivery->send($this->deliveryId);
    }
}
