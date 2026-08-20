<?php

namespace justinholtweb\sevvies\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\sevvies\errors\ApiException;
use justinholtweb\sevvies\Plugin;
use yii\console\ExitCode;

/**
 * Connection checks and housekeeping.
 */
class ToolsController extends Controller
{
    /** @var int Days of log to keep. Defaults to the configured retention. */
    public int $days = 0;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), $actionID === 'prune-log' ? ['days'] : []);
    }

    /**
     * Check the token and show what the account looks like.
     */
    public function actionCheck(): int
    {
        $plugin = Plugin::getInstance();
        $plugin->meta->flush();

        $result = $plugin->api->check();

        if (!$result['ok']) {
            $this->stderr('✗ ' . $result['message'] . PHP_EOL, Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout('✓ ' . $result['message'] . PHP_EOL, Console::FG_GREEN);

        try {
            $accounts = $plugin->meta->checkAccounts(true);
            $this->stdout(PHP_EOL . 'Check accounts:' . PHP_EOL, Console::FG_GREY);

            foreach ($accounts as $account) {
                $this->stdout(sprintf("  #%d  %s  (%s, %s)\n", $account['id'], $account['name'], $account['type'], $account['currency']));
            }

            $users = $plugin->meta->users(true);
            $this->stdout(PHP_EOL . 'Users:' . PHP_EOL, Console::FG_GREY);

            foreach ($users as $user) {
                $this->stdout(sprintf("  #%d  %s\n", $user['id'], $user['name']));
            }

            $this->stdout(PHP_EOL . sprintf("Countries known: %d\n", count($plugin->meta->countries(true))), Console::FG_GREY);
        } catch (ApiException $e) {
            $this->stderr('Could not read the account details: ' . $e->getMessage() . PHP_EOL, Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    /**
     * Drop log entries past the retention window.
     */
    public function actionPruneLog(): int
    {
        $removed = Plugin::getInstance()->log->prune($this->days ?: null);

        $this->stdout("Removed {$removed} log entries." . PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Forget every cached sevDesk id — countries, users, check accounts.
     */
    public function actionFlushCache(): int
    {
        Plugin::getInstance()->meta->flush();
        $this->stdout('Cache cleared.' . PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
    }
}
