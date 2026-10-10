# Reference Generator Requirements

**Creator by:** Jahan Haidari (BA)

**Team:** 77

**Sprint:** 3

---

## 1. Scope of the Task

Editors need to generate a reference document for a reviewer based on the data already stored in the dashboard. The reference acknowledges the reviewer's contributions and can be shared with them at the end of the year.

The example reference letter received from the client is the basis for what the document contains. This document maps each part of the letter to the data source on the reviewer page.

---

## 2. Where the Button Lives

A button appears on the reviewer page in the Editor Monitoring Dashboard.

The button is labelled Generate Reference.

Only Managing Editors and Quality Review Editors can see and use the button.

Clicking the button generates a reference document for that reviewer.

---

## 3. What the Letter Contains

The reference letter has a narrative structure. It is not just a list of numbers. It tells the reviewer's story for a chosen year.

| Section | What it says | Data source |
|---------|--------------|-------------|
| Date | The date the letter is generated | System date |
| Opening | To whom it may concern | Static text |
| Introduction | Reviewer name and the year they joined the journal | reviewer.name and reviewer.reviewerSince |
| Review count | How many reviews the reviewer completed in the chosen year | stats.completed filtered by year |
| Leadership context | How many reviewers were invited to lead groups that year | Calculated from review group data |
| Leadership count | How many reviews the reviewer led in the chosen year | stats.timesLed filtered by year |
| Engagement list | A bullet list of what the reviewer did during the year | Built from contribution types and shaping feedback records |
| Character statement | A closing paragraph about the reviewer's contribution to the community | Template text, optionally edited by the editor |
| Sign off | Editor names | Journal settings |

---

## 4. How the Engagement List Is Built

The bullet list in the example letter describes specific contributions. Each bullet maps to data the dashboard already records.

| Example bullet | Data source |
|----------------|-------------|
| Preparing review notes | shaping feedback option: uploaded_notes |
| Attending online review group meetings and leading three | attendance count and times led |
| Presenting review notes and contributing to review conversations | contribution type: discussion |
| Drafting feedback notes to authors | shaping feedback option: created_draft |
| Reviewing feedback notes to authors | shaping feedback option: commented_on_draft |
| Effectively managing the time during the review meeting | contribution type: other or free text |
| Active engagement with a community of practice around peer review | contribution type: discussion or other |

Only the bullets that match the reviewer's actual records for the chosen year are included. Bullets with no supporting data are left out.

---

## 5. Time Range of Data

The reference letter uses data for a chosen year.

By default the letter covers the current year.

The editor can choose a different year before generating the letter.

The All time option is available for reviewers who have served across multiple years.

Reviewer since and last activity are always shown in full, not filtered by year.

The introduction uses the year the reviewer joined, regardless of the chosen year.

---

## 6. Tone and Format

The letter is written in a formal, appreciative tone.

The letter is generated as a document the editor can download.

The default format is PDF.

A Word format option is available for editors who want to make further changes.

The editor can edit the character statement before generating if they want.

The document includes the journal name and the editor names from journal settings.

---

## 7. User Stories

### Managing Editor

As a Managing Editor I want to generate a reference letter for a reviewer from their dashboard page so that I can recognise their contributions without writing it from scratch.

As a Managing Editor I want to choose which year the reference covers so that I can generate letters for the correct period.

As a Managing Editor I want to download the reference as a PDF so that I can send it to the reviewer.

As a Managing Editor I want to edit the closing statement before generating so that I can personalise it for each reviewer.

As a Managing Editor I want the letter to use the reviewer data already stored in the dashboard so that I do not need to enter anything manually.

### Quality Review Editor

As a Quality Review Editor I want to generate a reference letter for a reviewer so that I can help with the end of year acknowledgements.

As a Quality Review Editor I want to preview the letter before downloading so that I can check it looks correct.

### Reviewer

As a Reviewer I want to receive a reference letter that accurately reflects my contributions so that I can use it for my professional records.

---

## 8. Roles and Permissions

| Role | Can generate | Can preview | Can download | Can edit closing statement |
|------|--------------|-------------|--------------|----------------------------|
| Managing Editor | Yes | Yes | Yes | Yes |
| Quality Review Editor | Yes | Yes | Yes | Yes |
| Review Group Leader | No | No | No | No |
| Reviewer | No | No | No | No |

---

## 9. Assumptions

The reference letter is only generated for reviewers who have completed at least one review.

The letter is based on submitted participation forms and review group records.

Historical values are used even if a field or label has since been removed from the settings.

The editor can regenerate the letter at any time.

The reviewer receives the letter outside the plugin. The plugin does not send it automatically.

The same letter can be generated for a reviewer for different years.

---

## 10. Open Questions for the Client

Should the letter be signed by specific editors, or should the editor choose who signs before generating?

Should the bullet list be editable by the editor, or always generated from data?

Should development areas be included, or only strengths and contributions?

Should the letter include a list of specific submissions the reviewer worked on, or only counts?

Should the letter be available in more than one format, for example PDF and Word?

Should the letter use British or American spelling? The example uses British spelling for some words.

---