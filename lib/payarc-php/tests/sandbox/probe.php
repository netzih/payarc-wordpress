<?php

/**
 * Sandbox probe: answers the questions the PayArc docs leave open, using
 * server-side tokens of the TSYS test cards. Hosted Fields tokens are probed
 * separately by tests/sandbox/web (they may behave differently).
 *
 *   php tests/sandbox/probe.php [env-file]
 *
 * Moves only sandbox money; every charge it makes is voided. Writes the full
 * responses to tests/sandbox/reports/probe-<time>.json and prints a summary.
 */

require_once __DIR__ . '/lib.php';

use Payarc\CardDetails;
use Payarc\Charge;
use Payarc\DonorMessage;
use Payarc\GatewayException;

$env = payarc_env($argv[1] ?? NULL);
$client = payarc_client($env);
$run = 'probe-' . gmdate('YmdHis');
$report = ['run' => $run, 'api' => $env['PAYARC_API_URL'], 'experiments' => []];
$toVoid = [];

/**
 * Run one experiment, recording its result or exception.
 */
function probe(string $name, string $question, callable $fn): mixed {
  global $report;
  $entry = ['question' => $question];
  try {
    $result = $fn();
    $entry['ok'] = TRUE;
    $entry['result'] = $result;
  }
  catch (\Throwable $e) {
    $result = NULL;
    $entry['ok'] = FALSE;
    $entry['exception'] = [
      'class' => get_class($e),
      'code' => $e->getCode(),
      'message' => $e->getMessage(),
      'data' => $e instanceof GatewayException ? $e->getResponseData() : NULL,
    ];
  }
  $report['experiments'][$name] = $entry;
  $summary = $entry['ok'] ? 'ok' : $entry['exception']['class'] . ' ' . $entry['exception']['code'] . ': ' . $entry['exception']['message'];
  printf("%-28s %s\n", $name, $summary);
  return $result;
}

function token_id(array $raw): string {
  $id = $raw['body']['data']['id'] ?? '';
  if ($id === '') {
    throw new \RuntimeException('Tokenization failed: HTTP ' . $raw['status'] . ' ' . json_encode($raw['body']));
  }
  return $id;
}

// 1. Credentials and the list row shape.
probe('list_shape', 'What does a list row look like (created_at format, metadata, refunds)?', function () use ($client) {
  $list = $client->listCharges(2, 1);
  $row = $list['rows'][0] ?? [];
  return [
    'total_pages' => $list['total_pages'],
    'row_keys' => array_keys($row),
    'created_at' => $row['created_at'] ?? NULL,
    'has_metadata' => Charge::hasMetadata($row),
    'metadata' => Charge::metadata($row),
  ];
});

// 2. Server tokens: plain and $0-authorized.
$tokenPlain = probe('token_plain', 'Server token without authorize_card: is the card verified?', function () use ($env) {
  $raw = payarc_test_token($env);
  return ['status' => $raw['status'], 'id' => token_id($raw), 'card' => CardDetails::fromResponse($raw['body']), 'body' => $raw['body']];
});
$tokenAuthorized = probe('token_authorized', 'Server token with authorize_card=1: verified?', function () use ($env) {
  $raw = payarc_test_token($env, TRUE);
  return ['status' => $raw['status'], 'id' => token_id($raw), 'card' => CardDetails::fromResponse($raw['body'])];
});

// 3. A token charge with every field we plan to send.
$reference = $run . '-c1';
$charge = probe('charge_token', 'Token charge: response shape, charge_description, metadata echo, email suppression fields accepted?', function () use ($client, $tokenPlain, $reference, &$toVoid) {
  $response = $client->chargeToken($tokenPlain['id'], '1.01', [
    'reference' => $reference,
    'invoice' => 'PROBE-1',
    'description' => 'Sandbox probe charge',
    'metadata' => ['contact_id' => '42'],
  ]);
  $toVoid[] = Charge::id($response);
  return ['outcome' => Charge::outcome($response), 'id' => Charge::id($response), 'metadata' => Charge::metadata($response), 'response' => $response];
});

// 4. Idempotency.
probe('idempotent_replay', 'Same Idempotency-Key and same body: same charge id returned?', function () use ($client, $tokenPlain, $reference, $charge) {
  $again = $client->chargeToken($tokenPlain['id'], '1.01', [
    'reference' => $reference,
    'invoice' => 'PROBE-1',
    'description' => 'Sandbox probe charge',
    'metadata' => ['contact_id' => '42'],
  ]);
  return ['same_id' => Charge::id($again) === ($charge['id'] ?? NULL), 'id' => Charge::id($again), 'outcome' => Charge::outcome($again)];
});
probe('idempotent_other_amount', 'Same Idempotency-Key, different amount: original returned, error, or new charge?', function () use ($client, $tokenPlain, $reference, $charge, &$toVoid) {
  $again = $client->chargeToken($tokenPlain['id'], '1.99', ['reference' => $reference]);
  if (Charge::id($again) !== ($charge['id'] ?? NULL)) {
    $toVoid[] = Charge::id($again);
  }
  return ['same_id' => Charge::id($again) === ($charge['id'] ?? NULL), 'id' => Charge::id($again), 'amount' => Charge::amountCents($again)];
});
probe('token_reuse', 'Token used once, new key: refused (single-use)?', function () use ($client, $tokenPlain, $run, &$toVoid) {
  $again = $client->chargeToken($tokenPlain['id'], '1.03', ['reference' => $run . '-reuse']);
  $toVoid[] = Charge::id($again);
  return ['outcome' => Charge::outcome($again), 'id' => Charge::id($again)];
});

