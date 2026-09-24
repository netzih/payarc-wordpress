<?php
/**
 * Receives a Hosted Fields token from index.php and runs it through the
 * library. Every charge is voided. Appends the result to
 * tests/sandbox/reports/hosted-fields.jsonl.
 */
require_once dirname(__DIR__) . '/lib.php';

use Payarc\Charge;
use Payarc\GatewayException;

header('Content-Type: application/json');
$env = payarc_env();
$client = payarc_client($env);
$input = json_decode(file_get_contents('php://input'), TRUE) ?: [];
$tokenResponse = $input['tokenResponse'] ?? [];
$token = is_array($tokenResponse) ? (string) ($tokenResponse['token'] ?? $tokenResponse['id'] ?? $tokenResponse['data']['id'] ?? '') : '';
$run = 'hf-' . gmdate('YmdHis');
$result = ['run' => $run, 'action' => $input['action'] ?? NULL, 'token_response' => $tokenResponse, 'steps' => []];

$step = function (string $name, callable $fn) use (&$result) {
  try {
    $value = $fn();
    $result['steps'][$name] = ['ok' => TRUE, 'result' => $value];
    return $value;
  }
  catch (\Throwable $e) {
    $result['steps'][$name] = ['ok' => FALSE, 'class' => get_class($e), 'code' => $e->getCode(), 'message' => $e->getMessage(), 'data' => $e instanceof GatewayException ? $e->getResponseData() : NULL];
    return NULL;
  }
};

if ($token === '') {
  $result['error'] = 'No token found in the Hosted Fields response.';
}
elseif (($input['action'] ?? '') === 'charge') {
  $charge = $step('charge_token', fn() => $client->chargeToken($token, '1.10', ['reference' => $run, 'description' => 'Hosted Fields probe']));
  if ($charge && Charge::id($charge) !== '') {
    $step('summary', fn() => ['outcome' => Charge::outcome($charge), 'card' => \Payarc\CardDetails::fromResponse($charge)]);
    $step('void', fn() => $client->void(Charge::id($charge), 'other')['status'] ?? NULL);
  }
}
elseif (($input['action'] ?? '') === 'save') {
  $saved = $step('save_card', fn() => $client->saveCard($token, ['email' => 'probe+hf@example.org', 'name' => 'Hosted Fields Probe', 'description' => $run]));
  if ($saved) {
    $step('card', fn() => $saved['card']);
    $charge = $step('charge_saved_card_no_cvv', fn() => $client->chargeCard($saved['reference'], '1.11', ['reference' => $run . '-s', 'recurring' => TRUE]));
    if ($charge) {
      $step('outcome', fn() => Charge::outcome($charge));
      if (Charge::id($charge) !== '') {
        $step('void', fn() => $client->void(Charge::id($charge), 'other')['status'] ?? NULL);
      }
    }
    $step('verify_card', fn() => array_intersect_key($client->verifyCard($saved['reference']), array_flip(['id', 'status', 'failure_code', 'failure_message', 'void_error'])));
    $step('delete_card', function () use ($client, $saved) {
      $client->deleteCard($saved['reference']);
      return TRUE;
    });
  }
}

@mkdir(dirname(__DIR__) . '/reports', 0700);
file_put_contents(dirname(__DIR__) . '/reports/hosted-fields.jsonl', json_encode($result, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
