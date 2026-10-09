<?php
/**
 * Stop Sign integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web \
 *         php /var/www/craft-stopsign/tests/integration/checks.php
 *
 * Covers what a unit fixture cannot: presence rows racing each other through a real unique index,
 * locks changing hands, the verdict that every surface reads from, and — most importantly — that
 * Craft's own `canSave()` is left alone when there is no lock to enforce.
 *
 * Idempotent and self-cleaning. Settings are swapped in memory only; nothing is written to project
 * config, which on this shared harness would fight the queue runner for the lock.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\services\Elements;
use justinholtweb\stopsign\conditions\IsLockedConditionRule;
use justinholtweb\stopsign\models\Settings;
use justinholtweb\stopsign\models\Verdict;
use justinholtweb\stopsign\Plugin;
use justinholtweb\stopsign\records\CollisionRecord;
use justinholtweb\stopsign\records\LockRecord;
use justinholtweb\stopsign\records\PresenceRecord;
use justinholtweb\stopsign\records\SaveRecord;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$original = $plugin->getSettings()->toArray();
$site = Craft::$app->getSites()->getPrimarySite();

/** Swaps a settings shape in, in memory only, and clears everything memoised behind it. */
function configure(array $overrides = []): Settings
{
    $plugin = Plugin::getInstance();
    $settings = new Settings();
    $settings->setAttributes($overrides, false);
    $plugin->setSettings($settings->toArray());
    $plugin->scope->reset();

    return $plugin->getSettings();
}

/** Empties Stop Sign's own tables between phases so one check cannot seed the next. */
function wipe(): void
{
    foreach ([PresenceRecord::TABLE, LockRecord::TABLE, SaveRecord::TABLE, CollisionRecord::TABLE] as $table) {
        Db::delete($table, []);
    }

    Plugin::getInstance()->presence->prune();
    Plugin::getInstance()->locks->prune();
}

// ---------------------------------------------------------------------------- fixtures

section('Fixtures');

$suffix = StringHelper::randomString(6);

/** @var User[] $users */
$users = [];

foreach (['alex', 'dana', 'robin'] as $name) {
    $user = new User();
    $user->username = "stopsign-$name-$suffix";
    $user->email = "stopsign-$name-$suffix@example.test";
    $user->firstName = ucfirst($name);
    $user->lastName = 'Tester';
    $user->admin = false;
    Craft::$app->getElements()->saveElement($user);
    $users[$name] = $user;
}

$admin = User::find()->admin(true)->status(null)->one();

check('three test users exist', fn() => count(array_filter($users, fn(User $u) => (bool)$u->id)) === 3);
check('an admin exists to test the bypass with', fn() => $admin instanceof User);

$section = Craft::$app->getEntries()->getAllSections()[0] ?? null;
check('the harness has a section to put a test entry in', fn() => $section !== null);

$entryType = $section->getEntryTypes()[0];
$entry = new Entry();
$entry->sectionId = $section->id;
$entry->typeId = $entryType->id;
$entry->siteId = $site->id;
$entry->title = "Stop Sign test entry $suffix";

// Authored by the admin, not by one of the fixtures: those are inactive accounts, and Craft
// re-validates the author on every save — so an entry owned by one of them could be created but
// never saved again, which would look exactly like the save ledger failing to record anything.
$entry->authorId = $admin->id;
Craft::$app->getElements()->saveElement($entry);

check('a test entry exists', fn() => (bool)$entry->id);

$otherEntry = new Entry();
$otherEntry->sectionId = $section->id;
$otherEntry->typeId = $entryType->id;
$otherEntry->siteId = $site->id;
$otherEntry->title = "Stop Sign control entry $suffix";
$otherEntry->authorId = $admin->id;
Craft::$app->getElements()->saveElement($otherEntry);

// Holding or taking a lock needs edit rights, so alex and dana can save the test entry and robin
// can only read it — robin is the reviewer who must never lock anybody out by getting there first.
$sectionPerms = fn(array $verbs) => array_map(fn($verb) => strtolower("$verb:$section->uid"), $verbs);
$sitePerm = strtolower("editSite:$site->uid");
$editor = array_merge([$sitePerm, 'accesscp'], $sectionPerms(['viewEntries', 'viewPeerEntries', 'saveEntries', 'savePeerEntries']));
$reader = array_merge([$sitePerm, 'accesscp'], $sectionPerms(['viewEntries', 'viewPeerEntries']));

Craft::$app->getUserPermissions()->saveUserPermissions($users['alex']->id, $editor);
Craft::$app->getUserPermissions()->saveUserPermissions($users['dana']->id, $editor);
Craft::$app->getUserPermissions()->saveUserPermissions($users['robin']->id, $reader);

check('editors can save the test entry and the reader cannot', function() use ($entry, $users) {
    $elements = Craft::$app->getElements();

    return ($elements->canSave($entry, $users['alex']) && $elements->canView($entry, $users['robin']) && !$elements->canSave($entry, $users['robin']))
        ?: 'the fixture permissions did not take';
});

$TAB_A = str_repeat('a', 32);
$TAB_B = str_repeat('b', 32);
$TAB_C = str_repeat('c', 32);

// ---------------------------------------------------------------------------- scope

section('Scope');

check('everything is watched by default', function() use ($entry) {
    configure();

    return Plugin::getInstance()->scope->watches($entry) ?: 'entry was not watched';
});

check('the master switch stops everything', function() use ($entry) {
    configure(['enabled' => false]);

    return !Plugin::getInstance()->scope->watches($entry) ?: 'a disabled plugin still watched';
});

check('an excluded section is left alone', function() use ($entry, $section) {
    configure(['excludedSections' => [$section->handle]]);

    return !Plugin::getInstance()->scope->watches($entry) ?: 'an excluded section was still watched';
});

check('naming element types excludes the ones not named', function() use ($entry) {
    configure(['watchAllElementTypes' => false, 'watchedElementTypes' => [User::class]]);

    return !Plugin::getInstance()->scope->watches($entry) ?: 'an unnamed element type was watched';
});

check('locking is off until it is switched on', function() use ($entry) {
    configure();

    return !Plugin::getInstance()->scope->locks($entry) ?: 'locking was on by default';
});

