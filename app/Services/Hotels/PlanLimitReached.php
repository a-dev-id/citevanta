<?php

namespace App\Services\Hotels;

use RuntimeException;

/**
 * Thrown when an action would go over the team's plan limit.
 */
final class PlanLimitReached extends RuntimeException {}
