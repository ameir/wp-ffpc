<?php

/* check for WP cache enabled */
if (!defined('WP_CACHE')) {
    return;
}
if (defined('WP_INSTALLING') && WP_INSTALLING) {
    return;
}
if (!defined('WP_FFPC_DIR')) {
    $path = (defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins') . '/wp-ffpc';
    if (!file_exists($path)) {
        return;
    }

    define('WP_FFPC_DIR', $path);
}

include_once(WP_FFPC_DIR . '/wp-ffpc-acache.php');
