<?php

namespace Payarc\WordPress\Modules\GiveWP;

use Give\Donations\Models\Donation;
use Give\Donations\Models\DonationNote;
use Give\Subscriptions\Models\Subscription;
use Give\Subscriptions\Models\SubscriptionNote;
use Give\Subscriptions\ValueObjects\SubscriptionStatus;
use Payarc\AmbiguousGatewayException;
use Payarc\GatewayException;
use Payarc\ReconciliationInconclusiveException;
use Payarc\WordPress\Gateway as Shared;
use Payarc\WordPress\Lock;
use Payarc\WordPress\Log;
use Payarc\WordPress\Plugin;

/**
 * Charges due GiveWP recurring donations against the saved-card reference
 * stored as the subscription's gateway subscription id. Runs hourly from
 * WP-Cron (Plugin::CRON_GIVEWP). GiveWP core has no renewal cron of its own.
 *
 * Success: Subscription::createRenewal() records a completed renewal donation
 * and bumps renewsAt. Decline: status Failing, renewsAt pushed RETRY_DAYS
 * ahead, up to MAX_ATTEMPTS, then Cancelled. Attempt counts live in one
 * option keyed by subscription id (GiveWP has no subscription meta table).
 *
 * The orderid of every charge is written to that state (with the time) BEFORE
 * the charge is sent and cleared only once the outcome is recorded, so a run
 * that finds it looks the charge up before sending another one.
 */
final class Renewals {

  public const RETRY_DAYS = 3;

  public const MAX_ATTEMPTS = 3;

  private const LOCK = 'give_renewals';

  private const STATE = 'payarc_give_renewal_state';

  /**
   * @return array<string, int>
   */
  public function run(?\DateTimeImmutable $now = NULL): array {
    $now = $now ?? new \DateTimeImmutable('now', wp_timezone());
    $summary = ['due' => 0, 'charged' => 0, 'declined' => 0, 'cancelled' => 0, 'completed' => 0, 'skipped' => 0, 'ambiguous' => 0];
    $lock = Lock::acquire(self::LOCK, 15 * MINUTE_IN_SECONDS);
    if ($lock === NULL) {
      $summary['skipped']++;
      return $summary;
    }
    try {
      foreach ($this->dueSubscriptions($now) as $subscription) {
        $summary['due']++;
        $outcome = $this->process($subscription, $now);
        $summary[$outcome] = ($summary[$outcome] ?? 0) + 1;
      }
    }
    finally {
      Lock::release(self::LOCK, $lock);
    }
    return $summary;
  }

  /**
   * @return Subscription[]
   */
  public function dueSubscriptions(\DateTimeImmutable $now): array {
    $rows = Subscription::query()
      ->whereIn('status', [SubscriptionStatus::ACTIVE, SubscriptionStatus::FAILING])
      ->where('expiration', $now->format('Y-m-d H:i:s'), '<=')
      ->getAll();
    $due = [];
    foreach ((array) $rows as $subscription) {
      if ($subscription->gatewayId !== Gateway::ID || trim((string) $subscription->gatewaySubscriptionId) === '') {
        continue;
      }
      $due[] = $subscription;
    }
    return $due;
  }

