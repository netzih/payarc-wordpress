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
 * Reconcile::once() against a scripted gateway: which requests go out and
 * what the marker holds afterwards, for every way a request can end.
 */
final class ReconcileTest extends TestCase {

  /** @var array<int, array{method: string, url: string, body: ?string}> */
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
    return new GatewayClient('key', 'pin', 'https://sandbox.payarc.com/api/v2', function (string $method, string $url, array $headers, ?string $body) use (&$answers): array {
      $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body];
      $answer = array_shift($answers) ?? ['status' => 200, 'body' => []];
      return ['status' => $answer['status'], 'body' => json_encode($answer['body'])];
    });
  }

  private function store(): array {
    return [fn() => $this->marker, function (?array $marker): void { $this->marker = $marker; }];
  }

  private function sale(): callable {
    return static fn(GatewayClient $c) => $c->saleWithCardReference('card-ref', '2.00', ['orderid' => 'abc-wc-1']);
  }

  public function testApprovalKeepsTheMarkerForTheCaller(): void {
    $client = $this->client([['status' => 200, 'body' => ['result_code' => 'A', 'key' => 'new']]]);
    [$read, $write] = $this->store();

    $result = Reconcile::once($client, $read, $write, 'abc-wc-1', '2.00', $this->sale());

    self::assertSame('new', $result['response']['key']);
    self::assertFalse($result['reconciled']);
    self::assertCount(1, $this->requests);
    self::assertSame('abc-wc-1', $this->marker['orderid']);
    self::assertSame('2.00', $this->marker['amount']);
  }

  public function testDeclineClearsTheMarker(): void {
    $client = $this->client([['status' => 200, 'body' => ['result_code' => 'D', 'error' => 'Card Declined']]]);
    [$read, $write] = $this->store();

    $result = Reconcile::once($client, $read, $write, 'abc-wc-1', '2.00', $this->sale());

    self::assertSame('D', $result['response']['result_code']);
    self::assertNull($this->marker);
  }

  public function testRejectedRequestClearsTheMarker(): void {
    $client = $this->client([['status' => 400, 'body' => ['error' => 'Invalid amount']]]);
    [$read, $write] = $this->store();

    try {
      Reconcile::once($client, $read, $write, 'abc-wc-1', '2.00', $this->sale());
      self::fail('Expected a GatewayException.');
    }
    catch (GatewayException $e) {
      self::assertNull($this->marker);
    }
  }

  public function testLostAnswerKeepsTheMarkerWhenTheChargeIsNotListedYet(): void {
    $client = $this->client([
      ['status' => 0, 'body' => []],
      ['status' => 200, 'body' => ['type' => 'list', 'data' => [['key' => 'x', 'orderid' => 'other', 'created' => '2020-01-01 00:00:00']]]],
    ]);
    [$read, $write] = $this->store();

    try {
      Reconcile::once($client, $read, $write, 'abc-wc-1', '2.00', $this->sale());
      self::fail('Expected an AmbiguousGatewayException.');
    }
    catch (AmbiguousGatewayException $e) {
      self::assertSame('abc-wc-1', $this->marker['orderid']);
      self::assertCount(2, $this->requests);
    }
  }

  public function testLostAnswerIsRecoveredFromTheListing(): void {
    $client = $this->client([
      ['status' => 0, 'body' => []],
      ['status' => 200, 'body' => ['type' => 'list', 'data' => [['key' => 'found', 'orderid' => 'abc-wc-1', 'trantype_code' => 'S', 'amount' => '2.00', 'result_code' => 'A']]]],
    ]);
    [$read, $write] = $this->store();

    $result = Reconcile::once($client, $read, $write, 'abc-wc-1', '2.00', $this->sale());

    self::assertTrue($result['reconciled']);
    self::assertSame('found', $result['response']['key']);
  }

  public function testExistingMarkerIsLookedUpBeforeAnythingIsSent(): void {
    $this->marker = ['orderid' => 'abc-wc-1', 'sent_at' => time() - 600, 'amount' => '2.00'];
    $client = $this->client([
      ['status' => 200, 'body' => ['type' => 'list', 'data' => [['key' => 'earlier', 'orderid' => 'abc-wc-1', 'trantype_code' => 'S', 'amount' => '2.00', 'result_code' => 'A']]]],
    ]);
    [$read, $write] = $this->store();

    $result = Reconcile::once($client, $read, $write, 'abc-wc-1', '2.00', $this->sale());

    self::assertTrue($result['reconciled']);
    self::assertSame('earlier', $result['response']['key']);
    self::assertCount(1, $this->requests);
    self::assertStringContainsString('/transactions?limit=100', $this->requests[0]['url']);
  }

  public function testExistingMarkerWithAProvableMissLetsTheChargeGoOut(): void {
    $this->marker = ['orderid' => 'abc-wc-1', 'sent_at' => time() - 600, 'amount' => '2.00'];
    $client = $this->client([
      ['status' => 200, 'body' => ['type' => 'list', 'data' => [['key' => 'old', 'orderid' => 'other', 'created' => '2020-01-01 00:00:00']]]],
      ['status' => 200, 'body' => ['result_code' => 'A', 'key' => 'new']],
    ]);
    [$read, $write] = $this->store();

    $result = Reconcile::once($client, $read, $write, 'abc-wc-1', '2.00', $this->sale());

    self::assertFalse($result['reconciled']);
    self::assertSame('new', $result['response']['key']);
    self::assertCount(2, $this->requests);
  }

  public function testExistingMarkerWithAnInconclusiveLookupSendsNothing(): void {
    $this->marker = ['orderid' => 'abc-wc-1', 'sent_at' => time() - 600, 'amount' => '2.00'];
    $full = array_fill(0, 100, ['key' => 'k', 'orderid' => 'other', 'created' => gmdate('Y-m-d H:i:s')]);
    $client = $this->client(array_fill(0, 5, ['status' => 200, 'body' => ['type' => 'list', 'data' => $full]]));
    [$read, $write] = $this->store();

    try {
      Reconcile::once($client, $read, $write, 'abc-wc-1', '2.00', $this->sale());
      self::fail('Expected the lookup to be inconclusive.');
    }
    catch (ReconciliationInconclusiveException $e) {
      self::assertCount(5, $this->requests);
      foreach ($this->requests as $request) {
        self::assertSame('GET', $request['method']);
      }
      self::assertSame('abc-wc-1', $this->marker['orderid']);
    }
  }

  public function testExistingMarkerLooksUpTheAmountThatWasSentThen(): void {
    $this->marker = ['orderid' => 'abc-wc-1', 'sent_at' => time() - 600, 'amount' => '5.00'];
    $client = $this->client([
      ['status' => 200, 'body' => ['type' => 'list', 'data' => [['key' => 'earlier', 'orderid' => 'abc-wc-1', 'trantype_code' => 'C', 'amount' => '5.00', 'result_code' => 'A']]]],
    ]);
    [$read, $write] = $this->store();

    $result = Reconcile::once($client, $read, $write, 'abc-wc-1', '7.00', static fn(GatewayClient $c) => $c->refund('trankey', '7.00'), GatewayClient::TYPES_REFUND);

    self::assertTrue($result['reconciled']);
    self::assertSame('5.00', $result['amount']);
  }

  public function testConcurrentRequestForTheSameOrderIdSendsNothing(): void {
    $client = $this->client([['status' => 200, 'body' => ['result_code' => 'A', 'key' => 'new']]]);
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

    // Released: the next request goes through and leaves its lock behind.
    $result = Reconcile::once($client, $read, $write, 'abc-wc-1', '2.00', $this->sale());
    self::assertSame('new', $result['response']['key']);
    self::assertNotNull(Lock::acquire('reconcile_' . md5('abc-wc-1'), 300));
  }

  public function testAMarkerThatCannotBeStoredStopsTheRequest(): void {
    $client = $this->client([['status' => 200, 'body' => ['result_code' => 'A', 'key' => 'new']]]);
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

  public function testRefundRemembersTheRefundsThatExistedBeforeIt(): void {
    $client = $this->client([
      // The snapshot of refunds the sale already has.
      ['status' => 200, 'body' => ['type' => 'list', 'data' => [
        ['key' => 'r1', 'orderid' => 'abc-wc-1', 'trantype_code' => 'C', 'amount' => '5.00', 'result_code' => 'A', 'created' => gmdate('Y-m-d H:i:s')],
        ['key' => 'sale', 'orderid' => 'abc-wc-1', 'trantype_code' => 'S', 'amount' => '20.00', 'result_code' => 'A', 'created' => gmdate('Y-m-d H:i:s')],
      ]]],
      ['status' => 200, 'body' => ['result_code' => 'A', 'key' => 'r2']],
    ]);
    [$read, $write] = $this->store();

    $result = Reconcile::once($client, $read, $write, 'abc-wc-1', '5.00', static fn(GatewayClient $c) => $c->refund('sale', '5.00'), GatewayClient::TYPES_REFUND);

    self::assertSame('r2', $result['response']['key']);
    self::assertSame(['r1'], $this->marker['exclude']);
    self::assertCount(2, $this->requests);
    self::assertSame('GET', $this->requests[0]['method']);
    self::assertSame('POST', $this->requests[1]['method']);
  }

  public function testRepeatedEqualRefundIsNotReconciledToTheEarlierOne(): void {
    // A second $5 refund was sent and its answer lost; only r1 existed before.
    $this->marker = ['orderid' => 'abc-wc-1', 'sent_at' => time() - 600, 'amount' => '5.00', 'exclude' => ['r1']];
    $listing = ['type' => 'list', 'data' => [
      ['key' => 'r1', 'orderid' => 'abc-wc-1', 'trantype_code' => 'C', 'amount' => '5.00', 'result_code' => 'A', 'created' => gmdate('Y-m-d H:i:s', time() - 900)],
      ['key' => 'old', 'orderid' => 'other', 'created' => '2020-01-01 00:00:00'],
    ]];
    $client = $this->client([
      ['status' => 200, 'body' => $listing],
      ['status' => 200, 'body' => $listing],
      ['status' => 200, 'body' => ['result_code' => 'A', 'key' => 'r2']],
    ]);
    [$read, $write] = $this->store();

    $result = Reconcile::once($client, $read, $write, 'abc-wc-1', '5.00', static fn(GatewayClient $c) => $c->refund('sale', '5.00'), GatewayClient::TYPES_REFUND);

    // r1 is excluded, so the lost refund provably never happened: sent again.
    self::assertFalse($result['reconciled']);
    self::assertSame('r2', $result['response']['key']);
    self::assertSame(['r1'], $this->marker['exclude']);
  }

  public function testRepeatedEqualRefundThatWentThroughIsFound(): void {
    $this->marker = ['orderid' => 'abc-wc-1', 'sent_at' => time() - 600, 'amount' => '5.00', 'exclude' => ['r1']];
    $client = $this->client([
      ['status' => 200, 'body' => ['type' => 'list', 'data' => [
        ['key' => 'r2', 'orderid' => 'abc-wc-1', 'trantype_code' => 'C', 'amount' => '5.00', 'result_code' => 'A', 'created' => gmdate('Y-m-d H:i:s', time() - 500)],
        ['key' => 'r1', 'orderid' => 'abc-wc-1', 'trantype_code' => 'C', 'amount' => '5.00', 'result_code' => 'A', 'created' => gmdate('Y-m-d H:i:s', time() - 900)],
      ]]],
    ]);
    [$read, $write] = $this->store();

    $result = Reconcile::once($client, $read, $write, 'abc-wc-1', '5.00', static fn(GatewayClient $c) => $c->refund('sale', '5.00'), GatewayClient::TYPES_REFUND);

    self::assertTrue($result['reconciled']);
    self::assertSame('r2', $result['response']['key']);
    self::assertCount(1, $this->requests);
  }

}
