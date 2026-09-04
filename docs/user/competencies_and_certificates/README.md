[Back to user documentation index](../README.md)

# Competencies and certificates

Besides courses and booking options, a rule can demand a **competency** (a Moodle competency from a competency
framework). A competency target is fulfilled either by completing a booking option that carries that competency, or
— if the site allows it — by **uploading evidence** that a supervisor reviews and approves. This chapter describes
the competency target, the evidence workflow with its three review states, and the **My certificates** page that
lists a user's certificates issued by `tool_certificate`.

---

## Quick path

**Employee — hand in evidence for a competency**

1. Open your assignment (`/local/taskflow/assignment.php?id=<assignmentid>`, e.g. via the **My Assignments** table).
2. Next to the competency target click **Evidence**.
3. Fill in **Title**, optionally a description, a link and files; save. The evidence appears under the target with
   the status **Under review**, and your supervisor (or HR, depending on the rule) receives a request.

**Supervisor — review evidence**

1. Open the employee's assignment (from the Supervisor tab or the requests dashboard).
2. Click **Status** next to the evidence, choose **Approved** or **Rejected** under *User evidence status*, optionally
   set **Valid until date**, save.
3. On *Approved* the competency is credited and the assignment is recomputed — if it was the last open target it
   becomes **Completed**.

**Anyone — see certificates**

- `/local/taskflow/mycertificates.php` shows your own certificates; `?userid=<id>` shows another user's (needs
  the right to view that user's certificates).

---

## Competency targets

A target of type **Competency** (`targettype:competency`) is configured in the rule's *Targets* step by picking a
competency from the competency frameworks of the site (see [Targets](../rules/03-targets.md)). Unlike course or
booking targets, Taskflow does **not** enrol or book anything for a competency target — it only records the goal.
On the assignment page the target is listed with its name and *Completed* / *Not completed*.

A competency target counts as **completed** when one of the following is true:

| Way of completion | How Taskflow detects it |
|-------------------|-------------------------|
| **Booking option with the competency** | the booking option lists the competency in its option field *Competencies* (mod_booking) and the user's booking is marked as completed. Booking into such an option switches the assignment to **Enrolled** (3); completing it gives **Completed** (15) once all targets are done, otherwise **At least one target completed** (7) |
| **Approved evidence** | an evidence record for this user and competency exists with status **Approved** and either no *Valid until date* or one in the future |

For **cyclic** rules (see [Cyclic assignments](../assignments/06-cyclic-assignments.md)) a booking completion only
counts while it is younger than the rule's validation duration; older completions are ignored and the assignment is
re-opened. An approved evidence whose *Valid until date* has passed is no longer counted the next time the
assignment is recomputed.

**Possible courses:** below the targets, the assignment page lists booking options that carry the competency
(*Possible courses*, one card per competency target), so the employee can see which courses would fulfil the
requirement. This list needs `mod_booking`.

If a competency rating is revoked or a booking is set back to *not completed*, the assignment is recomputed and may
fall back from Completed (see [Status lifecycle](../assignments/01-status-lifecycle.md)).

---

## Evidence upload and review

### Enabling it

| Where | Setting | Effect |
|-------|---------|--------|
| Site administration → Wunderbyte Taskflow → *Taskflow Request Settings* | **Allow upload evidence of competencies by users** (`allowuploadevidence`, default off) | shows the **Evidence** button on competency targets of every assignment |
| Rule → step *Requests* | **Upload evidence of competencies** with *Requests go to:* **Supervisor** or **HR** | decides who receives the review request for assignments of this rule (see [Requests step](../rules/05-requests-step.md)) |
| Capabilities | `local/taskflow:uploaduserevidence` (or being the assignee) to open the upload form; `local/taskflow:createrequests` is used for the other request types | see [Capabilities](../capabilities/README.md) |

### Uploading (employee)

The **Evidence** button on a competency target that has no evidence yet opens the form *Evidence* with:

| Field | Meaning |
|-------|---------|
| **Title** (required, max. 100 characters) | name of the evidence |
| **Description (duration of prolongation)** | free text — the label is shared with the prolongation request; describe the evidence here |
| **Link** | URL to the evidence (e.g. an external certificate) |
| **Files** | file upload |