check('section lock mode only locks the sections named', function() use ($entry, $section) {
    configure(['lockMode' => Settings::LOCK_MODE_SECTIONS, 'lockedSections' => ['something-else']]);
    $unlocked = !Plugin::getInstance()->scope->locks($entry);

    configure(['lockMode' => Settings::LOCK_MODE_SECTIONS, 'lockedSections' => [$section->handle]]);
    $locked = Plugin::getInstance()->scope->locks($entry);

    return ($unlocked && $locked) ?: 'section lock scoping was wrong';
});

check('an excluded section cannot be locked even in “all” mode', function() use ($entry, $section) {
    configure(['lockMode' => Settings::LOCK_MODE_ALL, 'excludedSections' => [$section->handle]]);

    return !Plugin::getInstance()->scope->locks($entry) ?: 'an unwatched section was locked anyway';
});

// ---------------------------------------------------------------------------- presence

section('Presence');

check('a heartbeat creates one row', function() use ($entry, $users, $TAB_A) {
    configure();
    wipe();
    Plugin::getInstance()->presence->beat($entry, $users['alex'], $TAB_A, 'viewing', false);

    return (int)(new craft\db\Query())->from(PresenceRecord::TABLE)->count() === 1
        ?: 'expected exactly one presence row';
});

check('beating again updates rather than duplicates', function() use ($entry, $users, $TAB_A) {
    Plugin::getInstance()->presence->beat($entry, $users['alex'], $TAB_A, 'editing', true);

    return (int)(new craft\db\Query())->from(PresenceRecord::TABLE)->count() === 1
        ?: 'a second beat from the same tab made a second row';
});

check('firstSeen survives later beats', function() use ($entry, $users, $TAB_A) {
    $before = (new craft\db\Query())->select(['firstSeen'])->from(PresenceRecord::TABLE)->scalar();
    sleep(1);
    Plugin::getInstance()->presence->beat($entry, $users['alex'], $TAB_A, 'editing', true);
    $after = (new craft\db\Query())->select(['firstSeen'])->from(PresenceRecord::TABLE)->scalar();

    return $before === $after ?: "firstSeen was rewritten ($before -> $after) — “here for 12 minutes” would reset every beat";
});

check('lastSeen does move', function() use ($entry) {
    $row = (new craft\db\Query())->select(['firstSeen', 'lastSeen'])->from(PresenceRecord::TABLE)->one();

    return $row['lastSeen'] > $row['firstSeen'] ?: 'lastSeen did not advance';
});

check('a tab does not report itself back to itself', function() use ($entry, $users, $TAB_A) {
    $result = Plugin::getInstance()->presence->occupants($entry, $users['alex'], $TAB_A);

    return ($result['others'] === [] && $result['ownTabs'] === [])
        ?: 'a tab saw its own presence row';
});

check('a second tab of the same account is reported as your own', function() use ($entry, $users, $TAB_A, $TAB_B) {
    Plugin::getInstance()->presence->beat($entry, $users['alex'], $TAB_B, 'viewing', false);
    $result = Plugin::getInstance()->presence->occupants($entry, $users['alex'], $TAB_A);

    return (count($result['ownTabs']) === 1 && $result['others'] === [])
        ?: 'the same account in another tab was not reported';
});

check('a colleague is reported as somebody else', function() use ($entry, $users, $TAB_A, $TAB_C) {
    Plugin::getInstance()->presence->beat($entry, $users['dana'], $TAB_C, 'viewing', false);
    $result = Plugin::getInstance()->presence->occupants($entry, $users['alex'], $TAB_A);

    return (count($result['others']) === 1 && $result['others'][0]->name === $users['dana']->getName())
        ?: 'a colleague was not reported';
});

check('a colleague in two tabs is still one person', function() use ($entry, $users, $TAB_A) {
    Plugin::getInstance()->presence->beat($entry, $users['dana'], str_repeat('d', 32), 'viewing', false);
    $result = Plugin::getInstance()->presence->occupants($entry, $users['alex'], $TAB_A);

    return count($result['others']) === 1
        ?: 'one colleague in two tabs was reported as two people';
});

check('editing beats viewing when the same person has both', function() use ($entry, $users, $TAB_A) {
    Plugin::getInstance()->presence->beat($entry, $users['dana'], str_repeat('d', 32), 'editing', true);
    $result = Plugin::getInstance()->presence->occupants($entry, $users['alex'], $TAB_A);

    return $result['others'][0]->dirty
        ?: 'a viewing row masked an editing one — the worse state must win';
});

check('presence expires with the window', function() use ($entry, $users, $TAB_A) {
    configure(['presenceTtlSeconds' => 10, 'heartbeatSeconds' => 5]);

    Db::update(PresenceRecord::TABLE, [
        'lastSeen' => Db::prepareDateForDb((new DateTime('now', new DateTimeZone('UTC')))->modify('-1 hour')),
    ], ['not', ['sessionToken' => $TAB_A]]);

    $result = Plugin::getInstance()->presence->occupants($entry, $users['alex'], $TAB_A);

    return ($result['others'] === [] && $result['ownTabs'] === [])
        ?: 'stale heartbeats still counted as somebody being present';
});

check('isOccupied answers for the whole page in one query', function() use ($entry, $otherEntry, $users, $TAB_C) {
    configure();
    wipe();
    Plugin::getInstance()->presence->beat($entry, $users['dana'], $TAB_C, 'viewing', false);

    $presence = Plugin::getInstance()->presence;

    return ($presence->isOccupied($entry->id) && !$presence->isOccupied($otherEntry->id))
        ?: 'occupancy was answered wrongly';
});

check('the board names who is in what', function() use ($entry, $users) {
    $board = Plugin::getInstance()->presence->board();

    return (count($board) === 1
        && $board[0]['elementId'] === $entry->id
        && $board[0]['userName'] === $users['dana']->getName())
        ?: 'the board did not report the open element';
});