  /**
   * @return string charged | declined | cancelled | completed | skipped | ambiguous
   */
  public function process(Subscription $subscription, \DateTimeImmutable $now): string {
    $id = (int) $subscription->id;
    $isTest = method_exists($subscription->mode, 'isTest') ? $subscription->mode->isTest() : !$subscription->mode->isLive();
    if ($isTest !== (Gateway::mode() === 'sandbox')) {
      Log::debug('GiveWP renewal skipped: subscription mode differs from current test-mode setting', ['subscription' => $id]);
      return 'skipped';
    }
    if ($this->installmentsDone($subscription)) {
      $subscription->status = SubscriptionStatus::COMPLETED();
      $subscription->save();
      SubscriptionNote::create(['subscriptionId' => $id, 'content' => __('All scheduled donations have been made.', 'payarc-payments')]);
      return 'completed';
    }
    $lockKey = 'give_renewal_' . $id;
    $lock = Lock::acquire($lockKey, 10 * MINUTE_IN_SECONDS);
    if ($lock === NULL) {
      return 'skipped';
    }

    try {
      $amount = $subscription->amount->formatToDecimal();
      $state = $this->state($id);
      $pendingOrderId = (string) ($state['reconcile'] ?? '');
      $pendingSentAt = (int) ($state['reconcile_sent_at'] ?? 0);
      $response = NULL;
      $orderId = '';
      $attempt = 0;
      $installment = $now->format('Y-m-d');

      try {
        $client = Plugin::instance()->gateway()->client(Gateway::INTEGRATION, Gateway::mode());
        if ($pendingOrderId !== '') {
          // A previous run sent this charge and never recorded the answer.
          // Find out what happened before sending another one; an unanswered
          // lookup keeps the marker and ends this run without a charge.
          $response = $this->findTransaction($client, $pendingOrderId, $pendingSentAt ?: NULL, $amount);
          $earlierKey = $response ? Shared::transactionReference($response) : '';
          $recorded = $earlierKey !== '' ? give()->donations->getByGatewayTransactionId($earlierKey) : NULL;
          if ($recorded) {
            $outcome = $this->finishRecorded($subscription, $recorded, $pendingOrderId);
            if ($outcome !== NULL) {
              return $outcome;
            }
            // The donation belongs to an earlier period and the state with
            // it is a leftover: start this period afresh.
            $this->saveState($id, NULL);
            $state = [];
            $response = NULL;
          }
          else {
            if ($response === NULL) {
              $note = sprintf(__('PayArc has no record of the earlier charge %s; charging now.', 'payarc-payments'), $pendingOrderId);
            }
            elseif (Shared::approved($response)) {
              $note = sprintf(__('PayArc confirms the earlier charge %s was processed; recorded without charging again.', 'payarc-payments'), $pendingOrderId);
            }
            else {
              $note = sprintf(__('PayArc shows the earlier charge %s was declined; recorded without charging again.', 'payarc-payments'), $pendingOrderId);
            }
            SubscriptionNote::create(['subscriptionId' => $id, 'content' => $note]);
          }
        }

        $attempt = (int) ($state['attempts'] ?? 0);
        $scheduled = $subscription->renewsAt instanceof \DateTimeInterface ? $subscription->renewsAt->format('Y-m-d') : $now->format('Y-m-d');
        $installment = (string) ($state['installment'] ?? $scheduled);
        $orderId = Shared::orderId(sprintf('give-sub-%d-%s-%d', $id, $installment, $attempt));

        if ($response === NULL) {
          $initial = $subscription->initialDonation();
          $payer = $initial ? [
            'email' => (string) $initial->email,
            'first_name' => (string) $initial->firstName,
            'last_name' => (string) $initial->lastName,
            'phone' => (string) $initial->phone,
            'address' => (string) ($initial->billingAddress->address1 ?? ''),
            'address2' => (string) ($initial->billingAddress->address2 ?? ''),
            'city' => (string) ($initial->billingAddress->city ?? ''),
            'state' => (string) ($initial->billingAddress->state ?? ''),
            'postcode' => (string) ($initial->billingAddress->zip ?? ''),
            'country' => (string) ($initial->billingAddress->country ?? ''),
          ] : [];
          $currency = 'USD';
          try {
            $currency = (string) $subscription->amount->getCurrency()->getCode();
          }
          catch (\Throwable $e) {
            // keep USD
          }
          $metadata = Plugin::instance()->gateway()->metadata('GIVE-S' . $id, (string) ($initial ? $initial->formTitle : __('Recurring donation', 'payarc-payments')), $payer, ['currency' => $currency, 'orderid' => $orderId]);
          unset($metadata['clientip']);
          $this->setMarker($id, $orderId);
          $response = $client->saleWithCardReference((string) $subscription->gatewaySubscriptionId, $amount, $metadata);
        }
      }
      catch (ReconciliationInconclusiveException $e) {
        Log::error('GiveWP renewal reconciliation inconclusive', ['subscription' => $id, 'error' => $e->getMessage()]);
        SubscriptionNote::create(['subscriptionId' => $id, 'content' => sprintf(__('PayArc could not confirm whether the charge %s was processed. No new charge was sent; it will be checked again next hour.', 'payarc-payments'), $pendingOrderId)]);
        return 'ambiguous';
      }
      catch (AmbiguousGatewayException $e) {
        Log::error('GiveWP renewal ambiguous', ['subscription' => $id, 'error' => $e->getMessage()]);
        try {
          $found = $this->findTransaction($client, $orderId, time(), $amount);
        }
        catch (ReconciliationInconclusiveException $lookup) {
          $found = NULL;
        }
        if (!$found) {
          // The marker stays: the next run reconciles before any retry.
          SubscriptionNote::create(['subscriptionId' => $id, 'content' => sprintf(__('PayArc did not answer when charging the renewal due %1$s (attempt %2$d). It will be checked again next hour before any retry.', 'payarc-payments'), $installment, $attempt + 1)]);
          return 'ambiguous';
        }
        $response = $found;
      }
      catch (GatewayException $e) {
        // The gateway answered: nothing was charged.
        $this->clearMarker($id);
        $response = ['result_code' => 'E', 'error' => $e->getMessage()] + $e->getResponseData();
      }
      catch (\Throwable $e) {
        // Misconfiguration or a coding error must not kill the whole cron run.
        // A marker already written stays, so the next run looks it up first.
        Log::error('GiveWP renewal skipped', ['subscription' => $id, 'error' => $e->getMessage()]);
        SubscriptionNote::create(['subscriptionId' => $id, 'content' => sprintf(__('PayArc renewal skipped: %s', 'payarc-payments'), $e->getMessage())]);
        return 'skipped';
      }

      if (Shared::approved($response)) {
        return $this->recordSuccess($subscription, $response, $installment);
      }
      return $this->recordFailure($subscription, $response, $installment, $attempt, $now);
    }
    finally {
      Lock::release($lockKey, $lock);
    }
  }

