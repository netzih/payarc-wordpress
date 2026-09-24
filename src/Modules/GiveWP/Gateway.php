<?php

namespace Payarc\WordPress\Modules\GiveWP;

use Give\Donations\Models\Donation;
use Give\Donations\Models\DonationNote;
use Give\Framework\PaymentGateways\Commands\GatewayCommand;
use Give\Framework\PaymentGateways\Commands\PaymentComplete;
use Give\Framework\PaymentGateways\Commands\PaymentRefunded;
use Give\Framework\PaymentGateways\Commands\SubscriptionComplete;
use Give\Framework\PaymentGateways\Contracts\PaymentGatewayRefundable;
use Give\Framework\PaymentGateways\Exceptions\PaymentGatewayException;
use Give\Framework\PaymentGateways\PaymentGateway;
use Give\Subscriptions\Models\Subscription;
use Give\Subscriptions\Models\SubscriptionNote;
use Give\Subscriptions\ValueObjects\SubscriptionStatus;
use Payarc\AmbiguousGatewayException;
use Payarc\ReconciliationInconclusiveException;
use Payarc\WordPress\BusyException;
use Payarc\WordPress\Reconcile;
use Payarc\DonorMessage;
use Payarc\GatewayException;
use Payarc\WordPress\Gateway as Shared;
use Payarc\WordPress\Log;
use Payarc\WordPress\Plugin;

/**
 * GiveWP gateway (visual donation forms, v3). One-time gifts charge the
 * Hosted Fields token in createPayment(); recurring gifts save the card at
 * PayArc, charge the first installment to it and store the saved-card
 * reference as the gateway subscription id, which the renewal worker charges
 * on each renewsAt date.
 *
 * GiveWP's own Test Mode selects the sandbox credentials from
 * Settings > PayArc; live mode selects the live ones.
 */
final class Gateway extends PaymentGateway implements PaymentGatewayRefundable {

  public const ID = 'payarc';

  public const INTEGRATION = 'GiveWP';

  public static function id(): string {
    return self::ID;
  }

  public function getId(): string {
    return self::id();
  }

  public function getName(): string {
    return __('PayArc', 'payarc-payments');
  }

  public function getPaymentMethodLabel(): string {
    return __('Credit Card', 'payarc-payments');
  }

  /**
   * GiveWP's Test Mode chooses sandbox credentials.
   */
  public static function mode(): string {
    return function_exists('give_is_test_mode') && give_is_test_mode() ? 'sandbox' : 'live';
  }

  private function shared(): Shared {
    return Plugin::instance()->gateway();
  }

  // ---------------------------------------------------------------------
  // Form (v3) integration
  // ---------------------------------------------------------------------

  public function enqueueScript(int $formId) {
    $plugin = Plugin::instance();
    wp_enqueue_script('payarc-hostedfields', $plugin->url('assets/js/payarc-hostedfields.js'), [], $plugin->assetVersion('assets/js/payarc-hostedfields.js'), TRUE);
    wp_enqueue_script('payarc_givewp', $plugin->url('assets/js/payarc-givewp.js'), ['react', 'wp-i18n', 'payarc-hostedfields'], $plugin->assetVersion('assets/js/payarc-givewp.js'), TRUE);
    wp_enqueue_style('payarc-payments', $plugin->url('assets/css/payarc-payments.css'), [], $plugin->assetVersion('assets/css/payarc-payments.css'));
  }

  public function formSettings(int $formId): array {
    $settings = Plugin::instance()->settings();
    $mode = self::mode();
    return [
      'label' => $this->getPaymentMethodLabel(),
      'clientId' => $settings->clientId($mode),
      'scriptUrl' => $settings->hostedFieldsUrl($mode),
      'configured' => $settings->isConfigured($mode),
      'sandbox' => $mode === 'sandbox',
      'wallets' => $settings->wallets(),
      'i18n' => [
        'notConfigured' => __('The payment form is not configured correctly, so no charge was made. Please contact us.', 'payarc-payments'),
        'secureNote' => __('Card details are entered securely in a form hosted by PayArc.', 'payarc-payments'),
        'orCard' => __('or enter card details', 'payarc-payments'),
        'sandboxNote' => __('Sandbox mode: use test card 4012 0000 9876 5439, 12/29, CVV 999, ZIP 85284.', 'payarc-payments'),
      ],
    ];
  }