check('pruning keeps rows well past the window, and drops truly old ones', function() use ($entry, $users) {
    configure(['presenceTtlSeconds' => 45, 'heartbeatSeconds' => 10]);

    // Five minutes old: stale, but inside ten windows, so still evidence.
    Db::update(PresenceRecord::TABLE, [
        'lastSeen' => Db::prepareDateForDb((new DateTime('now', new DateTimeZone('UTC')))->modify('-5 minutes')),
    ], []);
    Plugin::getInstance()->presence->prune();
    $kept = (int)(new craft\db\Query())->from(PresenceRecord::TABLE)->count();

    Db::update(PresenceRecord::TABLE, [
        'lastSeen' => Db::prepareDateForDb((new DateTime('now', new DateTimeZone('UTC')))->modify('-1 day')),
    ], []);
    Plugin::getInstance()->presence->prune();
    $dropped = (int)(new craft\db\Query())->from(PresenceRecord::TABLE)->count();

    return ($kept === 1 && $dropped === 0) ?: "kept=$kept dropped=$dropped";
});

check('releasing removes only that tab', function() use ($entry, $users, $TAB_A, $TAB_B) {
    wipe();
    $presence = Plugin::getInstance()->presence;
    $presence->beat($entry, $users['alex'], $TAB_A, 'viewing', false);
    $presence->beat($entry, $users['alex'], $TAB_B, 'viewing', false);
    $presence->release($entry, $users['alex'], $TAB_A);

    return (int)(new craft\db\Query())->from(PresenceRecord::TABLE)->count() === 1
        ?: 'releasing one tab took the other with it';
});

// ---------------------------------------------------------------------------- locks

section('Locks');

check('nothing is claimed while locking is off', function() use ($entry, $users, $TAB_A) {
    configure();
    wipe();
    $state = Plugin::getInstance()->locks->claimOrRenew($entry, $users['alex'], $TAB_A);

    return (!$state->applicable && (int)(new craft\db\Query())->from(LockRecord::TABLE)->count() === 0)
        ?: 'a lock was taken with locking switched off';
});

check('the first tab in gets the lock', function() use ($entry, $users, $section, $TAB_A) {
    configure(['lockMode' => Settings::LOCK_MODE_SECTIONS, 'lockedSections' => [$section->handle]]);
    $state = Plugin::getInstance()->locks->claimOrRenew($entry, $users['alex'], $TAB_A);

    return ($state->applicable && $state->isMine && $state->holderId === $users['alex']->id)
        ?: 'the first claimant did not get the lock';
});

check('the second person is told who has it', function() use ($entry, $users, $TAB_C) {
    $state = Plugin::getInstance()->locks->claimOrRenew($entry, $users['dana'], $TAB_C);

    return ($state->isHeldByAnotherUser() && $state->holderName === $users['alex']->getName())
        ?: 'the second claimant was not told who held the lock';
});

check('there is still exactly one lock row', function() {
    return (int)(new craft\db\Query())->from(LockRecord::TABLE)->count() === 1
        ?: 'a second lock row was created for the same element';
});

check('the holder’s heartbeat renews the lock', function() use ($entry, $users, $TAB_A) {
    $before = (new craft\db\Query())->select(['expiryDate'])->from(LockRecord::TABLE)->scalar();
    sleep(1);
    Plugin::getInstance()->locks->claimOrRenew($entry, $users['alex'], $TAB_A);
    $after = (new craft\db\Query())->select(['expiryDate'])->from(LockRecord::TABLE)->scalar();

    return $after > $before ?: 'the holder’s heartbeat did not push the expiry out';
});

check('an expired lock is nobody’s', function() use ($entry, $users, $TAB_C) {
    Db::update(LockRecord::TABLE, [
        'expiryDate' => Db::prepareDateForDb((new DateTime('now', new DateTimeZone('UTC')))->modify('-1 hour')),
    ], []);

    $locks = Plugin::getInstance()->locks;
    $locks->prune();
    $state = $locks->claimOrRenew($entry, $users['dana'], $TAB_C);

    return ($state->isMine && $state->holderId === $users['dana']->id)
        ?: 'an expired lock still shut somebody out';
});

check('a non-holder cannot release somebody else’s lock', function() use ($entry, $users, $TAB_A) {
    Plugin::getInstance()->locks->release($entry, $users['alex'], $TAB_A);

    return (int)(new craft\db\Query())->from(LockRecord::TABLE)->count() === 1
        ?: 'releasing was a way to steal a lock';
});

check('take-over moves the lock and is allowed by default', function() use ($entry, $users, $TAB_A) {
    $locks = Plugin::getInstance()->locks;
    $moved = $locks->takeOver($entry, $users['alex'], $TAB_A);
    $state = $locks->state($entry, $users['alex']);

    return ($moved && $state->isMine && (int)(new craft\db\Query())->from(LockRecord::TABLE)->count() === 1)
        ?: 'take-over did not move the lock cleanly';
});

check('take-over can be switched off', function() use ($entry, $users, $section, $TAB_C) {
    configure([
        'lockMode' => Settings::LOCK_MODE_SECTIONS,
        'lockedSections' => [$section->handle],
        'allowTakeOver' => false,
    ]);

    return !Plugin::getInstance()->locks->takeOver($entry, $users['dana'], $TAB_C)
        ?: 'take-over happened while it was switched off';
});

check('a group restriction keeps other groups out', function() use ($entry, $users, $section) {
    configure([
        'lockMode' => Settings::LOCK_MODE_SECTIONS,
        'lockedSections' => [$section->handle],
        'takeOverGroups' => ['a-group-nobody-is-in'],
    ]);

    return !Plugin::getInstance()->locks->canTakeOver($entry, $users['dana'])
        ?: 'somebody outside the named groups could take over';
});

check('admins are never locked out', function() use ($entry, $admin, $section) {
    configure([
        'lockMode' => Settings::LOCK_MODE_SECTIONS,
        'lockedSections' => [$section->handle],
        'allowTakeOver' => false,
        'adminsBypassLocks' => true,
    ]);
    $locks = Plugin::getInstance()->locks;

    return ($locks->canTakeOver($entry, $admin) && !$locks->blocksSave($entry, $admin))
        ?: 'an admin was shut out with adminsBypassLocks on';
});

check('the admin bypass can itself be switched off', function() use ($entry, $admin, $section) {
    configure([
        'lockMode' => Settings::LOCK_MODE_SECTIONS,
        'lockedSections' => [$section->handle],
        'adminsBypassLocks' => false,
    ]);

    return Plugin::getInstance()->locks->blocksSave($entry, $admin)
        ?: 'the admin bypass could not be turned off';
});

