<?php

namespace justinholtweb\sevvies\services;

use Craft;
use craft\commerce\elements\Order;
use craft\elements\Asset;
use craft\helpers\Assets;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\sevvies\errors\ApiException;
use justinholtweb\sevvies\models\Settings;
use justinholtweb\sevvies\Plugin;
use justinholtweb\sevvies\records\InvoiceRecord;
use yii\base\Component;

/**
 * Getting the document to the customer and into the archive.
 *
 * Sending is what moves a sevDesk invoice out of draft, so it is also what
 * makes it a real bookkeeping document. Nothing here is allowed to run twice.
 */
class Documents extends Component
{
    /**
     * Send or mark-as-sent, according to the settings.
     *
     * @throws ApiException
     */
    public function send(Order $order, InvoiceRecord $record): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$record->sevdeskId || $record->sentAt !== null) {
            return false;
        }

        return match ($settings->sendMode) {
            Settings::SEND_EMAIL => $this->sendEmail($order, $record),
            Settings::SEND_MARK => $this->markSent($order, $record),
            default => false,
        };
    }

    /**
     * Have sevDesk email the invoice. sevDesk sends it, so it lands from the
     * merchant's own accounting address with their letterhead attached.
     *
     * @throws ApiException
     */
    public function sendEmail(Order $order, InvoiceRecord $record): bool
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $to = trim((string)$order->getEmail());

        if ($to === '') {
            $plugin->log->note(
                Craft::t('sevvies', 'The order has no email address, so the invoice was not sent.'),
                $order->id,
                false,
                'send',
            );

            return false;
        }

        $subject = $plugin->invoices->render($settings->emailSubject, $order)
            ?: Craft::t('sevvies', 'Invoice {number}', ['number' => $record->invoiceNumber ?: (string)$record->sevdeskId]);

        $text = $plugin->invoices->render($settings->emailText, $order)
            ?: Craft::t('sevvies', 'Please find your invoice attached.');

        $body = [
            'toEmail' => $to,
            'subject' => $subject,
            'text' => $text,
        ];

        $bcc = trim($settings->emailBcc);

        if ($bcc !== '') {
            $body['bccEmail'] = $bcc;
        }

        $plugin->api->post("Invoice/{$record->sevdeskId}/sendViaEmail", $body, $order->id);

        $record->sentAt = new DateTime();
        $record->sevdeskStatus = Invoices::SEVDESK_OPEN;
        $record->state = Invoices::STATE_SENT;
        $record->save(false);

        $plugin->log->note(
            Craft::t('sevvies', 'Emailed invoice {number} to {email}.', [
                'number' => $record->invoiceNumber ?: (string)$record->sevdeskId,
                'email' => $to,
            ]),
            $order->id,
            true,
            'send',
        );

        return true;
    }

    /**
     * Mark the invoice sent without sevDesk emailing anything — for merchants
     * who send the invoice from Craft and only want sevDesk's books to agree.
     *
     * @throws ApiException
     */
    public function markSent(Order $order, InvoiceRecord $record): bool
    {
        $plugin = Plugin::getInstance();

        $plugin->api->put("Invoice/{$record->sevdeskId}/sendBy", [
            // VPDF: downloaded. The document left the building as a PDF.
            'sendType' => 'VPDF',
            'sendDraft' => false,
        ], $order->id);

        $record->sentAt = new DateTime();
        $record->sevdeskStatus = Invoices::SEVDESK_OPEN;
        $record->state = Invoices::STATE_SENT;
        $record->save(false);

        $plugin->log->note(
            Craft::t('sevvies', 'Marked invoice {number} as sent.', [
                'number' => $record->invoiceNumber ?: (string)$record->sevdeskId,
            ]),
            $order->id,
            true,
            'send',
        );

        return true;
    }

    /**
     * The invoice PDF as bytes.
     *
     * @throws ApiException
     */
    public function pdf(InvoiceRecord $record): string
    {
        if (!$record->sevdeskId) {
            throw new ApiException(Craft::t('sevvies', 'This order has no sevDesk invoice yet.'));
        }

        return Plugin::getInstance()->api->download("Invoice/{$record->sevdeskId}/getPdf", [
            'download' => 'true',
            'preventSendBy' => 'true',
        ], $record->orderId);
    }

    /**
     * Store the PDF as a Craft asset so the archive survives the sevDesk
     * subscription.
     */
    public function archive(Order $order, InvoiceRecord $record): ?Asset
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$settings->pdfVolumeUid || $record->pdfAssetId) {
            return null;
        }

        $volume = Craft::$app->getVolumes()->getVolumeByUid($settings->pdfVolumeUid);

        if ($volume === null) {
            $plugin->log->note(
                Craft::t('sevvies', 'The volume chosen for invoice PDFs no longer exists.'),
                $order->id,
                false,
                'archive',
            );

            return null;
        }

        try {
            $contents = $this->pdf($record);
        } catch (ApiException $e) {
            $plugin->log->note(
                Craft::t('sevvies', 'Could not download the invoice PDF.'),
                $order->id,
                false,
                'archive',
                $e->getMessage(),
            );

            return null;
        }

        $tempPath = Craft::$app->getPath()->getTempPath()
            . DIRECTORY_SEPARATOR . 'sevvies-' . StringHelper::UUID() . '.pdf';

        FileHelper::writeToFile($tempPath, $contents);

        $folder = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);

        if ($folder === null) {
            FileHelper::unlink($tempPath);

            return null;
        }

        $subpath = trim($plugin->invoices->render($settings->pdfSubpath, $order), '/');

        if ($subpath !== '') {
            try {
                $folder = Craft::$app->getAssets()->ensureFolderByFullPathAndVolume($subpath . '/', $volume);
            } catch (\Throwable) {
                // Fall back to the volume root rather than losing the document.
            }
        }

        $filename = Assets::prepareAssetName(sprintf(
            '%s-%s.pdf',
            Craft::t('sevvies', 'invoice'),
            $record->invoiceNumber ?: (string)$record->sevdeskId,
        ));

        $asset = new Asset();
        $asset->tempFilePath = $tempPath;
        $asset->setFilename($filename);
        $asset->newFolderId = $folder->id;
        $asset->setVolumeId($volume->id);
        $asset->avoidFilenameConflicts = true;
        $asset->setScenario(Asset::SCENARIO_CREATE);

        if (!Craft::$app->getElements()->saveElement($asset)) {
            FileHelper::unlink($tempPath);

            $plugin->log->note(
                Craft::t('sevvies', 'Could not save the invoice PDF as an asset.'),
                $order->id,
                false,
                'archive',
                implode(' ', $asset->getFirstErrors()),
            );

            return null;
        }

        $record->pdfAssetId = $asset->id;
        $record->save(false);

        return $asset;
    }
}
