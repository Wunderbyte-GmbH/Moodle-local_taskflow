[Back to chapter overview](README.md)

# Placeholders

Placeholders are replaced with assignment data when a message is sent. They can be used in the **Message Subject** and
the **Message Body** of every template type. The editor lists all available placeholders below the body field
("Here you will find a list of all possible placeholders that can be used in messages:").

## Syntax

* Write the placeholder in angle brackets: `<firstname>`.
* Only lower-case letters, digits and underscores are allowed between the brackets.
* Unknown placeholders (e.g. `<company>`) are left in the text unchanged.
* Curly-brace variants (`{firstname}`) are **not** supported.
* Placeholders are only rendered when the message belongs to an existing assignment (which is always the case for
  messages sent by Taskflow).
* `<status>` accepts an optional language suffix: `<status de>`, `<status en>`, `<status fr>`. The suffix is ignored by
  all other placeholders.

## Reference

| Placeholder | Replaced with | Example output |
|---|---|---|
| `<firstname>` | First name of the **assignee** (the user of the assignment). | `Betty` |
| `<lastname>` | Last name of the assignee. | `Best` |
| `<supervisor_firstname>` | First name of the assignee's supervisor (the user whose id is stored in the assignee's profile field with the function *Supervisor*). Empty if no supervisor is configured. | `Berta` |
| `<supervisor_lastname>` | Last name of the supervisor; empty if none. | `Boss` |
| `<due_date>` | The assignment's current due date, formatted `dd.mm.yyyy` (server time zone). | `31.10.2026` |
| `<due_date_with_extension>` | Due date plus the rule's **Extension period**, formatted `dd.mm.yyyy` — the date the assignee would get after a prolongation. Equals `<due_date>` when the rule has no extension period. | `30.11.2026` |
| `<status>` / `<status de>` / `<status en>` / `<status fr>` | The assignment's current status name, localised in the recipient's current language or in the language given by the suffix (`Assigned`, `Overdue`, `Prolonged`, `Completed`, `At least one target completed`, …). | `Overdue` |
| `<targets>` | All targets of the rule action that contains this message, as a comma-separated list of names with links (course link, booking option link, competency name). Includes completed targets. Unknown target types render as empty. | `Fire safety basics, Data protection 2026` |
| `<opentargets>` | Only the targets the assignee has **not yet completed**, as names with links. If everything is completed: the text *No open targets*. | `Data protection 2026` |
| `<chat>` | The text of the **newest** internal chat message of the assignment. Meant for *Chat*-type templates; empty when there is no chat message yet. | `Could you please send me the certificate?` |

### Where the data comes from

* Names come from the Moodle user records at sending time.
* `<due_date>` and `<status>` are read from the assignment when the adhoc task runs, so a message queued for
  "7 days before due date" shows the due date valid at that moment, not the one at queuing time.
* `<targets>` is built from the **rule definition** (all targets of the action the message is attached to);
  `<opentargets>` is built from the **assignment record** (per-target completion flags). For sequential rules
  (*complete before next*) `<opentargets>` therefore lists every not-yet-completed target, not only the currently
  unlocked one.
* The status name uses the plugin language strings; adapters can override them (e.g. tuines wording).

## Examples

Reminder before the due date (Standard, *Due date*, *before*, 7 days, recipient *Assignee*):

```
Subject: Reminder: <opentargets> due on <due_date>
Body:    Hello <firstname> <lastname>,
         please complete <opentargets> until <due_date>.
         Current status: <status>. Your supervisor <supervisor_firstname> <supervisor_lastname>
         can grant an extension until <due_date_with_extension>.
```

Supervisor warning after the due date (Standard, *Due date*, *after*, 10 minutes, recipient *Supervisor Overview*):

```
Subject: <firstname> <lastname>: <targets> is <status en> / <status de>
Body:    <firstname> <lastname> has not completed <opentargets>. Due date was <due_date>.
```

Chat notification (Chat type):

```
Subject: New message on your assignment <targets>
Body:    <supervisor_firstname> <supervisor_lastname> or <firstname> <lastname> wrote:
         <chat>
```

## Related

* [Message templates](01-message-templates.md) — where placeholders are entered
* [Assignments — Status lifecycle](../assignments/01-status-lifecycle.md) — the status names `<status>` can produce
* [Rules — Targets](../rules/03-targets.md) — what `<targets>` and `<opentargets>` list
* [Assignments — Due dates, prolongation, overdue](../assignments/05-due-dates-prolongation-overdue.md) — extension period used by `<due_date_with_extension>`