check('a held lock blocks the other person’s save', function() use ($entry, $users, $section) {
    configure(['lockMode' => Settings::LOCK_MODE_SECTIONS, 'lockedSections' => [$section->handle]]);
    $locks = Plugin::getInstance()->locks;

    return ($locks->blocksSave($entry, $users['dana']) && !$locks->blocksSave($entry, $users['alex']))
        ?: 'the lock blocked the wrong person';
});

check('an advisory lock blocks nobody', function() use ($entry, $users, $section) {
    configure([
        'lockMode' => Settings::LOCK_MODE_SECTIONS,
        'lockedSections' => [$section->handle],
        'enforceLocks' => false,
    ]);

    return !Plugin::getInstance()->locks->blocksSave($entry, $users['dana'])
        ?: 'an advisory lock refused a save';
});

check('a reader cannot take a lock over', function() use ($entry, $users, $section, $TAB_A, $TAB_B) {
    configure(['lockMode' => Settings::LOCK_MODE_SECTIONS, 'lockedSections' => [$section->handle]]);
    wipe();
    $locks = Plugin::getInstance()->locks;
    $locks->claimOrRenew($entry, $users['alex'], $TAB_A);

    return (!$locks->canTakeOver($entry, $users['robin']) && !$locks->takeOver($entry, $users['robin'], $TAB_B))
        ?: 'somebody who can only view the entry took the lock from an editor';
});

check('a reader is not somebody who can edit', function() use ($entry, $users) {
    return !Plugin::getInstance()->locks->canEdit($entry, $users['robin'])
        ?: 'canEdit said yes to a view-only account, so a reader would claim the lock';
});

check('canEdit is not answered by Stop Sign’s own lock', function() use ($entry, $users) {
    // alex holds the lock, so dana is blocked from saving — but dana could save if it were not
    // for the lock, which is the question take-over needs answered.
    $locks = Plugin::getInstance()->locks;

    return ($locks->blocksSave($entry, $users['dana']) && $locks->canEdit($entry, $users['dana']) && $locks->canTakeOver($entry, $users['dana']))
        ?: 'the lock made its own holder’s colleague look like a reader';
});

check('releaseAll is a real kill switch', function() {
    Plugin::getInstance()->locks->releaseAll();

    return (int)(new craft\db\Query())->from(LockRecord::TABLE)->count() === 0
        ?: 'locks survived the kill switch';
});

// ---------------------------------------------------------------------------- authorisation

section('Craft’s own permission check');

check('canSave is untouched when nothing is locked', function() use ($entry, $users) {
    configure();
    wipe();

    // The event handler is attached for the life of the request, so this is the case that
    // matters: `AuthorizationCheckEvent::$authorized` arrives as null, null means “no opinion”,
    // and anything else here would override permissions Craft was about to work out for itself.
    $event = new craft\events\AuthorizationCheckEvent($users['alex'], [
        'element' => $entry,
        'authorized' => null,
    ]);
    Craft::$app->getElements()->trigger(Elements::EVENT_AUTHORIZE_SAVE, $event);

    return $event->authorized === null
        ?: 'the handler formed an opinion when it had no business having one, which would override Craft';
});

check('canSave is still untouched when locking is on but nobody holds one', function() use ($entry, $users, $section) {
    configure(['lockMode' => Settings::LOCK_MODE_SECTIONS, 'lockedSections' => [$section->handle]]);

    $event = new craft\events\AuthorizationCheckEvent($users['alex'], [
        'element' => $entry,
        'authorized' => null,
    ]);
    Craft::$app->getElements()->trigger(Elements::EVENT_AUTHORIZE_SAVE, $event);

    return $event->authorized === null ?: 'an unheld lock still denied a save';
});

check('a console request is never refused, whatever the locks say', function() use ($entry, $users, $section, $TAB_A) {
    configure(['lockMode' => Settings::LOCK_MODE_SECTIONS, 'lockedSections' => [$section->handle]]);
    Plugin::getInstance()->locks->claimOrRenew($entry, $users['alex'], $TAB_A);

    $event = new craft\events\AuthorizationCheckEvent($users['dana'], [
        'element' => $entry,
        'authorized' => null,
    ]);
    Craft::$app->getElements()->trigger(Elements::EVENT_AUTHORIZE_SAVE, $event);

    // This suite *is* a console request, which is the point: `resave/entries`, a queue job or a
    // Commerce order update being refused by a lock is not a collision warning, it is an outage.
    return $event->authorized === null
        ?: 'a lock refused a console save — resave/entries would break under this';
});

// ---------------------------------------------------------------------------- the verdict

section('The verdict');

check('an empty element is clear', function() use ($entry, $users, $TAB_A) {
    configure();
    wipe();
    $verdict = Plugin::getInstance()->verdicts->build($entry, $users['alex'], $TAB_A);

    return ($verdict->isClear() && $verdict->level() === Verdict::LEVEL_CLEAR && $verdict->headline() === '')
        ?: 'an empty element was not clear';
});

check('a colleague looking is a warning', function() use ($entry, $users, $TAB_A, $TAB_C) {
    Plugin::getInstance()->presence->beat($entry, $users['dana'], $TAB_C, 'viewing', false);
    $verdict = Plugin::getInstance()->verdicts->build($entry, $users['alex'], $TAB_A);

    return ($verdict->level() === Verdict::LEVEL_WARNING
        && str_contains($verdict->headline(), $users['dana']->getName()))
        ?: 'a viewer did not produce a warning: ' . $verdict->level() . ' / ' . $verdict->headline();
});

check('a colleague typing is a danger', function() use ($entry, $users, $TAB_A, $TAB_C) {
    Plugin::getInstance()->presence->beat($entry, $users['dana'], $TAB_C, 'editing', true);
    $verdict = Plugin::getInstance()->verdicts->build($entry, $users['alex'], $TAB_A);

    return ($verdict->level() === Verdict::LEVEL_DANGER && $verdict->hasEditors())
        ?: 'an editor did not escalate past a warning';
});

