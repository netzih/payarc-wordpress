<?php

/**
 * Shared helpers for the sandbox probes. Sandbox only: every script refuses
 * a non-sandbox API URL.
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Payarc\GatewayClient;

function payarc_env(?string $file = NULL): array {
  $file = $file ?: (getenv('PAYARC_ENV') ?: getenv('HOME') . '/.config/payarc/sandbox.env');
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
  $env['PAYARC_API_URL'] = rtrim($env['PAYARC_API_URL'] ?? GatewayClient::SANDBOX_URL, '/');
  if (($env['PAYARC_BEARER_TOKEN'] ?? '') === '') {
    fwrite(STDERR, "PAYARC_BEARER_TOKEN is empty in $file\n");
    exit(2);
  }
  if (!str_contains($env['PAYARC_API_URL'], 'testapi.')) {
    fwrite(STDERR, "Refusing to run: {$env['PAYARC_API_URL']} is not the sandbox.\n");
    exit(2);
  }
  return $env;
}

function payarc_client(array $env): GatewayClient {
  return new GatewayClient($env['PAYARC_BEARER_TOKEN'], $env['PAYARC_API_URL'], NULL, 'payarc-php-sandbox-probe/0.1');
}

/**
 * A raw call, for endpoints the library deliberately lacks (server-side
 * tokenization of test card numbers) and for inspecting exact responses.
 *
 * @return array{status: int, body: mixed, headers: string}
 */
function payarc_raw(array $env, string $method, string $path, ?array $payload = NULL, array $extraHeaders = []): array {
  $curl = curl_init($env['PAYARC_API_URL'] . '/' . ltrim($path, '/'));
  $headers = array_merge([
    'Accept: application/json',
    'Content-Type: application/json',
    'Authorization: Bearer ' . $env['PAYARC_BEARER_TOKEN'],
  ], $extraHeaders);
  curl_setopt_array($curl, [
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_RETURNTRANSFER => TRUE,
    CURLOPT_HEADER => TRUE,
    CURLOPT_TIMEOUT => 45,
  ]);
  if ($payload !== NULL) {
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload));
  }
  $raw = curl_exec($curl);
  if ($raw === FALSE) {
    return ['status' => 0, 'body' => curl_error($curl), 'headers' => ''];
  }
  $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
  $body = substr($raw, $headerSize);
  $decoded = json_decode($body, TRUE);
  return ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'body' => $decoded ?? $body, 'headers' => substr($raw, 0, $headerSize)];
}

/**
 * Tokenize a sandbox test card server-side (TSYS cert Visa by default).
 */
function payarc_test_token(array $env, bool $authorize = FALSE, string $number = '4012000098765439', string $cvv = '999'): array {
  $payload = [
    'card_source' => 'INTERNET',
    'card_number' => $number,
    'exp_month' => '12',
    'exp_year' => '2029',
    'cvv' => $cvv,
    'card_holder_name' => 'Sandbox Probe',
    'address_line1' => '8320',
    'zip' => '85284',
  ];
  if ($authorize) {
    $payload['authorize_card'] = 1;
  }
  return payarc_raw($env, 'POST', '/tokens', $payload);
}
