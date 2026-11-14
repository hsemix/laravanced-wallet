<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Exceptions;

use RuntimeException;

/** Base class: catch this to handle every wallet failure. */
class WalletException extends RuntimeException {}
