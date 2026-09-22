# Bulk send checker

The bulk send checker is a safety net in front of the message sending of `local_taskflow`.
A rule that suddenly matches hundreds of users would otherwise mail all of them at once.
Instead, sending of a checked message is **postponed by a delay**, every queued send is
**recorded**, and when a postponed task finally runs it **counts the sends of the same
message and rule around its own sending time**. If that count is over the limit the send is
**parked** instead of carried out, and a human decides whether the burst goes out or not.

## Files

| File | Role |
| --- | --- |
| `bulk_check.php` | The checker itself: record, verdict, release, dismiss, cleanup, notification |
| `bulk_check_config.php` | Global settings plus the per message row, cached as one blob |
| `../types/standard.php` | Applies the delay when scheduling and records the queued send |
| `../../../task/send_taskflow_message.php` | Asks for the verdict before it sends, marks the row sent afterwards |
| `../../../task/release_bulk_check.php` | Turns released rows back into send tasks, in batches |
| `../../../task/bulk_check_reminder.php` | Daily digest of everything still parked |
| `../../../task/bulk_check_cleanup.php` | Daily tidy-up of orphaned pending rows and stale releases |
| `../../../table/bulk_check_table.php` | The wunderbyte table and its four actions |
| `../../../output/bulkcheck.php` + `/bulkcheck.php` | The page an admin takes the decision on |

## Components

```mermaid
%%{init: {"theme": "dark"}}%%
flowchart LR
    subgraph config["Configuration"]
        SET["Site settings<br/>bulkcheckenabled<br/>bulkcheckdelay<br/>bulkcheckperiod<br/>bulkchecknotifyusers"]
        MSGFORM["Message edit form<br/>bulkcheckactive + bulkchecklimit"]
        CFGTBL[("local_taskflow_bulk_config")]
        CACHE[["cache: bulkcheckconfig"]]
    end

    subgraph runtime["Sending path"]
        STD["standard::schedule_message"]
        ADHOC[("task_adhoc")]
        SEND["send_taskflow_message::execute"]
        BC["bulk_check::check"]
        BCTBL[("local_taskflow_bulk_check")]
    end

    subgraph human["Decision"]
        PAGE["bulkcheck.php<br/>bulk_check_table"]
        REL["release_bulk_check task"]
        HIST[("local_taskflow_history")]
    end

    MSGFORM --> CFGTBL --> CACHE
    SET --> CACHE
    CACHE --> BC
    CACHE --> STD

    STD -->|"queue with delay"| ADHOC
    STD -->|"record_scheduled"| BCTBL
    ADHOC --> SEND --> BC
    BC <--> BCTBL
    BC -->|"blocked"| NOTIFY["Notification to<br/>notify users"]
    BC -->|"blocked"| HIST

    BCTBL --> PAGE
    PAGE -->|"release"| REL --> ADHOC
    PAGE -->|"dismiss / release"| HIST
```

## The main flow

```mermaid
%%{init: {"theme": "dark"}}%%
flowchart TD
    A["Rule schedules a message<br/>standard::schedule_message"] --> B{"bulk_check::applies<br/>site switch ON and<br/>per message row enabled?"}
    B -- no --> C["Queue send task at the normal time"] --> Z1["Message goes out as before"]
    B -- yes --> D["runtime = sendingtime + bulkcheckdelay"]
    D --> E["queue_adhoc_task"]
    E --> F["record_scheduled<br/>drop earlier pending row of<br/>same user, rule, message<br/>then INSERT status = PENDING"]
    F --> G(("...delay passes,<br/>burst piles up in the queue..."))
    G --> H["Cron runs send_taskflow_message"]
    H --> I{"assignment exists,<br/>not already sent,<br/>still valid?"}
    I -- no --> Z2["Nothing happens"]
    I -- yes --> J{"message class == standard?"}
    J -- no --> Z3["Send without a check"]
    J -- yes --> K["bulk_check::check"]

    K --> K1{"applies still true?"}
    K1 -- "no, switched off meanwhile" --> S["SEND"]
    K1 -- yes --> K2{"row for this taskid?"}
    K2 -- "no row" --> S
    K2 -- yes --> K3{"row status"}
    K3 -- RELEASED --> S
    K3 -- BLOCKED --> BL["BLOCKED<br/>verdict already taken"]
    K3 -- "PENDING" --> K4["count_window<br/>rows of same message+rule<br/>inside the centred period,<br/>excluding RELEASED/RELEASING"]
    K4 --> K5{"count <= limit?"}
    K5 -- yes --> S
    K5 -- no --> K6["UPDATE status = BLOCKED"]
    K6 --> K7["history: limit_reached"]
    K7 --> K8["notify_burst<br/>once per burst, under a lock"]
    K8 --> BL

    S --> T["send_and_save_message<br/>writes local_taskflow_sent_messages"] --> U["mark_sent<br/>DELETE the row — from here on<br/>the send counts via sent_messages"]
    BL --> V["Task returns quietly,<br/>no retry, row waits on the page"]
```

