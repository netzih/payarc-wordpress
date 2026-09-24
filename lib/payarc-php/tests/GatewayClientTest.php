<?php

namespace Payarc\Tests;

use PHPUnit\Framework\TestCase;
use Payarc\AmbiguousGatewayException;
use Payarc\Charge;
use Payarc\GatewayClient;
use Payarc\GatewayException;
use Payarc\ReconciliationInconclusiveException;
use Payarc\UnsettledPartialRefundException;

final class GatewayClientTest extends TestCase {

  /**
   * @var array[] Requests the fake transport received.
   */
  private array $requests = [];

  /**
   * @var array[] Queued responses: [status, body].
   */
  private array $responses = [];

  public function testTokenChargeSendsCentsReferenceAndSuppressesReceipts(): void {
    $this->queue(201, ['data' => $this->charge(['id' => 'CH1', 'amount' => 1230])]);

    $charge = $this->client()->chargeToken('tok_123', '12.3', [
      'reference' => 'ab12cd-gf-9-2026-09-23-0',
      'invoice' => 'GF3-9',
      'description' => 'Donation',
      'email' => 'donor@example.org',
      'metadata' => ['contact_id' => 7],
    ]);

    self::assertSame('CH1', $charge['id']);
    $request = $this->requests[0];
    self::assertSame('POST', $request['method']);
    self::assertSame('https://testapi.payarc.net/v1/charges', $request['url']);
    self::assertSame('Bearer secret-token', $this->header($request, 'Authorization'));
    self::assertSame('ab12cd-gf-9-2026-09-23-0', $this->header($request, 'Idempotency-Key'));

    $body = json_decode($request['body'], TRUE);
    self::assertSame(1230, $body['amount']);
    self::assertSame('usd', $body['currency']);
    self::assertSame(1, $body['capture']);
    self::assertSame('tok_123', $body['token_id']);
    self::assertSame('yes', $body['do_not_send_email_to_customer']);
    self::assertSame('yes', $body['do_not_send_sms_to_customer']);
    self::assertSame('donor@example.org', $body['email']);
    self::assertSame('Donation', $body['charge_description']);
    self::assertArrayNotHasKey('description', $body);
    self::assertSame([
      'contact_id' => '7',
      'invoice' => 'GF3-9',
      'reference' => 'ab12cd-gf-9-2026-09-23-0',
      'software' => 'payarc-php/0.1',
    ], json_decode($body['metadata'], TRUE));
  }

  public function testSavedCardChargeSendsCustomerAndCardAndRecurringFlag(): void {
    $this->queue(201, ['data' => $this->charge()]);

    $this->client()->chargeCard('CUS1:CARD1', '5.00', ['recurring' => TRUE]);
    $body = json_decode($this->requests[0]['body'], TRUE);

    self::assertSame('CUS1', $body['customer_id']);
    self::assertSame('CARD1', $body['card_id']);
    self::assertSame('2', $body['eci_indicator']);
    self::assertArrayNotHasKey('token_id', $body);
    self::assertNull($this->header($this->requests[0], 'Idempotency-Key'));
  }

  public function testMetadataKeysAndValuesAreTrimmedToPayarcLimits(): void {
    $this->queue(201, ['data' => $this->charge()]);

    $this->client()->chargeToken('tok', '1', ['metadata' => ['a very long key name indeed' => str_repeat('x', 150), 'empty' => '']]);
    $metadata = json_decode(json_decode($this->requests[0]['body'], TRUE)['metadata'], TRUE);

    self::assertSame(str_repeat('x', 100), $metadata['a_very_long_key_name']);
    self::assertArrayNotHasKey('empty', $metadata);
  }

  public function testZeroAndInvalidAmountsAreRefusedBeforeSending(): void {
    $client = $this->client();
    foreach (['0', '-1', 'ten'] as $amount) {
      try {
        $client->chargeToken('tok', $amount);
        self::fail('Amount ' . $amount . ' was accepted.');
      }
      catch (\InvalidArgumentException $e) {
        self::assertSame([], $this->requests);
      }
    }
  }

  public function testDeclineInsideSuccessfulResponseIsReturnedForTheCallerToRead(): void {
    $this->queue(201, ['data' => $this->charge(['status' => 'Invalid CVV', 'failure_code' => 'D2020', 'failure_message' => 'CVV2 verification failed'])]);

    $charge = $this->client()->chargeToken('tok', '10');

    self::assertSame(Charge::DECLINED, Charge::outcome($charge));
  }

