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

## Supported runtime

- SAT requires PHP 8.1 or newer.
- SAT requires WordPress 7.1.3 or newer.
- The WordPress 7.1.3 minimum is a deliberate security baseline and must not be
  lowered to an earlier WordPress 7.x release without explicit approval.
- PHP 8.1 language and standard-library features may be used.
- Do not add compatibility work for PHP 8.0 or WordPress versions earlier than
  7.1.3 unless the supported-runtime policy is explicitly changed.
- Tests on newer PHP or WordPress versions supplement, but do not replace,
  validation against the declared minimum versions.

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

## Versioning and releases

- SAT uses semantic versions in `MAJOR.MINOR.PATCH` form.
- Do not bump the plugin version during ordinary implementation, review, or
  intermediate commits.
- When a requested task prepares a deployable plugin package or release,
  determine whether the completed changes require a patch, minor, or major
  version bump and report the recommendation before changing versions.
- A backward-compatible bug fix uses a patch bump.
- A backward-compatible feature or substantial new workflow uses a minor bump.
- An incompatible change or upgrade requiring deliberate user intervention
  uses a major bump.
- The `Version:` header in
  `wordpress/scout-accounting-toolkit/scout-accounting-toolkit.php` and
  `SAT_VERSION` must always be identical.
- Any test that checks plugin versions must require those two values to match;
  do not loosen the test to hide a mismatch.
- Perform the version bump only when explicitly asked to prepare the release or
  deployment package.
- A release-version change, changelog or handover update, plugin ZIP, Git tag,
  and deployment should all refer to the same commit.