<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Minimal WordPress shims for the pure classes under test.
if (!function_exists('__')) {
  function __(string $text, string $domain = 'default'): string {
    return $text;
  }
}

if (!defined('HOUR_IN_SECONDS')) {
  define('HOUR_IN_SECONDS', 3600);
}

if (!function_exists('sanitize_text_field')) {
  function sanitize_text_field(string $text): string {
    return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags($text)));
  }
}

if (!function_exists('sanitize_key')) {
  function sanitize_key(string $key): string {
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
  }
}
