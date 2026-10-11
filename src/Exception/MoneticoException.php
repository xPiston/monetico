<?php

declare(strict_types=1);

namespace Piston\Monetico\Exception;

use Throwable;

/**
 * Every exception this library throws implements this interface.
 *
 * So an application can catch everything that comes from Monetico with one
 * `catch (MoneticoException $e)` without also swallowing its own
 * InvalidArgumentExceptions - which matters here, because the classes below
 * deliberately extend the SPL types an application already handles.
 */
interface MoneticoException extends Throwable {}
