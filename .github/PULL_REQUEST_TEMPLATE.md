## What and why

<!-- One focused change. Link the issue if there is one, for example "Fixes #123". Larger changes should be discussed in an issue first. -->

## Type of change

- [ ] Bug fix
- [ ] New feature or option
- [ ] Documentation
- [ ] Refactoring, tests or tooling

## Checklist

- [ ] `composer check` passes (manifest validation, coding standard, static analysis, tests)
- [ ] Redis changes: `composer check:redis` passes (run from the host, needs Docker)
- [ ] Tests added or updated. A bug fix has a test that failed before the fix
- [ ] Docs and examples updated, and `php tools/check-docs.php` passes
- [ ] `CHANGELOG.md` has a line under **Unreleased** for changes users can see
- [ ] No new required dependency in the core, and no breaking change (or it is called out above)
- [ ] I made no claim about performance, supported platforms or availability that I did not measure

## Checks I could not run

<!-- List anything you could not run, and why. -->
