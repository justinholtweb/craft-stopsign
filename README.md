# Stop Sign

Concurrent-editing collision warnings for Craft CMS 5.

Two people open the same entry. Both type. Both save. One of them loses an afternoon, and nobody
finds out until a week later when the missing paragraph is noticed. Stop Sign is about the ninety
seconds before that happens.

**Free. One edition. Everything switched on.**

---

## What Craft already does, and where it stops

Craft is not silent about this, and it is worth being precise about what it does before claiming
to add anything. Since 4.5 there has been an `elementactivity` table, a `elements/recent-activity`
action, and a poll in `Craft.ElementEditor` that runs every fifteen seconds and stacks a few 26px
avatars in the editor header with a tooltip on each.

It is deliberately understated, and it stops well short of a collision warning:

| | Craft 5 | Stop Sign |
|---|---|---|
| Somebody else is here | A 26px avatar with a tooltip | A banner that names them and says what happens if you both save |
| You click Save anyway | Nothing happens. It just saves | A confirmation that says who and why, with a way through |
| The entry changed under you | A "Reload" prompt — **full-page editors only** | The same warning in slideouts too, and it names who saved |
| Who saved it | Not recorded anywhere | Named |
| Your own account in another tab | Never reported — your user id is filtered out of the results | Reported |
| Before you open the entry | Nothing | A badge on the index chip, plus “Being edited by” and “Locked by” columns |
| Finding what is locked | Nothing | An “Is locked” condition rule for custom index sources |
| Afterwards | Nothing. The table is emptied every 60 seconds | A history, with what each author decided |
| Configurable | No | Yes |
| Locking | No | Optional, per section, always takeable |

The 60-second number is not an exaggeration: Craft's garbage collector runs
`DELETE FROM elementactivity WHERE timestamp < '1 minute ago'` on every pass. The table is a live
signal and nothing else, by design — which is why Stop Sign keeps its own.

## Requirements

Craft CMS 5.3.0 or later, PHP 8.2 or later. No runtime dependencies, no build step, no outbound
requests.

## Installation

```sh
composer require justinholtweb/craft-stopsign
php craft plugin/install stopsign
```

Nothing else is needed. The defaults warn, guard saves, and do not lock.

## How it works

**Presence.** Every open editor sends a heartbeat every ten seconds — one upsert and one indexed
read. It is keyed per *tab*, not per user, which is what lets Stop Sign report your own account in
a second window. The heartbeat carries `dontExtendSession`, so leaving an editor open does not keep
a control panel session alive forever.

**One verdict.** The banner, the save guard, the read-only state, the history row and the console
output all read from a single `Verdict` object built on the server. A warning that says one thing
in the banner and something else in the modal is worse than no warning, because the author stops
believing either.

**The save guard.** Stop Sign wraps `handleSubmit` on the `ElementEditor` instance. That one method
covers full-page saves, slideout saves, and ⌘S in both — `Craft.ElementEditorSlideout.handleSubmit()`
delegates straight to it. Nothing global is patched. The check is taken fresh at the moment of
saving rather than reused from the last heartbeat, because ten seconds is long enough for a
colleague to arrive.

**Soft locks.** Off by default. When on, the lock is claimed by a unique database index rather than
by a read-then-write, because two people clicking into the same entry in the same second is the
exact case this plugin exists for. It is enforced through `Elements::EVENT_AUTHORIZE_SAVE`, which
means Craft renders the second editor read-only *itself* — no disabled-looking form that only fails
at the end. It always expires, it is always recorded, and unless you deliberately turn that off it
can always be taken over.

If a lock ever does get in the way:

```sh
php craft stopsign/unlock --all
```

Console commands, queue jobs and front-end requests are never refused by a lock. A lock that stops
`resave/entries` is not a collision warning, it is an outage.

## Settings

Settings → Plugins → Stop Sign. The ones worth knowing about:

- **Only when they have actually typed** — on by default. A modal in front of every save because a
  colleague has the entry open in a background tab is a modal people learn to dismiss without
  reading, and then it is not there when it matters.
- **Sections to leave alone** — for sections one person owns, and for machine-written content that
  would otherwise collide with itself.
- **Lock mode** — `off`, chosen sections, or everything. Leave it off until warnings have stopped
  being enough.
- **Admins are never locked out** — leave this on. It is what keeps a mistyped group list from
  shutting a site's owner out of their own content.

## Utilities → Stop Sign

Who is in what right now, which locks are held, and the collision history. The number worth
watching is not how many collisions happened — it is how many were clicked straight through.

## Console

```sh
php craft stopsign/status            # what it is doing right now
php craft stopsign/unlock            # clear expired locks
php craft stopsign/unlock --all      # clear every lock — the kill switch
php craft stopsign/prune             # trim presence, locks and history
php craft stopsign/history --days=30 # collisions, and how many were clicked through
```

## Twig

```twig
{% if craft.stopsign.isOccupied(entry) %}
    <span class="busy">Somebody is in this right now</span>
{% endif %}

{% for row in craft.stopsign.board() %}
    {{ row.userName }} — {{ row.dirty ? 'editing' : 'viewing' }} #{{ row.elementId }}
{% endfor %}
```

## Privacy

Stop Sign stores who had what open and who saved what. It records nothing about visitors, makes no
outbound requests, and holds no content — the collision history is user ids, element ids and an
outcome. Presence and locks are pruned automatically; history retention is a setting, and zero
means keep it.

## License

The Craft License. See `LICENSE.md`.
