<?php

namespace App\Exceptions\Domain;

use DomainException;

/** Base class of every domain rule violation; messages come from lang/{ar,en}/exceptions.php. */
class BusinessRuleViolationException extends DomainException {}