check('viewers can be silenced without silencing editors', function() use ($entry, $users, $TAB_A, $TAB_C) {
    wipe();
    configure(['warnAboutViewers' => false]);
    Plugin::getInstance()->presence->beat($entry, $users['dana'], $TAB_C, 'viewing', false);
    $quiet = Plugin::getInstance()->verdicts->build($entry, $users['alex'], $TAB_A);

    Plugin::getInstance()->presence->beat($entry, $users['dana'], $TAB_C, 'editing', true);
    $loud = Plugin::getInstance()->verdicts->build($entry, $users['alex'], $TAB_A);

    return ($quiet->isClear() && $loud->level() === Verdict::LEVEL_DANGER)
        ?: 'warnAboutViewers did not do what it says';
});

check('two editors are counted, not listed twice', function() use ($entry, $users, $TAB_A, $TAB_C) {
    wipe();
    configure();
    Plugin::getInstance()->presence->beat($entry, $users['dana'], $TAB_C, 'editing', true);
    Plugin::getInstance()->presence->beat($entry, $users['robin'], str_repeat('e', 32), 'editing', true);
    $verdict = Plugin::getInstance()->verdicts->build($entry, $users['alex'], $TAB_A);

    return str_contains($verdict->headline(), '2')
        ?: 'two editors did not produce a count: ' . $verdict->headline();
});

check('your own other tab is a notice, not a danger', function() use ($entry, $users, $TAB_A, $TAB_B) {
    wipe();
    configure();
    Plugin::getInstance()->presence->beat($entry, $users['alex'], $TAB_B, 'editing', true);
    $verdict = Plugin::getInstance()->verdicts->build($entry, $users['alex'], $TAB_A);

    return ($verdict->level() === Verdict::LEVEL_NOTICE && $verdict->ownTabs !== [])
        ?: 'the own-tab case was mislevelled: ' . $verdict->level();
});

check('own-tab warnings can be switched off', function() use ($entry, $users, $TAB_A) {
    configure(['warnOnOwnOtherTabs' => false]);
    $verdict = Plugin::getInstance()->verdicts->build($entry, $users['alex'], $TAB_A);

    return $verdict->isClear() ?: 'warnOnOwnOtherTabs did nothing';
});

check('a lock outranks everything else', function() use ($entry, $users, $section, $TAB_A, $TAB_C) {
    wipe();
    configure(['lockMode' => Settings::LOCK_MODE_SECTIONS, 'lockedSections' => [$section->handle]]);
    Plugin::getInstance()->locks->claimOrRenew($entry, $users['dana'], $TAB_C);
    Plugin::getInstance()->presence->beat($entry, $users['dana'], $TAB_C, 'editing', true);

    $verdict = Plugin::getInstance()->verdicts->build($entry, $users['alex'], $TAB_A);

    return ($verdict->level() === Verdict::LEVEL_LOCKED && $verdict->lock->isHeldByAnotherUser())
        ?: 'a held lock did not outrank the editing warning: ' . $verdict->level();
});

check('an unwatched element produces nothing at all', function() use ($entry, $users, $section, $TAB_A, $TAB_C) {
    configure(['excludedSections' => [$section->handle]]);
    Plugin::getInstance()->presence->beat($entry, $users['dana'], $TAB_C, 'editing', true);
    $verdict = Plugin::getInstance()->verdicts->build($entry, $users['alex'], $TAB_A);

    return $verdict->isClear() ?: 'an excluded section still produced a verdict';
});

// ---------------------------------------------------------------------------- index badges

section('Index badges');

check('an occupied chip is badged and an empty one is not', function() use ($entry, $users, $TAB_C) {
    wipe();
    configure();
    Plugin::getInstance()->presence->beat($entry, $users['dana'], $TAB_C, 'editing', true);

    $occupied = craft\helpers\Cp::elementChipHtml($entry, ['context' => 'index']);

    $spare = Entry::find()->id(['not', $entry->id])->status(null)->one();
    $empty = $spare ? craft\helpers\Cp::elementChipHtml($spare, ['context' => 'index']) : '';

    return (str_contains($occupied, 'stopsign-occupied') && !str_contains($empty, 'stopsign-occupied'))
        ?: 'the badge landed on the wrong chips';
});

check('badges can be switched off', function() use ($entry) {
    configure(['showIndexBadges' => false]);

    return !str_contains(craft\helpers\Cp::elementChipHtml($entry, ['context' => 'index']), 'stopsign-occupied')
        ?: 'a badge was rendered with badges switched off';
});

check('an excluded section is never badged', function() use ($entry, $section) {
    configure(['excludedSections' => [$section->handle]]);

    return !str_contains(craft\helpers\Cp::elementChipHtml($entry, ['context' => 'index']), 'stopsign-occupied')
        ?: 'an excluded section was badged';
});

// ---------------------------------------------------------------------------- index columns + rule

section('Index columns and the “Is locked” rule');

/** Runs a query through an element condition holding only the “Is locked” rule. */
function lockedIds(bool $value, array $ids, ?callable $tweak = null): array
{
    $condition = Entry::createCondition();
    $rule = new IsLockedConditionRule();
    $rule->value = $value;
    $condition->addConditionRule($rule);

    // Through the config round trip a saved custom source takes, not only the live object.
    $condition = Craft::$app->getConditions()->createCondition($condition->getConfig());

    $query = Entry::find()->id($ids)->status(null);

    if ($tweak) {
        $tweak($query);
    }

    $condition->modifyQuery($query);

    $found = array_map('intval', $query->ids());
    sort($found);

    return $found;
}

check('entry indexes offer a “Being edited by” column', function() {
    configure();

    return isset(Entry::tableAttributes()[Plugin::ATTRIBUTE_OCCUPANTS]) ?: 'the column was not registered';
});

check('the “Locked by” column only appears once locking is on', function() {
    configure();
    $off = !isset(Entry::tableAttributes()[Plugin::ATTRIBUTE_LOCK]);
    configure(['lockMode' => Settings::LOCK_MODE_ALL]);
    $on = isset(Entry::tableAttributes()[Plugin::ATTRIBUTE_LOCK]);

    return ($off && $on) ?: 'the lock column ignored the lock mode';
});

