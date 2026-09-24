<?php

namespace Payarc;

/**
 * A charge lookup ran out of listing pages, or met a row it could not judge,
 * before it could prove the reference is absent. The charge may or may not
 * exist: callers must not send another one until a later lookup is
 * conclusive.
 */
class ReconciliationInconclusiveException extends GatewayException {
}
