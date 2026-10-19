<?php

namespace App\Billing;

use RuntimeException;

/** Thrown instead of starting a second subscription for a team that already has one. The message is safe to show. */
class AlreadySubscribed extends RuntimeException {}
