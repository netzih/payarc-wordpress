<?php

namespace Payarc\WordPress\Tests;

use PHPUnit\Framework\TestCase;
use Payarc\AmbiguousGatewayException;
use Payarc\GatewayClient;
use Payarc\GatewayException;
use Payarc\ReconciliationInconclusiveException;
use Payarc\WordPress\BusyException;
use Payarc\WordPress\Lock;
use Payarc\WordPress\Reconcile;

/**
 * Reconcile::once() against a scripted gateway: which requests go out, with
 * which idempotency key, and what the marker holds afterwards.
 */
final class ReconcileTest extends TestCase {

  /** @var array<int, array{method: string, url: string, body: ?string, key: ?string}> */
  private array $requests = [];

  private ?array $marker = NULL;

  protected function setUp(): void {
    Lock::useMemory();
  }

  protected function tearDown(): void {
    Lock::useMemory(FALSE);
  }

  /**
   * @param array<int, array{status: int, body: array}> $answers
   *   Answers in order; a status of 0 simulates a dropped connection.
   */
  private function client(array $answers): GatewayClient {
    $this->requests = [];
    return new GatewayClient('token', GatewayClient::SANDBOX_URL, function (string $method, string $url, array $headers, ?string $body) use (&$answers): array {
      $key = NULL;
      foreach ($headers as $header) {
        if (str_starts_with($header, 'Idempotency-Key: ')) {
          $key = substr($header, 17);
        }
      }
      $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body, 'key' => $key];
      $answer = array_shift($answers) ?? ['status' => 500, 'body' => []];
      return ['status' => $answer['status'], 'body' => json_encode($answer['body'])];
    });
  }

  private function store(): array {
    return [fn() => $this->marker, function (?array $marker): void { $this->marker = $marker; }];
  }

  private function sale(): callable {
    return static fn(GatewayClient $c, string $key) => $c->chargeCard('CUS:CARD', '2.00', ['reference' => $key]);
  }

  private static function charge(array $fields = []): array {
    return ['status' => 201, 'body' => ['data' => $fields + ['object' => 'Charge', 'id' => 'CH1', 'amount' => 200, 'amount_approved' => 200, 'status' => 'submitted_for_settlement', 'failure_code' => NULL, 'created_at' => time()]]];
  }

  public function testApprovalKeepsTheMarkerAndSendsItsKey(): void {
    $client = $this->client([self::charge(['id' => 'NEW'])]);
    [$read, $write] = $this->store();

    $result = Reconcile::once($client, $read, $write, 'abc-wc-1', '2.00', $this->sale());

    self::assertSame('NEW', $result['response']['id']);
    self::assertFalse($result['reconciled']);
    self::assertCount(1, $this->requests);
    self::assertMatchesRegularExpression('/^abc-wc-1\.[0-9a-f]{6}$/', $this->marker['key']);
    self::assertSame($this->marker['key'], $this->requests[0]['key']);
    self::assertSame('2.00', $this->marker['amount']);
  }

  public function testDeclineClearsTheMarkerSoTheNextAttemptGetsANewKey(): void {
    $client = $this->client([
      self::charge(['status' => 'Declined', 'failure_code' => 'D2026', 'failure_message' => 'Do not honor']),
      self::charge(['id' => 'NEW']),
    ]);
    [$read, $write] = $this->store();

    $first = Reconcile::once($client, $read, $write, 'abc-wc-1', '2.00', $this->sale());
    self::assertSame('D2026', $first['response']['failure_code']);
    self::assertNull($this->marker);

    Reconcile::once($client, $read, $write, 'abc-wc-1', '2.00', $this->sale());
    self::assertNotSame($this->requests[0]['key'], $this->requests[1]['key']);
  }

  public function testRejectedRequestClearsTheMarker(): void {
    $client = $this->client([['status' => 404, 'body' => ['message' => 'The requested token_id is not valid or already used']]]);
    [$read, $write] = $this->store();

    try {
      Reconcile::once($client, $read, $write, 'abc-wc-1', '2.00', $this->sale());
      self::fail('Expected a GatewayException.');
    }
    catch (GatewayException $e) {
      self::assertNull($this->marker);
    }
  }

  public function testLostAnswerIsResentAtOnceWithTheSameKey(): void {
    $client = $this->client([['status' => 0, 'body' => []], self::charge(['id' => 'FOUND'])]);
    [$read, $write] = $this->store();

    $result = Reconcile::once($client, $read, $write, 'abc-wc-1', '2.00', $this->sale());

    self::assertSame('FOUND', $result['response']['id']);
    self::assertCount(2, $this->requests);
    self::assertSame($this->requests[0]['key'], $this->requests[1]['key']);
  }

  public function testTwiceLostAnswerKeepsTheMarker(): void {
    $client = $this->client([['status' => 0, 'body' => []], ['status' => 502, 'body' => []]]);
    [$read, $write] = $this->store();

    try {
      Reconcile::once($client, $read, $write, 'abc-wc-1', '2.00', $this->sale());
      self::fail('Expected an AmbiguousGatewayException.');
    }
    catch (AmbiguousGatewayException $e) {
      self::assertSame('abc-wc-1', $this->marker['orderid']);
    }
  }

  public function testUnknownStatusIsAmbiguousAndKeepsTheMarker(): void {
    // D0001: "Duplicate Request (Approved previously)" is not a decline.
    $client = $this->client([self::charge(['status' => 'Duplicate', 'failure_code' => 'D0001'])]);
    [$read, $write] = $this->store();

    $this->expectException(AmbiguousGatewayException::class);
    try {
      Reconcile::once($client, $read, $write, 'abc-wc-1', '2.00', $this->sale());
    }
    finally {
      self::assertNotNull($this->marker);
    }
  }

  public function testPartialApprovalIsVoidedAndReportedAsFailed(): void {
    $client = $this->client([
      self::charge(['id' => 'PART', 'amount_approved' => 100]),
      self::charge(['id' => 'PART', 'status' => 'void']),
    ]);
    [$read, $write] = $this->store();

    $result = Reconcile::once($client, $read, $write, 'abc-wc-1', '2.00', $this->sale());

    self::assertSame('PARTIAL', $result['response']['failure_code']);
    self::assertStringEndsWith('/charges/PART/void', $this->requests[1]['url']);
    self::assertNull($this->marker);
  }

  public function testRecentMarkerIsReplayedWithItsKey(): void {
    $this->marker = ['orderid' => 'abc-wc-1', 'key' => 'abc-wc-1.aaaaaa', 'sent_at' => time() - 600, 'amount' => '2.00', 'kind' => Reconcile::CHARGE];
    $client = $this->client([self::charge(['id' => 'EARLIER', 'created_at' => time() - 600])]);
    [$read, $write] = $this->store();

    $result = Reconcile::once($client, $read, $write, 'abc-wc-1', '2.00', $this->sale());

    self::assertTrue($result['reconciled']);
    self::assertSame('EARLIER', $result['response']['id']);
    self::assertCount(1, $this->requests);
    self::assertSame('abc-wc-1.aaaaaa', $this->requests[0]['key']);
  }

  public function testOldMarkerIsLookedUpBeforeAnythingIsSent(): void {
    $sent = time() - 2 * Reconcile::REPLAY_WINDOW;
    $this->marker = ['orderid' => 'abc-wc-1', 'key' => 'abc-wc-1.aaaaaa', 'sent_at' => $sent, 'amount' => '2.00', 'kind' => Reconcile::CHARGE];
    $client = $this->client([
      ['status' => 200, 'body' => ['data' => [
        ['id' => 'EARLIER', 'amount' => 200, 'status' => 'submitted_for_settlement', 'created_at' => $sent + 1, 'transaction_metadata' => ['data' => [['key' => 'reference', 'value' => 'abc-wc-1.aaaaaa']]]],
      ], 'meta' => ['pagination' => ['total_pages' => 1]]]],
    ]);
    [$read, $write] = $this->store();

    $result = Reconcile::once($client, $read, $write, 'abc-wc-1', '2.00', $this->sale());

    self::assertTrue($result['reconciled']);
    self::assertSame('EARLIER', $result['response']['id']);
    self::assertCount(1, $this->requests);
    self::assertSame('GET', $this->requests[0]['method']);
  }

  public function testOldMarkerWithAProvableMissSendsWithANewKey(): void {
    $sent = time() - 2 * Reconcile::REPLAY_WINDOW;
    $this->marker = ['orderid' => 'abc-wc-1', 'key' => 'abc-wc-1.aaaaaa', 'sent_at' => $sent, 'amount' => '2.00', 'kind' => Reconcile::CHARGE];
    $client = $this->client([
      ['status' => 200, 'body' => ['data' => [
        ['id' => 'OLD', 'amount' => 200, 'status' => 'submitted_for_settlement', 'created_at' => $sent - 3600, 'transaction_metadata' => ['data' => []]],
      ], 'meta' => ['pagination' => ['total_pages' => 9]]]],
      self::charge(['id' => 'NEW']),
    ]);
    [$read, $write] = $this->store();

    $result = Reconcile::once($client, $read, $write, 'abc-wc-1', '2.00', $this->sale());

    self::assertFalse($result['reconciled']);
    self::assertSame('NEW', $result['response']['id']);
    self::assertNotSame('abc-wc-1.aaaaaa', $this->requests[1]['key']);
  }

  public function testOldMarkerWithAnInconclusiveLookupSendsNothing(): void {
    $this->marker = ['orderid' => 'abc-wc-1', 'key' => 'abc-wc-1.aaaaaa', 'sent_at' => time() - 2 * Reconcile::REPLAY_WINDOW, 'amount' => '2.00', 'kind' => Reconcile::CHARGE];
    $row = ['id' => 'X', 'amount' => 200, 'status' => 'submitted_for_settlement', 'created_at' => time(), 'transaction_metadata' => ['data' => []]];
    $client = $this->client(array_fill(0, 5, ['status' => 200, 'body' => ['data' => array_fill(0, 100, $row), 'meta' => ['pagination' => ['total_pages' => 99]]]]));
    [$read, $write] = $this->store();

    try {
      Reconcile::once($client, $read, $write, 'abc-wc-1', '2.00', $this->sale());
      self::fail('Expected the lookup to be inconclusive.');
    }
    catch (ReconciliationInconclusiveException $e) {
      self::assertCount(5, $this->requests);
      self::assertSame('abc-wc-1.aaaaaa', $this->marker['key']);
    }
  }

  public function testRefundSnapshotsTheSaleAndSendsItsKey(): void {
    $client = $this->client([
      // Reconcile's snapshot, then refund()'s own read, then the refund.
      self::charge(['id' => 'SALE', 'amount' => 2000, 'status' => 'settled', 'amount_refunded' => 500]),
      self::charge(['id' => 'SALE', 'amount' => 2000, 'status' => 'settled', 'amount_refunded' => 500]),
      self::charge(['id' => 'SALE', 'amount' => 2000, 'status' => 'partial_refund', 'amount_refunded' => 1000]),
    ]);
    [$read, $write] = $this->store();

    $result = Reconcile::once($client, $read, $write, 'abc-wc-1-refund', '5.00', static fn(GatewayClient $c, string $key) => $c->refund('SALE', '5.00', ['reference' => $key]), Reconcile::REFUND, 'SALE');

    self::assertSame('partial_refund', $result['response']['status']);
    self::assertSame(500, $this->marker['refunded_before']);
    self::assertSame(1500, $this->marker['remaining_before']);
    self::assertSame($this->marker['key'], $this->requests[2]['key']);
  }

  public function testLostRefundThatWentThroughIsFoundOnTheSale(): void {
    $this->marker = ['orderid' => 'abc-wc-1-refund', 'key' => 'k.aaaaaa', 'sent_at' => time() - 86400, 'amount' => '5.00', 'kind' => Reconcile::REFUND, 'charge_id' => 'SALE', 'refunded_before' => 500, 'remaining_before' => 1500];
    $client = $this->client([self::charge(['id' => 'SALE', 'amount' => 2000, 'status' => 'partial_refund', 'amount_refunded' => 1000])]);
    [$read, $write] = $this->store();

    $result = Reconcile::once($client, $read, $write, 'abc-wc-1-refund', '5.00', static fn(GatewayClient $c, string $key) => $c->refund('SALE', '5.00', ['reference' => $key]), Reconcile::REFUND, 'SALE');

    self::assertTrue($result['reconciled']);
    self::assertCount(1, $this->requests);
  }

  public function testLostRefundThatVoidedTheSaleIsFound(): void {
    $this->marker = ['orderid' => 'r', 'key' => 'k.aaaaaa', 'sent_at' => time() - 60, 'amount' => '20.00', 'kind' => Reconcile::REFUND, 'charge_id' => 'SALE', 'refunded_before' => 0, 'remaining_before' => 2000];
    $client = $this->client([self::charge(['id' => 'SALE', 'amount' => 2000, 'status' => 'void', 'amount_voided' => 2000])]);
    [$read, $write] = $this->store();

    $result = Reconcile::once($client, $read, $write, 'r', '20.00', static fn(GatewayClient $c, string $key) => $c->refund('SALE', NULL, ['reference' => $key]), Reconcile::REFUND, 'SALE');

    self::assertTrue($result['reconciled']);
  }

  public function testLostRefundThatNeverHappenedIsSentAgain(): void {
    $this->marker = ['orderid' => 'r', 'key' => 'k.aaaaaa', 'sent_at' => time() - 60, 'amount' => '5.00', 'kind' => Reconcile::REFUND, 'charge_id' => 'SALE', 'refunded_before' => 0, 'remaining_before' => 2000];
    $unchanged = self::charge(['id' => 'SALE', 'amount' => 2000, 'status' => 'settled', 'amount_refunded' => 0]);
    $client = $this->client([$unchanged, $unchanged, $unchanged, self::charge(['id' => 'SALE', 'amount' => 2000, 'status' => 'partial_refund', 'amount_refunded' => 500])]);
    [$read, $write] = $this->store();

    $result = Reconcile::once($client, $read, $write, 'r', '5.00', static fn(GatewayClient $c, string $key) => $c->refund('SALE', '5.00', ['reference' => $key]), Reconcile::REFUND, 'SALE');

    self::assertFalse($result['reconciled']);
    self::assertSame('POST', $this->requests[3]['method']);
    self::assertNotSame('k.aaaaaa', $this->requests[3]['key']);
  }

  public function testUnsettledPartialRefundClearsTheMarkerAndSendsNothing(): void {
    $open = self::charge(['id' => 'SALE', 'amount' => 2000, 'status' => 'submitted_for_settlement']);
    $client = $this->client([$open, $open]);
    [$read, $write] = $this->store();

    try {
      Reconcile::once($client, $read, $write, 'r', '5.00', static fn(GatewayClient $c, string $key) => $c->refund('SALE', '5.00', ['reference' => $key]), Reconcile::REFUND, 'SALE');
      self::fail('Expected an UnsettledPartialRefundException.');
    }
    catch (\Payarc\UnsettledPartialRefundException $e) {
      self::assertNull($this->marker);
      foreach ($this->requests as $request) {
        self::assertSame('GET', $request['method']);
      }
    }
  }

  public function testConcurrentRequestForTheSameOrderIdSendsNothing(): void {
    $client = $this->client([self::charge(['id' => 'NEW'])]);
    [$read, $write] = $this->store();
    $held = Lock::acquire('reconcile_' . md5('abc-wc-1'), 300);
    self::assertNotNull($held);

    try {
      Reconcile::once($client, $read, $write, 'abc-wc-1', '2.00', $this->sale());
      self::fail('Expected a BusyException.');
    }
    catch (BusyException $e) {
      self::assertCount(0, $this->requests);
      self::assertNull($this->marker);
    }
    Lock::release('reconcile_' . md5('abc-wc-1'), $held);

    $result = Reconcile::once($client, $read, $write, 'abc-wc-1', '2.00', $this->sale());
    self::assertSame('NEW', $result['response']['id']);
    self::assertNotNull(Lock::acquire('reconcile_' . md5('abc-wc-1'), 300));
  }

  public function testAMarkerThatCannotBeStoredStopsTheRequest(): void {
    $client = $this->client([self::charge()]);
    $read = static fn() => NULL;
    $write = static function (?array $marker): void {};

    try {
      Reconcile::once($client, $read, $write, 'abc-wc-1', '2.00', $this->sale());
      self::fail('Expected the request to be refused.');
    }
    catch (\RuntimeException $e) {
      self::assertNotInstanceOf(GatewayException::class, $e);
      self::assertCount(0, $this->requests);
    }
  }

  public function testInspectNeverSends(): void {
    $this->marker = ['orderid' => 'abc-wc-1', 'key' => 'abc-wc-1.aaaaaa', 'sent_at' => time() - 60, 'amount' => '2.00', 'kind' => Reconcile::CHARGE];
    $client = $this->client([['status' => 200, 'body' => ['data' => [], 'meta' => ['pagination' => ['total_pages' => 1]]]]]);

    self::assertSame('absent', Reconcile::inspect($client, $this->marker)['state']);
    self::assertSame('GET', $this->requests[0]['method']);
  }

}
