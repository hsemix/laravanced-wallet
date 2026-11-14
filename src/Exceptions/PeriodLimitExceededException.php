<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Exceptions;

/**
 * A day/week/month limit is used up right now. Unlike a per-payment limit this is
 * temporary: the same payment can succeed once the period rolls over, so scheduled
 * installments are deferred rather than failed when they hit it.
 */
class PeriodLimitExceededException extends LimitExceededException {}
