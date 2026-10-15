# Scout Accounting Toolkit - Copilot Instructions

## Project context

Scout Accounting Toolkit (SAT) is a PHP/WordPress accounting toolkit for Scout
groups. It imports and reviews financial transactions and prepares reviewed
transactions for export to Wave Accounting.

Before substantial work, read the relevant project documentation. In
particular:

- `README.md` for repository overview
- `docs/HANDOVER.md` for current project state and recent decisions
- `docs/WAVE_EXPORT.md` before changing Wave export behaviour
- `docs/INSTALL.md` and `docs/UPGRADING.md` when changing installation,
  database, or deployment behaviour

Prefer the current implementation and current documentation over assumptions
about how the system ought to work.

## Development workflow

- Work from the existing code and architecture rather than inventing parallel
  implementations.
- Keep changes tightly scoped to the requested task.
- Before changing existing behaviour, trace the relevant call sites and related
  code.
- Do not make unrelated cleanup or refactoring changes while implementing a
  requested fix or feature.
- Do not commit changes unless explicitly asked to commit them.
- Before proposing a commit, show or summarize the files changed and the
  validation performed.

## Validation

- Run appropriate available validation after changing code.
- For changed PHP files, run `php -l`.
- Run relevant existing tests when they cover the changed behaviour.
- If a requested validation cannot be run, report that clearly rather than
  treating it as passed.
- Review `git diff --check` before considering a code change complete.

## Safety

- Treat Wave exports and accounting data as high-impact operations.
- Do not perform live Wave API mutations, database writes, deployment actions,
  or destructive operations unless explicitly requested.
- Preserve existing transaction, export, retry, and audit behaviour unless the
  task explicitly requires changing it.