  public function testServerErrorsAndTimeoutsAreAmbiguous(): void {
    foreach ([[500, '{"message":"server error"}'], [0, ''], [408, ''], [429, ''], [200, 'not json']] as [$status, $body]) {
      $this->responses = [[$status, $body]];
      try {
        $this->client()->chargeToken('tok', '10');
        self::fail('HTTP ' . $status . ' was not ambiguous.');
      }
      catch (AmbiguousGatewayException $e) {
        self::assertSame($status, $e->getCode());
      }
    }
  }

  public function testValidationErrorIsDefinitiveAndCarriesFieldMessages(): void {
    $this->queue(422, ['message' => 'The given data was invalid.', 'errors' => ['amount' => ['The amount field is required.']]]);

    try {
      $this->client()->chargeToken('tok', '10');
      self::fail('No exception.');
    }
    catch (AmbiguousGatewayException $e) {
      self::fail('A 422 is not ambiguous.');
    }
    catch (GatewayException $e) {
      self::assertSame(422, $e->getCode());
      self::assertSame('The given data was invalid. The amount field is required.', $e->getMessage());
    }
  }

  public function testUnauthenticatedIsDefinitive(): void {
    $this->queue(401, ['error' => 'Unauthenticated.']);

    $this->expectException(GatewayException::class);
    $this->expectExceptionMessage('Unauthenticated.');
    $this->client()->verifyCredentials();
  }

  public function testSaveCardCreatesCustomerAttachesTokenAndReturnsReference(): void {
    $this->queue(201, ['data' => ['object' => 'customer', 'customer_id' => 'CUS1', 'card' => ['data' => []]]]);
    $this->queue(200, ['data' => ['object' => 'customer', 'customer_id' => 'CUS1', 'card' => ['data' => [
      ['object' => 'Card', 'id' => 'CARD1', 'brand' => 'V', 'last4digit' => '5439', 'exp_month' => '12', 'exp_year' => '2029', 'is_verified' => 1, 'is_default' => 1],
    ]]]]);

    $saved = $this->client()->saveCard('tok_1', ['email' => 'donor@example.org', 'name' => 'Dana Donor', 'zip' => '23220', 'phone' => '(804) 555-1212']);

    self::assertSame('CUS1:CARD1', $saved['reference']);
    self::assertSame('Visa', $saved['card']['brand']);
    self::assertSame('5439', $saved['card']['last4']);
    self::assertTrue($saved['card']['verified']);
    self::assertSame('https://testapi.payarc.net/v1/customers', $this->requests[0]['url']);
    self::assertSame(['email' => 'donor@example.org', 'name' => 'Dana Donor', 'zip' => '23220', 'phone' => '8045551212'], json_decode($this->requests[0]['body'], TRUE));
    self::assertSame('PATCH', $this->requests[1]['method']);
    self::assertSame('https://testapi.payarc.net/v1/customers/CUS1', $this->requests[1]['url']);
    self::assertSame(['token_id' => 'tok_1'], json_decode($this->requests[1]['body'], TRUE));
  }

  public function testSaveCardRequiresEmail(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->client()->saveCard('tok', ['name' => 'No Email']);
  }

  public function testRefusedTokenDeletesTheEmptyCustomer(): void {
    $this->queue(201, ['data' => ['customer_id' => 'CUS1']]);
    $this->queue(409, ['message' => 'Invalid CVV']);
    $this->queue(204, '');

    try {
      $this->client()->saveCard('tok', ['email' => 'donor@example.org']);
      self::fail('No exception.');
    }
    catch (GatewayException $e) {
      self::assertSame('Invalid CVV', $e->getMessage());
    }
    self::assertSame('DELETE', $this->requests[2]['method']);
    self::assertSame('https://testapi.payarc.net/v1/customers/CUS1', $this->requests[2]['url']);
  }

  public function testSaveCardReadsTheCustomerWhenTheUpdateOmitsCards(): void {
    $this->queue(201, ['data' => ['customer_id' => 'CUS1']]);
    $this->queue(200, ['data' => ['customer_id' => 'CUS1']]);
    $this->queue(200, ['data' => ['customer_id' => 'CUS1', 'card' => ['data' => [['id' => 'CARD9', 'brand' => 'M', 'last4digit' => '0055']]]]]);

    $saved = $this->client()->saveCard('tok', ['email' => 'donor@example.org']);

    self::assertSame('CUS1:CARD9', $saved['reference']);
    self::assertSame('GET', $this->requests[2]['method']);
  }

