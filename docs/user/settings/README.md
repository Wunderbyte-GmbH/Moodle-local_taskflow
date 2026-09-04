[Back to user documentation index](../README.md)

# Settings — Reference

All admin settings of **Wunderbyte Taskflow** live on one page: *Site administration → Plugins → Local plugins → Wunderbyte Taskflow* (`/admin/settings.php?section=local_taskflow_settings`). The page is grouped by headings; this reference follows the same order. Setting keys are given as `local_taskflow/<key>`; adapter settings use the component `taskflowadapter_<name>` and are documented on the adapter pages.

Labels are the English UI texts. Because Taskflow strings can be overridden by the active adapter, a label may read differently on your site when an adapter ships its own wording.

---

## Quick setup path

1. Open `/admin/settings.php?section=local_taskflow_settings`.
2. Under **Taskflow Settings** choose the adapter, supervisor role, HR users, organisational-unit model.
3. Under the adapter heading (**Standard API Settings** / **KSW API Settings** / **INES API Settings**) map your profile fields.
4. Under **Taskflow Request Settings** enable the request types you want employees to use.
5. Save, then create message templates and rules.

---

## Table of contents

1. [Taskflow Settings (general)](#1-taskflow-settings-general)
2. [Taskflow Request Settings](#2-taskflow-request-settings)
3. [Adapter settings](#3-adapter-settings)
4. [Included functions](#4-included-functions)
5. [Rule inheritance](#5-rule-inheritance)
6. [Organisational units](#6-organisational-units)
7. [Assignments Display](#7-assignments-display)
8. [Shortcodes Settings](#8-shortcodes-settings)
9. [Manage messages and documentation links](#9-manage-messages-and-documentation-links)
10. [Mail and completion settings](#10-mail-and-completion-settings)
11. [Internal communication settings](#11-internal-communication-settings)
12. [Settings without a UI](#12-settings-without-a-ui)
13. [Related](#13-related)

---

## 1. Taskflow Settings (general)

Heading **Taskflow Settings** — "General settings".

| Key | UI label | Type | Default | Meaning / where it takes effect |
|-----|----------|------|---------|-------------------------------|
| `external_api_option` | **External api with user data** | select (installed adapters: *Standard API*, *KSW API*, *Ines API*) | `standard` | Which adapter is active. Decides the import format, the profile-field mapping that is read, supervisor lookup, dashboard and edit-form variants, and which strings override the core ones. See [Adapters](../adapters/README.md). |
| `supervisorrole` | **Supervisor role** | select (all roles) | none (`0`) | "This role is automatically assigned to everyone who is registered as a supervisor." Assigned in the system context when an import writes a supervisor; removed again by the adhoc task *Check for supervisor role* when a user is no longer anybody's supervisor. The upgrade creates a role with shortname `supervisor` carrying `local/taskflow:issupervisor` that you can select here. See [Units and users](../units_and_users/README.md). |
| `hrusers` | **HR userids** | text | `0` | "Enter the Moodle user ids of HR, comma separated". These users receive requests that a rule routes to **HR**; the first id is also recorded as the modifying user when an assignment status is changed manually. See [Requests](../requests/README.md). |
| `cohortenrollment` | **Cohort enrollment** | checkbox | on (`1`) | "Manually enrolling a user into a cohort adds the user to a taskflow unit" — with this on, adding/removing a cohort member (core event) is treated like a unit membership change and re-evaluates the rules of that unit. See [Units and users](../units_and_users/README.md). |
| `defaultauth` | **Default authentication method** | select (installed auth plugins) | `manual` | "Choose which authentication method should be assigned to users created by Taskflow." Applies to users created by an adapter import or the upload-users form. |

---

## 2. Taskflow Request Settings

Heading **Taskflow Request Settings** — "Request settings". Each checkbox enables one request type globally; a rule must additionally allow the type in its **Requests** step (see [Requests step](../rules/05-requests-step.md)).

| Key | UI label | Type | Default | Meaning |
|-----|----------|------|---------|---------|
| `allowuploadevidence` | **Allow upload evidence of competencies by users** | checkbox | off | "Users can upload evidence of their competencies instead of taking a course." Shows the **Evidence** button on competency targets of the assignment detail page. Request type 3. See [Competencies and certificates](../competencies_and_certificates/README.md). |
| `allowselfextension` | **Allow user to request extension of assignment duedate** | checkbox | off | "Users can request extension of their assignment duedate." Shows the **Request Prolongation** button. Request type 2. |
| `allowselfnotrelevant` | **Allow user to request assignment not-relevant status** | checkbox | off | Lets users request that an assignment is not relevant for them; shows the **Not relevant for me** button. Request type 1. |

Details on the request workflow: [Requests](../requests/README.md).

---

## 3. Adapter settings

Every installed adapter adds its own section to this page (headings **Standard API Settings**, **KSW API Settings**, **INES API Settings**), regardless of which adapter is selected in **External api with user data**. Only the values of the *active* adapter are read.

Common to all adapters (component `taskflowadapter_<name>`):

| Key | UI label | Meaning |
|-----|----------|---------|
| `translator_user_firstname`, `translator_user_lastname`, `translator_user_email` | **JSON key for userprofilefield: First name / Last name / Email** | JSON keys of the core user fields in the import. |
| `translator_user_<shortname>` | **JSON key for userprofilefield: <field name>** | JSON key whose value is written into that custom profile field. Empty = not imported. |
| `<shortname>` | **Assign function to userprofilefield: <field name>** | Which Taskflow *function* the field has: *No function*, *Target group*, *Organisational unit*, *Supervisor*, *Deputy*, *Supervisor (external Moodle id)*, *Long Leave*, *Contract end*, *Contract start*, *external ID*. Each function should be mapped exactly once. |
| `translator_target_group_name`, `translator_target_group_description`, `translator_target_group_unitid` | target group keys | JSON keys of target-group records (used by the INES format). |

Adapter-specific settings (e.g. `necessaryuserprofilefields`, `blscertificatekey`, KSW `protectedcohorts`, INES `dwhurl`, `usingprolongedstate`, `excludestatus`) are documented on the adapter pages:

- [Adapters — the mapping model](../adapters/README.md)
- [Standard adapter](../adapters/standard.md)
- [KSW adapter](../adapters/ksw.md)
- [TU Wien INES adapter](../adapters/tuines.md)

> **Note:** the setting **Supervisor Overview** (`supervisor_field`, "Choose a field for the supervisor") is only added to the page when *no* adapter subplugin is installed. Since the three adapters ship with the plugin, you normally configure the supervisor field through the adapter's function mapping instead.

---

## 4. Included functions

Heading **Included functions** — "Here you can decide which functionalities should be used in Taskflow."

| Key | UI label | Type | Default | Meaning |
|-----|----------|------|---------|---------|
| `includedsteps` | **Included functions** | multiselect: *Filter*, *Target*, *Messages*, *Requests* | none selected (= all) | Which steps the rule editor (`/local/taskflow/editrule.php`) shows after the first step **Rule**. "If you do not select any, all functionalities will be available." See [Rules](../rules/README.md). |

---

## 5. Rule inheritance

Heading **Rule inheritance?** — "How should rules from parent organizational units affect lower-level ones?"

| Key | UI label | Type | Default | Meaning |
|-----|----------|------|---------|---------|
| `inheritance_option` | **Rule inheritance** | select: *No inheritance*, *Inherit rule from parent unit*, *Inherit rule from all units above* | `noinheritance` | Intended to control whether rules of parent units apply to child units. |

> **Note:** in the current code this setting is stored but not read anywhere. Whether a rule applies to sub-units is controlled per rule (see the recursive/inheritance options in the [Rule step](../rules/01-rule-step.md)), not by this global setting.

---

## 6. Organisational units

Heading **Organisational units**.

| Key | UI label | Type | Default | Meaning |
|-----|----------|------|---------|---------|
| `organisational_unit_option` | **Organisational unit** | select: *Units*, *Cohorts* | `unit` | Which entity models an organisational unit: Taskflow's own unit records (*Units*) or Moodle cohorts (*Cohorts*). Read by the unit factories and by all adapters when importing. Changing it on a running site changes where memberships are looked up — decide before importing. See [Units and users](../units_and_users/README.md). |

---

## 7. Assignments Display

Heading **Assignments Display**.

| Key | UI label | Type | Default | Meaning |
|-----|----------|------|---------|---------|
| `assignment_fields` | **Display optional user profile field** | multiselect (custom profile fields) | none | "Select the desired custom field to be shown in the assignment table." Each selected field becomes an extra column (`custom_<shortname>`) in every assignments table and dashboard; the columns are searchable and sortable. See [Dashboard](../dashboard/README.md). |
| `showassignmentslist` | **Show assignments list** | checkbox | off | "Display a list of all assigned assignments on individual assignment overview pages." When on, each per-user tab on the dashboard (`/local/taskflow/index.php`) shows that user's assignment table below the info and stats cards. |

---

## 8. Shortcodes Settings

Heading **Shortcodes Settings** — "Taskflow supports several shortcodes that allow you to display useful information in different places on your website."

| Key | UI label | Type | Default | Meaning |
|-----|----------|------|---------|---------|
| `shortcodespassword` | **Password** | text | empty | "If you enter a value here, shortcodes can only be used with the 'password' parameter; otherwise, a warning will appear." Every Taskflow shortcode then needs `password="<value>"`. See [Shortcodes](../shortcodes/README.md). |

---

## 9. Manage messages and documentation links

Two headings on the page are links rather than settings:

| Heading | Link | Purpose |
|---------|------|---------|
| **Manage messages** | **Manage taskflow messages** → `/local/taskflow/message_form/editmessage.php` | Opens the message template editor. See [Messages](../messages/README.md). |
| **Taskflow documentation** | `/local/taskflow/documentation.php` | Opens these pages inside Moodle (shown only to users with `local/taskflow:viewdocumentation`). |

---

## 10. Mail and completion settings

These checkboxes follow the links above (no own heading).

| Key | UI label | Type | Default | Meaning |
|-----|----------|------|---------|---------|
| `sendmailstodeputy` | **Send mails to deputy** | checkbox | off | "Mails send to supervisor will be additionally forewarded to deputy" — message templates addressed to the supervisor, and request notifications for the supervisor, are also delivered to the supervisor's deputies. See [Messages](../messages/README.md), [Units and users](../units_and_users/README.md). |
| `sendmanualmailsmultipletimes` | **Send manual mails always** | checkbox | off | "Manual triggered mails will be send always" — normally a message is sent only once per user, message and rule (deduplication). With this on, messages triggered by a manual status change bypass that check and are sent every time. See [Messages](../messages/README.md). |
| `allowoverduecompletion` | **Overdue assignments can still be completed** | checkbox | on (`1`) | "If enabled, users are allowed to mark assignments as completed even after the due date has passed." When off, an assignment that is already *Overdue* keeps that status even when all targets are completed. See [Due dates, prolongation, overdue](../assignments/05-due-dates-prolongation-overdue.md). |

---

## 11. Internal communication settings

Heading **Internal communication settings** — "Internal communication between supervisors and users regarding assignments." This block appears only when the active adapter provides an internal-communication form (currently the INES adapter).

| Key | UI label | Type | Default | Meaning |
|-----|----------|------|---------|---------|
| `allowinternalcommunication` | **Internal Chat** (`allowinternalcommunication`) | checkbox | on (`1`) | Enables the per-assignment chat between assignee and supervisor on the assignment detail and edit pages. |
| `internalcommunicationpreviewlength` | **Internal communication preview length** | select: off, 100, 150, 175, 200, 300, 400, 500, 600 | `300` | "Define the maximum number of characters shown in the preview of internal communications between supervisors and users regarding assignments." Applies to the *Chat messages* column of the assignments table. |

Details: [Internal communication](../messages/03-internal-communication.md).

---

## 12. Settings without a UI

| Key | Where it matters |
|-----|------------------|
| `local_taskflow/supervisor_field` | Read as fallback for the supervisor lookup when no adapter mapping is available; shown on the settings page only if no adapter is installed (see section 3). |
| `taskflowadapter_<name>/excludestatus` | Status ids hidden from the manual status select and blocked as transitions. Only the INES adapter exposes it in the UI (**Do not use status**); for other adapters it can only be set via `set_config`. See [Status lifecycle](../assignments/01-status-lifecycle.md). |

---

## 13. Related

- [Adapters](../adapters/README.md) — the adapter sections of the settings page
- [Capabilities](../capabilities/README.md)
- [Shortcodes](../shortcodes/README.md)
- [Requests](../requests/README.md)
- [Messages](../messages/README.md)

---

**For AI / explain-docs routing:** this chapter answers *"what does setting X do / where do I switch Y on"*. Adapter-specific settings (JSON keys, functions, DWH URL, protected cohorts, prolonged state, exclude status) are explained in [Adapters](../adapters/README.md) and the adapter pages. Permissions are in [Capabilities](../capabilities/README.md), background jobs in [Scheduled tasks](../scheduled_tasks/README.md).
