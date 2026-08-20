<?php

namespace justinholtweb\sevvies\queue\jobs;

use Craft;
use craft\commerce\elements\Order;
use craft\queue\BaseJob;
use justinholtweb\sevvies\errors\ApiException;
use justinholtweb\sevvies\Plugin;

/**
 * Sends one order to sevDesk.
 *
 * Retries only what is worth retrying: a rejected payload will be rejected the
 * same way forever, so it fails the job once and stays visible in the log
 * rather than churning the queue.
 */
class SyncOrder extends BaseJob
{
    public int $orderId = 0;
    public bool $force = false;

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $order = Order::find()->id($this->orderId)->status(null)->one();

        if (!$order instanceof Order) {
            return;
        }

        $this->setProgress($queue, 0.1, Craft::t('sevvies', 'Building the invoice'));

        try {
            $record = $plugin->invoices->sync($order, $this->force);
        } catch (ApiException $e) {
            if ($e->isTransient() && $this->attemptsLeft($order->id)) {
                // Let the queue back this off and try again.
                throw $e;
            }

            $plugin->log->note(
                Craft::t('sevvies', 'Giving up on order {reference}.', [
                    'reference' => (string)($order->reference ?: $order->number),
                ]),
                $order->id,
                false,
                'invoice',
                $e->getMessage(),
            );

            return;
        }

        $this->setProgress($queue, 1, $record->invoiceNumber ?: '');
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('sevvies', 'Sending an order to sevDesk');
    }

    private function attemptsLeft(int $orderId): bool
    {
        $max = Plugin::getInstance()->getSettings()->maxAttempts;

        if ($max <= 0) {
            return false;
        }

        $record = Plugin::getInstance()->invoices->recordFor($orderId);

        return $record === null || (int)$record->attempts < $max;
    }
}
