# Release Notes for Stop Sign

## Unreleased

### Added

- “Being edited by” and “Locked by” element index columns, so you can see who is in what — and
  who holds which lock — across a whole section at once. One query per page each, not one per row.
- An “Is locked” condition rule on every element condition, for building a “currently locked”
  custom source on any element index. It matches the lock in the site being viewed, includes the
  drafts of locked entries, and never matches an expired lock.

## 5.0.0 - 2026-10-03

Initial release.

### Added

- A collision banner in every element editor, full-page and slideout, that names who else is in
  the element and whether they have started typing.
- A confirmation step between the save button and the save, when somebody else is in the element
  or when it changed underneath you.
- Staleness detection that names the person who saved — and that works in slideouts, which Craft's
  own reload prompt does not.
- Warnings about your own account in a second tab, which Craft cannot report at all.
- Optional soft locks, per section or everywhere, enforced through Craft's own permission check so
  the second editor opens read-only. Always expiring, always recordable, always takeable.
- Occupancy badges on element index chips, so a collision is visible before the entry is opened.
- A collision history with outcomes, so it is possible to tell whether the warning is still being
  read or has become wallpaper.
- Utilities → Stop Sign: who is in what right now, which locks are held, and the history.
- `stopsign/status`, `stopsign/unlock`, `stopsign/prune` and `stopsign/history` console commands.
