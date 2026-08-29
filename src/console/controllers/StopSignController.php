<?php

namespace justinholtweb\stopsign\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\stopsign\models\Settings;
use justinholtweb\stopsign\Plugin;
use yii\console\ExitCode;

/**
 * Stop Sign from the command line.
 *
 * `stopsign/unlock` is the one that matters. Any plugin that can stop a save has to have a way to
 * be told to stop stopping it that does not itself go through the control panel — because the
 * screen you need in order to fix a lock is a screen a lock can keep you off.
 */
class StopSignController extends Controller
{
    /** Release every lock, not only expired ones. */
    public bool $all = false;

    /** Answer yes to the confirmation. */
    public bool $force = false;

    /** How many days of history to show. */
    public int $days = 7;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        return match ($actionID) {
            'unlock' => array_merge($options, ['all', 'force']),
            'history' => array_merge($options, ['days']),
            default => $options,
        };
    }

    /** What Stop Sign is doing right now. */
    public function actionStatus(): int
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $this->stdout("Stop Sign\n", Console::BOLD);
        $this->stdout(str_repeat('-', 60) . "\n");

        $this->row('Enabled', $settings->enabled ? 'yes' : 'no');
        $this->row('Heartbeat', $settings->heartbeatSeconds . 's');
        $this->row('Presence window', $settings->presenceTtlSeconds . 's');
        $this->row('Save guard', $settings->guardSaves ? 'on' : 'off');
        $this->row('Stale guard', $settings->guardStaleSaves ? 'on' : 'off');
        $this->row('Lock mode', $settings->lockMode);

        if ($settings->lockMode !== Settings::LOCK_MODE_OFF) {
            $this->row('Locks enforced', $settings->enforceLocks ? 'yes — saves are refused' : 'no — advisory only');
            $this->row('Take-over', $settings->allowTakeOver ? 'allowed' : 'not allowed');
        }

        $board = $plugin->presence->board();
        $locks = $plugin->locks->all();

        $this->stdout("\nOpen right now\n", Console::BOLD);

        if ($board === []) {
            $this->stdout("  nobody\n", Console::FG_GREY);
        } else {
            foreach ($board as $row) {
                $this->stdout(sprintf(
                    "  %-22s %-9s element %d (site %d)\n",
                    $row['userName'],
                    $row['dirty'] ? 'editing' : 'viewing',
                    $row['elementId'],
                    $row['siteId'],
                ));
            }
        }

        $this->stdout("\nLocks held\n", Console::BOLD);

        if ($locks === []) {
            $this->stdout("  none\n", Console::FG_GREY);
        } else {
            foreach ($locks as $lock) {
                $this->stdout(sprintf(
                    "  %-22s element %d (site %d), expires in %ds\n",
                    $lock['userName'],
                    $lock['elementId'],
                    $lock['siteId'],
                    max(0, $lock['expiryDate']->getTimestamp() - time()),
                ));
            }
        }

        return ExitCode::OK;
    }

    /**
     * Releases locks.
     *
     * Without `--all` this only clears locks that have already expired, which is housekeeping.
     * With it, it clears every lock on the site — the thing to reach for when somebody is stuck
     * and nobody can work out why.
     */
    public function actionUnlock(): int
    {
        $locks = Plugin::getInstance()->locks;

        if (!$this->all) {
            $count = $locks->prune();
            $this->stdout("Released $count expired lock(s).\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        $held = count($locks->all());

        if (!$this->force && !$this->confirm("Release all $held live lock(s)?")) {
            $this->stdout("Nothing released.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $count = $locks->releaseAll();
        $this->stdout("Released $count lock(s).\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /** Trims presence, locks and history to their retention settings. */
    public function actionPrune(): int
    {
        $plugin = Plugin::getInstance();

        $this->stdout('Presence rows removed: ' . $plugin->presence->prune() . "\n");
        $this->stdout('Expired locks removed: ' . $plugin->locks->prune() . "\n");
        $this->stdout('Collision rows removed: ' . $plugin->collisions->prune() . "\n");
        $this->stdout('Save-ledger rows removed: ' . $plugin->collisions->pruneSaves() . "\n");

        return ExitCode::OK;
    }

    /** Recent collisions, and how many of them were clicked straight through. */
    public function actionHistory(): int
    {
        $plugin = Plugin::getInstance();
        $summary = $plugin->collisions->summary($this->days);

        $this->stdout("Last {$this->days} days\n", Console::BOLD);

        foreach ($summary as $outcome => $count) {
            $this->row($outcome, (string)$count);
        }

        $rows = $plugin->collisions->recent(30);

        if ($rows === []) {
            $this->stdout("\nNo collisions recorded.\n", Console::FG_GREY);

            return ExitCode::OK;
        }

        $this->stdout("\nMost recent\n", Console::BOLD);

        foreach ($rows as $row) {
            $this->stdout(sprintf(
                "  %s  %-18s %-11s %-10s element %d%s\n",
                $row['dateCreated']->format('Y-m-d H:i'),
                mb_substr($row['userName'], 0, 18),
                $row['kind'],
                $row['outcome'],
                $row['elementId'],
                $row['otherUserName'] ? ' (with ' . $row['otherUserName'] . ')' : '',
            ));
        }

        return ExitCode::OK;
    }

    private function row(string $label, string $value): void
    {
        $this->stdout('  ' . str_pad($label, 20));
        $this->stdout($value . "\n", Console::FG_CYAN);
    }
}
