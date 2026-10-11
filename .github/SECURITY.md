# Security policy

## Supported versions

Reqtide has no release yet. Until the first release, fixes are made on `main`. After that, only the newest `0.x` minor release line of the three packages (`enaxon/reqtide`, `enaxon/reqtide-redis`, `enaxon/reqtide-guzzle`) will receive security fixes. The three packages share one version number.

| Version | Supported |
|---------|-----------|
| `main` (no release yet) | Yes |
| latest `0.x` minor (after the first release) | Yes |
| older `0.x` minors | No |

After 1.0 this table will list the latest minor of each supported major.

## Reporting a vulnerability

Report privately through GitHub's private vulnerability reporting: open <https://github.com/ehsanenaloo/reqtide/security/advisories/new> (Security tab, "Report a vulnerability").

If you cannot use GitHub advisories, write to <report@enaloo.com>. Please do not send exploit details in a public place.

Do not open a public issue, pull request or discussion for a suspected vulnerability.

Please include:

- The package and the version or commit.
- A minimal reproduction: a short script, and the Redis or HTTP setup if one is needed.
- The impact you expect.
- Whether the problem is already public.

## What to expect

Reqtide has one maintainer. These are goals, not promises.

| Step | Goal |
|------|------|
| Acknowledgement | within 7 days |
| Assessment and severity | within 14 days of the acknowledgement |
| Fix or mitigation | as soon as practical, in order of severity |

A fix ships as a patch release with a published GitHub advisory that credits you, unless you prefer to stay anonymous. Please keep the details private until the advisory is published. The date will be agreed with you.

## Safe harbour

Good-faith research is welcome. If you test only against your own installation, avoid privacy violations and service disruption, report promptly and give reasonable time to fix before you disclose, the maintainer will not pursue or support legal action against you. This is a statement of intent by the maintainer of an open-source library, not a legal contract. Reqtide runs no hosted service.

## Scope

In scope:

- Code, tooling and workflows in this repository, and the release archives built from them.
- Reqtide code that mishandles input in a way that lets a caller exhaust memory, corrupt shared state or read another caller's state.
- Secrets or credentials appearing in exceptions, events or logs that Reqtide produces.
- A weakened or skipped TLS check in the Redis package.

Out of scope:

- Vulnerabilities in PHP, Predis, Guzzle, Redis or other dependencies. Report them upstream.
- Behavior the documentation lists as a non-guarantee, such as exactly-once execution, interrupting a running callback or strict guarantees during Redis failover.
- Reports from automated scanners without a working proof of concept.

## Known limitations

- Reqtide limits how work is attempted. It does not make an unsafe operation safe. Retrying a request that changes data is the caller's decision.
- State is kept in process memory unless you use the Redis package. In-memory state is not shared between processes or hosts.
- Redis state is only as available and consistent as the Redis setup behind it.
