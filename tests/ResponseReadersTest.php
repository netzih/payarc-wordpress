<?php

namespace Payarc\Tests;

use PHPUnit\Framework\TestCase;
use Payarc\Amount;
use Payarc\CardDetails;
use Payarc\CardReference;
use Payarc\Charge;
use Payarc\DonorMessage;

final class ResponseReadersTest extends TestCase {

  public function testOutcomes(): void {
    self::assertSame(Charge::APPROVED, Charge::outcome(['status' => 'submitted_for_settlement']));
    self::assertSame(Charge::APPROVED, Charge::outcome(['data' => ['object' => 'Charge', 'id' => 'x', 'status' => 'authorized']]));
    self::assertSame(Charge::DECLINED, Charge::outcome(['status' => 'Invalid CVV', 'failure_code' => 'D2020']));
    self::assertSame(Charge::DECLINED, Charge::outcome(['status' => 'failed_by_gateway']));
    self::assertSame(Charge::REVERSED, Charge::outcome(['status' => 'void']));
    self::assertSame(Charge::REVERSED, Charge::outcome(['status' => 'partial_refund']));
    self::assertSame(Charge::PARTIAL, Charge::outcome(['status' => 'submitted_for_settlement', 'amount' => 1000, 'amount_approved' => 400]));
    self::assertSame(Charge::PARTIAL, Charge::outcome(['status' => 'submitted_for_settlement', 'tsys_response_code' => 'A0002']));
    self::assertSame(Charge::UNKNOWN, Charge::outcome(['status' => 'something_new']));
    self::assertSame(Charge::UNKNOWN, Charge::outcome([]));
  }

  public function testDuplicateOfAnApprovedRequestIsNotADecline(): void {
    self::assertSame(Charge::UNKNOWN, Charge::outcome(['status' => 'Duplicate', 'failure_code' => 'D0001']));
    self::assertSame(Charge::DECLINED, Charge::outcome(['status' => 'Duplicate', 'failure_code' => 'D0003']));
  }

  public function testRemainingAndCreatedTime(): void {
    self::assertSame(600, Charge::remainingCents(['amount' => 1000, 'amount_refunded' => 400, 'status' => 'partial_refund']));
    // PayArc records only the asked-for amount in amount_voided but voids everything.
    self::assertSame(0, Charge::remainingCents(['amount' => 200, 'amount_voided' => 75, 'status' => 'void']));
    self::assertTrue(Charge::isSettled(['status' => 'settled']));
    self::assertFalse(Charge::isSettled(['status' => 'submitted_for_settlement']));
    self::assertSame(1726057812, Charge::createdTime(['created_at' => 1726057812]));
    self::assertSame(strtotime('2018-11-26 14:06:54 UTC'), Charge::createdTime(['created_at' => '2018-11-26 14:06:54']));
    self::assertNull(Charge::createdTime([]));
  }

  public function testMetadataShapes(): void {
    self::assertSame(['a' => '1'], Charge::metadata(['metadata' => '{"a":"1"}']));
    self::assertSame(['a' => '1'], Charge::metadata(['metadata' => ['a' => 1]]));
    self::assertSame(['a' => '1'], Charge::metadata(['transaction_metadata' => ['data' => [['key' => 'a', 'value' => '1']]]]));
    self::assertSame(['a' => '1'], Charge::metadata(['extra_metadata' => [['a' => '1']]]));
    self::assertFalse(Charge::hasMetadata(['id' => 'x']));
  }

  public function testCardDetailsFromChargeAndCustomer(): void {
    $charge = ['data' => ['object' => 'Charge', 'card' => ['data' => ['object' => 'Card', 'id' => 'C1', 'brand' => 'X', 'last4digit' => '2376', 'exp_month' => '5', 'exp_year' => '29', 'is_verified' => 0]]]];
    self::assertSame(['brand' => 'Amex', 'last4' => '2376', 'exp_month' => '05', 'exp_year' => '2029', 'card_id' => 'C1', 'verified' => FALSE], CardDetails::fromResponse($charge));

    $customer = ['card' => ['data' => [
      ['id' => 'OLD', 'brand' => 'V', 'last4digit' => '1111', 'created_at' => 1],
      ['id' => 'NEW', 'brand' => 'R', 'last4digit' => '6909', 'created_at' => 2],
    ]]];
    self::assertSame('NEW', CardDetails::fromResponse($customer)['card_id']);
    self::assertSame('Discover', CardDetails::fromResponse($customer)['brand']);
    self::assertSame('MasterCard', CardDetails::fromResponse(['card' => ['data' => ['first6digit' => 222300, 'last4digit' => '0011']]])['brand']);
    self::assertNull(CardDetails::fromResponse([])['last4']);
  }