Saving does three things at once: a Moodle *user evidence* (Competencies → *Evidence of prior learning*) is created,
Taskflow records it for this assignment and competency with status **Under review**, and a request of type
*Recognition of evidence* is created for the receiver defined in the rule; the event is written to the assignment's
history (*Competency upload*). A second upload for the same competency is blocked while the first request is still
open (*duplicate*). Messages of the type "on request created" are sent to the receiver (see
[Messages](../messages/README.md)).

The employee sees the evidence card under the target: title, **Link to evidence**, description, upload date, the
files, and the buttons **Edit** and **Delete**. *Delete* removes the Moodle user evidence, Taskflow's record and the
open request.

### Reviewing (supervisor / HR)

When a user with `local/taskflow:issupervisor` opens somebody else's assignment, each evidence card shows a
**Status** button. It opens the same form in *status mode* with:

| Field | Options / meaning |
|-------|-------------------|
| **User evidence status** | **Under review** · **Approved** · **Rejected** |
| **Valid until date** (optional) | date until which the approved evidence counts; leave empty for unlimited validity |

What each status does:

| Status | Effect |
|--------|--------|
| **Approved** | the competency is linked to the evidence and credited to the user (Moodle *user competency*), the assignment is recomputed immediately (the competency target becomes *Completed*), the review request is **confirmed** and the history gets a *Request confirmed* entry; the assignee receives the "on request closed" message |
| **Rejected** | any credited competency link is removed, the request is **declined** (history *Request declined*), the assignee is notified |
| **Under review** | resets a previous decision: the competency link is removed, the request stays as it is |

> **Note:** Saving a status currently requires the capability `local/taskflow:editmessages` in addition to
> `local/taskflow:issupervisor`; without it the form falls back to view mode and the status cannot be changed.
> Give reviewing supervisors this capability (see [Capabilities](../capabilities/README.md)).

The three states are shown on the evidence card as **Under review**, **Approved**, **Rejected**. Confirming or
declining the request through the **requests dashboard** instead (see [Requests](../requests/README.md)) only
treats the request; it does not credit the competency — use the *Status* button for evidence.

---

## Certificates

### `/local/taskflow/mycertificates.php`

| Parameter | Meaning |
|-----------|---------|
| `userid` | whose certificates to show; omitted = your own |
| `download` | export format of the table |
| `page`, `perpage` | paging |

The page requires the certificate tool `tool_certificate` to be installed. You may view another user's list if the
certificate tool lets you see that user's certificates (e.g. you are the user, or you may view all certificates via
`tool/certificate:viewallcertificates`). The page appears in the user's profile navigation as **My certificates**.

The table is the standard certificate list (name, issue date, expiry, code, download) with one Taskflow addition: if
a certificate was issued for a **booking option**, the **name** column shows the booking option's name instead of the
template name.

### Where certificate counts appear

- The **Training history** box of the user stats card on the [dashboard](../dashboard/README.md) shows the number of
  certificates of the selected user and links to this page.
- The **standard adapter** issues a `tool_certificate` certificate automatically when an assignment of a rule whose
  name contains "BLS" is completed (adapter setting *BLS certificate template*); see
  [Standard adapter](../adapters/standard.md).

---

## Related

- [Targets](../rules/03-targets.md) — configuring competency targets in a rule
- [Requests](../requests/README.md) — the *Recognition of evidence* request in the requests dashboard
- [Requests step](../rules/05-requests-step.md) — routing evidence requests to supervisor or HR
- [Assignment detail page](../assignments/02-assignment-detail-page.md) — where the Evidence/Status buttons live
- [Status lifecycle](../assignments/01-status-lifecycle.md) — Enrolled, At least one target completed, Completed
- [Cyclic assignments](../assignments/06-cyclic-assignments.md) — validity of completions
- [Dashboard](../dashboard/README.md) — certificate counter in the user stats card
- [Settings](../settings/README.md) — `allowuploadevidence`

---

## For AI / explain-docs routing

Questions that belong here: how a competency target is fulfilled, why a competency target is (not) completed, how an
employee uploads evidence, what Under review / Approved / Rejected do, the *Valid until date*, who may review
evidence, and where certificates are listed or counted.

Route elsewhere: adding a competency target to a rule → [Targets](../rules/03-targets.md); the requests table and
its confirm/decline buttons → [Requests](../requests/README.md); the automatic BLS certificate →
[Standard adapter](../adapters/standard.md); the *Not relevant* and *Prolongation* buttons on the assignment page →
[Assignment detail page](../assignments/02-assignment-detail-page.md).
