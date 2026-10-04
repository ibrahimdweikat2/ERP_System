<?php

namespace App\Support\Tenancy;

use Illuminate\Database\MySqlConnection;

class TenantMySqlConnection extends MySqlConnection
{
    protected function getDefaultQueryGrammar()
    {
        return new TenantMySqlGrammar($this);
    }
}
