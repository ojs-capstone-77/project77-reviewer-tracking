# Configurable Participation Form and Labels Requirements

**Creator by:** Jahan Haidari (BA)

**Team:** 77

**Sprint:** 3

---

## 1. Task Scope

Editors need to control which fields appear in the participation recording form and how reviewer labels are managed. This document defines what is configurable, what stays locked, and how changes interact with the monitoring dashboard and reviewer history.

The client has already confirmed that editors should manage configuration through journal settings. This document expands on that decision.

---

## 2. What Editors Can Configure

### 2.1 Required and Optional Fields

Editors can choose which participation form fields are required and which are optional.

The fields that can be configured are attendance, contribution types, strengths, development opportunities, shaping feedback response, comments on contributions, and comments on shaping feedback.

### 2.2 Contribution Types

Editors can add, edit, or remove the contribution type options. The default options are Discussion, Writing, Analysis, Editing, and Other.

### 2.3 Attendance Options

Editors can add, edit, or remove attendance options. The default options are Attended, Did not attend with apology, Did not attend without apology, N.A., and Other.

### 2.4 Shaping Feedback Options

Editors can add, edit, or remove shaping feedback options. The default options are Uploaded notes or comments, Commented on the feedback draft, Offered creating the draft, and Created the draft.

### 2.5 Reviewer Labels
Editors can manage the label types themselves as well as the values inside them.

Editors can add a new label type, rename an existing one, or remove a label type they no longer need.

Editors can also add, edit, or remove the values inside each label type.

Each label type is set as either single choice or multiple choice when it is created.

The default label types that ship with the plugin are experience level, methodology background, and expertise.

| Label type | Values | Multiple |
|------------|--------|----------|
| Experience level | Novice, Intermediate, Experienced | No |
| Methodology background | Quantitative, Qualitative, Mixed methods | No |
| Expertise | Education, Statistics | Yes |

Editors can replace these defaults or add new label types on top of them.

---

## 3. What Stays Locked

The following fields are always present and cannot be removed or made optional.

| Field | Why it is locked |
|-------|------------------|
| Other field per reviewer | Client requested this to allow the RGL to add extra comments |
| General comments field for the editors | Client requested this for the RGL to communicate with editors |
| Reviewer name | Required to link the record to a reviewer |
| Attendance recorded | Used for calculating participation and attendance percentages |

Editors cannot add new form field types beyond the ones listed in section 2. Label types are separate from form fields and can be added freely.

---

## 4. How Configuration Affects Existing Data

Configuration changes only apply to future forms.

Already submitted forms keep the fields and options that were in place when they were saved.

If an editor removes a contribution type or attendance option, any existing records that used it stay in the database and continue to appear in the reviewer history on the dashboard.

If an editor renames an option, the old name is preserved for existing records so the history stays accurate.

If a label type is removed, reviewers who already have a value for it keep their value in the database, but the label type no longer appears in the editor settings or on new reviewer pages.
---

## 5. How This Affects the Monitoring Dashboard

The monitoring dashboard reads from the same data the participation form writes.

If a field or option is removed from the form, past records that used it still count toward the reviewer's stats on the dashboard.

The dashboard does not need to know which fields are currently active. It reads whatever is stored.

Label values that are removed from the settings stay attached to reviewers who already had them. New values can be assigned going forward.

The label counts section on the Overview page is generated from whatever label types currently exist. If an editor adds a new label type, the dashboard shows it automatically. If a label type is removed, the dashboard stops showing it, but the historical values stay in the database.
---

## 6. Storing Old Fields for History

When a contribution type, attendance option, or shaping feedback option is removed, the value stored in past records stays unchanged.

The dashboard shows the historical value as it was recorded.

The settings page only controls what appears in new forms, not what is shown in history.

Label changes are tracked in a history log with the date, the person who made the change, and a summary of what changed. This applies to changes to label types as well as changes to the values inside them.

---

## 7. What Needs to Be Configurable and What Stays Locked

| Element | Configurable |
|---------|--------------|
| Required or optional status for form fields | Yes |
| Contribution type list | Yes |
| Attendance option list | Yes |
| Shaping feedback option list | Yes |
| Label types themselves | Yes |
| Single or multiple choice setting per label type | Yes |
| Experience level values | Yes |
| Methodology background values | Yes |
| Expertise values | Yes |
| Other field per reviewer | No |
| General comments field | No |
| Reviewer name | No |
| Attendance recorded field | No |

---

## 8. Open Questions for the Client

The following points need confirmation from Eva before development starts.

Can editors reorder the contribution type and attendance options, or only add and remove them?

When a label value is removed, should reviewers who already have it keep it visible in the dashboard, or should it stop showing?

Should the general comments field for the editors ever be hidden in special cases, or always visible?

Can editors bulk assign label values to many reviewers at once, or only one at a time?

When a label type is removed, should the dashboard hide it completely, or keep it visible in history for reviewers who already had a value?

Is there a maximum number of label types or values per label type, or no limit?
---
