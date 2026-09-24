<?php

namespace Payarc;

/**
 * Turns a PayArc decline or error into wording a donor can act on.
 *
 * PayArc reports declines with a code (TSYS "D2012", Payfac "DECLINED-051",
 * "EXPIRED CARD") and merchant-facing text ("CVV2 verification failed"). The
 * code decides the wording when it is known; otherwise the text is matched.
 * The raw text and code are kept for staff notes and logs. Pure PHP; the
 * translator is injected.
 */
class DonorMessage {

  /**
   * Optional translator fn(string $english): string, e.g. WordPress __() or CiviCRM ts().
   *
   * @var callable|null
   */
  private static $translator = NULL;

  /**
   * Response codes (TSYS and Payfac tables in PayArc's FAQ) mapped to the
   * reason category used by donorText().
   */
  private const CODES = [
    'D2012' => 'funds',
    'D2015' => 'funds',
    'D2031' => 'funds',
    'DECLINED-051' => 'funds',
    'D2001' => 'bank',
    'D2002' => 'bank',
    'D2026' => 'bank',
    'D2029' => 'bank',
    'D2032' => 'bank',
    'DECLINED-005' => 'bank',
    'RESTRICTED CARD' => 'bank',
    'E6057' => 'bank',
    'E6058' => 'bank',
    'D2011' => 'expired',
    'D2028' => 'expired',
    'EXPIRED CARD' => 'expired',
    'D2020' => 'cvv',
    'D2005' => 'number',
    'D2006' => 'number',
    'D0001' => 'duplicate',
    'D0003' => 'duplicate',
    'D0008' => 'duplicate',
    'D2022' => 'duplicate',
    'D2004' => 'amount',
    'D2999' => 'declined',
    'DECLINED-182' => 'declined',
    'D0092' => 'unavailable',
    'D2021' => 'unavailable',
    'E0200' => 'unavailable',
    'E0300' => 'unavailable',
    'E0350' => 'unavailable',
    'E0910' => 'unavailable',
    'E0911' => 'unavailable',
    'E0912' => 'unavailable',
    'E0016' => 'config',
    'E0010' => 'config',
    'D0050' => 'config',
    'D1006' => 'config',
  ];

  public static function setTranslator(?callable $translator): void {
    self::$translator = $translator;
  }

  /**
   * @param array $response
   *   A declined charge (failure_code/failure_message), or the response data
   *   of a GatewayException ({message, errors}), or ['error' => text] for
   *   transport failures.
   * @return array{donor: string, gateway: string}
   */
  public static function fromResponse(array $response): array {
    $gateway = self::gatewayText($response);
    return ['donor' => self::donorText($gateway, self::code($response)), 'gateway' => $gateway];
  }

  /**
   * The gateway's text plus its response code for staff.
   */
  public static function gatewayText(array $response): string {
    $charge = Charge::unwrap($response);
    $text = '';
    foreach ([$charge['failure_message'] ?? NULL, $response['message'] ?? NULL, $response['error'] ?? NULL] as $candidate) {
      if (is_string($candidate) && trim($candidate) !== '') {
        $text = trim($candidate);
        break;
      }
    }
    // A declined charge also puts its reason in 'status' ("Invalid CVV").
    $status = trim((string) ($charge['status'] ?? ''));
    if ($text === '' && $status !== '' && Charge::outcome($charge) === Charge::DECLINED && $status !== 'failed_by_gateway') {
      $text = $status;
    }
    // Validation errors: {"message": "The given data was invalid.", "errors": {"field": ["..."]}}
    if (is_array($response['errors'] ?? NULL)) {
      $details = [];
      foreach ($response['errors'] as $messages) {
        foreach ((array) $messages as $message) {
          if (is_string($message) && trim($message) !== '') {
            $details[] = trim($message);
          }
        }
      }
      if ($details) {
        $text = trim($text . ' ' . implode(' ', $details));
      }
    }
    if ($text === '') {
      $text = Charge::failureCode($charge) !== '' ? 'Declined' : 'Error';
    }
    $code = self::code($response);
    if ($code !== '' && stripos($text, $code) === FALSE) {
      $text .= ' [' . $code . ']';
    }
    return $text;
  }

