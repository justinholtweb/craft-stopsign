---
title: Installation
slug: installation
order: 10
summary: Two commands, no configuration, and what changes on your site the moment it is installed.
---

Stop Sign needs Craft CMS 5.3 or later and PHP 8.2 or later. It has no runtime dependencies, no
build step, and makes no outbound requests.

```sh
composer require justinholtweb/craft-stopsign
php craft plugin/install stopsign
```

That is the whole installation. The defaults warn, guard saves, and do not lock anything.

## What changes immediately

- Every element editor — full-page and slideout — starts sending a heartbeat every ten seconds.
- A banner appears above the content when somebody else is in the element.
- Saving pauses to ask when somebody else is actively editing, or when the element changed while
  you had it open.
- Element index chips get a dot when somebody is inside that element.
- **Utilities → Stop Sign** appears.

Craft's own avatar stack in the editor header carries on exactly as before. Stop Sign does not
replace it, hide it, or interfere with it — it keeps its own presence records for reasons covered
in **How it works**.

## What it adds to your database

Four small tables:

| Table | Holds | Pruned |
|---|---|---|
| `stopsign_presence` | One row per open tab | Automatically, on garbage collection |
| `stopsign_locks` | One row per locked element | On expiry |
| `stopsign_saves` | One row per element — who last saved it | After 180 days of no saves |
| `stopsign_collisions` | The history | By the retention setting |

None of them hold content. `stopsign_collisions` is user ids, element ids and an outcome.

## Removing it

```sh
php craft plugin/uninstall stopsign
```

All four tables are dropped. Nothing is left behind in your content, and no element is altered by
having been watched — presence and locks live entirely in Stop Sign's own tables.

If locks are enabled and something has gone wrong, release them **before** uninstalling:

```sh
php craft stopsign/unlock --all
```