Two details of `check()` are worth keeping in mind:

* The **verdict is taken per task**, but it is **stable across a burst**, because the window
  is centred on the sending time rather than looking only backwards. Every row of one burst
  sees every other row, so cron reaches the same answer no matter in which order it works
  through the tasks.
* A **blocked row keeps counting**. A released or releasing one does not — otherwise the
  sends just let through would be measured against their own limit and blocked again, and
  no mail would ever leave.
* A **sent row does not exist**. The moment the mail is out its row is deleted, and the send
  counts through `local_taskflow_sent_messages` instead, which the sending writes anyway.
  That is what catches a slow flood without keeping a second copy of every send.

## The counting window

```mermaid
%%{init: {"theme": "dark"}}%%
flowchart LR
    subgraph win["window = one period, centred on this row's scheduledtime"]
        direction LR
        WS["scheduledtime<br/>- period/2"] --- ROW["the row<br/>being judged"] --- WE["scheduledtime<br/>+ period/2"]
    end
    WIN2["counted: PENDING and BLOCKED rows here, by scheduledtime,<br/>plus local_taskflow_sent_messages, by timesent,<br/>of the same messageid + ruleid<br/><br/>not counted: RELEASED, RELEASING<br/>gone entirely: sent, dismissed and dropped rows"]
    win --> WIN2
```

## Status of a row

`local_taskflow_bulk_check.status`. The values `1`, `4` and `5` once meant *sent*,
*superseded* and *dismissed*; none is a state any more. A send that will never happen is
deleted rather than parked in a final status, and a send that did happen is deleted because
`local_taskflow_sent_messages` already records it. The numbers stay unused.

```mermaid
%%{init: {"theme": "dark"}}%%
stateDiagram-v2
    [*] --> PENDING : record_scheduled (0)

    PENDING --> [*] : under the limit, mail sent — row deleted, counts via sent_messages
    PENDING --> BLOCKED : over the limit (2)
    PENDING --> [*] : drop_pending — rescheduled or sent messages wiped, task deleted too
    PENDING --> [*] : cleanup — task_adhoc row gone and past the horizon

    BLOCKED --> RELEASING : release by hand (6) one UPDATE for the whole burst
    BLOCKED --> [*] : dismiss by hand — row deleted, history entry is what remains

    RELEASING --> RELEASED : release_bulk_check queued a fresh send task (3)
    RELEASING --> RELEASING : cleanup rescues a stale row by requeueing the release task

    RELEASED --> [*] : the requeued task sends — row deleted, counts via sent_messages

    note right of BLOCKED
        Counts towards the window.
        This is what keeps the verdict
        stable while cron works through
        the rest of the burst.
    end note
    note right of RELEASING
        Does not count, or the released
        burst would block itself again.
    end note
```

## Blocking a burst and telling somebody

```mermaid
%%{init: {"theme": "dark"}}%%
sequenceDiagram
    autonumber
    participant Cron as Cron worker A
    participant Cron2 as Cron worker B
    participant BC as bulk_check
    participant DB as local_taskflow_bulk_check
    participant Lock as lock factory
    participant Hist as history
    participant User as notify users

    Cron->>BC: check(message, ruleid, userid, taskid)
    BC->>DB: get_row_by_task(taskid)
    DB-->>BC: row (PENDING)
    BC->>DB: count_window(row, period)
    DB-->>BC: 320
    Note over BC: 320 > limit 50
    BC->>DB: UPDATE status = BLOCKED
    BC->>Hist: log(limit_reached, count)
    BC->>Lock: get_lock("m{messageid}r{ruleid}")
    Lock-->>BC: acquired
    BC->>DB: burst_was_notified? (notified = 1 in window)
    DB-->>BC: no
    BC->>DB: SET notified = 1 on this row
    loop every configured user, admins as fallback
        BC->>User: bulkchecknotification<br/>subject, body, link to bulkcheck.php?messageid=…
    end
    BC->>Lock: release
    BC-->>Cron: BLOCKED

    opt the rest of the burst
        Cron2->>BC: check(...) for another user
        BC->>DB: count_window → still over the limit
        BC->>DB: UPDATE status = BLOCKED
        BC->>Lock: get_lock(same key)
        Lock-->>BC: refused / already notified
        Note over BC: blocks silently,<br/>no second mail
        BC-->>Cron2: BLOCKED
    end
```

The daily `bulk_check_reminder` picks up where that single alert stops: it sends one digest
per notify user listing every parked burst and how long it has waited, and goes quiet by
itself once nothing is parked.

