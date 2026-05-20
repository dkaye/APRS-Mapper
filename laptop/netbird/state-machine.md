# NetBird Device State Machine

Four internal states. Two of them display as **Disabled**.

```mermaid
stateDiagram-v2
    direction LR

    [*] --> Off

    state "OFF · shows: Disabled" as Off
    state "PENDING · shows: Disabled" as Pending
    state "OFFLINE · shows: Offline" as Offline
    state "ONLINE · shows: Online" as Online

    Off     --> Pending : Toggle ON\nenabled_at = now\nlast_request = null\npoll_force → immediate poll

    Pending --> Off     : Toggle OFF
    Pending --> Offline : poll sent (last_request ≥ enabled_at)\nno response
    Pending --> Online  : poll sent (last_request ≥ enabled_at)\ndevice responds → online = true

    Offline --> Off     : Toggle OFF
    Offline --> Online  : device responds → online = true

    Online  --> Off     : Toggle OFF
    Online  --> Offline : 180 s with no response → online = false
```

## State conditions

| State | `enabled` | `online` | `last_request` vs `enabled_at` | Displayed |
|-------|-----------|----------|-------------------------------|-----------|
| Off | `false` | — | — | **Disabled** |
| Pending | `true` | `false` | `last_request` null or `< enabled_at` | **Disabled** |
| Offline | `true` | `false` | `last_request ≥ enabled_at` | **Offline** |
| Online | `true` | `true` | — | **Online** |

The **Pending** state is transient. With `poll_force`, the daemon fires within 100 ms
and devices respond within 1–5 s, so **Pending → Offline/Online** happens in seconds.

## Polling triggers

| Trigger | Source | Throttled? |
|---------|--------|------------|
| `poll_force` file | Any admin change (toggle, add, edit, delete) | No — bypasses `repeat_seconds` |
| `poll_now` file | `api.php` on page load when data is stale | Yes — once per `repeat_seconds` |
| Periodic | Daemon loop | Every `repeat_seconds` |

The daemon pauses all polling when no browser has fetched `api.php` within 45 seconds.
