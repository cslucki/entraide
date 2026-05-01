<?php

namespace App\Observers;

use App\Models\Service;
use App\Services\BadgeService;

class ServiceObserver
{
    public function __construct(private BadgeService $badgeService) {}

    public function created(Service $service): void
    {
        $this->badgeService->checkAndAward($service->user);
    }
}
