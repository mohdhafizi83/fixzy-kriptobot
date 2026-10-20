<?php

namespace Fixzy\Kriptobot\Service;

use RuntimeException;

/**
 * ConfigValidationException — thrown when a user-supplied configuration
 * section fails validation. The message is built exclusively from
 * validator-generated strings describing the user's own input, so the REST
 * layer may return it to the client verbatim (unlike generic RuntimeExceptions,
 * which stay masked as "Request could not be processed.").
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
class ConfigValidationException extends RuntimeException
{
}
