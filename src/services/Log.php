<?php

namespace justinholtweb\sevvies\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use justinholtweb\sevvies\db\Table;
use justinholtweb\sevvies\Plugin;
use justinholtweb\sevvies\records\LogRecord;
use yii\base\Component;

/**
 * The connection log.
 *
 * A bookkeeping integration that cannot show what it sent is unauditable, so
 * every request, decision and failure lands here.
 */
class Log extends Component
{
    /**
     * Write one entry. Logging must never be the reason a sync fails, so a
     * write error is swallowed into Craft's own log.
     */
    public function record(array $attributes): void
    {
        try {
            $record = new LogRecord();
            $record->orderId = $attributes['orderId'] ?? null;
            $record->type = $attributes['type'] ?? 'info';
            $record->method = $attributes['method'] ?? null;
            $record->endpoint = $attributes['endpoint'] ?? null;
            $record->statusCode = $attributes['statusCode'] ?? null;
            $record->success = (bool)($attributes['success'] ?? true);
            $record->durationMs = $attributes['durationMs'] ?? null;
            $record->message = $attributes['message'] ?? null;
            $record->requestBody = $attributes['requestBody'] ?? null;
            $record->responseBody = $attributes['responseBody'] ?? null;
            $record->save(false);
        } catch (\Throwable $e) {
            Craft::warning('Could not write a Sevvies log entry: ' . $e->getMessage(), 'sevvies');
        }
    }

    /**
     * A non-HTTP note — a decision, a skip, a reconciliation result.
     */
    public function note(string $message, ?int $orderId = null, bool $success = true, string $type = 'info', ?string $detail = null): void
    {
        $this->record([
            'orderId' => $orderId,
            'type' => $type,
            'success' => $success,
            'message' => $message,
            'responseBody' => $detail,
        ]);
    }

    public function query(): Query
    {
        return (new Query())->from([Table::LOG])->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC]);
    }

    public function find(int $id): ?array
    {
        $row = (new Query())->from([Table::LOG])->where(['id' => $id])->one();

        return $row ?: null;
    }

    /**
     * Drop entries older than the retention window. Returns the number removed.
     */
    public function prune(?int $days = null): int
    {
        $days ??= Plugin::getInstance()->getSettings()->logRetentionDays;

        if ($days <= 0) {
            return 0;
        }

        $cutoff = (new DateTime('now'))->modify("-{$days} days");

        return (int)Craft::$app->getDb()->createCommand()
            ->delete(Table::LOG, ['<', 'dateCreated', Db::prepareDateForDb($cutoff)])
            ->execute();
    }

    public function clear(): int
    {
        return (int)Craft::$app->getDb()->createCommand()->delete(Table::LOG)->execute();
    }
}
