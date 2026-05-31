<?php

namespace App\Actions\Proxies;

use App\Models\ProxyServer;

class DeleteProxyAction
{
    public function execute(ProxyServer $proxy): void
    {
        $proxy->delete();
    }
}