## Releasing a burst

The page never queues the send tasks itself. A burst can hold thousands of rows, and a
timeout half way through would leave it split between two states with no way to tell which
rows had been dealt with. So the request only flips the rows in **one statement** and hands
the queueing to a background task.

```mermaid
%%{init: {"theme": "dark"}}%%
sequenceDiagram
    autonumber
    participant Admin
    participant Table as bulk_check_table
    participant BC as bulk_check
    participant DB as local_taskflow_bulk_check
    participant Hist as history
    participant Task as release_bulk_check
    participant Adhoc as task_adhoc

    Admin->>Table: action_releaseselected / releaseall
    Table->>Table: require_capability local/taskflow:editmessages
    Table->>BC: release_rows(checkedids) or release_message(messageid)
    BC->>BC: clean_ids → intval, unique, chunks of 500
    BC->>DB: select_blocked(where AND status = BLOCKED)
    DB-->>BC: rows
    BC->>Hist: log_decision(bulk_released)<br/>one insert_records, one cache purge
    BC->>DB: UPDATE … SET status = RELEASING WHERE … AND status = BLOCKED
    Note over BC,DB: the status is re-checked in the statement,<br/>so a row another admin already released<br/>is not taken away from them
    BC->>Adhoc: queue release_bulk_check per messageid
    BC-->>Table: count
    Table->>Table: purge cache changesinbulkcheckmails
    Table-->>Admin: "n queued", reload table

    Note over Task: later, in cron
    Task->>Task: get_lock("release{messageid}")
    alt lock refused
        Task->>Adhoc: requeue in 60s
        Note over Task: two workers on the same message would read<br/>the same row before either writes its taskid back,<br/>and that send would go out twice
    else lock held
        loop up to 10 batches of 500
            Task->>BC: queue_released_batch(messageid, 500)
            BC->>DB: read rows with status = RELEASING
            loop each row
                BC->>Adhoc: queue send_taskflow_message
                BC->>DB: UPDATE status = RELEASED, taskid = new task
            end
            BC-->>Task: queued
        end
        opt a full last batch, so more remains
            Task->>Adhoc: requeue immediately
        end
    end
```

When the requeued `send_taskflow_message` finally runs, `check()` sees `STATUS_RELEASED` and
returns `SEND` without counting anything — the decision has already been taken by a person.

## Dismissing a burst

```mermaid
%%{init: {"theme": "dark"}}%%
flowchart TD
    A["dismissselected / dismissall"] --> B["require_capability<br/>local/taskflow:editmessages"]
    B --> C["clean_ids, chunks of 500"]
    C --> D["select_blocked"]
    D --> E["log_decision: bulk_dismissed"]
    E --> F["delete_blocked<br/>DELETE … WHERE id IN … AND status = BLOCKED"]
    F --> G["Rows are gone"]
    G --> H["The mail is never sent.<br/>The history entry is the only record left,<br/>and the one that answers why<br/>somebody never got it."]
```

Dismissed rows are deleted rather than kept in a final status: a send that will never happen
cannot influence a verdict and would only make the table grow.

## The admin page

`/local/taskflow/bulkcheck.php`, reachable from the site settings and straight from the
alert with `?messageid=…`.

```mermaid
%%{init: {"theme": "dark"}}%%
flowchart TD
    P["bulkcheck.php"] --> CAP["require_capability local/taskflow:editmessages"]
    CAP --> SCOPE{"messageid given?"}
    SCOPE -- yes --> INFO["Info box naming the message<br/>plus a link back to all messages"]
    SCOPE -- no --> ALL["Every parked mail of the site"]
    INFO --> T
    ALL --> T["bulk_check_table<br/>one row per parked mail"]

    T --> COLS["Columns: message, rule, recipient, email,<br/>due, waiting<br/>filters on message and rule,<br/>fulltext search, sorting, paging"]
    T --> BTN1["Send selected — ticked rows"]
    T --> BTN2["Dismiss selected — ticked rows"]
    T --> BTN3["Send all n — the page's scope"]
    T --> BTN4["Dismiss all n — the page's scope"]

    BTN3 --> WARN["The scope is the one the page was built with,<br/>not what the filter happens to show:<br/>the action web service is told neither<br/>the filter nor the search"]
    BTN4 --> WARN
```

Deliberately, the page does **not** check `bulkcheckenabled`: switching the feature off must
never hide mails that are already parked, or they would be abandoned with no way to reach
them. Message and rule are joined loosely everywhere and fall back to a placeholder, so rows
whose message or rule was deleted stay visible and disposable.

## Cleanup

