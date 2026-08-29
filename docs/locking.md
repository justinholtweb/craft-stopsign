---
title: Locking
slug: locking
order: 40
summary: Why locking is off by default, the three rules that keep a soft lock from becoming a support call, and how to get unstuck.
---

Locking is **off by default**, and on most sites it should stay off. A warning that names a
colleague solves the problem socially, which is faster and less annoying than a lock. Reach for
locking when warnings have demonstrably stopped being enough — the collision history on
**Utilities → Stop Sign** will tell you, because it counts how many warnings were clicked straight
through.

## Three rules

A soft lock is soft in three specific ways, and each of them exists because the failure it prevents
is the failure that makes people uninstall a locking plugin.

**It always expires.** The lock is renewed by the holder's heartbeat, not by their intention. Close
the laptop and it is gone in `lockTtlSeconds` — two minutes by default — not never.

**It can always be taken over.** Unless an administrator has deliberately turned that off. Taking a
lock over is recorded against the taker's name, so it is a visible act rather than a silent one.

**It is claimed by the database, not by PHP.** The unique index on `(elementId, siteId)` is what
settles the race. Two people clicking into the same entry in the same second is the exact case this
plugin exists for; a read-then-write would hand the lock to both of them.

## What the second person sees

If **Refuse saves from anyone but the holder** is on — the default — the lock is enforced through
`Elements::EVENT_AUTHORIZE_SAVE`. That is Craft's own permission check, which means Craft renders
the editor read-only *itself*: the save button goes, the fields disable, autosave stops.

This matters more than it sounds. The alternative — a live form that only refuses at the end — lets
somebody spend twenty minutes writing into a box that was never going to save.

Above it, Stop Sign's banner names the holder and offers **Take over** if this person is allowed to.

If the setting is off, the lock is advisory: the banner still names the holder, the history still
records the collision, but nothing stops a determined save.

## What never gets locked

- **Console commands.** `resave/entries`, custom commands, anything in a terminal.
- **Queue jobs.**
- **Front-end requests.** Form submissions, Commerce orders, anything a visitor causes.
- **Admins**, while *Admins are never locked out* is on.

A lock that stops `resave/entries` or a Commerce order update is not a collision warning, it is an
outage. Stop Sign checks that the request is a control panel request before it will refuse
anything.

## Losing a lock you were holding

Craft renders the second editor read-only because the lock was already held when the editor was
built. Somebody who had the page open *first* and then lost it is a different case: their form is
live, and it would fail at the end.

When Stop Sign notices that a lock it was holding has changed hands, it says so and disables
saving. The work is not lost — a provisional draft is still a provisional draft, and it is still
theirs. What has gone is the ability to publish it over the top of the person who took over.

## Getting unstuck

```sh
php craft stopsign/unlock          # clear expired locks — routine housekeeping
php craft stopsign/unlock --all    # clear every lock on the site
```

`--all` is the kill switch, and it is a console command on purpose: the control panel screen you
would need in order to fix a lock is a screen a lock can keep you off.

The same thing is available as a button on **Utilities → Stop Sign**, for when the control panel is
reachable.

## The setting not to touch

**Allow take-over: off** plus **Admins are never locked out: off** is the only configuration from
which there is no way out inside the control panel. It is possible because some editorial workflows
genuinely want it. If you use it, know that `stopsign/unlock --all` is the only door.
