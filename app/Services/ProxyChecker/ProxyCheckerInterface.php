<?php

namespace App\Services\ProxyChecker;

use App\Data\ProxyCheckResult;
use App\Models\ProxyServer;

interface ProxyCheckerInterface
{
    public function check(ProxyServer $proxy): ProxyCheckResult;
}
