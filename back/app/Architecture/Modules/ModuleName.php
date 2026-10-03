<?php

namespace App\Architecture\Modules;

enum ModuleName: string
{
    case Identity = 'Identity';
    case Tenancy = 'Tenancy';
    case People = 'People';
    case Teams = 'Teams';
    case Academy = 'Academy';
    case Scheduling = 'Scheduling';
    case Competition = 'Competition';
    case Performance = 'Performance';
    case Contracts = 'Contracts';
    case Partnerships = 'Partnerships';
    case Finance = 'Finance';
    case Media = 'Media';
    case Reporting = 'Reporting';
}
