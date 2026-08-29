---
title: Usage
slug: usage
order: 25
summary: What each warning means, what the save guard asks, the utility screen, and the Twig and console interfaces.
---

## The five things Stop Sign says

| It says | It means | Colour |
|---|---|---|
| *"Dana Reyes is editing this right now."* | Somebody else has typed. If you both save, one of you loses the work | Red |
| *"Dana Reyes has this open."* | Somebody else is on the screen but has not typed | Amber |
| *"Dana Reyes saved this while you had it open."* | What you are looking at is out of date. Saving would put the old version back | Red |
| *"You have this open in another tab."* | Two of your own windows will overwrite each other | Blue |
| *"Dana Reyes has this locked."* | Read-only until they finish, or until you take over | Red |

The own-tab warning is a notice rather than a danger, and it does **not** interrupt saving. It is
still your own work either way, and a modal in front of every save because you happen to have two
windows open is a modal you would learn to click through. The banner is enough.

## The save guard

When somebody else is actively editing, or the element changed while you had it open, saving stops
and asks:

> **Hold on**
> Dana Reyes is editing this right now.
> If you both save, one of you loses the work.
>
> `Go back` `Save anyway`

When the element is stale there is a third button, **Reload the latest**, which discards nothing —
it reloads so you can see what changed before deciding.

Every one of those three choices is recorded. That is the point of the history: the interesting
number is not how many collisions happened, it is how many were clicked straight through.

## Index badges

An element that somebody is inside gets a dot on its chip, on element indexes and in relation
fields. It is a snapshot from when the page rendered, not a live feed — the alternative is polling
from every index screen in the control panel, which is a lot of requests to answer a question that
is only ever "probably not".

## Utilities → Stop Sign

Three things:

- **Open right now** — who is in what, viewing or editing, and since when.
- **Locks held** — only shown when locking is on. Includes a **Release every lock** button.
- **Collisions** — the history, with a summary of outcomes across the retention window.

## Console

```sh
php craft stopsign/status            # settings, who is open, what is locked
php craft stopsign/unlock            # clear expired locks
php craft stopsign/unlock --all      # clear every lock — the kill switch
php craft stopsign/prune             # trim presence, locks, history and the save ledger
php craft stopsign/history --days=30 # collisions, and how many were clicked through
```

`stopsign/prune` runs automatically as part of Craft's garbage collection; the command is there for
when you want it now.

## Twig

```twig
{# Is anybody inside this element right now? #}
{% if craft.stopsign.isOccupied(entry) %}
    <span class="busy">Somebody is working on this</span>
{% endif %}

{# A dashboard of everything open #}
{% for row in craft.stopsign.board() %}
    {{ row.userName }} — {{ row.dirty ? 'editing' : 'viewing' }} #{{ row.elementId }}
{% endfor %}

{# Live locks #}
{% for lock in craft.stopsign.locks() %}
    {{ lock.userName }} holds #{{ lock.elementId }}
{% endfor %}

{# Collision history, optionally for one element #}
{% for row in craft.stopsign.history(20, entry.id) %}
    {{ row.dateCreated|datetime }} — {{ row.userName }} {{ row.outcome }}
{% endfor %}
```

`isOccupied()` answers from a single query memoised for the whole request, so calling it in a loop
over a hundred elements costs one query, not a hundred.
