---
title: Configuration
slug: configuration
order: 30
summary: Every setting, what it defaults to, and the three that are worth thinking about rather than accepting.
---

**Settings → Plugins → Stop Sign.**

## What Stop Sign watches

| Setting | Default | |
|---|---|---|
| Enabled | on | The master switch. Off, Stop Sign renders nothing and records nothing; Craft's own avatar stack carries on as it always did. |
| Every element type | on | Watch anything with an editor. Turn it off to name the types yourself. |
| Sections to leave alone | none | Entries in these sections get no warning, no badge and no lock. |

**Sections to leave alone** earns its place on two kinds of site: sections one person owns
outright, and machine-written content — importers, syncs, feed ingestion — which would otherwise
collide with itself and train everyone to ignore the banner.

## The warning

| Setting | Default | |
|---|---|---|
| Banner style | Loud | `Loud` is a full-width banner. `Subtle` is one line: the same words, less of the screen. |
| Warn about people who are only looking | on | Off, only people who have started typing are reported. |
| Warn about your own other tabs | on | Craft can never report this — it filters you out of its own results. |
| Badge occupied elements on index screens | on | A dot on the chip, so you find out before you open the entry. |

`Subtle` drops the detail line and the octagon rather than shrinking everything. A smaller banner
keeps every part of a banner and just makes them all harder to read.

## The save guard

| Setting | Default | |
|---|---|---|
| Ask before saving over somebody | on | |
| Only when they have actually typed | on | |
| Ask when the element changed underneath you | on | |

**Only when they have actually typed** is the setting worth understanding. With it on, a colleague
merely reading the entry produces a banner but no modal. With it off, every save while anybody has
the entry open is interrupted — which on a team of six means a modal in front of most saves, and a
modal in front of most saves is a modal people learn to dismiss without reading. Then it is not
there when it matters.

Turn it off only on a small team where every open editor really is somebody about to type.

## Soft locks

| Setting | Default | |
|---|---|---|
| Lock mode | Off | `Off`, chosen sections, or everything Stop Sign watches. |
| Sections to lock | none | Used when lock mode is "chosen sections". |
| Lock timeout | 120 seconds | How long a lock survives without a heartbeat from the tab holding it. |
| Refuse saves from anyone but the holder | on | Off, the lock is advisory. |
| Allow take-over | on | |
| Groups allowed to take over | anyone who can edit | Empty means anyone who could save the element. Naming groups narrows that further; it never lets a read-only account in. |
| Admins are never locked out | on | |

See **Locking** for what these actually do. Two of them are worth a warning here:

- **Allow take-over: off** is the one way to get genuinely stuck. `php craft stopsign/unlock --all`
  is the way back.
- **Admins are never locked out: on** is what keeps a mistyped group handle from shutting a site's
  owner out of their own content. Leave it on.

## Timing and history

| Setting | Default | |
|---|---|---|
| Heartbeat interval | 10 seconds | Craft's own poll is fixed at 15; this one is yours. |
| Presence window | 45 seconds | How long after a tab's last heartbeat it still counts as present. |
| Record collisions | on | |
| History retention | 30 days | Zero keeps it forever. |

The presence window must be at least **twice** the heartbeat, and the plugin refuses to save a
configuration where it is not. A single dropped request would otherwise make a colleague blink out
of the banner and back in again, which reads as a broken plugin rather than a misconfigured one.
For the same reason the lock timeout must be at least three heartbeats, or a lock can expire under
its own holder.

Raising the heartbeat to 5 seconds roughly doubles the request count for open editors; each request
is one upsert and one indexed read, so this is cheap, but it is not free on a site with forty
people in the control panel at once.

## Config file

Everything above can go in `config/stopsign.php`, with the usual multi-environment support:

```php
<?php

return [
    'lockMode' => 'sections',
    'lockedSections' => ['news', 'homepage'],
    'guardOnlyWhenOtherIsEditing' => true,
    'historyRetentionDays' => 90,
];
```

Anything set there is locked in the control panel, as with every Craft plugin.