check('the occupants column names who is in, typing first, and only on that row', function() use ($entry, $otherEntry, $users, $TAB_A, $TAB_C) {
    wipe();
    configure();
    $presence = Plugin::getInstance()->presence;
    $presence->beat($entry, $users['alex'], $TAB_A, 'viewing', false);
    $presence->beat($entry, $users['dana'], $TAB_C, 'editing', true);

    $html = $entry->getAttributeHtml(Plugin::ATTRIBUTE_OCCUPANTS);
    $empty = $otherEntry->getAttributeHtml(Plugin::ATTRIBUTE_OCCUPANTS);
    $expected = $users['dana']->getName() . ' (typing), ' . $users['alex']->getName();

    return ($html === $expected && $empty === '') ?: "got “{$html}” and “{$empty}”";
});

check('two tabs of one person are one name', function() use ($entry, $users, $TAB_B) {
    Plugin::getInstance()->presence->beat($entry, $users['alex'], $TAB_B, 'viewing', false);

    return substr_count($entry->getAttributeHtml(Plugin::ATTRIBUTE_OCCUPANTS), $users['alex']->getName()) === 1
        ?: 'a second tab was listed as a second person';
});

check('the occupants column is one query for the whole index, not one per row', function() use ($entry, $otherEntry) {
    $presence = Plugin::getInstance()->presence;
    $presence->occupantsOf($entry->id);

    // Write a row behind the memo's back: a per-row query would see it, a memoised one must not.
    Db::insert(PresenceRecord::TABLE, [
        'elementId' => $otherEntry->id,
        'siteId' => $otherEntry->siteId,
        'userId' => $entry->authorId,
        'sessionToken' => str_repeat('d', 32),
        'elementType' => Entry::class,
        'intent' => 'viewing',
        'dirty' => false,
        'firstSeen' => Db::prepareDateForDb(new DateTime()),
        'lastSeen' => Db::prepareDateForDb(new DateTime()),
    ]);
    $memoised = $presence->occupantsOf($otherEntry->id) === [];
    Db::delete(PresenceRecord::TABLE, ['sessionToken' => str_repeat('d', 32)]);

    return $memoised ?: 'the column queried again for a second row';
});

check('names are escaped in the occupants column', function() use ($entry, $users, $TAB_C) {
    $dana = $users['dana'];
    $realName = $dana->fullName;
    $dana->fullName = '<b>Dana</b> Tester';
    Craft::$app->getElements()->saveElement($dana, false);
    Plugin::getInstance()->presence->beat($entry, $dana, $TAB_C, 'editing', true);

    $html = $entry->getAttributeHtml(Plugin::ATTRIBUTE_OCCUPANTS);

    $dana->fullName = $realName;
    Craft::$app->getElements()->saveElement($dana, false);

    return (!str_contains($html, '<b>') && str_contains($html, '&lt;b&gt;')) ?: "unescaped: $html";
});

check('an excluded section gets an empty occupants cell', function() use ($entry, $section) {
    configure(['excludedSections' => [$section->handle]]);

    return $entry->getAttributeHtml(Plugin::ATTRIBUTE_OCCUPANTS) === '' ?: 'an unwatched entry named its occupants';
});

check('the lock column names the holder on the locked row only', function() use ($entry, $otherEntry, $users, $TAB_A) {
    wipe();
    configure(['lockMode' => Settings::LOCK_MODE_ALL]);
    Plugin::getInstance()->locks->claimOrRenew($entry, $users['alex'], $TAB_A);

    $html = $entry->getAttributeHtml(Plugin::ATTRIBUTE_LOCK);
    $empty = $otherEntry->getAttributeHtml(Plugin::ATTRIBUTE_LOCK);

    return ($html === $users['alex']->getName() && $empty === '') ?: "got “{$html}” and “{$empty}”";
});

check('the lock column empties when the lock changes hands or goes', function() use ($entry, $users, $TAB_A, $TAB_C) {
    $locks = Plugin::getInstance()->locks;
    $locks->takeOver($entry, $users['dana'], $TAB_C);
    $afterTakeOver = $entry->getAttributeHtml(Plugin::ATTRIBUTE_LOCK);
    $locks->release($entry, $users['dana'], $TAB_C);
    $afterRelease = $entry->getAttributeHtml(Plugin::ATTRIBUTE_LOCK);

    return ($afterTakeOver === $users['dana']->getName() && $afterRelease === '')
        ?: "got “{$afterTakeOver}” then “{$afterRelease}”";
});

check('every entry condition offers “Is locked”', function() {
    foreach (Entry::createCondition()->getSelectableConditionRules() as $rule) {
        if ($rule instanceof IsLockedConditionRule) {
            return true;
        }
    }

    return 'the rule was not selectable';
});

check('“Is locked” is still offered with locking off, so a saved source never widens to everything', function() {
    configure();

    foreach (Entry::createCondition()->getSelectableConditionRules() as $rule) {
        if ($rule instanceof IsLockedConditionRule) {
            return true;
        }
    }

    return 'the rule vanished with the setting';
});

check('“Is locked” narrows a query to the locked entry, and “not” to the rest', function() use ($entry, $otherEntry, $users, $TAB_A) {
    wipe();
    configure(['lockMode' => Settings::LOCK_MODE_ALL]);
    Plugin::getInstance()->locks->claimOrRenew($entry, $users['alex'], $TAB_A);
    $ids = [$entry->id, $otherEntry->id];

    $locked = lockedIds(true, $ids);
    $unlocked = lockedIds(false, $ids);

    return ($locked === [$entry->id] && $unlocked === [$otherEntry->id])
        ?: 'locked ' . json_encode($locked) . ', unlocked ' . json_encode($unlocked);
});

check('“Is locked” agrees with itself on a single element', function() use ($entry, $otherEntry) {
    $rule = new IsLockedConditionRule();
    $rule->value = true;

    return ($rule->matchElement($entry) && !$rule->matchElement($otherEntry)) ?: 'matchElement disagreed with the query';
});

check('“Is locked” finds the drafts of a locked entry', function() use ($entry, $admin) {
    $draft = Craft::$app->getDrafts()->createDraft($entry, $admin->id);

    try {
        $found = lockedIds(true, [$draft->id], fn($query) => $query->drafts(true));
    } finally {
        Craft::$app->getElements()->deleteElement($draft, true);
    }

    return $found === [$draft->id] ?: 'a draft of a locked entry was not matched: ' . json_encode($found);
});

