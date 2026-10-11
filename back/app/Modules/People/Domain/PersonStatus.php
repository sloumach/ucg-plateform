<?php

namespace App\Modules\People\Domain;

/** A profile status never grants or revokes authentication or membership rights. */
enum PersonStatus: string
{
    case Candidate = 'candidate';
    case Trial = 'trial';
    case Active = 'active';
    case Unavailable = 'unavailable';
    case Substitute = 'substitute';
    case Former = 'former';
}
