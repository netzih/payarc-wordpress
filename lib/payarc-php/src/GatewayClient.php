<?php

namespace Payarc;

/**
 * Minimal PayArc API v1 client.
 *
 * Amounts are passed as decimal strings ("12.30") and sent as integer cents.
 * Methods return the "data" object of PayArc's response. Every charge and
 * refund asks PayArc not to email or text the payer: PayArc does both by
 * default, and the calling application sends its own receipts.
 */
class GatewayClient {

  public const DEFAULT_SOFTWARE = 'payarc-php/0.1';

  public const LIVE_URL = 'https://api.payarc.net/v1';

  public const SANDBOX_URL = 'https://testapi.payarc.net/v1';

  /**
   * Hosts of the Hosted Fields script (iframeprocess.js) and its endpoints.
   */
  public const LIVE_PORTAL = 'https://portal.payarc.net';

  public const SANDBOX_PORTAL = 'https://testportal.payarc.net';

  /**
   * Amount authorized (then voided) by verifyCard().
   */
  public const CARD_VERIFICATION_AMOUNT = '1.00';

  /**
   * Metadata key carrying the caller's reference (idempotency key) on every
   * charge, so a charge can be found again by it (findChargeByReference).
   */
  public const REFERENCE_KEY = 'reference';

  /**
   * The docs show list rows with created_at as a date without a zone; read
   * as UTC it can be off by up to 14 hours either way, so a cutoff against
   * such a row is widened by this much. The sandbox returns a Unix time
   * instead (2026-09-24), which only needs CLOCK_SLACK.
   */
  public const CREATED_TIME_SLACK = 26 * 3600;

  /**
   * Allowance for clock differences against an exact (Unix) created_at.
   */
  public const CLOCK_SLACK = 15 * 60;

  /**
   * Void reasons PayArc accepts.
   */
  public const VOID_REASONS = ['requested_by_customer', 'fraudulent', 'duplicate', 'other'];

  private string $bearerToken;

  private string $baseUrl;

  /**
   * Sent in the User-Agent and as metadata 'software' so the dashboard shows
   * which integration made each charge.
   */
  private string $software;

  /**
   * Optional test transport: fn(string $method, string $url, array $headers, ?string $body): array{status: int, body: string}.
   */
  private $transport;

  public function __construct(string $bearerToken, string $baseUrl = self::LIVE_URL, ?callable $transport = NULL, string $software = self::DEFAULT_SOFTWARE) {
    $this->bearerToken = trim($bearerToken);
    $this->baseUrl = rtrim(trim($baseUrl), '/');
    $this->transport = $transport;
    $this->software = trim($software) === '' ? self::DEFAULT_SOFTWARE : trim($software);

    if ($this->bearerToken === '') {
      throw new \InvalidArgumentException('A PayArc API bearer token is required.');
    }
    if (!str_starts_with($this->baseUrl, 'https://')) {
      throw new \InvalidArgumentException('PayArc API URL must use HTTPS.');
    }
  }

  /**
   * Charge a single-use token from Hosted Fields (or wallet buttons).
   *
   * @param array $options
   *   See chargePayload(). 'reference' is strongly recommended: it becomes
   *   the Idempotency-Key, so the same request can be sent again after a lost
   *   response without charging twice.
   */
  public function chargeToken(string $token, string $amount, array $options = []): array {
    $payload = $this->chargePayload($amount, $options);
    $payload['token_id'] = $this->validId($token, 'token');
    return $this->charge($payload, $options);
  }

  /**
   * Charge a saved card (a CardReference from saveCard()).
   */
  public function chargeCard(string $cardReference, string $amount, array $options = []): array {
    $reference = CardReference::parse($cardReference);
    $payload = $this->chargePayload($amount, $options);
    $payload['customer_id'] = $reference['customer_id'];
    if ($reference['card_id'] !== NULL) {
      $payload['card_id'] = $reference['card_id'];
    }
    return $this->charge($payload, $options);
  }

