<?php

namespace Payarc;

/**
 * PayArc may have received the request, but no reliable result was read.
 *
 * Callers must not treat the charge as failed. Either send the same request
 * again with the same idempotency key (PayArc returns the original result) or
 * look it up (GatewayClient::findChargeByReference) before charging again.
 */
class AmbiguousGatewayException extends GatewayException {
}
