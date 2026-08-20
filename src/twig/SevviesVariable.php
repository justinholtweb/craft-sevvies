<?php

namespace justinholtweb\sevvies\twig;

use craft\commerce\elements\Order;
use craft\elements\Asset;
use justinholtweb\sevvies\models\InvoiceDraft;
use justinholtweb\sevvies\Plugin;
use justinholtweb\sevvies\records\InvoiceRecord;
use yii\base\Component;

/**
 * `craft.sevvies` — read-only, so a template can show a customer their invoice
 * without being able to issue one.
 */
class SevviesVariable extends Component
{
    /**
     * The sevDesk invoice row for an order, if it has one.
     */
    public function invoice(Order|int|null $order): ?InvoiceRecord
    {
        $orderId = $order instanceof Order ? $order->id : $order;

        return Plugin::getInstance()->invoices->recordFor($orderId ? (int)$orderId : null);
    }

    /**
     * Has this order been invoiced in sevDesk?
     */
    public function isInvoiced(Order|int|null $order): bool
    {
        return $this->invoice($order)?->sevdeskId !== null;
    }

    /**
     * The sevDesk invoice number for an order.
     */
    public function invoiceNumber(Order|int|null $order): ?string
    {
        return $this->invoice($order)?->invoiceNumber;
    }

    /**
     * The archived PDF asset, when one was stored.
     */
    public function pdf(Order|int|null $order): ?Asset
    {
        $assetId = $this->invoice($order)?->pdfAssetId;

        return $assetId ? Asset::find()->id((int)$assetId)->one() : null;
    }

    /**
     * The VAT rule an order would be invoiced under, and why. Useful on a
     * checkout summary for B2B customers expecting reverse charge.
     */
    public function taxRule(Order $order): array
    {
        $decision = Plugin::getInstance()->tax->decide($order);

        return [
            'rule' => $decision->rule,
            'label' => $decision->label,
            'reason' => $decision->reason,
            'text' => $decision->text,
            'zeroRated' => $decision->zeroRated,
        ];
    }

    /**
     * Build the payload without sending it — for a "what would we file?" panel.
     */
    public function preview(Order $order): InvoiceDraft
    {
        return Plugin::getInstance()->invoices->build($order);
    }
}
