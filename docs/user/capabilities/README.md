[Back to user documentation index](../README.md)

# Capabilities — Reference

Moodle capabilities define what each role may do in **Wunderbyte Taskflow**. All Taskflow capabilities are checked in the **system context** and can be assigned per role under *Site administration → Users → Permissions → Define roles*. By default every capability is allowed for the **manager** archetype; only `local/taskflow:createrequests` is also granted to the other archetypes.

---

## Quick setup path

1. Open *Site administration → Users → Permissions → Define roles*.
2. Edit the role (e.g. a *HR* system role, the *Supervisor* role created by Taskflow, or *Authenticated user*).
3. Filter for `local/taskflow` and allow the capabilities listed for that role below.
4. Assign system roles under *Site administration → Users → Permissions → Assign system roles*.
5. Verify with a test account: open `/local/taskflow/index.php` and an assignment page.

---

## Table of contents

1. [How capabilities work in Taskflow](#1-how-capabilities-work-in-taskflow)
2. [Recommended role setup](#2-recommended-role-setup)
3. [Assignments](#3-assignments)
4. [Rules and messages](#4-rules-and-messages)
5. [Supervision and reporting](#5-supervision-and-reporting)
6. [Requests](#6-requests)
7. [Evidence and documentation](#7-evidence-and-documentation)
8. [Access that does not depend on a capability](#8-access-that-does-not-depend-on-a-capability)
9. [Related](#9-related)

---

## 1. How capabilities work in Taskflow

- **Context:** every capability is defined at `CONTEXT_SYSTEM`. Assign the role at system level; course-level roles have no effect on Taskflow pages.
- **Defaults:** the *manager* archetype gets everything. New capabilities added in an upgrade follow the archetype only on fresh installs — on existing sites check the role definition after an upgrade.
- **Supervisor role:** the upgrade creates a role with shortname `supervisor` that carries `local/taskflow:issupervisor`. Select it in the setting **Supervisor role**; Taskflow then assigns it automatically to every user who is stored as someone's supervisor and removes it when they are not (adhoc task *Check for supervisor role*). See [Units and users](../units_and_users/README.md).
- **Being the supervisor of the assignee** is an additional access path independent of capabilities: the assignment detail and edit pages open for the supervisor of the assignee even without `viewassignment`.
- **HR users** (setting **HR userids**, and — for the admin dashboard tab and the requests shortcode — the HR users configured in `bookingextension_confirmation_supervisor`) also get access in some places without a capability; see section 8.
- **Shortcodes** check their required capability for the *viewing* user; without it the shortcode renders nothing (or a warning in debug mode). See [Shortcodes](../shortcodes/README.md).

---

## 2. Recommended role setup

| Role | Capabilities to allow | What they can do |
|------|-----------------------|------------------|
| **Employee / Authenticated user** | `local/taskflow:createrequests` (already default for the *user* archetype) | See own assignments, open own assignment page, raise requests, chat with the supervisor, upload evidence if enabled |
| **Supervisor** (role selected in **Supervisor role**) | `local/taskflow:issupervisor`, `local/taskflow:viewrequests`, `local/taskflow:treatrequests`, optionally `local/taskflow:downloaddashboard` | Supervisor dashboard tab, team assignments, edit assignments of the team (supervisor form), confirm/decline requests of the team, search own team |
| **HR** | all of the supervisor set plus `local/taskflow:viewassignment`, `local/taskflow:editassignment`, `local/taskflow:viewreports`, `local/taskflow:viewallrequests`, `local/taskflow:viewrules`, `local/taskflow:createrules`, `local/taskflow:editmessages`, `local/taskflow:uploaduserevidence` | Admin dashboard tab, all assignments, admin edit form, all requests, rule and message management, user search over all users |
| **Manager / site administrator** | everything (default) | Full access including `local/taskflow:viewdocumentation` |

---

## 3. Assignments

| Capability | UI name | Type | What it allows | Default roles |
|------------|---------|------|----------------|---------------|
| `local/taskflow:viewassignment` | View assignment | read | Open any assignment on `/local/taskflow/assignment.php` and `/local/taskflow/editassignment.php`, see the edit form. Without it only the assignee and their supervisor can open these pages. | manager |
| `local/taskflow:editassignment` | Edit assignments | write | Shows the **edit** icon in every assignments table, switches the edit page to the **admin** variant (status change with reason, comment, due date, keep changes) and unlocks the **Admin- Dashboard** tab and the `[assignmentsdashboard]` shortcode. See [Edit assignment](../assignments/03-edit-assignment.md). | manager |
| `local/taskflow:downloaddashboard` | Download dashboard data | read | Shows the download buttons of the assignments tables (CSV/Excel export). | manager |

---

## 4. Rules and messages

| Capability | UI name | Type | What it allows | Default roles |
|------------|---------|------|----------------|---------------|
| `local/taskflow:createrules` | Create rules | write | Open the rule editor `/local/taskflow/editrule.php`, the **Create rule** button on the rules dashboard. Rules are also edited and deleted from that dashboard. See [Rules](../rules/README.md). | manager |
| `local/taskflow:viewrules` | View rules | write | Render the `[rulesdashboard]` shortcode. | manager |
| `local/taskflow:editmessages` | Edit messages | write | Manage message templates on `/local/taskflow/message_form/editmessage.php`. See [Messages](../messages/README.md). | manager |

---

## 5. Supervision and reporting

| Capability | UI name | Type | What it allows | Default roles |
|------------|---------|------|----------------|---------------|
| `local/taskflow:issupervisor` | Is supervisor | write | **Supervisor** tab on the dashboard, `[supervisorassignments]` shortcode, the supervisor part of `[assignmentsavailability]`, user search limited to the own team (and teams where the user is deputy), review buttons on competency evidence. Carried by the automatic supervisor role. | manager (+ supervisor role) |
| `local/taskflow:viewreports` | View reports | write | User selector on the dashboard with search over **all** users (not only the own team); each found user opens as a tab. | manager |

---

## 6. Requests

| Capability | UI name | Type | What it allows | Default roles |
|------------|---------|------|----------------|---------------|
| `local/taskflow:createrequests` | Create requests | write | Raise a request on an own assignment (**Not relevant for me**, **Request Prolongation**, evidence upload). The request type must also be enabled globally and in the rule. | manager, coursecreator, editingteacher, teacher, user |
| `local/taskflow:viewrequests` | View requests | write | Render the `[requests]` shortcode (requests dashboard). Which requests are listed depends on being supervisor/deputy or HR. | manager |
| `local/taskflow:treatrequests` | Treat requests | write | The **Confirm request** / **Decline request** (and grant/deny prolongation) actions in the requests table. | manager |
| `local/taskflow:viewallrequests` | View all requests | write | Together with the shortcode argument `all=1`, list **every** request instead of only those of the own team / HR queue. | manager |

Details: [Requests](../requests/README.md).

---

## 7. Evidence and documentation

| Capability | UI name | Type | What it allows | Default roles |
|------------|---------|------|----------------|---------------|
| `local/taskflow:uploaduserevidence` | Upload certificate | write | Use the evidence upload form for competency targets (in addition to the setting **Allow upload evidence of competencies by users**). See [Competencies and certificates](../competencies_and_certificates/README.md). | manager |
| `local/taskflow:viewdocumentation` | View the taskflow documentation | read | Open `/local/taskflow/documentation.php` and see the documentation link on the settings page. | manager |

---

## 8. Access that does not depend on a capability

| Feature | Who gets access |
|---------|-----------------|
| Dashboard page `/local/taskflow/index.php` | Every logged-in user can open it; the **Admin- Dashboard** tab needs `editassignment` *or* membership in the HR user list of `bookingextension_confirmation_supervisor`; the **Supervisor** tab needs `issupervisor`; the user selector needs `viewreports` or `issupervisor`. |
| Assignment detail / edit page | The assignee (detail page) and the supervisor of the assignee (both pages) — no capability needed. |
| `[myassignments]` shortcode | Every logged-in user (shows the own assignments). |
| `[assignmentsavailability]` shortcode | Every logged-in user; the supervisor part only with `issupervisor`. |
| Requests dashboard content | Supervisors see requests of their direct reports, deputies those of the supervisors listing them; HR users (from `bookingextension_confirmation_supervisor`) get the HR view. `viewrequests` is still required to render the shortcode. |
| My certificates `/local/taskflow/mycertificates.php` | The user for their own list; other users need `tool/certificate:viewallcertificates`. |
| Debug data wipe on `view.php` | Only with `moodle/site:config` **and** debugging enabled — never use on a production site. |

---

## 9. Related

- [Settings](../settings/README.md) — **Supervisor role**, **HR userids**
- [Units and users](../units_and_users/README.md) — how supervisors and deputies are determined
- [Shortcodes](../shortcodes/README.md) — required capability per shortcode
- [Requests](../requests/README.md)

---

**For AI / explain-docs routing:** this chapter answers *"which permission do I need for X / why does a user not see the tab, button or shortcode"*. Whether someone counts as **supervisor or deputy** is a data question (profile fields) answered in [Units and users](../units_and_users/README.md); shortcode arguments are in [Shortcodes](../shortcodes/README.md); the request workflow itself is in [Requests](../requests/README.md).
