<?php

namespace justinholtweb\sevvies\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\sevvies\errors\ApiException;
use justinholtweb\sevvies\models\Settings;
use justinholtweb\sevvies\Plugin;
use yii\web\Response;

/**
 * The settings screen and the tools on it.
 */
class SettingsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_SETTINGS);

        return true;
    }

    public function actionEdit(): Response
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        return $this->renderTemplate('sevvies/settings', [
            'plugin' => $plugin,
            'settings' => $settings,
            'taxRules' => $plugin->tax->ruleOptions(),
            'orderStatuses' => $this->orderStatuses(),
            'volumes' => $this->volumes(),
            'overrides' => Craft::$app->getConfig()->getConfigFromFile('sevvies'),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $posted = $this->request->getBodyParam('settings', []);

        // Checkbox groups post nothing when everything is unticked.
        $posted['triggerStatuses'] = array_values(array_filter((array)($posted['triggerStatuses'] ?? [])));

        $condition = $posted['orderCondition'] ?? null;
        unset($posted['orderCondition']);

        /** @var Settings $settings */
        $settings = $plugin->getSettings();
        $settings->setAttributes($posted, false);

        if (is_array($condition)) {
            $settings->orderCondition = $condition;
        }

        // Archiving is on exactly when a volume was chosen — one control, not two.
        $settings->archivePdf = trim((string)$settings->pdfVolumeUid) !== '';

        if (!$settings->archivePdf) {
            $settings->pdfVolumeUid = null;
        }

        if (!$settings->validate()) {
            $this->setFailFlash(Craft::t('sevvies', 'Couldn’t save settings.'));
            Craft::$app->getUrlManager()->setRouteParams(['settings' => $settings]);

            return null;
        }

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            $this->setFailFlash(Craft::t('sevvies', 'Couldn’t save settings.'));

            return null;
        }

        // Cached ids belong to whichever account was configured a moment ago.
        $plugin->meta->flush();

        $this->setSuccessFlash(Craft::t('sevvies', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Check the token and report what the account looks like.
     */
    public function actionTestConnection(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $plugin = Plugin::getInstance();
        $plugin->meta->flush();

        $result = $plugin->api->check();

        if (!$result['ok']) {
            return $this->asJson($result);
        }

        try {
            $result['checkAccounts'] = $plugin->meta->checkAccounts(true);
            $result['users'] = $plugin->meta->users(true);
            $result['countries'] = count($plugin->meta->countries(true));
        } catch (ApiException $e) {
            $result['message'] .= ' ' . $e->getMessage();
        }

        return $this->asJson($result);
    }

    /**
     * @return array<string,string>
     */
    private function orderStatuses(): array
    {
        $statuses = [];

        try {
            $commerce = \craft\commerce\Plugin::getInstance();

            foreach ($commerce->getOrderStatuses()->getAllOrderStatuses() as $status) {
                $statuses[$status->handle] = $status->name;
            }
        } catch (\Throwable) {
            // Commerce not ready — an empty list is honest.
        }

        return $statuses;
    }

    /**
     * @return array<int,array{label:string,value:string}>
     */
    private function volumes(): array
    {
        $options = [['label' => Craft::t('sevvies', 'Don’t archive'), 'value' => '']];

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            $options[] = ['label' => $volume->name, 'value' => $volume->uid];
        }

        return $options;
    }
}
