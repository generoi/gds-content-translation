<?php

namespace GeneroWP\ContentTranslation\Tests;

use RuntimeException;

/**
 * Thrown from the `wp_redirect` filter so a handler that redirects and exits
 * can be tested: the exception leaves it before `exit`.
 */
class RedirectException extends RuntimeException
{
    public string $location;

    public function __construct(string $location)
    {
        $this->location = $location;

        parent::__construct("Redirected to {$location}");
    }
}
