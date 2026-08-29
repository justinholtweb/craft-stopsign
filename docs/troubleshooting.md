---
title: Troubleshooting
slug: troubleshooting
order: 60
summary: Nothing appears, everybody is locked out, the banner flickers, and the other three things that actually go wrong.
---

## Nothing appears, ever

Check in this order:

1. **Is it enabled?** `php craft stopsign/status`.
2. **Is the section excluded?** Settings → Plugins → Stop Sign → *Sections to leave alone*.
3. **Is the element saved?** A brand-new entry that has never been saved cannot be collided with —
   every "new entry" screen is its own unsaved element, and there is nothing for a second person to
   be inside.
4. **Is the browser console clean?** Stop Sign attaches to `Craft.ElementEditor` instances. If
   another plugin has thrown before ours ran, nothing after it is initialised.

`php craft stopsign/status` lists everything currently open. If somebody is on an editor screen and
they are not in that list, the heartbeat is not reaching the server — check the network tab for
`stopsign/presence/ping`.

## People flicker in and out of the banner

The presence window is too close to the heartbeat interval. One dropped request then drops somebody
out of the banner until the next beat.

Stop Sign refuses to save a configuration where the presence window is less than **twice** the
heartbeat, but a window set through `config/stopsign.php` bypasses control panel validation. Set it
to at least four times the heartbeat on a flaky connection.

## Everybody is locked out

```sh
php craft stopsign/unlock --all
```

Then work out why. The usual cause is **Groups allowed to take over** naming a group handle that
does not exist, combined with **Admins are never locked out** having been turned off. Turn the
admin bypass back on.

Locks never apply to console commands, queue jobs or front-end requests, so if `resave/entries` is
failing, it is not Stop Sign.

## A lock is held by somebody who went home

It expires. `lockTtlSeconds` — two minutes by default — after their last heartbeat. If you cannot
wait, take it over from the banner, or `php craft stopsign/unlock --all`.

A lock cannot outlive a crashed browser, because it is renewed by the heartbeat rather than
released on unload. That is deliberate: releasing on unload alone means a lock survives forever the
first time a laptop loses power.

## The save guard fires on my own saves

It should not, and there is a specific guard against it: a save recorded against your own user id
never counts as somebody moving the element under you.

If it is happening, the save ledger is not seeing your saves. It records the **signed-in** user, so
saves made by a console command, a queue job or an importer are attributed to nobody — and a
subsequent editor is then correctly told the element changed without being told who by. That is the
intended behaviour for automation, not a bug.

## Editors are read-only and I did not turn locking on

Check `php craft stopsign/status` for the lock mode. Then check `config/stopsign.php` — a value set
there overrides and locks the control panel field, so the screen can say one thing while the plugin
does another.

If lock mode really is `off`, Stop Sign is not what is making them read-only: it forms no opinion
at all on `canSave()` unless a lock is both enabled and actually held. Look at user permissions and
at other plugins listening on `Elements::EVENT_AUTHORIZE_SAVE`.

## Sessions are expiring sooner than they used to

That is not Stop Sign — the heartbeat sends `dontExtendSession`, which means an open editor does
**not** keep a control panel session alive. That is the correct behaviour and matches Craft's own
activity poll, but if you were previously relying on a plugin that did keep sessions alive, the
difference will show.

## The history is empty

**Record collisions** may be off, or **History retention** may be shorter than you think. Also note
that a collision is written when the situation first becomes worth warning about and when an author
makes a decision — not on every heartbeat. Two people editing for an hour produce a handful of
rows, not three hundred and sixty.
