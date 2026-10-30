<?php

namespace App\Enums;

/**
 * The outcome of one distribution attempt. Returned to the caller only, never stored.
 */
enum DistributionResult: string
{
    case Assigned = 'assigned';

    case Waiting = 'waiting';

    case Expired = 'expired';
}
