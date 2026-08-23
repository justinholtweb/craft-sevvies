<?php

namespace justinholtweb\sevvies\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\helpers\Json;
use craft\web\Controller;
use justinholtweb\sevvies\db\Table;
use justinholtweb\sevvies\errors\ApiException;
use justinholtweb\sevvies\Plugin;
use justinholtweb\sevvies\services\Invoices;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The invoices screen, the per-order detail, and the actions on both.
 */
class InvoicesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        return true;
    }

    public function actionIndex(): Response
    {
        $state = $this->request->getParam('state');
        $page = max(1, (int)$this->request->getParam('page', 1));
        $perPage = 50;

        $query = (new Query())
            ->from(['i' => Table::INVOICES])
            ->orderBy(['i.dateCreated' => SORT_DESC]);

        if ($state) {
            $query->where(['i.state' => $state]);
        }

        $total = (int)(clone $query)->count();
        $rows = $query->offset(($page - 1) * $perPage)->limit($perPage)->all();

        $orders = [];

        if ($rows !== []) {
            foreach (Order::find()->id(array_column($rows, 'orderId'))->status(null)->all() as $order) {
                $orders[$order->id] = $order;
            }
        }

        return $this->renderTemplate('sevvies/invoices/_index', [
            'rows' => $rows,
            'orders' => $orders,
            'state' => $state,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'counts' => $this->counts(),
        ]);
    }

    public function actionDetail(int $orderId): Response
    {
        $plugin = Plugin::getInstance();
        $order = $this->order($orderId);
        $record = $plugin->invoices->recordFor($orderId);

        $draft = null;
        $buildError = null;

        try {
            $draft = $plugin->invoices->build($order, $record?->contactId ? (int)$record->contactId : null);
        } catch (ApiException $e) {
            $buildError = Craft::t('sevvies', '{message} Sevvies needs to read your sevDesk account to build a document — check the connection on the settings screen.', [
                'message' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            $buildError = $e->getMessage();
        }

        return $this->renderTemplate('sevvies/invoices/_detail', [
            'order' => $order,
            'record' => $record,
            'draft' => $draft,
            'buildError' => $buildError,
            'payload' => $draft ? Json::encode($draft->payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'settings' => $plugin->getSettings(),
        ]);
    }

    /**
     * Create the invoice now.
     */
    public function actionSync(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $orderId = (int)$this->request->getRequiredBodyParam('orderId');
        $order = $this->order($orderId);

        try {
            $record = $plugin->invoices->sync($order, (bool)$this->request->getBodyParam('force'));
        } catch (ApiException $e) {
            return $this->failure($e->getMessage());
        }

        if ($record->state === Invoices::STATE_BLOCKED) {
            return $this->failure((string)$record->lastError);
        }

        if ($record->state === Invoices::STATE_SKIPPED) {
            return $this->success(Craft::t('sevvies', 'Dry run — nothing was sent. The payload is in the log.'));
        }

        return $this->success(Craft::t('sevvies', 'Invoice {number} created.', [
            'number' => $record->invoiceNumber ?: (string)$record->sevdeskId,
        ]));
    }

    /**
     * Send, or mark as sent.
     */
    public function actionSend(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $orderId = (int)$this->request->getRequiredBodyParam('orderId');
        $order = $this->order($orderId);
        $record = $plugin->invoices->recordFor($orderId);

        if ($record === null || !$record->sevdeskId) {
            return $this->failure(Craft::t('sevvies', 'This order has no sevDesk invoice yet.'));
        }

        try {
            $sent = $this->request->getBodyParam('mode') === 'email'
                ? $plugin->documents->sendEmail($order, $record)
                : $plugin->documents->markSent($order, $record);
        } catch (ApiException $e) {
            return $this->failure($e->getMessage());
        }

        return $sent
            ? $this->success(Craft::t('sevvies', 'Invoice sent.'))
            : $this->failure(Craft::t('sevvies', 'The invoice was not sent.'));
    }

    /**
     * Book the payment against the invoice.
     */
    public function actionBook(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();

        $orderId = (int)$this->request->getRequiredBodyParam('orderId');
        $order = $this->order($orderId);

        try {
            $booked = $plugin->payments->book($order);
        } catch (ApiException $e) {
            return $this->failure($e->getMessage());
        }

        return $booked
            ? $this->success(Craft::t('sevvies', 'Payment booked.'))
            : $this->failure(Craft::t('sevvies', 'There was nothing to book.'));
    }

    /**
     * Download the PDF straight from sevDesk.
     */
    public function actionPdf(int $orderId): Response
    {
        $plugin = Plugin::getInstance();
        $record = $plugin->invoices->recordFor($orderId);

        if ($record === null || !$record->sevdeskId) {
            throw new NotFoundHttpException(Craft::t('sevvies', 'This order has no sevDesk invoice yet.'));
        }

        try {
            $contents = $plugin->documents->pdf($record);
        } catch (ApiException $e) {
            $this->setFailFlash($e->getMessage());

            return $this->redirect("sevvies/invoices/{$orderId}");
        }

        return $this->response->sendContentAsFile(
            $contents,
            sprintf('%s.pdf', $record->invoiceNumber ?: 'invoice-' . $record->sevdeskId),
            ['mimeType' => 'application/pdf'],
        );
    }

    /**
     * Forget the link locally. The sevDesk document is left alone.
     */
    public function actionForget(): Response
    {
        $this->requirePostRequest();

        $orderId = (int)$this->request->getRequiredBodyParam('orderId');
        Plugin::getInstance()->invoices->forget($orderId);

        return $this->success(Craft::t('sevvies', 'Sevvies has forgotten this order. The sevDesk document was not touched.'));
    }

    // ——————————————————————————————————————————————————————————————

    private function order(int $orderId): Order
    {
        $order = Order::find()->id($orderId)->status(null)->one();

        if (!$order instanceof Order) {
            throw new NotFoundHttpException(Craft::t('sevvies', 'Order not found.'));
        }

        return $order;
    }

    /**
     * @return array<string,int>
     */
    private function counts(): array
    {
        $counts = [];

        foreach ((new Query())
            ->select(['state', 'total' => 'COUNT(*)'])
            ->from([Table::INVOICES])
            ->groupBy(['state'])
            ->all() as $row) {
            $counts[(string)$row['state']] = (int)$row['total'];
        }

        return $counts;
    }

    private function success(string $message): Response
    {
        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => true, 'message' => $message]);
        }

        $this->setSuccessFlash($message);

        return $this->redirectToPostedUrl();
    }

    private function failure(string $message): Response
    {
        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => false, 'message' => $message]);
        }

        $this->setFailFlash($message);

        return $this->redirectToPostedUrl();
    }
}
