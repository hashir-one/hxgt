# Official Android Key Attestation verifier

`upstream/src` and `upstream/LICENSE` are unmodified sources from
https://github.com/android/keyattestation at commit
`621149c4ebaeeea844d6f43098687c73c4d370a3` (Apache-2.0).
The upstream project is actively maintained but pre-1.0. Review updates deliberately.
The Gradle wrapper is from the same revision; its distribution checksum is pinned.

Our build excludes upstream CLI, printer and testing utilities from the runtime.
Test utilities are compiled only for synthetic fixture generation. The wrapper
uses fresh Google trust data supplied by Laravel, never bundled test roots or
the upstream snapshot of Google's roots. Bouncy Castle is upgraded from upstream's
1.78.1 to 1.86; the other dependency versions follow upstream, with Gradle locks.

Build with Java 21: `./gradlew --no-daemon installDist`.
Generate synthetic fixtures: `./gradlew --no-daemon fixtures`.
Do not use synthetic roots on a deployed server.
