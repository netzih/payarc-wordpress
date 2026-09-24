<?php

namespace Payarc;

/**
 * A definitive error returned by PayArc or rejected before transmission.
 */
class GatewayException extends \RuntimeException {

  protected array $responseData;

  public function __construct(string $message, int $code = 0, array $responseData = [], ?\Throwable $previous = NULL) {
    parent::__construct($message, $code, $previous);
    $this->responseData = $responseData;
  }

  public function getResponseData(): array {
    return $this->responseData;
  }

}