  public function testVerifyCardAuthorizesWithoutCaptureThenVoids(): void {
    $this->queue(201, ['data' => $this->charge(['id' => 'AUTH1', 'amount' => 100, 'status' => 'authorized', 'captured' => 0])]);
    $this->queue(201, ['data' => $this->charge(['id' => 'AUTH1', 'status' => 'void'])]);

    $auth = $this->client()->verifyCard('CUS1:CARD1');

    self::assertTrue(Charge::approved($auth));
    self::assertArrayNotHasKey('void_error', $auth);
    $body = json_decode($this->requests[0]['body'], TRUE);
    self::assertSame(0, $body['capture']);
    self::assertSame(100, $body['amount']);
    self::assertSame('https://testapi.payarc.net/v1/charges/AUTH1/void', $this->requests[1]['url']);
    self::assertSame('other', json_decode($this->requests[1]['body'], TRUE)['reason']);
  }

  public function testVerifyCardReportsAFailedVoid(): void {
    $this->queue(201, ['data' => $this->charge(['id' => 'AUTH1', 'status' => 'authorized'])]);
    $this->queue(409, ['message' => 'Reversal Not Allowed.']);

    $auth = $this->client()->verifyCard('CUS1:CARD1');

    self::assertSame('Reversal Not Allowed.', $auth['void_error']);
  }

  public function testPartialRefundOfASettledChargeSendsCents(): void {
    $this->queue(200, ['data' => $this->charge(['status' => 'settled'])]);
    $this->queue(201, ['data' => $this->charge(['status' => 'partial_refund', 'amount_refunded' => 250])]);

    $result = $this->client()->reverse('CH1', '2.50', ['reference' => 'ref-r1', 'description' => 'Donor asked']);

    self::assertSame('refund', $result['action']);
    self::assertSame('GET', $this->requests[0]['method']);
    self::assertSame('https://testapi.payarc.net/v1/charges/CH1/refunds', $this->requests[1]['url']);
    self::assertSame('ref-r1', $this->header($this->requests[1], 'Idempotency-Key'));
    self::assertSame([
      'reason' => 'requested_by_customer',
      'do_not_send_email_to_customer' => 'yes',
      'do_not_send_sms_to_customer' => 'yes',
      'amount' => 250,
      'description' => 'Donor asked',
    ], json_decode($this->requests[1]['body'], TRUE));
  }

  public function testPartialRefundOfAChargeNotKnownToBeSettledIsNeverSent(): void {
    // PayArc would void the whole charge (verified in the sandbox).
    $this->queue(200, ['data' => $this->charge(['status' => 'submitted_for_settlement'])]);

    try {
      $this->client()->refund('CH1', '4.00');
      self::fail('No exception.');
    }
    catch (UnsettledPartialRefundException $e) {
      self::assertCount(1, $this->requests);
      self::assertSame('GET', $this->requests[0]['method']);
    }
  }

  public function testFullRefundOmitsTheAmountAndReportsAVoid(): void {
    $this->queue(200, ['data' => $this->charge(['status' => 'submitted_for_settlement'])]);
    $this->queue(201, ['data' => $this->charge(['status' => 'void', 'amount_voided' => 1000])]);

    $result = $this->client()->reverse('CH1', '10.00');

    self::assertSame('void', $result['action']);
    self::assertArrayNotHasKey('amount', json_decode($this->requests[1]['body'], TRUE));
  }

  public function testFullRefundOfASettledChargeIsARefund(): void {
    $this->queue(200, ['data' => $this->charge()]);
    $this->queue(201, ['data' => $this->charge(['status' => 'refunded', 'amount_refunded' => 1000])]);

    self::assertSame('refund', $this->client()->reverse('CH1')['action']);
  }

  public function testRemainderAfterAPartialRefundIsSentWithItsAmount(): void {
    $this->queue(200, ['data' => $this->charge(['status' => 'partial_refund', 'amount_refunded' => 400])]);
    $this->queue(201, ['data' => $this->charge(['status' => 'refunded', 'amount_refunded' => 1000])]);

    $this->client()->refund('CH1');

    self::assertSame(600, json_decode($this->requests[1]['body'], TRUE)['amount']);
  }