  // ---------------------------------------------------------------------
  // Payments
  // ---------------------------------------------------------------------

  /**
   * @param array $gatewayData
   *   'payarcToken' from beforeCreatePayment().
   */
  public function createPayment(Donation $donation, $gatewayData): GatewayCommand {
    $key = trim((string) ($gatewayData['payarcToken'] ?? ''));
    if ($key === '') {
      throw new PaymentGatewayException(__('Please enter your card details.', 'payarc-payments'));
    }
    $orderId = Shared::orderId('give-' . $donation->id);
    $options = $this->chargeOptions($donation, 'GIVE-' . $donation->id);
    $amount = $donation->amount->formatToDecimal();
    $this->assertNotRefused($amount);

    $response = $this->charge(static fn($client, $reference) => $client->chargeToken($key, $amount, ['reference' => $reference] + $options), $orderId, $donation, $amount);
    $reference = Shared::transactionReference($response);
    $command = new PaymentComplete($reference);
    $command->setPaymentNotes($this->gatewayNote($response));
    return $command;
  }

  public function refundDonation(Donation $donation): GatewayCommand {
    $reference = trim((string) $donation->gatewayTransactionId);
    if ($reference === '') {
      throw new PaymentGatewayException(__('This donation has no PayArc transaction reference.', 'payarc-payments'));
    }
    // Refund against the host the donation was made on, whatever Test Mode is now.
    $mode = isset($donation->mode) && method_exists($donation->mode, 'isLive') ? ($donation->mode->isLive() ? 'live' : 'sandbox') : self::mode();
    try {
      $client = $this->shared()->client(self::INTEGRATION, $mode);
      // A marker in the donation meta makes sure a refund whose answer was
      // lost is found on the sale, not repeated. It is never cleared on
      // success: GiveWP records the refund after this returns, and a second
      // call simply finds it on the sale again.
      $donationId = (int) $donation->id;
      [$read, $write] = Reconcile::metaStore(
        static fn() => give_get_meta($donationId, '_payarc_refund_sent', TRUE),
        static function (array $marker) use ($donationId): void { give_update_meta($donationId, '_payarc_refund_sent', $marker); },
        static function () use ($donationId): void { give_delete_meta($donationId, '_payarc_refund_sent'); }
      );
      $result = Reconcile::once(
        $client,
        $read,
        $write,
        Shared::orderId('give-refund-' . $donationId),
        $donation->amount->formatToDecimal(),
        static fn($c, $key) => $c->refund($reference, NULL, ['reference' => $key, 'description' => sprintf('GiveWP donation %d', $donationId)]),
        Reconcile::REFUND,
        $reference
      );
      $response = $result['response'];
      $voided = in_array(strtolower((string) ($response['status'] ?? '')), ['void', 'voided'], TRUE);
      $verb = $voided ? __('voided before settlement', 'payarc-payments') : __('refunded', 'payarc-payments');
      if ($result['reconciled']) {
        DonationNote::create(['donationId' => $donationId, 'content' => __('PayArc confirms the earlier refund went through; recorded without refunding again.', 'payarc-payments')]);
      }
    }
    catch (ReconciliationInconclusiveException | AmbiguousGatewayException | BusyException $e) {
      Log::error('GiveWP refund unresolved', ['donation' => $donation->id, 'error' => $e->getMessage()]);
      $message = Reconcile::refundBlockedMessage($e, $reference);
      DonationNote::create(['donationId' => $donation->id, 'content' => $message]);
      throw new PaymentGatewayException($message);
    }
    catch (GatewayException | \InvalidArgumentException $e) {
      Log::error('GiveWP refund failed', ['donation' => $donation->id, 'error' => $e->getMessage()]);
      DonationNote::create(['donationId' => $donation->id, 'content' => sprintf(__('PayArc refund failed: %s', 'payarc-payments'), $e->getMessage())]);
      throw new PaymentGatewayException(sprintf(__('PayArc refund failed: %s', 'payarc-payments'), $e->getMessage()));
    }
    if (!Shared::reversed($response)) {
      $failure = Shared::failure($response);
      DonationNote::create(['donationId' => $donation->id, 'content' => sprintf(__('PayArc refund failed: %s', 'payarc-payments'), $failure['gateway'])]);
      throw new PaymentGatewayException(sprintf(__('PayArc refund failed: %s', 'payarc-payments'), $failure['gateway']));
    }
    $command = new PaymentRefunded();
    $command->setPaymentNotes(sprintf(__('Donation %1$s via PayArc. Reference: %2$s', 'payarc-payments'), $verb, $reference));
    return $command;
  }

