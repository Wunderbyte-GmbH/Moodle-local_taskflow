[Back to user documentation index](../README.md)

# Dashboard

The **Taskflow dashboard** is the central page for everybody who works with assignments: administrators and HR
see all assignments and requests, supervisors see the assignments and requests of their team, and both can open
a per-user view (profile card, training statistics, assignment list) for any employee they are allowed to see.
The same building blocks — most importantly the **assignments table** — are reused by the shortcodes, so what you
learn here also applies to dashboards embedded in course pages or in a `block_multiblock`.

---

## Quick path

1. Open `/local/taskflow/index.php` (any logged-in user may open the page; which tabs appear depends on your rights).
2. The **Supervisor** tab is active by default if you hold `local/taskflow:issupervisor`; click **Admin- Dashboard**
   if you are an administrator/HR user.
3. Type a name, e-mail or user id into the **Select a user** box above the tabs. Pick a user — a new tab with that
   user's name opens automatically.
4. In any assignments table click the **info icon** (<i>i</i>) of a row to open the assignment page
   (`/local/taskflow/assignment.php?id=<assignmentid>`), or the **edit icon** (pencil) to open
   `/local/taskflow/editassignment.php?id=<assignmentid>`.
5. Close a user tab with the **×** on the tab; it is removed from your session and will not reappear on the next visit.

## Pages covered in this chapter

| Page | URL | Who can open it |
|------|-----|-----------------|
| Taskflow dashboard | `/local/taskflow/index.php` | every logged-in user; tabs are shown according to capabilities (see below) |
| Import / overview page | `/local/taskflow/view.php` | every logged-in user; the two import buttons only work for site administrators (`moodle/site:config`) |
| Assignment page | `/local/taskflow/assignment.php?id=<assignmentid>` | assignee, their supervisor, or `local/taskflow:viewassignment` — see [Assignment detail page](../assignments/02-assignment-detail-page.md) |
| Edit assignment | `/local/taskflow/editassignment.php?id=<assignmentid>` | `local/taskflow:viewassignment` or the assignee's supervisor — see [Edit assignment](../assignments/03-edit-assignment.md) |
| Rules dashboard | shortcode `[rulesdashboard]`, create/edit via `/local/taskflow/editrule.php?id=0` | `local/taskflow:viewrules` / `local/taskflow:createrules` — see [Rules](../rules/README.md) |
| Requests dashboard | shortcode `[requests]` and the "Applications" cards | see [Requests](../requests/README.md) |

---

## The tabs of `/local/taskflow/index.php`

The page has a tab band with up to three kinds of tabs. All of them are rendered from the same data set, so
switching tabs does not reload the page.

| Tab | Label (English) | Shown when | Content |
|-----|-----------------|------------|---------|
| Admin dashboard | **Admin- Dashboard** | you hold `local/taskflow:editassignment` **or** your user id is listed in the setting *HR users* of the booking extension `bookingextension_confirmation_supervisor` (`confirmation_supervisor_hrusers`) | cards **Detailview**, **Applications**, **Overview** (see below) |
| Supervisor dashboard | **Supervisor** | you hold `local/taskflow:issupervisor` | cards **Pending Approvals**, **Applications**, **Overview**, **Bookings of your Teams**, **Detailview** |
| One tab per selected user | the user's full name, with a **×** button | for every user you selected in the user search — and always for **yourself** | user info card, user stats card, optionally the user's assignment list |

The Supervisor tab is the active tab when the page opens (if you have it). Which classes render the two dashboards
depends on the active adapter (`external_api_option`): the standard adapter's layout is described here; the KSW
adapter uses the same cards with slightly different columns (see [KSW adapter](../adapters/ksw.md)); the INES
adapter (`tuines`) uses the standard layout but swaps in its own assignments table
(see [TU Wien INES adapter](../adapters/tuines.md)).

> **Note:** The HR-user list that unlocks the Admin tab is *not* the Taskflow setting `local_taskflow/hrusers`
> (which decides who receives HR requests) but the booking extension setting mentioned above. If a user without
> `local/taskflow:editassignment` is supposed to see the Admin tab, add them there.

### Admin dashboard (standard adapter)

