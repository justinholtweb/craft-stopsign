---
title: How it works
slug: how-it-works
order: 20
summary: What Craft already does, why Stop Sign keeps its own presence table, and the one method the save guard wraps.
---

## What Craft already does

Craft has had a quiet version of this since 4.5, and it is worth knowing exactly what it is before
reading anything Stop Sign claims to add.

`craft\services\Elements::trackActivity()` writes to an `elementactivity` table. Every open element
editor polls `elements/recent-activity` every fifteen seconds, and
`Craft.ElementEditor._checkActivity()` renders the result as a row of 26px avatars in the editor
header, each with a tooltip reading *"Dana Reyes is editing this entry."*

It is deliberately understated, and it stops in five specific places:

1. **It is a tooltip on a small avatar.** There is no banner, no colour, and nothing that
   interrupts. On a busy editor screen it is genuinely easy to miss.
2. **There is nothing at save time.** Craft's activity feature has no opinion about the save
   button. An author who has not looked at the avatars saves straight over the top.
3. **The staleness prompt is full-page only.** The "someone changed this — Reload" button lives
   inside an `isFullPage` branch, so editing in a slideout gives no conflict signal at all.
4. **It keeps no history.** Craft's garbage collector deletes every `elementactivity` row older
   than **sixty seconds**. The question *"did anyone overwrite anyone last Tuesday?"* has no answer
   on a stock install.
5. **It cannot see your own second tab.** `getRecentActivity()` takes an `$excludeUserId` and the
   controller always passes the current user, so two windows of the same account — a real and
   common way to lose work — are invisible to it.

Stop Sign is those five gaps.

## Presence

Every open editor sends a heartbeat: one upsert and one indexed read, every ten seconds by default.

The row is keyed on `(elementId, siteId, userId, sessionToken)` — **per tab, not per user**. That
is the only shape that can answer "you have this open in another tab", and it is why Stop Sign
keeps its own table rather than reading Craft's: `elementactivity` is keyed per user and is emptied
every sixty seconds, so it can never answer anything but "in the last minute", and the window is
not configurable.

The heartbeat carries `dontExtendSession`, exactly as Craft's own poll does. Without it, an editor
left open would keep a control panel session alive indefinitely and the plugin would quietly defeat
`userSessionDuration` on every site that installed it.

A hidden tab stops beating — you are not there — unless it has unsaved changes, in which case it
keeps beating at a quarter rate. Closing a laptop lid should not hand your half-finished entry to
somebody else.

## One verdict

The banner, the save guard, the read-only state, the history row and the console output all read
from a single `Verdict` object built on the server.

This is not tidiness. A warning that says one thing in the banner and something else in the modal
is worse than no warning at all, because the author stops believing either of them. The wording is
decided once and the browser only renders it.

The levels, strongest first:

| Level | When |
|---|---|
| `locked` | Somebody else holds the lock |
| `danger` | Somebody else is typing, or the element moved under you |
| `warning` | Somebody else has it open but has not typed |
| `notice` | Your own account, in another tab |
| `clear` | Nothing to say |

## The save guard

Stop Sign wraps `handleSubmit` on each `ElementEditor` **instance**. Nothing global is patched.

That one method is enough for everything, because `Craft.ElementEditorSlideout.handleSubmit()`
does its own bookkeeping and then calls straight through to `this.elementEditor.handleSubmit(event)`.
Full-page saves, slideout saves and ⌘S in both arrive at the same place.

The check is taken **fresh**, not reused from the last heartbeat. Ten seconds is easily long enough
for a colleague to open the entry, and a guard that waves through a collision it could have seen is
worse than no guard.

If the guard endpoint cannot be reached, the save goes through. A plugin that makes saving
impossible when its own endpoint is down is a worse outage than the collision it was preventing.

## Staleness

The browser sends the canonical `dateUpdated` it started from; the server compares it to the
current one.

Two things make this better than Craft's version. It works in slideouts, and it **names the
person** — `stopsign_saves` records who last saved each element, because nothing on a canonical
element row does. Revisions come closest and can be switched off per section, so a warning built on
them would silently degrade to "updated by nobody" on exactly the sections whose owners disabled
revisions to save space.

Your own save never counts as somebody moving the element under you. Without that guard the modal
would fire on an author's own second save of the session, which is the fastest possible way to
teach somebody to click straight through it.

## Soft locks

Off by default. See **Locking**.
