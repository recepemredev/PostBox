<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\ContractServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    ContractServiceProvider::class,
];
