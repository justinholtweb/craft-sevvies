<?php

namespace justinholtweb\sevvies;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\Order;
use craft\commerce\events\OrderStatusEvent;
use craft\commerce\events\TransactionEvent;
use craft\commerce\services\OrderHistories;
use craft\commerce\services\Payments as CommercePayments;
use craft\commerce\Plugin as Commerce;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use justinholtweb\sevvies\models\Settings;
use justinholtweb\sevvies\queue\jobs\SyncOrder;
use justinholtweb\sevvies\services\Api;
use justinholtweb\sevvies\services\Contacts;
use justinholtweb\sevvies\services\Documents;
use justinholtweb\sevvies\services\Invoices;
use justinholtweb\sevvies\services\Log;
use justinholtweb\sevvies\services\Meta;
use justinholtweb\sevvies\services\Payments;
use justinholtweb\sevvies\services\Tax;
use justinholtweb\sevvies\twig\SevviesVariable;
use yii\base\Event;

/**
 * Sevvies — sevDesk invoicing for Craft Commerce.
 *
 * @property-read Api $api
 * @property-read Contacts $contacts
 * @property-read Documents $documents
 * @property-read Invoices $invoices
 * @property-read Log $log
 * @property-read Meta $meta
 * @property-read Payments $payments
 * @property-read Tax $tax
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public const PERMISSION_MANAGE = 'sevvies:manage';
    public const PERMISSION_SETTINGS = 'sevvies:settings';

    public string $schemaVersion = '5.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function editions(): array
    {
        return [self::EDITION_LITE, self::EDITION_PRO];
    }

    public static function config(): array
    {
        return [
            'components' => [
                'api' => ['class' => Api::class],
                'contacts' => ['class' => Contacts::class],
                'documents' => ['class' => Documents::class],
                'invoices' => ['class' => Invoices::class],
                'log' => ['class' => Log::class],
                'meta' => ['class' => Meta::class],
                'payments' => ['class' => Payments::class],
                'tax' => ['class' => Tax::class],
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerRoutes();
        $this->registerPermissions();
        $this->registerVariable();

        // Commerce may not be installed yet during a composer update.
        if (!class_exists(Commerce::class) || Craft::$app->getPlugins()->getPlugin('commerce') === null) {
            return;
        }

        Craft::$app->onInit(function(): void {
            $this->registerOrderEvents();
            $this->registerOrderPanel();
        });
    }

    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO);
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(
            \craft\helpers\UrlHelper::cpUrl('sevvies/settings'),
        );
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();

        if ($item === null) {
            return null;
        }

        $item['label'] = Craft::t('sevvies', 'Sevvies');
        $item['subnav'] = [
            'invoices' => ['label' => Craft::t('sevvies', 'Invoices'), 'url' => 'sevvies'],
            'log' => ['label' => Craft::t('sevvies', 'Log'), 'url' => 'sevvies/log'],
        ];

        if (Craft::$app->getUser()->checkPermission(self::PERMISSION_SETTINGS)) {
            $item['subnav']['settings'] = [
                'label' => Craft::t('sevvies', 'Settings'),
                'url' => 'sevvies/settings',
            ];
        }

        return $item;
    }

    // ——————————————————————————————————————————————————————————————

    private function registerRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, static function(RegisterUrlRulesEvent $event): void {
            $event->rules['sevvies'] = 'sevvies/invoices/index';
            $event->rules['sevvies/settings'] = 'sevvies/settings/edit';
            $event->rules['sevvies/invoices/<orderId:\d+>'] = 'sevvies/invoices/detail';
            $event->rules['sevvies/log'] = 'sevvies/log/index';
            $event->rules['sevvies/log/<id:\d+>'] = 'sevvies/log/detail';
        });
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, static function(RegisterUserPermissionsEvent $event): void {
            $event->permissions[] = [
                'heading' => Craft::t('sevvies', 'Sevvies'),
                'permissions' => [
                    self::PERMISSION_MANAGE => [
                        'label' => Craft::t('sevvies', 'Sync orders and view invoices'),
                    ],
                    self::PERMISSION_SETTINGS => [
                        'label' => Craft::t('sevvies', 'Change Sevvies settings'),
                    ],
                ],
            ];
        });
    }

    private function registerVariable(): void
    {
        Event::on(\craft\web\twig\variables\CraftVariable::class, \craft\web\twig\variables\CraftVariable::EVENT_INIT, static function(Event $event): void {
            /** @var \craft\web\twig\variables\CraftVariable $variable */
            $variable = $event->sender;
            $variable->set('sevvies', SevviesVariable::class);
        });
    }

    /**
     * A panel on Commerce's own order screen. The merchant should never have to
     * go looking for the plugin to find out whether an order is filed.
     */
    private function registerOrderPanel(): void
    {
        Craft::$app->getView()->hook('cp.commerce.order.edit.details', function(array &$context): string {
            if (!Craft::$app->getUser()->checkPermission(self::PERMISSION_MANAGE)) {
                return '';
            }

            $order = $context['order'] ?? null;

            if (!$order instanceof Order || !$order->id) {
                return '';
            }

            return Craft::$app->getView()->renderTemplate('sevvies/_order-panel', [
                'order' => $order,
                'record' => $this->invoices->recordFor($order->id),
                'isPro' => $this->isPro(),
                'settings' => $this->getSettings(),
            ], \craft\web\View::TEMPLATE_MODE_CP);
        });
    }

    /**
     * The order lifecycle hooks.
     *
     * All of them fail open. sevDesk being unreachable must never stop a
     * customer paying, and must never stop Commerce recording that they did.
     */
    private function registerOrderEvents(): void
    {
        Event::on(Order::class, Order::EVENT_AFTER_COMPLETE_ORDER, function(Event $event): void {
            /** @var Order $order */
            $order = $event->sender;
            $this->maybeSync($order, Settings::TRIGGER_COMPLETE);
        });

        Event::on(Order::class, Order::EVENT_AFTER_ORDER_PAID, function(Event $event): void {
            /** @var Order $order */
            $order = $event->sender;

            if ($this->maybeSync($order, Settings::TRIGGER_PAID)) {
                return;
            }

            // Already invoiced under a different trigger — book the payment.
            $this->maybeBook($order);
        });

        Event::on(OrderHistories::class, OrderHistories::EVENT_ORDER_STATUS_CHANGE, function(OrderStatusEvent $event): void {
            $settings = $this->getSettings();

            if ($settings->trigger !== Settings::TRIGGER_STATUS) {
                return;
            }

            $handle = $event->order->getOrderStatus()?->handle;

            if ($handle === null || !in_array($handle, $settings->triggerStatuses, true)) {
                return;
            }

            $this->maybeSync($event->order, Settings::TRIGGER_STATUS, true);
        });

        Event::on(CommercePayments::class, CommercePayments::EVENT_AFTER_REFUND_TRANSACTION, function(TransactionEvent $event): void {
            $settings = $this->getSettings();

            if ($settings->refundMode === Settings::REFUND_NONE || !$this->isPro()) {
                return;
            }

            $order = $event->transaction->getOrder();

            try {
                $this->payments->refund($order, $event->transaction);
            } catch (\Throwable $e) {
                $this->log->note(
                    Craft::t('sevvies', 'The refund could not be mirrored to sevDesk.'),
                    $order->id,
                    false,
                    'refund',
                    $e->getMessage(),
                );
            }
        });
    }

    /**
     * Queue or run a sync, if this trigger is the configured one.
     *
     * @return bool Whether the order was handed off for syncing.
     */
    private function maybeSync(Order $order, string $trigger, bool $ignoreConfigured = false): bool
    {
        $settings = $this->getSettings();

        if (!$ignoreConfigured && $settings->trigger !== $trigger) {
            return false;
        }

        if ($settings->trigger === Settings::TRIGGER_OFF || !$order->id || !$order->isCompleted) {
            return false;
        }

        if (!$this->api->isConfigured()) {
            return false;
        }

        if (!$this->matchesCondition($order)) {
            return false;
        }

        if ($this->invoices->recordFor($order->id)?->sevdeskId) {
            return false;
        }

        try {
            if ($settings->useQueue) {
                Craft::$app->getQueue()->push(new SyncOrder(['orderId' => (int)$order->id]));
            } else {
                $this->invoices->sync($order);
            }
        } catch (\Throwable $e) {
            $this->log->note(
                Craft::t('sevvies', 'The order could not be sent to sevDesk.'),
                $order->id,
                false,
                'invoice',
                $e->getMessage(),
            );
        }

        return true;
    }

    private function maybeBook(Order $order): void
    {
        $settings = $this->getSettings();

        if (!$settings->bookPayments || !$this->isPro() || !$this->api->isConfigured()) {
            return;
        }

        try {
            $this->payments->book($order);
        } catch (\Throwable $e) {
            $this->log->note(
                Craft::t('sevvies', 'The payment could not be booked in sevDesk.'),
                $order->id,
                false,
                'payment',
                $e->getMessage(),
            );
        }
    }

    /**
     * Pro: an order condition deciding which orders get invoiced at all.
     */
    public function matchesCondition(Order $order): bool
    {
        $settings = $this->getSettings();

        if (!$this->isPro() || empty($settings->orderCondition)) {
            return true;
        }

        try {
            /** @var \craft\commerce\elements\conditions\orders\OrderCondition $condition */
            $condition = Craft::$app->getConditions()->createCondition($settings->orderCondition);

            return $condition->matchElement($order);
        } catch (\Throwable $e) {
            // A broken condition must not silently stop every invoice.
            $this->log->note(
                Craft::t('sevvies', 'The order condition could not be evaluated, so the order was included.'),
                $order->id,
                false,
                'invoice',
                $e->getMessage(),
            );

            return true;
        }
    }
}
