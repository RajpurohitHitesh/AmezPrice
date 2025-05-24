<?php
if (!function_exists('fa_kit_url')) {
    function fa_kit_url() {
        $config = include config_path('globals.php');
        return "https://kit.fontawesome.com/{$config['fontawesome']['kit_id']}.js";
    }
}