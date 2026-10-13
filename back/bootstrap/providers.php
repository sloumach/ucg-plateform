<?php

use App\Modules\Identity\Providers\IdentityServiceProvider;
use App\Modules\People\Providers\PeopleServiceProvider;
use App\Modules\Teams\Providers\TeamsServiceProvider;
use App\Modules\Tenancy\Providers\TenancyServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    IdentityServiceProvider::class,
    PeopleServiceProvider::class,
    TeamsServiceProvider::class,
    TenancyServiceProvider::class,
    AppServiceProvider::class,
    HorizonServiceProvider::class,
];