  /**
   * A renewal donation for the earlier charge already exists.
   *
   * GiveWP records a renewal in two steps, the donation and then the next
   * renewal date. When a run died between them the subscription still shows
   * this period as due and the donation, created after the due date, is the
   * one for it: only the date is moved now. A donation created before the
   * current renewal date belongs to an earlier period, so the marker is a
   * leftover of a run that died after finishing; NULL then says this period
   * is still to be charged.
   *
   * @return string|null
   *   charged | completed, or NULL when the donation is an earlier period's.
   */
  private function finishRecorded(Subscription $subscription, Donation $donation, string $pendingOrderId): ?string {
    $id = (int) $subscription->id;
    $due = $subscription->renewsAt;
    $created = $donation->createdAt;
    if (!($due instanceof \DateTimeInterface) || !($created instanceof \DateTimeInterface) || $created < $due) {
      return NULL;
    }
    $state = $this->state($id);
    if (!empty($state['renews_at'])) {
      // A retry moved renewsAt to the retry date; advance from the real one.
      $subscription->renewsAt = new \DateTime((string) $state['renews_at'], wp_timezone());
    }
    $subscription->bumpRenewalDate();
    if ($subscription->status->isFailing()) {
      $subscription->status = SubscriptionStatus::ACTIVE();
    }
    $subscription->save();
    $this->saveState($id, NULL);
    SubscriptionNote::create(['subscriptionId' => $id, 'content' => sprintf(__('PayArc confirms the earlier charge %1$s was processed and donation #%2$d had already been recorded for it; the next renewal date is now set.', 'payarc-payments'), $pendingOrderId, (int) $donation->id)]);
    if ($this->installmentsDone($subscription)) {
      $subscription->status = SubscriptionStatus::COMPLETED();
      $subscription->save();
      SubscriptionNote::create(['subscriptionId' => $id, 'content' => __('All scheduled donations have been made.', 'payarc-payments')]);
      return 'completed';
    }
    return 'charged';
  }

  private function recordSuccess(Subscription $subscription, array $response, string $installment): string {
    $id = (int) $subscription->id;
    $transaction = Shared::transactionReference($response);
    $existing = $transaction !== '' ? give()->donations->getByGatewayTransactionId($transaction) : NULL;
    if ($existing) {
      // Reached only through the pending-marker path, which handles this
      // before charging; kept so a renewal date is never moved twice here.
      $outcome = $this->finishRecorded($subscription, $existing, $transaction);
      if ($outcome !== NULL) {
        return $outcome;
      }
      Log::error('GiveWP renewal already recorded for an earlier period', ['subscription' => $id, 'transaction' => $transaction]);
      $this->clearMarker($id);
      return 'skipped';
    }
    // A retry moved renewsAt to the retry date; put the real due date back so
    // createRenewal() advances from it and the billing anniversary stays put.
    $state = $this->state($id);
    if (!empty($state['renews_at'])) {
      $subscription->renewsAt = new \DateTime((string) $state['renews_at'], wp_timezone());
    }
    if ($subscription->status->isFailing()) {
      $subscription->status = SubscriptionStatus::ACTIVE();
    }
    $subscription->save();
    // The donation, which carries the transaction key, is the commit point:
    // a run that dies after it is finished by finishRecorded() next time.
    $donation = $subscription->createRenewal(['gatewayTransactionId' => $transaction]);
    DonationNote::create([
      'donationId' => $donation->id,
      'content' => sprintf(__('Renewal due %1$s charged via PayArc. %2$s', 'payarc-payments'), $installment, (new Gateway())->gatewayNote($response)),
    ]);
    $this->saveState($id, NULL);

    if ($this->installmentsDone($subscription)) {
      $subscription->status = SubscriptionStatus::COMPLETED();
      $subscription->save();
      SubscriptionNote::create(['subscriptionId' => $id, 'content' => __('All scheduled donations have been made.', 'payarc-payments')]);
      return 'completed';
    }
    return 'charged';
  }

