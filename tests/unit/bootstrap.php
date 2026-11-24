<?php

/**
 * Bootstrap for the unit tests: the tested classes do not need Roundcube.
 */
foreach (glob(__DIR__ . '/../../lib/*.php') as $file) {
    require_once $file;
}
