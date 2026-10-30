<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The authenticated source may not accept new site requests now. The message is safe
 * to return to the site that owns the key.
 */
class SiteIntakeUnavailable extends RuntimeException {}