  /**
   * Save a single-use token as a reusable card.
   *
   * Creates a PayArc customer for this card and attaches the token to it,
   * as PayArc's own WooCommerce plugin does: one customer per saved card
   * keeps the reference self-contained and deleting a card simple. Nothing is
   * charged. A token is single-use, so a first payment on a new saved card is
   * made afterwards with chargeCard(), which also proves the saved card can
   * be charged without the CVV.
   *
   * @param array $customer
   *   'email' (required by PayArc), 'name', 'description', 'phone',
   *   'address_1', 'address_2', 'city', 'state', 'zip', 'country'.
   *
   * @return array{reference: string, customer_id: string, card_id: ?string, card: array, customer: array}
   *   'card' is CardDetails::fromResponse() of the saved card; its 'verified'
   *   is PayArc's is_verified, which decides whether later charges need a CVV.
   */
  public function saveCard(string $token, array $customer): array {
    $token = $this->validId($token, 'token');
    $fields = $this->customerPayload($customer);
    if (($fields['email'] ?? '') === '') {
      throw new \InvalidArgumentException('PayArc requires an email address to save a card.');
    }

    $created = $this->request('POST', '/customers', $fields);
    $customerId = trim((string) ($created['customer_id'] ?? $created['id'] ?? ''));
    if ($customerId === '') {
      throw new AmbiguousGatewayException('PayArc created a customer but returned no customer_id.', 0, $created);
    }

    try {
      $updated = $this->request('PATCH', '/customers/' . rawurlencode($customerId), ['token_id' => $token]);
    }
    catch (AmbiguousGatewayException $e) {
      throw $e;
    }
    catch (GatewayException $e) {
      // The token was refused (invalid, used, expired): drop the empty customer.
      $this->deleteCustomerQuietly($customerId);
      throw $e;
    }

    $card = CardDetails::fromResponse($updated);
    if ($card['card_id'] === NULL) {
      $updated = $this->getCustomer($customerId);
      $card = CardDetails::fromResponse($updated);
    }
    if ($card['card_id'] === NULL) {
      throw new AmbiguousGatewayException('PayArc accepted the card but did not list it on the customer.', 0, $updated);
    }

    return [
      'reference' => CardReference::encode($customerId, $card['card_id']),
      'customer_id' => $customerId,
      'card_id' => $card['card_id'],
      'card' => $card,
      'customer' => $updated,
    ];
  }

  /**
   * Prove a saved card can be charged, without keeping a charge: authorize
   * CARD_VERIFICATION_AMOUNT (capture=0) and void it at once.
   *
   * Returns the authorization. If the void failed, 'void_error' carries the
   * reason; an uncaptured authorization expires by itself after seven days.
   */
  public function verifyCard(string $cardReference, array $options = []): array {
    $options['capture'] = FALSE;
    $options += ['description' => 'Card verification'];
    $response = $this->chargeCard($cardReference, self::CARD_VERIFICATION_AMOUNT, $options);
    if (Charge::approved($response) && Charge::id($response) !== '') {
      try {
        $this->void(Charge::id($response), 'other', 'Card verification');
      }
      catch (GatewayException $e) {
        $response['void_error'] = $e->getMessage();
      }
    }
    return $response;
  }

  /**
   * Void an unsettled charge in full. PayArc answers a settled one with HTTP
   * 409 "Reversal Not Allowed.".
   */
  public function void(string $chargeId, string $reason = 'requested_by_customer', ?string $description = NULL): array {
    if (!in_array($reason, self::VOID_REASONS, TRUE)) {
      throw new \InvalidArgumentException('Invalid PayArc void reason.');
    }
    $payload = ['reason' => $reason];
    if ($description !== NULL && trim($description) !== '') {
      $payload['void_description'] = $this->reasonText($description, 'Void');
    }
    return $this->request('POST', '/charges/' . rawurlencode($this->validId($chargeId, 'charge')) . '/void', $payload);
  }

