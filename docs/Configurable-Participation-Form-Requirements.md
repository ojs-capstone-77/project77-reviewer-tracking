# Configuration Requirements Participation Form 

**Prepared by:** Jahan Haidari (BA)

**Team:** 77

**Sprint:** 3

---

## 1. Task Scope

Editors need to control parts of the participation form and the reviewer labels. The PM and UX have agreed on what is configurable and what is not. This document sets out exactly what editors can change.

The aim is to keep configuration simple and to integrate naturally into OJS, so editors work in the same place they already manage review settings.

---

## 2. What the Form Contains

These are the fields in the participation form. Each reviewer has their own column.

| Field | Type |
|-------|------|
| Meeting attendance | Dropdown |
| Comments on contributions (meeting) | Text area |
| Feedback response contributions | Multi select |
| Comments on contributions (feedback response) | Text area |
| Other comments | Text area |

The form also has one general comments field for the whole form, not per reviewer.

---

## 3. What Editors Can Configure

### 3.1 Form Text

Editors can edit the text on the form. This includes the field names and the descriptions that appear under each field.

For example, an editor could rename "Comments on contributions" to "Reviewer contribution notes" or change the guidance text that appears below a field.

### 3.2 Feedback Response Contributions

Editors can add and remove options in the feedback response contributions multi select.

The default options are Uploaded their notes or comments, Commented on the feedback draft, Offered to create the draft, Created the draft, Did not contribute to shaping the response, and Other.

### 3.3 Reviewer Labels

Editors can manage the label types and the values inside them.

Editors can add a new label type, rename an existing one, or remove a label type.

Editors can add, edit, or remove the values inside each label type.

The default label types are experience level, methodology background, and expertise.

---

## 4. What Editors Cannot Configure

The following items are locked. Editors cannot change them.

| Item | Reason |
|------|--------|
| Which fields exist on the form | The field set is fixed by the plugin |
| Attendance dropdown options | The options are fixed |
| The general comments field | It is always present |
| The Other comments field per reviewer | It is always present |

Editors cannot add new field types. They can only change the text that labels existing fields.

---

## 5. Where Configuration Lives

Configuration is added to the existing OJS review settings.

Everything sits under Workflow > Review.

The participation form and the reviewer labels appear as separate tabs alongside the existing Reviewer Reference settings.

Editors do not go to a separate page to configure the plugin. They use the same review settings area they already use for other journal setup.

---

## 6. How Configuration Affects Existing Data

Form text changes apply only to future forms. Already submitted forms keep the text they were saved with.

If an editor removes a feedback response contribution option, existing records that used it stay in the database and continue to appear on the reviewer page and dashboard.

If an editor adds a new feedback response contribution option, it appears for future forms.

Label type changes follow the same rule. Removing a label type stops it appearing in settings and on new reviewer pages, but reviewers who already have a value keep it in the database.

Label history is part of the reviewer page in the dashboard, not the settings. It is already implemented and does not need to be configured.

---

## 7. User Stories

### Managing Editor

As a Managing Editor I want to edit the text of the participation form so that I can use wording that fits our journal.

As a Managing Editor I want to add or remove feedback response contribution options so that the form matches how our reviewers work.

As a Managing Editor I want to manage reviewer label types and values so that I can track what matters to our journal.

As a Managing Editor I want to find all these settings under Workflow > Review so that I do not need to learn a new part of OJS.

### Quality Review Editor

As a Quality Review Editor I want to view and change label values so that I can keep reviewer records up to date.

---

## 8. Roles and Permissions

| Role | Can configure |
|------|---------------|
| Managing Editor | Yes |
| Quality Review Editor | Yes |
| Review Group Leader | No |
| Reviewer | No |

---

## 9. Open Questions for the Client

Should the editor be able to reset the form text back to the default if they make a mistake?

Should there be a preview of the form inside the settings so the editor can see what the changes will look like?

---

Historical records keep the values they were saved with.

Label history remains on the reviewer page in the dashboard and is not part of the settings.
