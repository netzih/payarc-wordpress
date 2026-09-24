<?php

namespace Payarc\WordPress\Tests;

use PHPUnit\Framework\TestCase;
use Payarc\WordPress\Settings;

final class AccountsTest extends TestCase {

  public function testNewRowsGetIdsFromTheirLabels(): void {
    $out = Settings::sanitizeAccounts([
      ['label' => 'Camp Account', 'live_api_key' => 'k1'],
      ['label' => 'Camp account', 'sandbox_api_key' => 'k2'],
      ['label' => '2027'],
      ['label' => '', 'live_api_key' => ''],
    ], []);
    self::assertSame(['camp-account', 'camp-account-2', 'account-2027'], array_column($out, 'id'));
    self::assertSame('k1', $out[0]['live_api_key']);
  }

  public function testSavedRowsKeepIdAndBlankPinKeepsTheStoredOne(): void {
    $current = ['camp' => ['id' => 'camp', 'label' => 'Camp', 'live_api_pin' => '1234', 'sandbox_api_pin' => '9999']];
    $out = Settings::sanitizeAccounts([
      ['id' => 'camp', 'label' => 'Camp (renamed)', 'live_api_pin' => '', 'sandbox_api_pin' => '', 'sandbox_clear_pin' => '1'],
    ], $current);
    self::assertSame('camp', $out[0]['id']);
    self::assertSame('Camp (renamed)', $out[0]['label']);
    self::assertSame('1234', $out[0]['live_api_pin']);
    self::assertSame('', $out[0]['sandbox_api_pin']);
  }

  public function testRemovedRowsAndTheDefaultIdAreDropped(): void {
    $current = ['camp' => ['id' => 'camp', 'label' => 'Camp']];
    $out = Settings::sanitizeAccounts([
      ['id' => 'camp', 'label' => 'Camp', 'remove' => '1'],
      ['label' => 'Default'],
    ], $current);
    self::assertSame(['default-2'], array_column($out, 'id'));
  }

}