| Card | Content |
|------|---------|
| **Detailview** | the [assignments table](#the-assignments-table) with **all** assignments on the site (active and inactive), columns Name, Targets, Rulename, Status, Time created, Time modified, Actions |
| **Applications** | intended for the open requests of the site (the requests table with 10 rows per page in KSW) |
| **Overview** | the [status chart](#the-status-chart) over all active assignments |

> **Note:** In the current version the Admin tab's **Applications** card stays empty even when requests exist,
> because the requests list is rendered under a different name than the card expects. Use the `[requests all=1]`
> shortcode (see [Requests](../requests/README.md)) to work through requests as HR.

### Supervisor dashboard (standard adapter)

| Card | Content |
|------|---------|
| **Pending Approvals** | bookings of your team members waiting for your approval (from `mod_booking`, requires the booking extension *confirmation supervisor*) |
| **Applications** | requests (not relevant / prolongation / evidence) addressed to you as supervisor or deputy — see [Requests](../requests/README.md) |
| **Overview** | status chart over the active assignments of your team |
| **Bookings of your Teams** | the booking list of your team (from `mod_booking`) |
| **Detailview** | the assignments table restricted to your team, columns Name, Targets, Due date, Status, Actions (KSW: Name, Targets, Status, Information) |

"Your team" means: every user whose **Supervisor** profile field contains your user id, plus — if you are entered
as **Deputy** of another supervisor — that supervisor's team as well. How these fields are resolved is described in
[Units and users](../units_and_users/README.md#supervisor-and-deputy).

### User tabs

Every user tab contains, top to bottom:

1. **User info card** — profile picture (links to the profile), **First name**, **Last name**, **Email**, and the user id.
2. **User stats card** with two boxes:
   - **Training history** — three linked counters: *Finished Learnactivities* (completed bookings, links to
     `/mod/booking/mybookings.php?userid=<id>&completed=1`), *Certificates* (number of certificates issued by
     `tool_certificate`, links to `/local/taskflow/mycertificates.php?userid=<id>` — see
     [Competencies and certificates](../competencies_and_certificates/README.md)) and *All finished and unfinished
     Learnactivities* (all bookings). The booking counters need `mod_booking`, the certificate counter needs
     `tool_certificate`.
   - **Courses** — the [status chart](#the-status-chart) of that user's **active** assignments.
3. **Assignment view** — only if the admin setting **Show assignments list** (`showassignmentslist`) is enabled: the
   assignments table with *all* assignments of that user, active and inactive.

Your own user is always added as a tab when the page is opened, so you always see your own statistics.

### How tabs are remembered and cleared

Selected users are stored in a **session cache** (`local_taskflow/dashboardfilter`), not in the database:

- The list survives page reloads and navigating away and back during the same login session.
- The **×** on a tab removes the user from the cache immediately (web service `local_taskflow_clear_dashboard_cache`).
- The list is emptied when you log out, when the session expires, and whenever the cache is invalidated by a change
  to the assignments list (the same event that refreshes the assignments table, `changesinassignmentslist`) or by
  *Purge all caches*.
- The rendered status charts are cached in the same session cache. If a chart looks stale, purge caches or wait for
  the next assignment change.

---

## User search

The **Select a user** autocomplete above the tabs is shown if you hold `local/taskflow:viewreports` **or**
`local/taskflow:issupervisor`. Choosing a suggestion submits automatically and opens the user tab.

| You hold | Who is found |
|----------|--------------|
| `local/taskflow:viewreports` | all users of the site (not deleted, not suspended) |
| only `local/taskflow:issupervisor` | your direct team and the teams of supervisors who list you as deputy |
| neither | the search returns nothing and the warning *No permission as supervisor* |

Search rules: every word you type must match; words are matched against user id, first name, last name and e-mail
(a numeric word matches the id exactly). Suggestions show *Firstname Lastname*, the id and the e-mail. If more than
100 users match, no list is shown and Moodle's "too many users to show" warning appears — type more characters.

---

## The assignments table

One table class serves every assignments list: the Detailview cards, the user tabs, the assignment list on
`/local/taskflow/view.php`, and the shortcodes `[assignmentsdashboard]`, `[myassignments]` and
`[supervisorassignments]`. Which rows you see is decided by the surrounding dashboard/shortcode (all / own / team);
which **columns** are shown is decided by the dashboard or by the shortcode argument `columns` (see
[Shortcodes](../shortcodes/README.md)). The **Actions** column is always appended.

### Columns

| Column key | Header (English) | Meaning |
|------------|------------------|---------|
| `id` | ID | assignment id |
| `fullname` | Full name | assignee; INES adapter links the name to the person's TISS page |
| `targets` | Targets | one line per target: **type:** name (Completed / Not completed) |
| `rulename` | Rulename | name of the rule; linked to the assignment page |
| `supervisor` | Supervisor Overview (lang key `supervisor`; adapters may rename it) | full name of the assignee's supervisor (read from the Supervisor profile field) |
| `status` | Status | the numeric status code as stored (e.g. `0`, `10`, `15`); prefer `statussortkey` for a readable label |
| `statussortkey` | Status | readable status name; for **Prolonged** and **Overdue** the counter is appended in brackets, e.g. `Overdue (2)` = the assignment became overdue twice. Technically the value is `<statuscode>_<counter>`, so sorting by this column groups by status and, within *Prolonged*/*Overdue*, by how often it happened |
| `active` | Active | `1` active / `0` inactive (the `active` flag, see [Status lifecycle](../assignments/01-status-lifecycle.md)) |
| `usermodified` | Last modified by | user id of the last modifier |
| `usermodified_fullname` | User that modified | full name of the last modifier |
| `timecreated` | Time created | `dd.mm.yyyy hh:mm` |
| `timemodified` | Time modified | `dd.mm.yyyy hh:mm` |
| `duedate` | Due date | formatted due date |
| `comment` | Comment | the comment of the **last manual status change** (from the history), shortened to 50 characters; full text as tooltip; `-` if none. INES adapter: date and text of the latest comment (200 characters) |
| `lastinternalcomment` | Chat messages | preview of the internal chat, see [Chat preview](#chat-preview) |
| `info` | Information | info icon only (link to the assignment page) — used by the KSW supervisor Detailview |
| `actions` | Actions | see [Actions](#actions) |
| `testmoodleid` | testmoodleid | technical column (Moodle user id); only filled by the INES adapter |
| `custom_<shortname>` | name of the profile field | one column per profile field selected in the admin setting **Display optional user profile field** (`assignment_fields`); shows the assignee's value. If the selected field is the one mapped as **Supervisor**, the stored user id is rendered as the supervisor's full name |

The custom profile-field columns are sortable and included in the full-text search. Settings for them are described
in [Settings](../settings/README.md).

### Chat preview

The **Chat messages** column summarises the internal communication of the assignment (see
[Internal communication](../messages/03-internal-communication.md)):

- Preview of the **newest** message: `date - sender: text`, truncated to the number of characters set in
  **Internal communication preview length** (`internalcommunicationpreviewlength`, default 300).
- An **eye icon** opens a modal *Internal Chat* with the full conversation.
- A yellow **bell icon** (*New chat message(s)*) appears when the newest message was not written by you and there are
  messages you have not seen yet (unread = newer than your last visit of the assignment page).
- Sorting by this column orders by the newest message; assignments without chat show `-` and sort last.
- In downloads only the plain preview text is exported.

### Filters, search, sorting, paging

- **Full-text search** covers Full name and Rulename (plus any custom profile-field columns).
- **Sortable** columns: id, fullname, rulename, statussortkey, status, supervisor, lastinternalcomment, timecreated,
  timemodified, duedate, comment and the custom columns. Default order: **Time created, newest first** (a shortcode
  can pass `sortby`/`sortorder`).
- **Filters** appear only when the dashboard/shortcode requests them via the `filter` argument, e.g.
  `filter=status,rulename,completed`:

  | Filter key | UI label | Behaviour |
  |------------|----------|-----------|
  | `status` | Status | drop-down of all statuses except those excluded by the adapter setting *Do not use status* (`excludestatus`) |
  | `rulename` | Rulename | drop-down of rule names present in the list |
  | `completed` | Hide completed assignments | toggle that hides assignments with status **Completed** |

- 20 rows per page; the row-count selector lets the viewer change that.
- **Download** buttons (top and bottom) appear for users with `local/taskflow:downloaddashboard`. The export
  contains the text of the columns; action icons and the chat modal are omitted.

### Actions

| Icon | Opens | Shown to |
|------|-------|----------|
| info circle | `/local/taskflow/assignment.php?id=<id>` — the [assignment page](../assignments/02-assignment-detail-page.md) | everybody who sees the row |
| pencil | `/local/taskflow/editassignment.php?id=<id>` — [Edit assignment](../assignments/03-edit-assignment.md) | users with `local/taskflow:editassignment`, and the assignee's supervisor |

Both links carry a `returnurl` (the page you came from; if the table was loaded by AJAX the dashboard URL is used)
and the marker `taskflow_multiblock`, which the assignment pages use to bring you back to the right
`block_multiblock` tab (see [Embedding](#embedding-the-dashboard-shortcodes-and-block_multiblock)).

The INES adapter labels the info link **Go to training** and shows the pencil to a supervisor only while the
assignment is in the state that allows a supervisor extension (see [TU Wien INES adapter](../adapters/tuines.md)).

### The status chart

The **Overview** and **Courses** cards render a doughnut chart over the **active** assignments of the respective
scope with three segments:

| Segment | Statuses counted |
|---------|------------------|
| **Overdue** | Overdue (10) |
| **Assigned** | Assigned (0), Enrolled (3), At least one target completed (7), Prolonged (5) |
| **Completed** | Completed (15) |

Other statuses (Planned, Paused, Not relevant, Droppedout, Reprimand, Sanctioned) are not part of the chart. If no
assignment falls into any segment the text *There is no data to render a chart.* is shown. Charts are cached per
session (see above).

---

## Rules dashboard and requests dashboards

- The **Rules dashboard** (card *Rules dashboard*, columns Rulename, Description, Active yes/no, Actions with edit and
  delete; **Create rule** button for `local/taskflow:createrules` leading to `/local/taskflow/editrule.php?id=0`) is
  provided as shortcode `[rulesdashboard]`. Everything about rules is in [Rules](../rules/README.md).
- The **Requests dashboard** (columns Name, Assignment, Status, Actions, Time created, Comment; filters *Open /
  Confirmed / Declined* and date) is the *Applications* card of the Supervisor tab and the shortcode `[requests]`.
  Confirming and declining is described in [Requests](../requests/README.md).

---

## `/local/taskflow/view.php` — import buttons and personal overview

This page shows two buttons followed by the **My Assignments** table (your own active assignments plus a notice
when a target only belongs to inactive assignments) and the **Supervisor view** table (assignments of your team).

| Button | What it does | Requirement |
|--------|--------------|-------------|
| **Trigger DWH import** | runs the INES data-warehouse import (`fetch_dwh_data`) immediately and shows its result message; see [TU Wien INES adapter](../adapters/tuines.md) | `moodle/site:config`; the INES adapter plugin must be installed |
| **Upload users** | opens *Upload Users (JSON)*: paste the external personnel JSON into **JSON input** and submit; the active adapter imports users, units, memberships and supervisors and reports the *Execution time*. Invalid JSON is rejected (*Invalid json structure*) | `moodle/site:config` |

Details on the accepted JSON per adapter: [Adapters](../adapters/README.md).

---

## Embedding the dashboard: shortcodes and `block_multiblock`

All cards of the dashboard exist as shortcodes — `[assignmentsdashboard]`, `[supervisorassignments]`,
`[myassignments]`, `[rulesdashboard]`, `[requests]` and `[assignmentsavailability]` — so you can place them on a
course page, a dashboard block or a static page. Arguments (`columns`, `filter`, `chart`, `toclarify`, `status`,
`active`, `all`, `sortby`, `password`, …) and the required capabilities are listed in
[Shortcodes](../shortcodes/README.md).

When such a shortcode sits inside a **block_multiblock** tab, Taskflow keeps you on the right tab:

- the links in the tables add `taskflow_multiblock` to the URL and the assignment pages append the multiblock hash
  (`#multiblock-<n>-<n>`) to the `returnurl`;
- the **Back** button on the assignment pages returns to that URL, and the tab matching the hash is opened
  automatically.

---

## Related

- [Assignments](../assignments/README.md) — the assignment page, editing, statuses, history
- [Requests](../requests/README.md) — the Applications cards and the requests table
- [Rules](../rules/README.md) — rules dashboard and the rule form
- [Shortcodes](../shortcodes/README.md) — embedding dashboards, arguments and passwords
- [Units and users](../units_and_users/README.md) — how supervisors, deputies and teams are determined
- [Capabilities](../capabilities/README.md) — which capability unlocks which tab or action
- [Settings](../settings/README.md) — `assignment_fields`, `showassignmentslist`, `internalcommunicationpreviewlength`

---

## For AI / explain-docs routing

Questions that belong here: what the tabs on `/local/taskflow/index.php` show and who sees them, the user search and
why a user is not found, how selected user tabs are remembered or cleared, the meaning of any column of the
assignments table (especially the Status column with a counter in brackets and the Chat messages column), table
filters, download, the status chart, and the buttons on `/local/taskflow/view.php`.

Route elsewhere: the *content* of one assignment or its buttons → [Assignments](../assignments/README.md);
confirming/declining a request → [Requests](../requests/README.md); shortcode arguments and passwords →
[Shortcodes](../shortcodes/README.md); why somebody is (not) a supervisor or deputy →
[Units and users](../units_and_users/README.md); rule creation → [Rules](../rules/README.md).