  // ---------------------------------------------------------------------
  // Subscriptions (site-managed)
  // ---------------------------------------------------------------------

  public function createSubscription(Donation $donation, Subscription $subscription, $gatewayData): GatewayCommand {
    $key = trim((string) ($gatewayData['payarcToken'] ?? ''));
    if ($key === '') {
      throw new PaymentGatewayException(__('Please enter your card details.', 'payarc-payments'));
    }
    $orderId = Shared::orderId('give-' . $donation->id);
    $options = $this->chargeOptions($donation, 'GIVE-' . $donation->id);
    $amount = $donation->amount->formatToDecimal();
    $this->assertNotRefused($amount);
    $payer = $this->payer($donation);
    $description = (string) ($donation->formTitle ?: __('Donation', 'payarc-payments'));

    // Save the card inside the guarded call (Gateway::saveCard() returns the
    // same card for a token it has seen) and charge the saved card, which
    // proves the renewals can be charged too.
    $shared = $this->shared();
    $saved = NULL;
    $call = static function ($client, $reference) use ($shared, $key, $payer, $description, $amount, $options, &$saved) {
      $saved = $shared->saveCard($client, $key, $payer, $description);
      return $client->chargeCard($saved['reference'], $amount, ['reference' => $reference] + $options);
    };
    $response = $this->charge($call, $orderId, $donation, $amount);
    if ($saved === NULL) {
      try {
        $saved = $shared->saveCard($shared->client(self::INTEGRATION, self::mode()), $key, $payer, $description);
      }
      catch (\Throwable $e) {
        Log::error('GiveWP subscription: saved card not found again', ['donation' => $donation->id, 'error' => $e->getMessage()]);
      }
    }
    $cardReference = trim((string) ($saved['reference'] ?? ''));
    if ($cardReference === '') {
      Log::error('GiveWP subscription: approved without saved card; voiding', ['donation' => $donation->id]);
      try {
        $shared->client(self::INTEGRATION, self::mode())->void(Shared::transactionReference($response), 'other', 'Card could not be saved');
      }
      catch (\Throwable $e) {
        Log::error('GiveWP subscription: void failed', ['error' => $e->getMessage()]);
      }
      throw new PaymentGatewayException(__('The card could not be saved for future donations. Please try a different card or contact us.', 'payarc-payments'));
    }
    $card = array_filter((array) ($saved['card'] ?? [])) + Shared::card($response);
    $reference = Shared::transactionReference($response);

    // The saved-card reference is the "gateway subscription id": nothing is
    // scheduled at PayArc, this site charges it on each renewal date.
    $command = new SubscriptionComplete($reference, $cardReference);
    // SubscriptionComplete carries no payment notes; write the donation note directly.
    DonationNote::create(['donationId' => $donation->id, 'content' => $this->gatewayNote($response)]);
    SubscriptionNote::create([
      'subscriptionId' => $subscription->id,
      'content' => sprintf(
        __('Recurring donation charged by this site through PayArc using %1$s ending in %2$s. Nothing is scheduled at PayArc. Declined renewals are retried every %3$d days, %4$d attempts in all.', 'payarc-payments'),
        $card['brand'] ?: __('card', 'payarc-payments'),
        $card['last4'] ?: '????',
        Renewals::RETRY_DAYS,
        Renewals::MAX_ATTEMPTS
      ),
    ]);
    return $command;
  }

