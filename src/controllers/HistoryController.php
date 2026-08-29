<?php

namespace justinholtweb\stopsign\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\stopsign\Plugin;
use justinholtweb\stopsign\records\CollisionRecord;
use yii\web\Response;

/**
 * The collision history screen.
 *
 * Behind `utility:stopsign` rather than a plugin permission of its own, because the utility is
 * where it is reached from and two different gates on the same screen is how a screen ends up
 * visible to people who cannot open it.
 */
class HistoryController extends Controller
{
    public function actionClear(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('utility:stopsign');

        Craft::$app->getDb()->createCommand()
            ->delete(CollisionRecord::TABLE)
            ->execute();

        return $this->asSuccess(Craft::t('stopsign', 'Collision history cleared.'));
    }

    /** Releases every lock on the site. The button behind the console command. */
    public function actionReleaseAllLocks(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('utility:stopsign');

        $count = Plugin::getInstance()->locks->releaseAll();

        return $this->asSuccess(Craft::t('stopsign', '{count, plural, =1{1 lock} other{# locks}} released.', ['count' => $count]));
    }
}