  /**
   * Give money back on a charge, in full (NULL amount) or in part.
   *
   * PayArc does not refuse a refund of an unsettled charge as documented: it
   * voids the WHOLE charge, whatever amount was asked for, and answers 201
   * with status "void" (verified in the sandbox, 2026-09-24: a $0.75 refund
   * of an unbatched $2.00 charge reversed all $2.00). So this reads the
   * charge first:
   * - Full amount: sent as is. PayArc voids an unsettled charge and refunds a
   *   settled one; the response status says which (see reverse()).
   * - Partial amount: sent only when the charge is known to have settled
   *   (Charge::isSettled()); otherwise UnsettledPartialRefundException and
   *   nothing is sent.
   *
   * @param array $options
   *   'reason' (a VOID_REASONS value), 'description', 'reference' (sent as
   *   the Idempotency-Key).
   *
   * @throws UnsettledPartialRefundException
   */
  public function refund(string $chargeId, ?string $amount = NULL, array $options = []): array {
    $chargeId = $this->validId($chargeId, 'charge');
    $charge = $this->getCharge($chargeId);
    $remaining = Charge::remainingCents($charge);
    if ($remaining === NULL) {
      throw new GatewayException('PayArc did not report the amount of charge ' . $chargeId . '.', 0, $charge);
    }
    if ($remaining === 0) {
      throw new GatewayException('This charge has already been voided or refunded in full.', 0, $charge);
    }
    $cents = $amount === NULL ? $remaining : $this->positiveCents($amount);
    if ($cents > $remaining) {
      throw new \InvalidArgumentException(sprintf('The refund (%s) is more than the %s left on the charge.', Amount::fromCents($cents), Amount::fromCents($remaining)));
    }
    if ($cents < $remaining && !Charge::isSettled($charge)) {
      throw new UnsettledPartialRefundException(
        'This charge may not have settled yet. PayArc would void the whole charge instead of refunding part of it, so nothing was sent. Refund the partial amount after the charge settles (normally the next business day), or refund it in full.',
        0,
        $charge
      );
    }

    $payload = [
      'reason' => in_array($options['reason'] ?? '', self::VOID_REASONS, TRUE) ? $options['reason'] : 'requested_by_customer',
      'do_not_send_email_to_customer' => 'yes',
      'do_not_send_sms_to_customer' => 'yes',
    ];
    // The amount is left out for a full refund of an untouched charge, the
    // one case verified to void or refund the whole charge cleanly.
    if ($cents < $remaining || (int) ($charge['amount_refunded'] ?? 0) > 0) {
      $payload['amount'] = $cents;
    }
    if (isset($options['description']) && trim((string) $options['description']) !== '') {
      $payload['description'] = $this->reasonText((string) $options['description'], 'Refund');
    }
    return $this->request('POST', '/charges/' . rawurlencode($chargeId) . '/refunds', $payload, $this->idempotencyKey($options));
  }

  /**
   * refund(), reporting what PayArc actually did.
   *
   * A refund of an unsettled charge comes back as a void (status "void").
   * The documented behaviour, a "Return Not Allowed." refusal, has not been
   * seen but is still handled: the full amount is then voided.
   *
   * @return array{action: 'refund'|'void', response: array}
   *
   * @throws UnsettledPartialRefundException
   */
  public function reverse(string $chargeId, ?string $amount = NULL, array $options = []): array {
    try {
      $response = $this->refund($chargeId, $amount, $options);
      $voided = in_array(strtolower((string) ($response['status'] ?? '')), ['void', 'voided'], TRUE);
      return ['action' => $voided ? 'void' : 'refund', 'response' => $response];
    }
    catch (AmbiguousGatewayException | UnsettledPartialRefundException $e) {
      throw $e;
    }
    catch (GatewayException $e) {
      if (!self::isUnsettledRefusal($e)) {
        throw $e;
      }
    }
    // Only a full refund reaches PayArc for a charge not known to be
    // settled, so voiding here never reverses more than was asked.
    $reason = in_array($options['reason'] ?? '', self::VOID_REASONS, TRUE) ? $options['reason'] : 'requested_by_customer';
    return ['action' => 'void', 'response' => $this->void($chargeId, $reason, $options['description'] ?? NULL)];
  }