Daily, and it runs whether or not the check is switched on — the rows of a check that was
switched off are exactly the ones nobody will look at again. Rows leave the table on their
own when they stop mattering (sent, dismissed, rescheduled); the cleanup only looks for the
two cases that cannot announce themselves. Deleting is batched, because a burst can leave
thousands of orphans behind and one unbounded statement would lock the table for the
duration.

```mermaid
%%{init: {"theme": "dark"}}%%
flowchart TD
    START["bulk_check_cleanup, daily"] --> H["horizon = now - bulkcheckperiod"]

    H --> B1["PENDING rows whose task_adhoc row is gone<br/>and scheduledtime < horizon"]
    B1 --> B2["up to 20 batches of 1000, DELETE"]
    B2 --> B3["They will never be sent.<br/>The horizon keeps a freshly written row out<br/>and rules out a send that is merely late"]

    H --> C1["RELEASING rows untouched for 15 minutes"]
    C1 --> C2{"a release_bulk_check task<br/>queued for that message?"}
    C2 -- yes --> C3["leave it alone"]
    C2 -- no --> C4["queue one — mail that was meant<br/>to go out gets its task back"]

    B3 --> END["mtrace: n orphaned, n rescued"]
    C3 --> END
    C4 --> END
```

## Configuration

```mermaid
%%{init: {"theme": "dark"}}%%
flowchart TD
    subgraph site["Site settings — local_taskflow"]
        S1["bulkcheckenabled — off by default.<br/>Off means nothing is postponed, recorded or blocked"]
        S2["bulkcheckdelay — default 15 min.<br/>How long sending is postponed so the burst can pile up"]
        S3["bulkcheckperiod — default 1 hour.<br/>Width of the counting window"]
        S4["bulkchecknotifyusers — users with editmessages.<br/>Empty falls back to all site admins"]
    end

    subgraph msg["Per message — the message edit form"]
        M1["bulkcheckactive — only shown while the site switch is on"]
        M2["bulkchecklimit — default 50, hidden unless active"]
        M3["Both hidden for message classes other than<br/>standard and onevent"]
    end

    S1 --> R["get_settings(message)"]
    M1 --> CFG[("local_taskflow_bulk_config<br/>one row per message")]
    M2 --> CFG
    CFG --> CACHE[["cache bulkcheckconfig<br/>the whole table under one key"]]
    CACHE --> R
    S2 --> R
    S3 --> R
    R --> OUT["null → not bulk checked<br/>array → limit, period, delay"]

    DEL["Message deleted"] --> DEL2["dismiss_message + delete_settings"]
```

Only the **limit** is per message. The window and the delay are global, so that sending stays
regular and nobody has to look up a different value for each message. `applies()` runs once
per assignee while a rule schedules its messages, which is why the whole config table is read
once into a cache instead of being queried per message. Every write goes through
`set_settings()`, which invalidates that cache.

## Tables

```mermaid
%%{init: {"theme": "dark"}}%%
erDiagram
    local_taskflow_messages ||--o| local_taskflow_bulk_config : "configured by"
    local_taskflow_messages ||--o{ local_taskflow_bulk_check : "parks"
    local_taskflow_messages ||--o{ local_taskflow_sent_messages : "counted from"
    local_taskflow_rules ||--o{ local_taskflow_bulk_check : "scheduled by"
    user ||--o{ local_taskflow_bulk_check : "addressed to"
    task_adhoc ||--o| local_taskflow_bulk_check : "taskid"
    local_taskflow_assignment ||--o{ local_taskflow_history : "decisions logged on"

    local_taskflow_bulk_check {
        int id PK
        int messageid
        int ruleid
        int userid
        int taskid "the send task this row belongs to"
        int status "0 pending, 2 blocked, 3 released, 6 releasing"
        int notified "set on the row that raised the alert"
        int scheduledtime "anchor of the counting window"
        int timecreated
        int timemodified
    }

    local_taskflow_bulk_config {
        int id PK
        int messageid UK
        int enabled
        int limitcount "default 50"
        int usermodified
        int timecreated
        int timemodified
    }

    local_taskflow_sent_messages {
        int id PK
        int messageid
        int ruleid
        int userid
        int timesent "the window count reads this"
    }
```

Indexes on the check table: `messageid, ruleid, scheduledtime` for the window count,
`taskid` for the per task lookup, `status, messageid` for the page, and
`status, scheduledtime` for the cleanup. The sent messages carry
`messageid, ruleid, timesent` for the same window count.

## History entries

| Type | Written when |
| --- | --- |
| `limit_reached` | `check()` blocks a send, with the count that triggered it |
| `bulk_released` | An admin lets parked sends go out |
| `bulk_dismissed` | An admin gives up on parked sends — the only remaining record |

All three hang off the assignment, so a row whose assignment is already gone is skipped.
Decisions are written with one `insert_records` and a single cache purge, because
`history::log()` purges on every call and a burst can hold thousands of rows.