check('an expired lock is not a lock', function() use ($entry, $otherEntry) {
    Db::update(LockRecord::TABLE, ['expiryDate' => Db::prepareDateForDb(new DateTime('-1 minute'))], ['elementId' => $entry->id]);

    return lockedIds(true, [$entry->id, $otherEntry->id]) === [] ?: 'an expired lock still matched';
});

// ---------------------------------------------------------------------------- staleness

section('Staleness');

check('an element that has not moved is not stale', function() use ($entry, $users, $TAB_A) {
    wipe();
    configure();
    $known = $entry->dateUpdated->getTimestamp();
    $verdict = Plugin::getInstance()->verdicts->build($entry, $users['alex'], $TAB_A, $known);

    return !$verdict->stale ?: 'an unchanged element was called stale';
});

check('an element saved since you loaded it is stale', function() use ($entry, $users, $TAB_A) {
    $known = $entry->dateUpdated->getTimestamp() - 600;
    $verdict = Plugin::getInstance()->verdicts->build($entry, $users['alex'], $TAB_A, $known);

    return ($verdict->stale && $verdict->level() === Verdict::LEVEL_DANGER)
        ?: 'a moved element was not reported stale';
});

check('the staleness warning names the person who saved', function() use ($entry, $users, $TAB_A) {
    Plugin::getInstance()->collisions->recordSave($entry, $users['dana']);
    $known = $entry->dateUpdated->getTimestamp() - 600;
    $verdict = Plugin::getInstance()->verdicts->build($entry, $users['alex'], $TAB_A, $known);

    return ($verdict->staleBy === $users['dana']->getName()
        && str_contains($verdict->headline(), $users['dana']->getName()))
        ?: 'the staleness warning could not name the person: ' . $verdict->headline();
});

check('your own save is not somebody moving the element under you', function() use ($entry, $users, $TAB_A) {
    Plugin::getInstance()->collisions->recordSave($entry, $users['alex']);
    $known = $entry->dateUpdated->getTimestamp() - 600;
    $verdict = Plugin::getInstance()->verdicts->build($entry, $users['alex'], $TAB_A, $known);

    return !$verdict->stale
        ?: 'the author’s own save triggered the guard — the fastest way to teach somebody to click through it';
});

check('the save ledger keeps one row per element', function() use ($entry) {
    return (int)(new craft\db\Query())->from(SaveRecord::TABLE)->where(['elementId' => $entry->id])->count() === 1
        ?: 'the save ledger grew a row per save instead of one per element';
});

check('a real save through Craft is recorded', function() use ($entry, $admin) {
    configure();
    Db::delete(SaveRecord::TABLE, []);

    // Saved as the admin rather than one of the fixtures above: those are inactive accounts with
    // no authoring permission, so Craft refuses the save outright and the ledger is then right to
    // be empty. The entry is what is under test here, not the permission system.
    Craft::$app->getUser()->setIdentity($admin);
    $entry->title = $entry->title . ' ✎';
    $saved = Craft::$app->getElements()->saveElement($entry);
    Craft::$app->getUser()->setIdentity(null);

    if (!$saved) {
        return 'the fixture entry would not save: ' . json_encode($entry->getErrors());
    }

    $last = Plugin::getInstance()->collisions->lastSave($entry);

    return ($last !== null && $last['userId'] === $admin->id)
        ?: 'a save through Craft was not recorded in the ledger';
});

check('a save in one site is attributable from another', function() use ($entry, $admin) {
    $sites = Craft::$app->getSites()->getAllSites();

    if (count($sites) < 2) {
        return true;
    }

    // `elements.dateUpdated` is global, so a save in one site makes the element stale everywhere.
    // A save ledger scoped to the viewer's site would leave that warning unable to name anybody.
    $other = clone $entry;
    $other->siteId = $sites[1]->id;

    $last = Plugin::getInstance()->collisions->lastSave($other);

    return ($last !== null && $last['userId'] === $admin->id)
        ?: 'a save made in one site could not be attributed from another';
});

// ---------------------------------------------------------------------------- the save guard

section('The save guard');

check('a clear verdict never interrupts', function() use ($entry, $users, $TAB_A) {
    wipe();
    configure();
    $verdict = Plugin::getInstance()->verdicts->build($entry, $users['alex'], $TAB_A);

    return !$verdict->needsConfirmation(true, true, true) ?: 'a clear verdict asked for confirmation';
});

check('a viewer does not interrupt when only editors should', function() use ($entry, $users, $TAB_A, $TAB_C) {
    Plugin::getInstance()->presence->beat($entry, $users['dana'], $TAB_C, 'viewing', false);
    $verdict = Plugin::getInstance()->verdicts->build($entry, $users['alex'], $TAB_A);

    return (!$verdict->needsConfirmation(true, true, true) && $verdict->needsConfirmation(true, true, false))
        ?: 'guardOnlyWhenOtherIsEditing did not change the guard’s mind';
});

check('an editor always interrupts', function() use ($entry, $users, $TAB_A, $TAB_C) {
    Plugin::getInstance()->presence->beat($entry, $users['dana'], $TAB_C, 'editing', true);
    $verdict = Plugin::getInstance()->verdicts->build($entry, $users['alex'], $TAB_A);

    return $verdict->needsConfirmation(true, true, true) ?: 'a live editor did not stop the save';
});

check('the guard can be switched off without switching off the stale guard', function() use ($entry, $users, $TAB_A) {
    $verdict = Plugin::getInstance()->verdicts->build($entry, $users['alex'], $TAB_A);
    $verdict->stale = true;

    return (!$verdict->needsConfirmation(false, false, true) && $verdict->needsConfirmation(false, true, true))
        ?: 'the two guards were not independent';
});

check('the browser payload carries the same wording as the banner', function() use ($entry, $users, $TAB_A, $TAB_C) {
    wipe();
    configure();
    Plugin::getInstance()->presence->beat($entry, $users['dana'], $TAB_C, 'editing', true);
    $verdict = Plugin::getInstance()->verdicts->build($entry, $users['alex'], $TAB_A);
    $payload = $verdict->toArray();

    return ($payload['headline'] === $verdict->headline()
        && $payload['detail'] === $verdict->detail()
        && $payload['level'] === $verdict->level()
        && count($payload['others']) === 1
        && !isset($payload['others'][0]['thumbHtml'][10000]))
        ?: 'the payload and the verdict disagreed, which is how a modal ends up saying something the banner did not';
});