  /**
   * Whether a refund was refused because the charge has not been batched.
   */
  public static function isUnsettledRefusal(GatewayException $e): bool {
    $data = $e->getResponseData();
    $message = strtolower((string) ($data['message'] ?? $e->getMessage()));
    return str_contains($message, 'return not allowed') || str_contains($message, 'not been batched') || str_contains($message, 'not batched');
  }

  public function getCharge(string $chargeId): array {
    return $this->request('GET', '/charges/' . rawurlencode($this->validId($chargeId, 'charge')) . '?include=transaction_metadata,extra_metadata');
  }

  /**
   * One page of the account's charges, newest first.
   *
   * @return array{rows: array[], total_pages: ?int}
   */
  public function listCharges(int $limit = 100, int $page = 1): array {
    $limit = max(1, min(100, $limit));
    $query = http_build_query(['limit' => $limit, 'page' => max(1, $page), 'include' => 'transaction_metadata,extra_metadata']);
    $response = $this->requestRaw('GET', '/charges?' . $query);
    $rows = is_array($response['data'] ?? NULL) ? array_values(array_filter($response['data'], 'is_array')) : [];
    $pages = $response['meta']['pagination']['total_pages'] ?? NULL;
    return ['rows' => $rows, 'total_pages' => is_numeric($pages) ? (int) $pages : NULL];
  }

  /**
   * Find a recent charge by the 'reference' we sent with it (metadata
   * REFERENCE_KEY). Used to reconcile a charge whose response never arrived
   * when resending with the same idempotency key is not wanted.
   *
   * The list endpoint documents no filters, so this pages through the newest
   * charges and matches locally.
   *
   * @param int|null $sentAt
   *   Unix time the charge was sent. Paging stops at the first row created
   *   before it (minus CLOCK_SLACK, or CREATED_TIME_SLACK for a row whose
   *   time has no zone): a miss is then conclusive.
   * @param string|null $amount
   *   When given, only a row for this amount counts.
   *
   * @return array|null
   *   The charge row, or NULL when the reference is provably absent. Rows
   *   that were declined never count; voided or refunded ones do (they went
   *   through), and Charge::outcome() tells them apart.
   *
   * @throws ReconciliationInconclusiveException
   *   When $maxPages were read without reaching the cutoff (or the end of the
   *   listing), or when a row in the window carries no readable metadata, so
   *   a miss could not be proved.
   */
  public function findChargeByReference(string $reference, ?int $sentAt = NULL, int $maxPages = 5, ?string $amount = NULL): ?array {
    $reference = trim($reference);
    if ($reference === '') {
      throw new \InvalidArgumentException('A charge reference is required.');
    }
    $cents = $amount === NULL ? NULL : Amount::toCents($amount);
    $pageSize = 100;
    for ($page = 1; $page <= max(1, $maxPages); $page++) {
      $list = $this->listCharges($pageSize, $page);
      foreach ($list['rows'] as $row) {
        $created = Charge::createdTime($row);
        if ($sentAt !== NULL && $created !== NULL) {
          $exact = is_int($row['created_at'] ?? NULL) || ctype_digit((string) ($row['created_at'] ?? ''));
          if ($created < $sentAt - ($exact ? self::CLOCK_SLACK : self::CREATED_TIME_SLACK)) {
            return NULL;
          }
        }
        if (!Charge::hasMetadata($row)) {
          throw new ReconciliationInconclusiveException(sprintf('PayArc lists charge %s without metadata, so it cannot be told whether it is %s.', Charge::id($row) ?: '?', $reference));
        }
        if ((Charge::metadata($row)[self::REFERENCE_KEY] ?? NULL) !== $reference) {
          continue;
        }
        if ($cents !== NULL && Charge::amountCents($row) !== $cents) {
          continue;
        }
        if (Charge::outcome($row) === Charge::DECLINED) {
          continue;
        }
        return $row;
      }
      if (count($list['rows']) < $pageSize || ($list['total_pages'] !== NULL && $page >= $list['total_pages'])) {
        return NULL;
      }
    }
    throw new ReconciliationInconclusiveException(sprintf(
      'The newest %d PayArc charges were read without reaching those older than reference %s could be, so it may exist among older ones.',
      max(1, $maxPages) * $pageSize,
      $reference
    ));
  }

