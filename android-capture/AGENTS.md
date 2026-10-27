# Android agent handoff

Before changing pairing or recovery behavior, read
[`../docs/ANDROID-HANDOFF.md`](../docs/ANDROID-HANDOFF.md), especially
"Server review of Android commit fe562a6 — instructions for the app agent".
That section records the next Android fixes, current server contract, and missing
server recovery API. Check the current source before treating older handoff
statements as implemented behavior.

Preserve existing phone identity, Android Keystore aliases, encrypted attempt
history, and unresolved outbox records. Uncertain submissions stay blocked until
authoritative server resolution; token expiry and the owner-panel status route
cannot establish the pending key's outcome.

Keep staging exceptions scoped to staging. Every newly generated pending key
needs its own server SPKI pin. Do not invent a recovery endpoint or bypass a
server policy to complete a local flow.

When handing work back, update `../docs/ANDROID-HANDOFF.md` with code changes,
verification actually performed, and remaining blockers. Distinguish builds,
synthetic tests, and physical-phone results. Do not commit secrets or phone data.
