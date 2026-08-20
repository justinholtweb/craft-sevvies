<?php

namespace justinholtweb\sevvies\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\sevvies\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The connection log.
 */
class LogController extends Controller
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
        $page = max(1, (int)$this->request->getParam('page', 1));
        $perPage = 100;
        $only = $this->request->getParam('only');

        $query = Plugin::getInstance()->log->query();

        if ($only === 'failures') {
            $query->where(['success' => false]);
        }

        $total = (int)(clone $query)->count();

        return $this->renderTemplate('sevvies/log/_index', [
            'entries' => $query->offset(($page - 1) * $perPage)->limit($perPage)->all(),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'only' => $only,
        ]);
    }

    public function actionDetail(int $id): Response
    {
        $entry = Plugin::getInstance()->log->find($id);

        if ($entry === null) {
            throw new NotFoundHttpException(Craft::t('sevvies', 'Log entry not found.'));
        }

        return $this->renderTemplate('sevvies/log/_detail', [
            'entry' => $entry,
        ]);
    }

    public function actionClear(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_SETTINGS);

        $removed = Plugin::getInstance()->log->clear();

        $this->setSuccessFlash(Craft::t('sevvies', '{count} log entries removed.', ['count' => $removed]));

        return $this->redirect('sevvies/log');
    }
}
