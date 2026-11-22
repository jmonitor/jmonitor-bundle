<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Stack\Collector;

/**
 * A command that cannot be found, an extension or a permission that is missing.
 */
final class MissingPrerequisiteException extends \RuntimeException {}