// 5. Reading it back.
probe('get_charge', 'GET with include: how is metadata returned? refund list?', function () use ($client, $charge) {
  $full = $client->getCharge($charge['id']);
  return ['metadata' => Charge::metadata($full), 'metadata_fields' => array_values(array_intersect(array_keys($full), ['metadata', 'transaction_metadata', 'extra_metadata'])), 'charge_description' => $full['charge_description'] ?? NULL, 'raw_metadata' => array_intersect_key($full, array_flip(['metadata', 'transaction_metadata', 'extra_metadata']))];
});
probe('find_by_reference', 'Can the list find the charge by our metadata reference?', function () use ($client, $reference) {
  $found = $client->findChargeByReference($reference, time() - 300, 2);
  return ['found_id' => $found ? Charge::id($found) : NULL];
});

// 6. Reversals.
probe('reverse_unsettled_full', 'Full reverse of an unsettled charge: refund refused, then void?', function () use ($client, $charge, &$toVoid) {
  $result = $client->reverse($charge['id']);
  $toVoid = array_values(array_diff($toVoid, [$charge['id']]));
  return ['action' => $result['action'], 'status' => $result['response']['status'] ?? NULL];
});
probe('void_twice', 'Voiding an already voided charge: which error?', function () use ($client, $charge) {
  return $client->void($charge['id']);
});
probe('refund_raw_unsettled', 'Exact refund error on an unsettled charge', function () use ($env, $client, $run, &$toVoid) {
  $token = token_id(payarc_test_token($env));
  $fresh = $client->chargeToken($token, '1.04', ['reference' => $run . '-r']);
  $toVoid[] = Charge::id($fresh);
  return payarc_raw($env, 'POST', '/charges/' . Charge::id($fresh) . '/refunds', ['amount' => 50, 'reason' => 'requested_by_customer', 'do_not_send_email_to_customer' => 'yes', 'do_not_send_sms_to_customer' => 'yes']);
});
probe('authorize_only', 'capture=0: status authorized, then void', function () use ($env, $client, $run) {
  $token = token_id(payarc_test_token($env));
  $auth = $client->chargeToken($token, '1.05', ['reference' => $run . '-a', 'capture' => FALSE]);
  $void = $client->void(Charge::id($auth), 'other');
  return ['auth_status' => $auth['status'] ?? NULL, 'outcome' => Charge::outcome($auth), 'void_status' => $void['status'] ?? NULL];
});

// 7. Saved cards: the recurring question.
foreach (['plain' => FALSE, 'authorized' => TRUE] as $label => $authorize) {
  $saved = probe("save_card_$label", "saveCard with a $label server token: card verified?", function () use ($env, $client, $authorize, $run, $label) {
    $token = token_id(payarc_test_token($env, $authorize));
    $saved = $client->saveCard($token, ['email' => "probe+$label@example.org", 'name' => 'Sandbox Probe', 'description' => $run]);
    return ['reference' => $saved['reference'], 'card' => $saved['card']];
  });
  if ($saved) {
    probe("charge_saved_$label", "Charge the $label saved card without CVV (recurring flag): approved?", function () use ($client, $saved, $run, $label, &$toVoid) {
      $response = $client->chargeCard($saved['reference'], '1.06', ['reference' => "$run-s-$label", 'recurring' => TRUE]);
      $toVoid[] = Charge::id($response);
      return ['outcome' => Charge::outcome($response), 'id' => Charge::id($response), 'donor' => Charge::approved($response) ? NULL : DonorMessage::fromResponse($response)];
    });
    probe("verify_card_$label", "verifyCard on the $label saved card", function () use ($client, $saved) {
      $auth = $client->verifyCard($saved['reference']);
      return ['outcome' => Charge::outcome($auth), 'void_error' => $auth['void_error'] ?? NULL];
    });
    probe("delete_card_$label", 'deleteCard (DELETE customer)', function () use ($client, $saved) {
      $client->deleteCard($saved['reference']);
      return TRUE;
    });
  }
}

// 8. Decline shapes.
probe('decline_bad_cvv_token', 'Tokenizing with a wrong CVV: where does the decline appear?', function () use ($env) {
  return payarc_test_token($env, TRUE, '4012000098765439', '123');
});
probe('decline_deleted_customer', 'Charging a deleted saved card: error shape', function () use ($client, $report) {
  $reference = $report['experiments']['save_card_plain']['result']['reference'] ?? 'NOPE00000000000';
  return $client->chargeCard($reference, '1.07');
});

// Clean up anything still captured.
foreach (array_filter(array_unique($toVoid)) as $id) {
  try {
    $client->void($id, 'other', 'Sandbox probe cleanup');
  }
  catch (\Throwable $e) {
    $report['cleanup_errors'][$id] = $e->getMessage();
  }
}

$dir = __DIR__ . '/reports';
@mkdir($dir, 0700);
$path = "$dir/$run.json";
file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "\nFull report: $path\n";
