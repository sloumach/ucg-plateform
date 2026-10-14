<?php

use App\Modules\Contracts\Providers\ContractsServiceProvider;
use App\Modules\Identity\Providers\IdentityServiceProvider;
use App\Modules\People\Providers\PeopleServiceProvider;
use App\Modules\Teams\Providers\TeamsServiceProvider;
use App\Modules\Tenancy\Providers\TenancyServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    ContractsServiceProvider::class,
    IdentityServiceProvider::class,
    PeopleServiceProvider::class,
    TeamsServiceProvider::class,
    TenancyServiceProvider::class,
    AppServiceProvider::class,
    HorizonServiceProvider::class,
];
