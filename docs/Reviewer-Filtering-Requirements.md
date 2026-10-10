# Reviewer Filtering Requirements

**Creator by:** Jahan Haidari (BA)

**Team:** 77

**Sprint:** 3

---

## 1. Scope of Task

The client asked for more filtering options on the Reviewers page in the Editor Monitoring Dashboard. Right now the page has a search box and basic filters for overloaded and unresponsive reviewers. This document defines what additional filters editors need.

The filters help editors find specific groups of reviewers quickly. For example, a Managing Editor may want to see everyone who has never completed a review, or everyone with a certain methodology background.

---

## 2. Where the Filters Live

The filters appear in a side panel on the Reviewers page.

Filters work alongside the existing search box and the year selector.

Multiple filters can be applied at the same time.

Each filter can be cleared individually.

Each filter can be cleared individually using the X icon next to it.
The filtered count of reviewers is shown so the editor knows how many results they are looking at.

---

## 3. Label Filters

Editors can filter reviewers by their label values.

### 3.1 Experience Level

Editors can select one or more values from experience level.

The options are the values that currently exist in the label settings.

Only reviewers with at least one of the selected values are shown.

### 3.2 Methodology Background

Editors can select one or more values from methodology background.

The options are the values that currently exist in the label settings.

Only reviewers with at least one of the selected values are shown.

### 3.3 Expertise

Editors can select one or more values from expertise.

Because expertise supports multiple values per reviewer, selecting two values means the reviewer must have both, not either.

### 3.4 Label Filters Are Dynamic

If an editor adds a new label type in the settings, it appears as a filter automatically.

If a label type is removed, its filter disappears.

If a value is removed from a label type, it no longer appears as a filter option, but reviewers who still have it keep it in their record.

---

### 4. Number Filters
Numbers are filtered by setting a value range on a column, the same way native OJS filtering works.

Each activity column in the reviewer table can have a minimum and maximum value set.

Only reviewers whose value falls within the range are shown.

The columns that can have a range set are Invited, Available, Available %, Selected, Selected %, Attended, Attended %, Completed, and Current.

Examples:

Reviewers with no completed reviews
Set the Completed column range to min 0, max 0.

Reviewers with no current reviews
Set the Current column range to min 0, max 0.

Reviewers who were never invited
Set the Invited column range to min 0, max 0.

Reviewers who were never selected
Set the Selected column range to min 0, max 0.

Reviewers who never attended
Set the Attended column range to min 0, max 0.

Reviewers with fewer than 3 completed reviews
Set the Completed column range to min 0, max 2.

---

## 5. Combined Filters

Editors can combine a label filter with a number filter.

For example, an editor can choose experience level = novice and No reviews done = yes to find new reviewers who have not completed any reviews yet.

All applied filters must match for a reviewer to appear.

---

## 6. Sorting With Filters

Sorting continues to work on the filtered list.

If the editor sorts by completed reviews while a filter is active, only the filtered reviewers are sorted.

The sort choice is kept when the year selector changes.

---

### 7. Available in an Open Poll Filter
Editors can filter reviewers by whether they responded as available in a specific poll.

The editor enters a Submission ID.

Only reviewers who responded as available in the poll for that submission are shown.

This helps the Review Group Leader see which reviewers said they were available for a specific review.

This filter applies regardless of the year selector.
---

## 8. User Stories

### Managing Editor

As a Managing Editor I want to filter reviewers by experience level so that I can find reviewers with the right background.

As a Managing Editor I want to filter reviewers by methodology background so that I can match reviewers to different types of submissions.

As a Managing Editor I want to filter reviewers by expertise so that I can find people with specific skills.

As a Managing Editor I want to see reviewers who have never done a review so that I can identify new people to work with.

As a Managing Editor I want to see reviewers who responded to polls but were never selected so that I can make sure they get opportunities.

As a Managing Editor I want to see reviewers who are available with no current work so that I can assign new reviews.

As a Managing Editor I want to combine a label filter with a number filter so that I can find specific groups of reviewers.

As a Managing Editor I want to clear all filters at once so that I can go back to the full list quickly.

### Quality Review Editor

As a Quality Review Editor I want to filter reviewers who have never attended a meeting so that I can follow up with them.

As a Quality Review Editor I want to filter reviewers with low attendance so that I can identify who needs support.

---

## 9. Roles and Permissions

| Role | Can use filters |
|------|-----------------|
| Managing Editor | Yes |
| Quality Review Editor | Yes |
| Review Group Leader | No |
| Reviewer | No |

---

## 10. Assumptions

Filter options are read from the label settings, so they stay in sync automatically.

Filters apply to the current year unless the year selector is set to All time.

Filters combine with the existing search box.

The reviewer count updates live as filters are applied.

The filtered list still supports sorting by any column.

---

### 11. Open Questions for the Client
Should the filter choices persist when the editor navigates away and comes back, or reset each time?

Should the Available in an open poll filter also show reviewers who said they were not available, or only those who said they were available?

---