  public function testCardReferenceRoundTrip(): void {
    self::assertSame('CUS1:CARD1', CardReference::encode('CUS1', 'CARD1'));
    self::assertSame('CUS1', CardReference::encode('CUS1', NULL));
    self::assertSame(['customer_id' => 'CUS1', 'card_id' => 'CARD1'], CardReference::parse(' CUS1:CARD1 '));
    self::assertSame(['customer_id' => 'CUS1', 'card_id' => NULL], CardReference::parse('CUS1'));
    $this->expectException(\InvalidArgumentException::class);
    CardReference::parse('a:b:c');
  }

  public function testAmounts(): void {
    self::assertSame(1230, Amount::toCents('12.3'));
    self::assertSame(1999, Amount::toCents('19.99'));
    self::assertSame(29, Amount::toCents('0.29'));
    self::assertSame('12.30', Amount::fromCents(1230));
    self::assertSame('0.00', Amount::fromCents(NULL));
  }

  public function testDonorWordingPrefersTheCode(): void {
    $insufficient = DonorMessage::fromResponse(['failure_code' => 'D2012', 'failure_message' => 'Insufficient Funds', 'status' => 'Insufficient Funds']);
    self::assertStringContainsString('over the available limit', $insufficient['donor']);
    self::assertSame('Insufficient Funds [D2012]', $insufficient['gateway']);

    self::assertStringContainsString('security code', DonorMessage::fromResponse(['failure_code' => 'D2020', 'failure_message' => 'CVV2 verification failed'])['donor']);
    self::assertStringContainsString('expired', DonorMessage::fromResponse(['failure_code' => 'EXPIRED CARD'])['donor']);
    self::assertStringContainsString('declined. Please check', DonorMessage::fromResponse(['failure_code' => 'DECLINED-077'])['donor']);
  }

  public function testDonorWordingFromHttpErrors(): void {
    self::assertStringContainsString('security code', DonorMessage::fromResponse(['message' => 'Invalid CVV'])['donor']);
    self::assertStringContainsString('not configured correctly', DonorMessage::fromResponse(['error' => 'Unauthenticated.'])['donor']);
    self::assertStringContainsString('did not respond', DonorMessage::fromResponse(['message' => 'server error'])['donor']);
    // A used token is not a configuration fault (HTTP 404, sandbox 2026-09-24).
    self::assertStringContainsString('enter your card details again', DonorMessage::fromResponse(['message' => 'The requested token_id is not valid or already used'])['donor']);
    // Laravel's {"code": 0} is not a response code.
    self::assertSame('Return Not Allowed.', DonorMessage::fromResponse(['status' => 'error', 'code' => 0, 'message' => 'Return Not Allowed.'])['gateway']);
  }

  public function testDonorWordingUsesTheDeclineStatusWhenNoMessage(): void {
    $message = DonorMessage::fromResponse(['data' => ['object' => 'Charge', 'id' => 'x', 'status' => 'Invalid CVV', 'failure_code' => 'X1']]);
    self::assertSame('Invalid CVV [X1]', $message['gateway']);
    self::assertStringContainsString('security code', DonorMessage::donorText('Invalid CVV'));
  }

  public function testTranslatorIsApplied(): void {
    DonorMessage::setTranslator(static fn(string $s): string => 'T:' . $s);
    try {
      self::assertStringStartsWith('T:', DonorMessage::donorText('anything'));
    }
    finally {
      DonorMessage::setTranslator(NULL);
    }
  }

}
