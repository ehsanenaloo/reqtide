# Releasing Reqtide

Maintainer checklist. Nothing has been released yet. Do not tag, push to the public repository or submit to Packagist before the release is approved.

## Versions

- The first tag is `v0.1.0`. Before 1.0, a breaking change bumps the minor version.
- The three packages share one version: `enaxon/reqtide`, `enaxon/reqtide-redis` and `enaxon/reqtide-guzzle`. The two adapters require the core as `self.version`.
- No `composer.json` has a `version` key. Packagist reads the version from the tag.
- Pre-releases use a SemVer suffix, for example `v0.2.0-rc.1`.

## 1. Prepare

1. In `CHANGELOG.md`, rename **Unreleased** to `## [X.Y.Z] - YYYY-MM-DD`, add a fresh empty `## [Unreleased]` above it, and update the compare links at the bottom. The release notes are taken from that section, so make sure it is complete.
2. List every breaking change in `UPGRADE.md` with the steps to migrate.
3. Rebuild the generated documentation: `composer docs`.
4. Run the full gate in Docker on the three PHP versions:

   ```bash
   docker compose -f tools/docker/compose.yaml run --rm php83 composer check
   docker compose -f tools/docker/compose.yaml run --rm php84 composer check
   docker compose -f tools/docker/compose.yaml run --rm php85 composer check
   docker compose -f tools/docker/compose.yaml run --rm php83 composer docs:check
   ```

5. Run the Redis lanes from the host: `bash tools/check-redis.sh`, `bash tools/check-redis-clock.sh` and `bash tools/check-redis-tls.sh`.
6. Commit the changelog and wait until CI is green on that exact commit.

## 2. Package repositories

The three packages are published from their own repositories. Before you tag, check each package tree:

- `composer.json` has no `repositories` key and no `version` key.
- `LICENSE` and `README.md` are present, and no `tests/` or `tools/` folder ships in the package.
- Both adapters require the core as `self.version`.

Install the three trees into an empty project, once with the latest and once with the lowest allowed dependencies (`composer update --prefer-lowest`), and run one example.

## 3. Tag and push

After the release is approved, push each package tree and its tag to its own repository. The three tags carry the same version. Push the core first, then the adapters. Sign the tags if you have a key.

The tag in the core repository starts the **Release (draft)** workflow. It checks the tag, builds the archives and creates a draft release for you to review. It never publishes the release and never calls Packagist.

If a job fails before you publish, fix the cause, delete the tag locally and on the remote, and tag again. Never move a tag that has a published release. Ship a patch release instead.

## 4. Packagist

Packagist is the last step. Submit a package only after its tag exists and the release is approved.

1. Submit `enaxon/reqtide` first, then `enaxon/reqtide-redis` and `enaxon/reqtide-guzzle`.
2. Add the GitHub webhook for automatic updates only after the first install works.
3. Check the install in an empty directory:

   ```bash
   mkdir reqtide-check && cd reqtide-check
   composer init --no-interaction --name=example/check
   composer require enaxon/reqtide:X.Y.Z enaxon/reqtide-redis:X.Y.Z enaxon/reqtide-guzzle:X.Y.Z
   ```

   Then run one example from the README.

Packagist cannot truly unpublish a version. Check the three packages before you submit.

## Security releases

Prepare the fix in a private fork of the advisory (Security > Advisories), release it as a patch, and publish the advisory right after the release. See [SECURITY.md](SECURITY.md).