  public static function code(array $response): string {
    $charge = Charge::unwrap($response);
    foreach ([$charge['failure_code'] ?? NULL, $response['code'] ?? NULL] as $candidate) {
      if (is_scalar($candidate) && trim((string) $candidate) !== '' && (string) $candidate !== '0') {
        return strtoupper(trim((string) $candidate));
      }
    }
    return '';
  }

  public static function donorText(string $gateway, string $code = ''): string {
    $category = self::CODES[strtoupper(trim($code))] ?? NULL;
    if ($category === NULL && preg_match('/^DECLINED-\d+$/i', trim($code))) {
      $category = 'declined';
    }
    return self::wording($category ?? self::categoryFromText($gateway, $code !== ''));
  }

  private static function categoryFromText(string $gateway, bool $hasCode): string {
    $g = strtolower($gateway);
    // Order matters: specific reasons before the generic decline.
    if (preg_match('/insufficient|nsf|over.?limit|exceeds.*limit|limit exceeded/', $g)) {
      return 'funds';
    }
    if (preg_match('/pick.?up|lost|stolen|fraud|suspected|restricted|hold.?card|do not honou?r|refer to issuer/', $g)) {
      return 'bank';
    }
    if (preg_match('/expir|invalid date/', $g)) {
      return 'expired';
    }
    if (preg_match('/cvv|cvc|cvv2|card code|security code|card verification/', $g)) {
      return 'cvv';
    }
    if (preg_match('/avs|address|zip|postal/', $g)) {
      return 'avs';
    }
    if (preg_match('/customer id|card id|no such customer|saved card|stored card|card not found/', $g)) {
      return 'saved';
    }
    if (preg_match('/invalid card|card number|luhn|card type|unsupported card|not accepted|no such issuer/', $g)) {
      return 'number';
    }
    if (preg_match('/duplicate/', $g)) {
      return 'duplicate';
    }
    if (preg_match('/declin|not approved|call|referral|reject/', $g) || $hasCode) {
      return 'declined';
    }
    if (preg_match('/timeout|timed out|unavailable|try again|re-enter|processor error|gateway error|connection|network|busy|system error|inoperative|server error/', $g)) {
      return 'unavailable';
    }
    if (preg_match('/unauthenticated|authenticat|token|permission|forbidden|merchant|terminal|disabled|inactive/', $g)) {
      return 'config';
    }
    if (preg_match('/amount|currency/', $g)) {
      return 'amount';
    }
    return 'other';
  }

  private static function wording(string $category): string {
    $ts = static fn(string $s): string => self::$translator ? (string) (self::$translator)($s) : $s;
    return match ($category) {
      'funds' => $ts('Your card was declined by your bank because the amount is over the available limit. Please try a different card or contact your bank.'),
      'bank' => $ts('Your card was declined by your bank. Please contact your bank or try a different card.'),
      'expired' => $ts('The card appears to be expired or the expiration date was entered incorrectly. Please check the expiration date and try again.'),
      'cvv' => $ts('The card security code (CVV) did not match. Please check the three or four digit code and try again.'),
      'avs' => $ts('The billing address or postal code did not match the card. Please check the billing address and try again.'),
      'saved' => $ts('The card we have on file for you could not be used. Please update your card details.'),
      'number' => $ts('The card number is not valid or this card type is not accepted. Please check the card number and try again.'),
      'duplicate' => $ts('This looks like a duplicate of a payment made a moment ago, so it was not charged again. Please check your email for a receipt before trying again.'),
      'declined' => $ts('Your card was declined. Please check the card details, try a different card, or contact your bank.'),
      'unavailable' => $ts('The payment could not be completed because the card processor did not respond. Please wait a moment and try again. If the problem continues, contact us.'),
      'config' => $ts('The payment system is not configured correctly, so no charge was made. Please contact us so we can fix it.'),
      'amount' => $ts('The payment amount could not be processed. Please contact us.'),
      default => $ts('The payment could not be processed. Please check the card details and try again, or contact us for help.'),
    };
  }

}
