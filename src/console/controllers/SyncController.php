<?php

namespace justinholtweb\sevvies\console\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\Json;
use justinholtweb\sevvies\errors\ApiException;
use justinholtweb\sevvies\Plugin;
use justinholtweb\sevvies\services\Invoices;
use yii\console\ExitCode;

/**
 * Sending orders to sevDesk from the command line.
 */
class SyncController extends Controller
{
    /** @var bool Send even if the order already has an invoice. */
    public bool $force = false;

    /** @var int How many orders to work through. */
    public int $limit = 50;

    /** @var bool Build the payloads but send nothing. */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'order' => ['force'],
            'pending' => ['limit', 'force', 'dryRun'],
            default => [],
        });
    }

    /**
     * Send one order to sevDesk.
     */
    public function actionOrder(int $orderId): int
    {
        $order = Order::find()->id($orderId)->status(null)->one();

        if (!$order instanceof Order) {
            $this->stderr("No order with id {$orderId}." . PHP_EOL, Console::FG_RED);

            return ExitCode::DATAERR;
        }

        return $this->send($order);
    }

    /**
     * Send every completed order that has no sevDesk invoice yet.
     */
    public function actionPending(): int
    {
        $plugin = Plugin::getInstance();

        if ($this->dryRun) {
            $plugin->getSettings()->dryRun = true;
        }

        $ids = $plugin->invoices->pendingOrderIds($this->limit);

        if ($ids === []) {
            $this->stdout('Nothing pending.' . PHP_EOL, Console::FG_GREEN);

            return ExitCode::OK;
        }

        $this->stdout(count($ids) . ' orders to send.' . PHP_EOL);

        $failed = 0;

        foreach ($ids as $id) {
            $order = Order::find()->id($id)->status(null)->one();

            if (!$order instanceof Order) {
                continue;
            }

            if ($this->send($order) !== ExitCode::OK) {
                $failed++;
            }
        }

        return $failed === 0 ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Print the payload for an order without sending it.
     */
    public function actionPreview(int $orderId): int
    {
        $order = Order::find()->id($orderId)->status(null)->one();

        if (!$order instanceof Order) {
            $this->stderr("No order with id {$orderId}." . PHP_EOL, Console::FG_RED);

            return ExitCode::DATAERR;
        }

        try {
            $draft = Plugin::getInstance()->invoices->build($order);
        } catch (ApiException $e) {
            // A preview should explain itself rather than throw a stack trace at
            // someone who has simply not finished setting the plugin up.
            $this->stderr('✗ ' . $e->getMessage() . PHP_EOL, Console::FG_RED);
            $this->stderr('  Run `craft sevvies/tools/check` to test the connection.' . PHP_EOL, Console::FG_GREY);

            return ExitCode::UNAVAILABLE;
        }

        $this->stdout('VAT rule: ', Console::FG_GREY);
        $this->stdout(($draft->decision?->label ?? '') . ' (taxRule ' . ($draft->decision?->rule ?? '?') . ')' . PHP_EOL);
        $this->stdout('Reason:   ', Console::FG_GREY);
        $this->stdout(($draft->decision?->reason ?? '') . PHP_EOL);
        $this->stdout('Commerce: ', Console::FG_GREY);
        $this->stdout(number_format($draft->expectedGross, 2) . ' ' . $draft->currency . PHP_EOL);
        $this->stdout('sevDesk:  ', Console::FG_GREY);
        $this->stdout(
            number_format($draft->computedGross, 2) . ' ' . $draft->currency . PHP_EOL,
            $draft->balances() ? Console::FG_GREEN : Console::FG_RED,
        );

        foreach ($draft->blockers as $blocker) {
            $this->stderr('! ' . $blocker . PHP_EOL, Console::FG_RED);
        }

        $this->stdout(PHP_EOL . Json::encode($draft->payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        return $draft->isSendable() ? ExitCode::OK : ExitCode::DATAERR;
    }

    private function send(Order $order): int
    {
        $reference = (string)($order->reference ?: $order->number);

        try {
            $record = Plugin::getInstance()->invoices->sync($order, $this->force);
        } catch (ApiException $e) {
            $this->stderr("✗ {$reference}: {$e->getMessage()}" . PHP_EOL, Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($record->state === Invoices::STATE_BLOCKED) {
            $this->stderr("✗ {$reference}: {$record->lastError}" . PHP_EOL, Console::FG_YELLOW);

            return ExitCode::DATAERR;
        }

        if ($record->state === Invoices::STATE_SKIPPED) {
            $this->stdout("· {$reference}: dry run" . PHP_EOL, Console::FG_GREY);

            return ExitCode::OK;
        }

        $this->stdout("✓ {$reference}: " . ($record->invoiceNumber ?: '#' . $record->sevdeskId) . PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
    }
}