  public function cancelSubscription(Subscription $subscription) {
    $subscription->status = SubscriptionStatus::CANCELLED();
    $subscription->save();
    SubscriptionNote::create([
      'subscriptionId' => $subscription->id,
      'content' => __('Cancelled. No further charges will be made through PayArc.', 'payarc-payments'),
    ]);
  }

  public function canPauseSubscription(): bool {
    return FALSE;
  }

  public function canSyncSubscriptionWithPaymentGateway(): bool {
    return FALSE;
  }

  public function canUpdateSubscriptionAmount(): bool {
    // Amount is read from the subscription at each renewal; editing it in
    // GiveWP is enough, nothing to tell the gateway.
    return TRUE;
  }

  public function updateSubscriptionAmount(Subscription $subscription, \Give\Framework\Support\ValueObjects\Money $newRenewalAmount) {
    $subscription->amount = $newRenewalAmount;
    $subscription->save();
  }

  public function canUpdateSubscriptionPaymentMethod(): bool {
    return FALSE;
  }

  // ---------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------

  /**
   * Run a charge and turn every failure into donor-safe wording.
   *
   * The charge is made at most once per donation: a marker in the donation's
   * meta is stored before the request, and a resubmit after a lost response
   * or a crash finds the earlier approval instead of charging again.
   */
  public function charge(callable $call, string $orderId, Donation $donation, ?string $amount = NULL): array {
    try {
      $client = $this->shared()->client(self::INTEGRATION, self::mode());
    }
    catch (GatewayException $e) {
      Log::error('GiveWP: not configured', ['error' => $e->getMessage()]);
      throw new PaymentGatewayException(__('The payment system is not configured correctly, so no charge was made. Please contact us so we can fix it.', 'payarc-payments'));
    }
    $donationId = (int) $donation->id;
    $read = static fn() => give_get_meta($donationId, '_payarc_charge_sent', TRUE);
    $write = static function (?array $marker) use ($donationId): void {
      if ($marker === NULL) {
        give_delete_meta($donationId, '_payarc_charge_sent');
      }
      else {
        give_update_meta($donationId, '_payarc_charge_sent', $marker);
      }
    };
    try {
      $result = Reconcile::once($client, $read, $write, $orderId, $amount, $call);
      $response = $result['response'];
      if ($result['reconciled']) {
        DonationNote::create(['donationId' => $donationId, 'content' => sprintf(__('PayArc confirms the earlier charge %s went through; recorded without charging again.', 'payarc-payments'), $orderId)]);
      }
    }
    catch (ReconciliationInconclusiveException $e) {
      Log::error('GiveWP: reconciliation inconclusive', ['donation' => $donationId, 'error' => $e->getMessage()]);
      throw new PaymentGatewayException(__('An earlier attempt to make this payment may have gone through, and the card processor could not confirm it. Nothing was charged now. Please contact us before trying again.', 'payarc-payments'));
    }
    catch (AmbiguousGatewayException $e) {
      Log::error('GiveWP: ambiguous response', ['donation' => $donationId, 'error' => $e->getMessage()]);
      throw new PaymentGatewayException(__('The payment could not be completed because the card processor did not respond. Please wait a moment and try again. If the problem continues, contact us.', 'payarc-payments'));
    }
    catch (GatewayException $e) {
      Log::error('GiveWP: gateway error', ['donation' => $donation->id, 'error' => $e->getMessage()]);
      Plugin::instance()->velocity()->failed(self::INTEGRATION);
      throw new PaymentGatewayException(DonorMessage::donorText($e->getMessage()));
    }
    catch (\InvalidArgumentException $e) {
      Log::error('GiveWP: invalid request', ['donation' => $donation->id, 'error' => $e->getMessage()]);
      Plugin::instance()->velocity()->failed(self::INTEGRATION);
      throw new PaymentGatewayException(__('The payment could not be processed. Please check the card details and try again, or contact us for help.', 'payarc-payments'));
    }
    catch (BusyException $e) {
      Log::debug('GiveWP: busy', ['donation' => $donation->id, 'error' => $e->getMessage()]);
      throw new PaymentGatewayException(Reconcile::busyMessage());
    }
    catch (\RuntimeException $e) {
      // The marker could not be stored; nothing was sent.
      Log::error('GiveWP: not sent', ['donation' => $donation->id, 'error' => $e->getMessage()]);
      DonationNote::create(['donationId' => $donation->id, 'content' => sprintf(__('PayArc charge not sent: %s', 'payarc-payments'), $e->getMessage())]);
      throw new PaymentGatewayException(__('The payment could not be processed right now. Please try again in a moment, or contact us for help.', 'payarc-payments'));
    }
    if (!Shared::approved($response)) {
      $failure = Shared::failure($response);
      Log::error('GiveWP: declined', ['donation' => $donation->id, 'gateway' => $failure['gateway']]);
      DonationNote::create(['donationId' => $donation->id, 'content' => sprintf(__('PayArc declined the card: %s', 'payarc-payments'), $failure['gateway'])]);
      Plugin::instance()->velocity()->failed(self::INTEGRATION);
      throw new PaymentGatewayException($failure['donor']);
    }
    if (!empty($response['void_error'])) {
      // The card was saved, but the $1 verification hold was not released.
      Log::error('GiveWP: verification hold not voided', ['donation' => $donation->id, 'error' => $response['void_error']]);
      DonationNote::create(['donationId' => $donation->id, 'content' => sprintf(__('PayArc saved the card but did not void the $%1$s verification hold (%2$s). The hold expires on its own after seven days.', 'payarc-payments'), \Payarc\GatewayClient::CARD_VERIFICATION_AMOUNT, $response['void_error'])]);
    }
    return $response;
  }

