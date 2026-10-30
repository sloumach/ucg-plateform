<?php

namespace App\Support\Operations;

enum ProbeState: string
{
    case Passed = 'passed';
    case Warning = 'warning';
    case Failed = 'failed';
    case Unverified = 'unverified';
}