  private function recordFailure(Subscription $subscription, array $response, string $installment, int $attempt, \DateTimeImmutable $now): string {
    $id = (int) $subscription->id;
    $failure = Shared::failure($response);
    $attempt++;
    Log::error('GiveWP renewal declined', ['subscription' => $id, 'attempt' => $attempt, 'gateway' => $failure['gateway']]);

    if ($attempt >= self::MAX_ATTEMPTS) {
      $subscription->status = SubscriptionStatus::CANCELLED();
      $subscription->save();
      SubscriptionNote::create(['subscriptionId' => $id, 'content' => sprintf(__('Renewal due %1$s declined (attempt %2$d of %3$d): %4$s. Subscription cancelled; the donor may start a new one.', 'payarc-payments'), $installment, $attempt, self::MAX_ATTEMPTS, $failure['gateway'])]);
      $this->saveState($id, NULL);
      return 'cancelled';
    }

    $retry = $now->modify('+' . self::RETRY_DAYS . ' days');
    $state = $this->state($id);
    if (empty($state['renews_at']) && $subscription->renewsAt instanceof \DateTimeInterface) {
      // Remember the real due date; renewsAt is about to hold the retry date.
      $state['renews_at'] = $subscription->renewsAt->format('Y-m-d H:i:s');
    }
    $subscription->status = SubscriptionStatus::FAILING();
    $subscription->renewsAt = \DateTime::createFromImmutable($retry);
    $subscription->save();
    // The new state carries no marker: the decline was a conclusive answer.
    $this->saveState($id, ['attempts' => $attempt, 'installment' => $installment, 'renews_at' => $state['renews_at'] ?? NULL]);
    SubscriptionNote::create(['subscriptionId' => $id, 'content' => sprintf(__('Renewal due %1$s declined (attempt %2$d of %3$d): %4$s. Next attempt %5$s.', 'payarc-payments'), $installment, $attempt, self::MAX_ATTEMPTS, $failure['gateway'], $retry->format('Y-m-d H:i'))]);
    return 'declined';
  }

  /**
   * GiveWP's own end condition (Subscription::shouldEndSubscription): the
   * initial donation plus renewals has reached the installment count.
   * hasExceededTheMaxInstallments() is strictly "greater than" and would let
   * one installment too many through.
   */
  private function installmentsDone(Subscription $subscription): bool {
    return !$subscription->isIndefinite() && $subscription->totalDonations() >= (int) $subscription->installments;
  }

  /**
   * The listed transaction carrying this orderid (approved or declined), or
   * NULL when PayArc provably has none.
   *
   * @throws \Payarc\ReconciliationInconclusiveException
   *   When the answer is unknown: the listing window ran out or the lookup
   *   itself failed. Callers must not charge.
   */
  private function findTransaction(\Payarc\GatewayClient $client, string $orderId, ?int $sentAt, string $amount): ?array {
    try {
      return $client->findTransactionByOrderId($orderId, $sentAt, 5, $amount);
    }
    catch (ReconciliationInconclusiveException $e) {
      throw $e;
    }
    catch (\Throwable $lookup) {
      Log::error('GiveWP renewal reconciliation failed', ['error' => $lookup->getMessage()]);
      throw new ReconciliationInconclusiveException('The lookup for ' . $orderId . ' failed: ' . $lookup->getMessage(), 0, [], $lookup);
    }
  }

  /**
   * Record that a charge with this orderid is about to be sent.
   */
  private function setMarker(int $subscriptionId, string $orderId): void {
    $state = $this->state($subscriptionId);
    $state['reconcile'] = $orderId;
    $state['reconcile_sent_at'] = time();
    $this->saveState($subscriptionId, $state);
  }

  private function clearMarker(int $subscriptionId): void {
    $state = $this->state($subscriptionId);
    unset($state['reconcile'], $state['reconcile_sent_at']);
    $this->saveState($subscriptionId, $state ?: NULL);
  }

  private function state(int $subscriptionId): array {
    $all = get_option(self::STATE, []);
    return is_array($all) && isset($all[$subscriptionId]) && is_array($all[$subscriptionId]) ? $all[$subscriptionId] : [];
  }

  private function saveState(int $subscriptionId, ?array $state): void {
    $all = get_option(self::STATE, []);
    $all = is_array($all) ? $all : [];
    if ($state === NULL) {
      unset($all[$subscriptionId]);
    }
    else {
      $all[$subscriptionId] = $state;
    }
    update_option(self::STATE, $all, FALSE);
  }

}
