<?php

namespace Payarc\WordPress;

/**
 * Another request is making (or has just made) the same payment or refund and
 * has not finished recording it. Nothing was sent; the caller should ask the
 * user to try again in a moment, when the first request's outcome is known.
 */
final class BusyException extends \RuntimeException {
}
