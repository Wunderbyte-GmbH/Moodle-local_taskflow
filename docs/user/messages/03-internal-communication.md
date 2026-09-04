[Back to chapter overview](README.md)

# Internal communication (assignment chat)

The internal communication is a small chat attached to **one assignment**. It lets the assignee and their supervisor
(and administrators on the edit page) exchange short messages about that assignment without leaving Taskflow. Chat
messages are stored per assignment, shown as chat bubbles, previewed in the assignments table, and summarised in a daily
digest to everyone who has unread messages.

## Availability

* Setting **Allow internal communication** (`local_taskflow/allowinternalcommunication`, default on) must be enabled.
* The block of internal-communication settings (and therefore the chat) is only offered when the active adapter ships a
  chat form. Of the bundled adapters only **tuines** does; with the *standard* or *ksw* adapter the settings heading is
  not shown and no chat card is rendered.
* With the chat enabled, the message editor additionally offers the **Message type** *Chat* (see below).

## Where the chat appears

| Page | Who sees it | Details |
|---|---|---|
| Assignment page `/local/taskflow/assignment.php?id=<assignmentid>` | The assignee and the assignee's supervisor | Card **Internal Chat**: chat history (own messages right/blue, others left/grey, sender name and date `dd.mm.yyyy hh:mm`), a text area and the button *Send message*. Shown only when the viewer is the assignee or the supervisor. Empty history shows "No conversation has been found so far.". |
| Edit-assignment page `/local/taskflow/editassignment.php?id=<assignmentid>` (tuines admin variant) | Users with `local/taskflow:viewassignment` | The same **Internal Chat** card next to the comment form (see [Edit assignment](../assignments/03-edit-assignment.md)). |
| Assignments table (dashboards) | Everyone who sees the table | Column **Chat messages**: preview of the newest message (`date - sender: text`, cut to the configured preview length with `…`), an eye icon opening a modal with the whole conversation, `-` when there is none. The column can be sorted by newest message. |

## Settings

| Setting (`local_taskflow/…`) | UI label | Default | Meaning |
|---|---|---|---|
| `allowinternalcommunication` | Allow internal communication | on | Enables the chat card, the *Chat* message type and the digest. Description: "Allow internal communication between supervisors and users regarding assignments." |
| `internalcommunicationpreviewlength` | Internal communication preview length | 300 | Maximum characters of the newest message shown in the **Chat messages** column: *How many characters should be shown in previewd description?* (= off, no truncation) or 100 / 150 / 175 / 200 / 300 / 400 / 500 / 600. |

## What happens when a message is posted

1. The text is stored with the sender and time on the assignment.
2. The sender's **last seen** timestamp for this assignment is updated (their own message never counts as unread for
   them).
3. The assignments-table cache is purged so the preview column shows the new message.
4. The event *new assignment message* is raised. For every **Chat**-type template attached to the assignment's rule an
   adhoc task `send_taskflow_message` is queued (offset from the template's **Send when?**, normally 0) and delivered on
   the next cron run as a **Moodle message** (provider *Taskflow notification*, not flagged as notification, no e-mail,
   no history entry). The `<chat>` placeholder inserts the newest chat text. Receiver: if the sender is the assignee,
   the assignee's supervisor; otherwise the receiver resolves to the **sender** (see note).
5. Independently of any template, the **daily digest** (below) informs everyone who has not read the message yet.

> **Note:** For chat messages written by the supervisor or an administrator, the immediate *Chat*-type message is
> currently addressed to the sender's own account rather than to the assignee. Rely on the daily digest for assignees,
> or use the *Chat* template mainly to alert supervisors about new messages from their staff.

## Read tracking ("last seen")

* Opening `/local/taskflow/assignment.php?id=…` records *assignment seen* for the viewer; posting a message does the
  same for the sender.
* A message is **unread** for a person when it was written by someone else *after* that person's last-seen timestamp
  on the assignment (or when the person has never opened the assignment).

## Daily digest: scheduled task "Notification of internal messages"

| Item | Value |
|---|---|
| Task | `\local_taskflow\task\notification_internal_messages`, scheduled daily at **00:00** (adjustable under *Site administration → Server → Scheduled tasks*). |
| Looks at | Chat messages created since the task's last run (fallback: last 24 h). |
| Recipients | For every assignment with new messages: the **assignee** (if a message by someone else is newer than their last seen), the **supervisor** (same rule, based on the supervisor profile field), and **every site administrator** (once per admin, listing all affected assignments). |
| Providers | Assignee → *Summary of internal Chat Message to assignees* (`assigneenotification`, e-mail forced on, popup on). Supervisor → *Summary of internal Chat Message to supervisors* (`supervisornotification`, popup and e-mail on). Admin → *Summary of internal Chat Message to admin and chiefs* (`adminnotification`, popup on, e-mail permitted). Users can adjust the popup/e-mail channels in their notification preferences where permitted. |
| Subject | *Notification of new chat messages* (tuines: *ines - You have new chat messages*). |
| Body | Bilingual: a German block, a line of `=` signs, then the English block. Each block: greeting *Hello {firstname} {lastname},* — *You have received new messages:* — a list with one line per assignment: rule name (+ assignee name for supervisors/admins) and a link *View assignment* to `/local/taskflow/assignment.php?id=…` (admins: *Edit Assignment* → `/local/taskflow/editassignment.php?id=…`) — *Kind regards, [Department]* — the footer "This is an automatically generated email. Please do not reply … For questions contact: [Contact Email] [Organization]". The bracketed parts are placeholders in the core language pack; the tuines adapter replaces them with the TU Wien HR-development wording and contact address. |
| Deduplication | One digest per person per run, each assignment listed once. No digest is sent when nothing is unread. |

The digest does **not** write to the assignment history and does not use the sent-messages log.

## Capabilities and access

* Posting requires only being logged in on the assignment page where the form is rendered; the page itself is shown to
  the assignee, the supervisor (from the supervisor profile field) or holders of `local/taskflow:viewassignment`.
* Reading the digest requires nothing beyond the notification preference.
* The **Chat** message type in the template editor requires `local/taskflow:editmessages` like all templates.

## Related

* [Chapter overview](README.md) — providers, sending path
* [Message templates](01-message-templates.md) — the *Chat* message type
* [Assignments — Assignment detail page](../assignments/02-assignment-detail-page.md)
* [Assignments — Edit assignment](../assignments/03-edit-assignment.md)
* [Adapters — tuines](../adapters/tuines.md) — branded chat mails and the chat form
* [Scheduled tasks](../scheduled_tasks/README.md)
