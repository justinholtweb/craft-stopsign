<?php

namespace justinholtweb\stopsign\utilities;

use Craft;
use craft\base\Utility;
use justinholtweb\stopsign\Plugin;
use justinholtweb\stopsign\web\assets\cp\CpAsset;

/**
 * The live board and the collision history.
 *
 * A utility rather than a control panel section: there is nothing here to create or manage, only
 * two things to look at. The board answers “who is in what right now”, which on a stock Craft
 * install cannot be asked at all — `elementactivity` is garbage-collected at sixty seconds and is
 * only ever read for the one element somebody happens to have open.
 */
class StopSignUtility extends Utility
{
    public static function displayName(): string
    {
        return Craft::t('stopsign', 'Stop Sign');
    }

    public static function id(): string
    {
        return 'stopsign';
    }

    public static function icon(): ?string
    {
        return Craft::getAlias('@justinholtweb/stopsign/icon-mask.svg');
    }

    public static function contentHtml(): string
    {
        $plugin = Plugin::getInstance();
        $view = Craft::$app->getView();
        $view->registerAssetBundle(CpAsset::class);

        return $view->renderTemplate('stopsign/_utility', [
            'plugin' => $plugin,
            'settings' => $plugin->getSettings(),
            'board' => $plugin->presence->board(),
            'locks' => $plugin->locks->all(),
            'history' => $plugin->collisions->recent(50),
            'summary' => $plugin->collisions->summary($plugin->getSettings()->historyRetentionDays ?: 30),
        ]);
    }
}