  public function testRefundOfAReversedChargeIsRefusedWithoutSending(): void {
    // PayArc answers 201 to a refund of a voided charge, so it must not be asked.
    $this->queue(200, ['data' => $this->charge(['status' => 'void', 'amount_voided' => 75])]);

    $this->expectException(GatewayException::class);
    $this->expectExceptionMessage('already been voided or refunded');
    try {
      $this->client()->refund('CH1', '1.00');
    }
    finally {
      self::assertCount(1, $this->requests);
    }
  }

  public function testRefundMoreThanRemainsIsRefused(): void {
    $this->queue(200, ['data' => $this->charge(['status' => 'settled'])]);

    $this->expectException(\InvalidArgumentException::class);
    $this->client()->refund('CH1', '10.01');
  }

  public function testReverseVoidsAfterTheDocumentedRefusal(): void {
    $this->queue(200, ['data' => $this->charge()]);
    $this->queue(422, ['status' => 'error', 'code' => 0, 'message' => 'Return Not Allowed.', 'status_code' => 422]);
    $this->queue(201, ['data' => $this->charge(['status' => 'void'])]);

    $result = $this->client()->reverse('CH1');

    self::assertSame('void', $result['action']);
    self::assertSame('https://testapi.payarc.net/v1/charges/CH1/void', $this->requests[2]['url']);
  }

  public function testReversePassesOnOtherRefundErrors(): void {
    $this->queue(200, ['data' => $this->charge()]);
    $this->queue(422, ['message' => 'The given data was invalid.', 'errors' => ['amount' => ['Too much.']]]);

    $this->expectException(GatewayException::class);
    $this->expectExceptionMessage('Too much.');
    $this->client()->reverse('CH1');
  }

  public function testReverseDoesNotVoidAfterAnAmbiguousRefund(): void {
    $this->queue(200, ['data' => $this->charge()]);
    $this->queue(502, '');

    try {
      $this->client()->reverse('CH1');
      self::fail('No exception.');
    }
    catch (AmbiguousGatewayException $e) {
      self::assertCount(2, $this->requests);
    }
  }

  public function testVoidRejectsUnknownReason(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->client()->void('CH1', 'because');
  }

  public function testFindChargeByReferenceMatchesMetadataAndAmount(): void {
    $now = time();
    $this->queue(200, $this->page([
      $this->charge(['id' => 'OTHER', 'created_at' => $now, 'metadata' => json_encode(['reference' => 'r-2'])]),
      $this->charge(['id' => 'WRONGAMT', 'amount' => 999, 'created_at' => $now, 'metadata' => ['reference' => 'r-1']]),
      $this->charge(['id' => 'HIT', 'amount' => 1000, 'created_at' => $now, 'transaction_metadata' => ['data' => [['key' => 'reference', 'value' => 'r-1']]]]),
    ], 3));

    $found = $this->client()->findChargeByReference('r-1', $now - 60, 5, '10.00');

    self::assertSame('HIT', $found['id']);
    self::assertStringContainsString('include=transaction_metadata', $this->requests[0]['url']);
  }

  public function testFindChargeByReferenceSkipsDeclines(): void {
    $now = time();
    $this->queue(200, $this->page([
      $this->charge(['id' => 'DECLINED', 'created_at' => $now, 'failure_code' => 'D2012', 'metadata' => ['reference' => 'r-1']]),
    ], 1));

    self::assertNull($this->client()->findChargeByReference('r-1', $now));
  }

  public function testFindChargeByReferenceStopsAtTheCutoff(): void {
    $sent = strtotime('2026-09-20 12:00:00 UTC');
    $rows = [];
    for ($i = 0; $i < 100; $i++) {
      $rows[] = $this->charge(['id' => 'C' . $i, 'created_at' => gmdate('Y-m-d H:i:s', $sent - GatewayClient::CREATED_TIME_SLACK - 60 + (100 - $i)), 'metadata' => ['reference' => 'x']]);
    }
    $rows[0]['created_at'] = gmdate('Y-m-d H:i:s', $sent);
    $this->queue(200, $this->page($rows, 50));

    self::assertNull($this->client()->findChargeByReference('r-1', $sent));
    self::assertCount(1, $this->requests);
  }

