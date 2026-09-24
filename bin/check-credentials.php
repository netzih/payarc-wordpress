<?php

/**
 * Prove a PayArc bearer token works without moving money.
 *
 *   php bin/check-credentials.php [env-file]
 *
 * The env file holds PAYARC_BEARER_TOKEN and optionally PAYARC_API_URL
 * (defaults to live) and PAYARC_CLIENT_ID. Defaults to
 * ~/.config/payarc/sandbox.env. Lists one charge; nothing is charged.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Payarc\GatewayClient;
use Payarc\GatewayException;

$file = $argv[1] ?? getenv('HOME') . '/.config/payarc/sandbox.env';
if (!is_readable($file)) {
  fwrite(STDERR, "Cannot read $file\n");
  exit(2);
}
$env = [];
foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
  if (preg_match('/^\s*([A-Z_]+)\s*=\s*(.*?)\s*$/', $line, $m)) {
    $env[$m[1]] = trim($m[2], "\"'");
  }
}
$url = $env['PAYARC_API_URL'] ?? GatewayClient::LIVE_URL;

try {
  $client = new GatewayClient($env['PAYARC_BEARER_TOKEN'] ?? '', $url);
  $list = $client->verifyCredentials();
  printf("OK: bearer token accepted by %s (%s charge pages on the account).\n", $url, $list['total_pages'] ?? '?');
}
catch (\InvalidArgumentException $e) {
  fwrite(STDERR, 'Configuration: ' . $e->getMessage() . "\n");
  exit(1);
}
catch (GatewayException $e) {
  fwrite(STDERR, sprintf("FAILED (HTTP %d): %s\n", $e->getCode(), $e->getMessage()));
  exit(1);
}

if (($env['PAYARC_CLIENT_ID'] ?? '') === '') {
  echo "Note: PAYARC_CLIENT_ID is empty; Hosted Fields need it in the browser.\n";
}
