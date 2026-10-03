---
title: FAQ
slug: faq
order: 70
summary: Cost, what it stores, why it does not use Craft's activity table, Snitch, Locked Entries, performance, and what happens when you remove it.
---

### How much does it cost?

Nothing. One edition, everything switched on.

### Doesn't Craft already do this?

Partly, and it is worth being exact. Craft has had an `elementactivity` table since 4.5, and an
open editor polls it every fifteen seconds to show a row of small avatars with tooltips.

What it does not have: any warning you could call loud, anything at all between the save button and
the save, a staleness prompt that works in slideouts, the name of whoever saved, any awareness of
your own second tab, any badge before you open the element, any history — the table is emptied
every sixty seconds — or any setting.

Stop Sign leaves Craft's avatars exactly where they are and adds those.

### Why not just read Craft's `elementactivity` table?

Two reasons, both fatal. Craft's garbage collector deletes every row older than sixty seconds, so
the table can only ever answer "in the last minute" and the window is not configurable. And it is
keyed per user, so it cannot distinguish two tabs of the same account — which
`getRecentActivity()` then filters out of its own results anyway.

### Is this Snitch?

Snitch (`marionnewlevant/snitch`) did the same job for Craft 3 and had 85,000 installs. It has not
had a release since December 2020 and was never ported to Craft 4 or 5. Stop Sign is not a port —
it is built for Craft 5's element editor, provisional drafts and slideouts, none of which existed
in the shape Snitch was written against.

### How is this different from Locked Entries?

Locked Entries locks. Stop Sign warns, and locking is an option that is off by default. When you do
turn it on, the lock always expires, is always recorded, and can always be taken over.

### What does it store?

Four small tables. Who had what open, who holds which lock, who last saved each element, and a
history of collisions with what the author decided. No content, no visitor data, no IP addresses,
and no outbound requests of any kind.

### Is it GDPR-relevant?

It records control panel users' activity on your own content — the same category as Craft's own
revision authorship, and less than Craft's activity table records in the moment. Nothing about
site visitors is touched. Collision history retention is a setting, and thirty days is the default.

### What does it cost in performance?

One request per open editor per ten seconds, and that request is one upsert and one indexed read.
Index badges add a single query per page regardless of how many chips are on it. Nothing runs on
the front end at all — Stop Sign registers no front-end assets, no template hooks and no event
handlers that fire outside the control panel.

### Does it work in slideouts?

Yes, including the save guard and the staleness warning. Craft's own staleness prompt is inside an
`isFullPage` branch and does not.

### Does it work with Matrix and CKEditor nested entries?

Yes — those get their own slideout editors, so two people really can be in one, and Stop Sign
attaches to them like any other editor. Section-handle rules cannot address them, because a nested
entry has no section.

### Will it block my imports, syncs or queue jobs?

No. Console commands, queue jobs and visitors' front-end requests are never refused. A lock is
consulted for control panel requests, and for one front-end case: a save posted to a front-end
action URL by somebody with control panel access, which is otherwise a way to step around it.

### What happens if the Stop Sign endpoint is down?

Saving goes through. A plugin that makes saving impossible when its own endpoint is unreachable is
a worse outage than the collision it was trying to prevent.

### What happens when I uninstall it?

All four tables are dropped and nothing else changes. No element was modified by having been
watched — presence, locks and history live entirely in Stop Sign's own tables.

If locking is enabled, run `php craft stopsign/unlock --all` first.
