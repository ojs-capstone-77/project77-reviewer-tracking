# Reviewer monitoring: definitions and template data

The standard for every count on the editor dashboard, and the data passed to its templates. Implement exactly as written.

## Definitions

**Reviewer:** a user in the RGM or RGL user group, or anyone invited to or leading a past poll (even if they've since lost the role). Disabled accounts are left out everywhere.

**Polls:** a poll is a row in `group_review_sessions`. Only closed polls count: finalised and expired. Draft, open and cancelled polls are ignored everywhere, so a reviewer's counts only change once a poll closes. A poll still marked open after its response deadline counts as expired (the plugin only updates its status when someone next views it). A **review group** is a finalised poll.

**Decided:** a review group is decided when the latest editorial decision on its review round (in OJS's `edit_decisions`) is accept, request revisions, resubmit for review, decline, new review round or cancel review round. Recommendations don't count, and a reverted decline makes the round undecided again. A review group whose latest such decision is **cancel review round** is **cancelled**: it's left out of Completed and Current, but its invitations, availability, selection, leading and attendance still count.

**Year:** filters on the poll's meeting date by calendar year: the chosen meeting time for finalised polls, otherwise the last proposed meeting time, in the poll's own timezone. "All time" removes the filter. Labels, Reviewer since and Last activity are never filtered. Current ignores the year.

### Per-reviewer counts (Reviewers table, reviewer page)

These count **polls**, so a reviewer invited 8 times has Invited 8.

| Count | Meaning |
|---|---|
| Invited | Polls they were invited to |
| Available | Polls where they gave at least one time |
| Not available | Polls where they responded with no times |
| No response | Polls where they didn't respond |
| Selected | Review groups they were selected for as a member |
| Not selected | Available − Selected |
| Times led | Review groups they led |
| Completed | Review groups selected for or led, decided and not cancelled |
| Current | Review groups selected for or led, not yet decided (cancelled ones are left out) |
| Attended | Submitted forms recording them as Attended |
| Attendance recorded | Submitted forms recording Attended or either "Did not attend" option (N.A., Other and Not recorded left out) |
| Reviewer since | First invitation or review group led |
| Last activity | Most recent invitation, response or review group |

Invited = Available + Not available + No response. Available = Selected + Not selected.

**Percentages:** Attended ÷ Attendance recorded; Available ÷ Invited; Selected ÷ Available. Whole numbers; none when the base is 0.

### Reviewer page only

| Item | Meaning |
|---|---|
| Meeting attendance counts | For each attendance answer, how many submitted forms in the year record it for them. Not recorded is left out, and answers never given aren't shown. |
| Contributions counts | For each feedback contribution option, how many submitted forms in the year record it for them. Options never given aren't shown. |
| Review group history | Review groups in the year they were selected for as a member or led, newest first. Member entries show their answered form fields; leader entries show the form's general comments. |

### Overview counts

**Live** (the top section; not affected by the year):

| Count | Meaning |
|---|---|
| Total | Reviewers currently holding the RGM or RGL role |
| Review Group Leader | Reviewers currently holding the RGL role |
| Current review groups | Review groups not yet decided, in any year (cancelled ones are left out) |

**Selected year** (the Activity section). The reviewer counts count **reviewers**, each once:

| Count | Meaning |
|---|---|
| Invited | Invited to at least one poll, or led at least one review group |
| Participated | Selected for or led at least one review group |
| Not selected | Gave at least one time, but never selected and led no review group |
| Inactive | Invited, never gave a time, and led no review group |
| Not invited | Currently holds the RGM or RGL role, but no invitations and led no review group |
| Review groups | Review groups with their meeting in the year |
| Completed | Of those, decided and not cancelled |

Invited = Participated + Not selected + Inactive. Invited + Not invited can be more than the live Total, because someone who has since lost the role still counts as Invited in years they took part.

**Labels:**

| Count | Meaning |
|---|---|
| Label Total | Reviewers currently holding that label value (live) |
| Label Active | Of those, gave at least one time or led a review group in the selected year |

## Labels

| Type key | Name | Values | Multiple |
|---|---|---|---|
| `experience_level` | Experience level | `novice`, `intermediate`, `experienced` | No |
| `methodology` | Methodology background | `quantitative`, `qualitative`, `mixed_methods` | No |
| `expertise` | Expertise | `education`, `statistics` | Yes |

## Template variables

Passed by `GroupReviewHandler`; the templates are built against these names.

### All three pages

| Variable | Contains |
|---|---|
| `year` | The selected year, or `'all'` |
| `yearOptions` | Year selector options: list of `value`, `label` |
| `overviewUrl` | Link to the Overview page |
| `reviewersUrl` | Link to the Reviewers table |

### `reviewers.tpl` (Reviewers table)

| Variable | Contains |
|---|---|
| `sort` | The sorted column |
| `dir` | `asc` or `desc` |
| `sortUrls` | Column → link that sorts by it (reverses if already sorted) |
| `reviewers` | One row per reviewer (fields below) |

Each row in `reviewers`:

| Field | Contains |
|---|---|
| `userId`, `name` | The reviewer |
| `labelsText` | Their labels as one line, for example "Intermediate, Qualitative" |
| `completed`, `current` | Counts |
| `attended`, `attendedPercent` | Count, and percentage or null |
| `invited` | Count |
| `available`, `availablePercent` | Count, and percentage or null |
| `selected`, `selectedPercent` | Count, and percentage or null |
| `url` | Link to their reviewer page |

### `reviewer.tpl` (reviewer page)

| Variable | Contains |
|---|---|
| `reviewer` | `userId`, `name`, `reviewerSince`, `lastActivity`, and `labels` (list of `name`, `valuesText`) |
| `stats` | Their counts: the same fields as a row from `getReviewerRows()` |
| `attendanceCounts` | Meeting attendance: list of `label`, `count` |
| `contributionCounts` | Contributions: list of `label`, `count` |
| `history` | Review groups (fields below) |
| `labelOptions` | Per label type: `name`, `multiple`, and `options` (list of `value`, `label`) |
| `labelValues` | Per label type: their current values |
| `labelHistory` | Past label changes: list of `changedAt`, `changedByName`, `summary`, `note` |
| `labelSaveError` | True if the last save failed |
| `saveLabelsUrl` | Where the Edit Labels form posts |
| `backUrl` | Link back to the Reviewers table |

Each entry in `history`:

| Field | Contains |
|---|---|
| `submissionId`, `round`, `date` | Which review group, and its meeting date |
| `leaderName`, `isLeader` | Who led it, and whether it was this reviewer |
| `answers` | Their form answers: list of `section`, `label`, `value` (answered fields only) |
| `generalComments` | The form's general comments (leader entries only) |

### `overview.tpl` (Overview)

| Variable | Contains |
|---|---|
| `live` | `total`, `leaders`, `currentGroups` |
| `activity` | `invited`, `participated`, `notSelected`, `inactive`, `notInvited`, `reviewGroups`, `completed` |
| `labels` | Per label type: `name`, and `values` (list of `label`, `total`, `active`), with "Not set" last |

`live` and `activity` come straight from `ReviewerStatsService::getOverview()`. Its label counts are keyed by label type and value, so the page operation turns them into the `labels` list above, adding the display names.

## Reviewer grid filtering

Filtering is applied by `ReviewerGridService` after the handler computes the
table percentages, without changing the statistics definitions above.

| URL setting | Meaning |
|---|---|
| `search` | Case-insensitive reviewer-name substring, at most 100 characters |
| `labels[type][]` | Supported label values; OR within single-value types, AND within multi-value types |
| `ranges[column][min]`, `ranges[column][max]` | Inclusive nonnegative integer bounds; blank means unlimited; percentages are bounded by 100 |
| `columns[]` | Visible numeric columns; missing means all, an empty value means no numeric columns |
| `availableSubmissionId` | Positive Submission ID; only reviewers available in an open, unexpired poll for this journal, regardless of year |

Supported numeric columns are `invited`, `available`, `availablePercent`,
`selected`, `selectedPercent`, `attended`, `attendedPercent`, `completed` and
`current`. An undefined percentage never matches an active percentage range.
Every active filter must match. The reviewer name and View link stay visible.

The handler additionally supplies `gridFilters` (normalized settings and errors),
`gridColumns` (column keys to translation keys), `labelFilters` (label options),
`totalReviewers` (unfiltered count), `resetFiltersUrl`, and `gridFilterParams`.
The existing `reviewers` variable contains only matching rows, sorted as requested.
Overview counts are never filtered by these additional grid settings.