// ---------------------------------------------------------------------------- history

section('History');

check('a collision is recorded', function() use ($entry, $users) {
    configure();
    Db::delete(CollisionRecord::TABLE, []);

    Plugin::getInstance()->collisions->record(
        $entry,
        $users['alex'],
        $users['dana']->id,
        CollisionRecord::KIND_CONCURRENT,
        CollisionRecord::OUTCOME_PROCEEDED,
    );

    $rows = Plugin::getInstance()->collisions->recent(10);

    return (count($rows) === 1
        && $rows[0]['userName'] === $users['alex']->getName()
        && $rows[0]['otherUserName'] === $users['dana']->getName()
        && $rows[0]['outcome'] === CollisionRecord::OUTCOME_PROCEEDED)
        ?: 'the collision was not recorded readably';
});

check('a repeated warning is one row, not one per heartbeat', function() use ($entry, $users) {
    $collisions = Plugin::getInstance()->collisions;

    foreach (range(1, 3) as $beat) {
        $collisions->record($entry, $users['alex'], $users['dana']->id, CollisionRecord::KIND_CONCURRENT, CollisionRecord::OUTCOME_WARNED);
    }

    $warned = (int)(new craft\db\Query())->from(CollisionRecord::TABLE)->where(['outcome' => CollisionRecord::OUTCOME_WARNED])->count();
    Db::delete(CollisionRecord::TABLE, ['outcome' => CollisionRecord::OUTCOME_WARNED]);

    return $warned === 1 ?: "a client asking for a warned row on every beat wrote $warned";
});

check('history can be switched off', function() use ($entry, $users) {
    configure(['recordHistory' => false]);
    Plugin::getInstance()->collisions->record($entry, $users['alex'], null, CollisionRecord::KIND_STALE, CollisionRecord::OUTCOME_CANCELLED);

    return count(Plugin::getInstance()->collisions->recent(10)) === 1
        ?: 'a row was written with history switched off';
});

check('the summary counts outcomes as integers', function() {
    configure();
    $summary = Plugin::getInstance()->collisions->summary(30);

    return ($summary[CollisionRecord::OUTCOME_PROCEEDED] === 1
        && $summary[CollisionRecord::OUTCOME_CANCELLED] === 0
        && array_sum($summary) === 1)
        ?: 'COUNT(*) came back as a string and the summary could not be added up';
});

check('retention prunes, and zero means forever', function() use ($entry, $users) {
    Db::update(CollisionRecord::TABLE, [
        'dateCreated' => Db::prepareDateForDb((new DateTime('now', new DateTimeZone('UTC')))->modify('-90 days')),
    ], []);

    configure(['historyRetentionDays' => 0]);
    Plugin::getInstance()->collisions->prune();
    $kept = count(Plugin::getInstance()->collisions->recent(10));

    configure(['historyRetentionDays' => 30]);
    Plugin::getInstance()->collisions->prune();
    $pruned = count(Plugin::getInstance()->collisions->recent(10));

    return ($kept === 1 && $pruned === 0) ?: "kept=$kept pruned=$pruned";
});

check('history outlives the element it is about', function() use ($otherEntry, $users) {
    configure();
    Plugin::getInstance()->collisions->record(
        $otherEntry,
        $users['alex'],
        $users['dana']->id,
        CollisionRecord::KIND_CONCURRENT,
        CollisionRecord::OUTCOME_PROCEEDED,
    );

    $id = $otherEntry->id;
    Craft::$app->getElements()->deleteElement($otherEntry, true);

    return count(Plugin::getInstance()->collisions->recent(10, $id)) === 1
        ?: 'deleting an entry erased the record that two people fought over it';
});

// ---------------------------------------------------------------------------- settings

section('Settings');

check('a presence window shorter than two heartbeats is refused', function() {
    $settings = new Settings();
    $settings->heartbeatSeconds = 30;
    $settings->presenceTtlSeconds = 45;

    return (!$settings->validate() && $settings->hasErrors('presenceTtlSeconds'))
        ?: 'a flickering configuration validated';
});

check('a lock timeout shorter than three heartbeats is refused', function() {
    $settings = new Settings();
    $settings->heartbeatSeconds = 60;
    $settings->lockTtlSeconds = 60;

    return (!$settings->validate() && $settings->hasErrors('lockTtlSeconds'))
        ?: 'a lock that would expire under its own holder validated';
});

check('the shipped defaults validate', function() {
    return (new Settings())->validate() ?: 'the defaults do not pass their own rules';
});

check('nothing is marked required', function() {
    $settings = new Settings();
    $rules = $settings->getActiveValidators();

    foreach ($rules as $validator) {
        if ($validator instanceof yii\validators\RequiredValidator) {
            return 'a required rule would block savePluginSettings() on a fresh install';
        }
    }

    return true;
});

// ---------------------------------------------------------------------------- cleanup

section('Cleanup');

check('the test rows are gone', function() {
    wipe();
    Db::delete(CollisionRecord::TABLE, []);

    foreach ([PresenceRecord::TABLE, LockRecord::TABLE, SaveRecord::TABLE, CollisionRecord::TABLE] as $table) {
        if ((int)(new craft\db\Query())->from($table)->count() !== 0) {
            return "rows left behind in $table";
        }
    }

    return true;
});

check('the test entry is deleted', function() use ($entry) {
    return Craft::$app->getElements()->deleteElement($entry, true);
});

check('the test users are deleted', function() use ($users) {
    foreach ($users as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }

    return true;
});

check('the original settings are restored', function() use ($plugin, $original) {
    // In memory only. `configure()` never persisted anything, so there is nothing in project
    // config to put back — and writing it here would fail on the project config lock while this
    // shared harness’s queue runner holds it.
    $plugin->setSettings($original);
    $plugin->scope->reset();

    return $plugin->getSettings()->toArray() == $original ?: 'settings did not round-trip';
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