  public function getCustomer(string $customerId): array {
    return $this->request('GET', '/customers/' . rawurlencode($this->validId($customerId, 'customer')) . '?include=card');
  }

  /**
   * Delete a saved card: its PayArc customer (see saveCard()).
   */
  public function deleteCard(string $cardReference): void {
    $reference = CardReference::parse($cardReference);
    $this->request('DELETE', '/customers/' . rawurlencode($reference['customer_id']));
  }

  /**
   * Prove the bearer token works without moving money: lists one charge.
   * PayArc answers a wrong token with HTTP 401 {"error": "Unauthenticated."}.
   */
  public function verifyCredentials(): array {
    return $this->listCharges(1, 1);
  }

  /**
   * Prove a Hosted Fields Client ID belongs to a merchant, without moving
   * money: asks the portal for one iframe, as the browser script does. The
   * portal answers a wrong Client ID with HTTP 403 (verified in the sandbox,
   * 2026-09-24). The iframe session it opens expires unused.
   *
   * @param string $portalUrl
   *   LIVE_PORTAL or SANDBOX_PORTAL, matching the Client ID's environment.
   */
  public function verifyClientId(string $clientId, string $portalUrl): void {
    $clientId = trim($clientId);
    if ($clientId === '' || preg_match('/[^A-Za-z0-9_-]/', $clientId)) {
      throw new \InvalidArgumentException('A PayArc Client ID is required.');
    }
    if (!str_starts_with($portalUrl, 'https://')) {
      throw new \InvalidArgumentException('PayArc portal URL must use HTTPS.');
    }
    $url = rtrim($portalUrl, '/') . '/v1/get-iframe?' . http_build_query([
      'user' => $clientId,
      'amount' => '1.00',
      'fields' => json_encode([['pl' => 'Card number', 'id' => 'payarc-check', 'type' => 'CARD_NUMBER']]),
    ]);
    $headers = ['Accept: application/json', 'User-Agent: ' . $this->userAgent()];
    $result = $this->transport ? ($this->transport)('GET', $url, $headers, NULL) : $this->curlRequest('GET', $url, $headers, NULL);
    $status = (int) ($result['status'] ?? 0);
    if ($status === 403) {
      throw new GatewayException('PayArc does not recognise this Client ID.', 403);
    }
    if ($status < 200 || $status >= 300) {
      throw new AmbiguousGatewayException('The PayArc portal returned HTTP ' . $status . '.', $status);
    }
  }

