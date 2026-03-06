<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Commodity;
use App\Model\Order;
use Kernel\Annotation\Bind;

#[Bind(class: \App\Service\Bind\Webshare::class)]
interface Webshare
{
    public function isSupportedCommodity(Commodity $commodity): bool;

    public function deliver(Order $order): string;

    public function getPreset(string $preset): array;

    public function getRealtimeCost(Commodity $commodity, array $input = [], int $quantity = 1): array;

    public function getRealtimeCostByWidget(Commodity $commodity, ?string $widgetJson, int $quantity = 1): array;

    public function syncCommodityCost(Commodity $commodity): array;

    public function convertSharedCommodity(Commodity $commodity): array;
}
