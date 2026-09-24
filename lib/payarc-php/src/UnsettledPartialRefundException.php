<?php

namespace Payarc;

/**
 * A partial refund was asked for on a charge that has not settled yet.
 *
 * PayArc refunds only batched charges ("Return Not Allowed.") and a void
 * always reverses the whole charge, so nothing was sent. The caller should
 * tell staff to refund the partial amount after the charge settles (normally
 * the next day) or void it in full.
 */
class UnsettledPartialRefundException extends GatewayException {
}
