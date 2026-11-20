<?php

namespace App\Services\Billing;

use RuntimeException;

/**
 * A billing action that is not possible right now; the message is shown to the user.
 */
final class BillingNotAllowed extends RuntimeException {}