  /**
   * Refuse the payment when the card-testing limits say so.
   */
  private function assertNotRefused(string $amount): void {
    $refusal = Plugin::instance()->velocity()->refusal($amount, self::INTEGRATION);
    if ($refusal !== NULL) {
      throw new PaymentGatewayException($refusal);
    }
  }

  public function payer(Donation $donation): array {
    $address = $donation->billingAddress;
    return [
      'email' => (string) $donation->email,
      'first_name' => (string) $donation->firstName,
      'last_name' => (string) $donation->lastName,
      'phone' => (string) $donation->phone,
      'address' => $address ? (string) ($address->address1 ?? '') : '',
      'address2' => $address ? (string) ($address->address2 ?? '') : '',
      'city' => $address ? (string) ($address->city ?? '') : '',
      'state' => $address ? (string) ($address->state ?? '') : '',
      'postcode' => $address ? (string) ($address->zip ?? '') : '',
      'country' => $address ? (string) ($address->country ?? '') : '',
    ];
  }

  public function chargeOptions(Donation $donation, string $invoice, array $extra = []): array {
    return $this->shared()->chargeOptions($invoice, (string) ($donation->formTitle ?: __('Donation', 'payarc-payments')), $this->payer($donation), $extra);
  }

  public function gatewayNote(array $response): string {
    $note = Shared::note($response, self::mode() === 'sandbox');
    $card = Shared::card($response);
    if ($card['last4']) {
      $note = rtrim($note, '.') . ', ' . sprintf(__('%1$s ending in %2$s', 'payarc-payments'), $card['brand'] ?: __('Card', 'payarc-payments'), $card['last4']) . '.';
    }
    return $note;
  }

}