  public function testFindChargeByReferenceUsesOnlyClockSlackForUnixTimes(): void {
    $sent = 1790224000;
    $rows = [];
    for ($i = 0; $i < 100; $i++) {
      $rows[] = $this->charge(['id' => 'C' . $i, 'created_at' => $sent - GatewayClient::CLOCK_SLACK - 60 - $i, 'metadata' => ['reference' => 'x']]);
    }
    $this->queue(200, $this->page($rows, 50));

    self::assertNull($this->client()->findChargeByReference('r-1', $sent));
    self::assertCount(1, $this->requests);
  }

  public function testFindChargeByReferenceIsInconclusiveWhenPagesRunOut(): void {
    $now = time();
    $rows = array_fill(0, 100, $this->charge(['created_at' => $now, 'metadata' => ['reference' => 'x']]));
    $this->queue(200, $this->page($rows, 9));
    $this->queue(200, $this->page($rows, 9));

    $this->expectException(ReconciliationInconclusiveException::class);
    $this->client()->findChargeByReference('r-1', $now - 60, 2);
  }

  public function testFindChargeByReferenceIsInconclusiveWithoutMetadata(): void {
    $now = time();
    $this->queue(200, $this->page([$this->charge(['created_at' => $now])], 1));

    $this->expectException(ReconciliationInconclusiveException::class);
    $this->client()->findChargeByReference('r-1', $now - 60);
  }

  public function testVerifyClientIdAsksThePortalForAnIframe(): void {
    $this->queue(200, ['payarc-check' => '<iframe>']);
    $this->client()->verifyClientId('abc123', GatewayClient::SANDBOX_PORTAL);
    self::assertStringStartsWith('https://testportal.payarc.net/v1/get-iframe?user=abc123&', $this->requests[0]['url']);
    self::assertNull($this->header($this->requests[0], 'Authorization'));

    $this->queue(403, '<html>');
    $this->expectException(GatewayException::class);
    $this->client()->verifyClientId('wrong', GatewayClient::SANDBOX_PORTAL);
  }

  public function testDeleteCardDeletesItsCustomer(): void {
    $this->queue(204, '');

    $this->client()->deleteCard('CUS1:CARD1');

    self::assertSame('DELETE', $this->requests[0]['method']);
    self::assertSame('https://testapi.payarc.net/v1/customers/CUS1', $this->requests[0]['url']);
  }

  public function testConstructorRequiresTokenAndHttps(): void {
    try {
      new GatewayClient(' ');
      self::fail('Empty token accepted.');
    }
    catch (\InvalidArgumentException $e) {
    }
    $this->expectException(\InvalidArgumentException::class);
    new GatewayClient('token', 'http://testapi.payarc.net/v1');
  }

  public function testIdsWithPathCharactersAreRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->client()->getCharge('../customers');
  }

  private function client(): GatewayClient {
    $this->requests = $this->requests ?? [];
    return new GatewayClient('secret-token', GatewayClient::SANDBOX_URL, function (string $method, string $url, array $headers, ?string $body): array {
      $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
      $next = array_shift($this->responses) ?? [500, ''];
      return ['status' => $next[0], 'body' => $next[1]];
    });
  }

  private function queue(int $status, array|string $body): void {
    $this->responses[] = [$status, is_array($body) ? json_encode($body) : $body];
  }

  private function header(array $request, string $name): ?string {
    foreach ($request['headers'] as $header) {
      if (str_starts_with($header, $name . ': ')) {
        return substr($header, strlen($name) + 2);
      }
    }
    return NULL;
  }

  private function charge(array $overrides = []): array {
    return $overrides + [
      'object' => 'Charge',
      'id' => 'CH1',
      'amount' => 1000,
      'amount_approved' => $overrides['amount'] ?? 1000,
      'amount_refunded' => 0,
      'amount_voided' => 0,
      'status' => 'submitted_for_settlement',
      'auth_code' => 'TAS321',
      'failure_code' => NULL,
      'failure_message' => NULL,
      'created_at' => 1726057812,
      'card' => ['data' => ['object' => 'Card', 'brand' => 'V', 'last4digit' => '5439']],
    ];
  }

  private function page(array $rows, int $totalPages): array {
    return ['data' => $rows, 'meta' => ['pagination' => ['total_pages' => $totalPages]]];
  }

}
