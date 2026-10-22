<?php
if (function_exists('opcache_reset')) {
    var_dump(opcache_reset());
} else {
    echo "OPcache is not available.";
}