  /**
   * @param array $options
   *   'reference': idempotency key, also stored as metadata REFERENCE_KEY.
   *   'invoice': our invoice number (metadata 'invoice').
   *   'description': shown with the charge (charge_description).
   *   'statement_description': text on the card statement.
   *   'email', 'phone': the payer's; shown in the dashboard. PayArc's own
   *     receipt stays off (do_not_send_* is always sent).
   *   'metadata': more key => value pairs (keys up to 20 characters).
   *   'capture': FALSE for an authorization only (expires after 7 days).
   *   'recurring': TRUE marks a merchant-initiated installment
   *     (eci_indicator 2, "Recurring Mail/Telephone Order").
   */
  private function chargePayload(string $amount, array $options): array {
    $payload = [
      'amount' => $this->positiveCents($amount),
      'currency' => 'usd',
      'capture' => array_key_exists('capture', $options) && !$options['capture'] ? 0 : 1,
      'do_not_send_email_to_customer' => 'yes',
      'do_not_send_sms_to_customer' => 'yes',
    ];
    if (!empty($options['description'])) {
      // 'description' is silently dropped; 'charge_description' is stored
      // and shown (verified in the sandbox, 2026-09-24).
      $payload['charge_description'] = $this->truncate((string) $options['description'], 255);
    }
    if (!empty($options['statement_description'])) {
      $payload['statement_description'] = $this->truncate((string) $options['statement_description'], 25);
    }
    if (!empty($options['email']) && filter_var($options['email'], FILTER_VALIDATE_EMAIL)) {
      $payload['email'] = (string) $options['email'];
    }
    $phone = preg_replace('/\D/', '', (string) ($options['phone'] ?? ''));
    if ($phone !== '' && strlen($phone) >= 10 && strlen($phone) <= 15) {
      $payload['phone_number'] = $phone;
    }
    if (!empty($options['recurring'])) {
      $payload['eci_indicator'] = '2';
    }

    $metadata = [];
    foreach ((array) ($options['metadata'] ?? []) as $key => $value) {
      $metadata[$key] = $value;
    }
    if (!empty($options['invoice'])) {
      $metadata['invoice'] = $options['invoice'];
    }
    if (!empty($options['reference'])) {
      $metadata[self::REFERENCE_KEY] = $options['reference'];
    }
    $metadata['software'] = $this->software;
    $payload['metadata'] = json_encode($this->cleanMetadata($metadata), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $payload;
  }

  private function charge(array $payload, array $options): array {
    return $this->request('POST', '/charges', $payload, $this->idempotencyKey($options));
  }

  /**
   * Metadata limits: 50 keys, keys up to 20 characters, values up to 100.
   */
  private function cleanMetadata(array $metadata): array {
    $clean = [];
    foreach ($metadata as $key => $value) {
      $key = substr(preg_replace('/[^A-Za-z0-9_]/', '_', (string) $key), 0, 20);
      if ($key === '' || $value === NULL || $value === '' || !is_scalar($value)) {
        continue;
      }
      $clean[$key] = $this->truncate((string) $value, 100);
    }
    return array_slice($clean, 0, 50, TRUE);
  }

  private function customerPayload(array $customer): array {
    $fields = [];
    if (!empty($customer['email']) && filter_var($customer['email'], FILTER_VALIDATE_EMAIL)) {
      $fields['email'] = (string) $customer['email'];
    }
    $limits = ['name' => 100, 'description' => 255, 'address_1' => 30, 'address_2' => 30, 'city' => 50, 'state' => 50, 'zip' => 10, 'country' => 50];
    foreach ($limits as $key => $limit) {
      if (isset($customer[$key]) && is_scalar($customer[$key]) && trim((string) $customer[$key]) !== '') {
        $fields[$key] = $this->truncate(trim((string) $customer[$key]), $limit);
      }
    }
    $phone = preg_replace('/\D/', '', (string) ($customer['phone'] ?? ''));
    if ($phone !== '' && strlen($phone) >= 10 && strlen($phone) <= 15) {
      $fields['phone'] = $phone;
    }
    return $fields;
  }

  private function deleteCustomerQuietly(string $customerId): void {
    try {
      $this->request('DELETE', '/customers/' . rawurlencode($customerId));
    }
    catch (\Throwable $e) {
      // An empty customer record is harmless.
    }
  }

  private function idempotencyKey(array $options): ?string {
    $key = trim((string) ($options['reference'] ?? ''));
    if ($key === '') {
      return NULL;
    }
    if (strlen($key) > 255 || preg_match('/[\x00-\x20\x7f]/', $key)) {
      throw new \InvalidArgumentException('Invalid charge reference.');
    }
    return $key;
  }

  private function positiveCents(string $amount): int {
    $cents = Amount::toCents($amount);
    if ($cents <= 0) {
      throw new \InvalidArgumentException('Payment amount must be greater than zero.');
    }
    return $cents;
  }

  private function validId(string $id, string $what): string {
    $id = trim($id);
    if ($id === '' || strlen($id) > 255 || preg_match('/[\x00-\x20\x7f\/?#]/', $id)) {
      throw new \InvalidArgumentException(sprintf('Invalid PayArc %s.', $what));
    }
    return $id;
  }

  /**
   * PayArc refuses a refund description shorter than five characters ("The
   * description field must be at least 5 characters.", sandbox 2026-09-24),
   * so a short one (a shop's "test" or "dup") is prefixed.
   */
  private function reasonText(string $text, string $prefix): string {
    $text = trim($text);
    $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    return $this->truncate($length < 5 ? $prefix . ': ' . $text : $text, 255);
  }

  private function truncate(string $value, int $length): string {
    return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
  }

  /**
   * A request whose response "data" object is returned.
   */
  private function request(string $method, string $path, ?array $payload = NULL, ?string $idempotencyKey = NULL): array {
    $response = $this->requestRaw($method, $path, $payload, $idempotencyKey);
    if ($method === 'DELETE' && !isset($response['data'])) {
      return $response;
    }
    if (!is_array($response['data'] ?? NULL)) {
      throw new AmbiguousGatewayException('PayArc returned a response without data.', 0, $response);
    }
    return $response['data'];
  }

  private function requestRaw(string $method, string $path, ?array $payload = NULL, ?string $idempotencyKey = NULL): array {
    $url = $this->baseUrl . '/' . ltrim($path, '/');
    $body = $payload === NULL ? NULL : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $headers = [
      'Accept: application/json',
      'Content-Type: application/json',
      'Authorization: Bearer ' . $this->bearerToken,
      'User-Agent: ' . $this->userAgent(),
    ];
    if ($idempotencyKey !== NULL) {
      $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    }

    $result = $this->transport
      ? ($this->transport)($method, $url, $headers, $body)
      : $this->curlRequest($method, $url, $headers, $body);

    $status = (int) ($result['status'] ?? 0);
    $responseBody = (string) ($result['body'] ?? '');
    $decoded = json_decode($responseBody, TRUE);
    $data = is_array($decoded) ? $decoded : [];

    // 5xx, no status (transport failure), 408 (the request may have been
    // processed before the answer timed out) and 429 are ambiguous.
    if ($status >= 500 || $status === 0 || $status === 408 || $status === 429) {
      throw new AmbiguousGatewayException(
        'PayArc did not return a conclusive response. Reconcile the charge before retrying.',
        $status,
        $data
      );
    }
    if ($status < 200 || $status >= 300) {
      throw new GatewayException($this->errorMessage($data, 'PayArc returned HTTP ' . $status . '.'), $status, $data);
    }
    if ($method === 'DELETE' && trim($responseBody) === '') {
      return [];
    }
    if (!is_array($decoded)) {
      throw new AmbiguousGatewayException('PayArc returned an unreadable response.', $status);
    }
    return $data;
  }

  private function errorMessage(array $data, string $fallback): string {
    $text = DonorMessage::gatewayText($data);
    return $text === 'Error' ? $fallback : $text;
  }

  private function userAgent(): string {
    return preg_replace('/[^A-Za-z0-9.\/_-]+/', '-', $this->software);
  }

  private function curlRequest(string $method, string $url, array $headers, ?string $body): array {
    if (!function_exists('curl_init')) {
      throw new GatewayException('PHP cURL is required for PayArc payments.');
    }
    $curl = curl_init($url);
    curl_setopt_array($curl, [
      CURLOPT_CUSTOMREQUEST => $method,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT => 45,
      CURLOPT_SSL_VERIFYPEER => TRUE,
      CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($body !== NULL) {
      curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }
    $responseBody = curl_exec($curl);
    if ($responseBody === FALSE) {
      throw new AmbiguousGatewayException('The connection to PayArc failed: ' . curl_error($curl));
    }
    return ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'body' => $responseBody];
  }

}
