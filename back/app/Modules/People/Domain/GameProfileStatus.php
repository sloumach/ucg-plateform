<?php

namespace App\Modules\People\Domain;

enum GameProfileStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
