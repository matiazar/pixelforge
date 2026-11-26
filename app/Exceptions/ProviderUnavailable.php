<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Timeout, dropped connection or an undocumented 5xx: the request may or may
 * not have reached the provider. Callers must treat the outcome as ambiguous.
 */
class ProviderUnavailable extends RuntimeException {}
