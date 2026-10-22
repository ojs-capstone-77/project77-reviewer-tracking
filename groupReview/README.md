# Group Review for OJS 3.4

DEVELOPMENT CANDIDATE ONLY. Install on staging, not production. See the parent
checkpoint's ALIGNMENT.md for verification limits, ARCHITECTURE.md for the
version/directory map, and SOURCE_TRUTH.md for reconciliation with the actual
student hand-in.

This work continues the 2.4.0.1 candidate toward the documented ASRHE Review Group Leader (RGL) and Review Group Member (RGM) workflow, on top of the 2.3.0.0 implementation.

The functional references are the actual student source, supplied Report.pdf
and Test Plan. The documented multi-screen poll workflow is restored; runtime
compliance still requires staging verification on the target OJS build.

## What it does

- Adds a **Group Review** tab to the current External Review round.
- Allows the RGL assigned to that submission to create poll details, then select and invite RGM users on a separate screen.
- Lists active users enrolled in the journal's RGM user group.
- Emails poll invitations to selected RGM users.
- Records availability and displays the response matrix to the RGL.
- Allows the RGL to select a meeting time, choose only available RGM users on a separate screen, and review/edit the selected and non-selected email bodies before sending.
- Adds selected RGM users to the submission as Review-stage participants under the RGM group.
- Creates one **Feedback Draft** Review-stage discussion containing the meeting details and including the RGL and selected RGM users.
- Creates OJS new-discussion task notifications for selected RGM users and restores the student poll-created/poll-closing task types.
- Emails selected and non-selected RGM users and can send one poll reminder to nonrespondents.
- Provides a plugin-owned **My Group Review Polls** page.

It does **not** create ordinary OJS reviewer assignments, reviewer file grants or reviewer task records.

## Reviewer monitoring

Journal editors and managers (OJS's Manager permission level) can open **Group Review**
from the backend sidebar, then switch between **Overview** and **Reviewers**, or
open a reviewer. RGLs see **Reviewers** only, listing the reviewers who are
available in the open polls they lead, without links to reviewer pages.

The pages follow [Reviewer Monitoring Definitions](../docs/Reviewer-Monitoring-Definitions.md).
The `year` URL parameter accepts a year in the selector or `all`; missing or invalid
values use the current year. Reviewers defaults to `sort=completed&dir=desc`.
Column links reverse the current direction; another numeric column starts highest
first, and names start alphabetically. The selected year is preserved across tabs,
opening a reviewer, Back, and label saves.

Edit Labels requires POST and a valid CSRF token. An unknown reviewer cannot be
updated; malformed or invalid label values are rejected without changing labels or
their history. A failed save returns to the reviewer page with an error notice.

### Reviewer grid filtering

The Reviewers header provides name search, the year selector and a **Columns**
menu. Name stays visible; each of the nine count/percentage columns can be shown
or hidden independently, and the counts show by default. The **Filter** side panel contains label choices,
inclusive range sliders and **Available in an open poll** by Submission ID.
Filter choices apply when **Apply** is pressed, so several can be set before the
page reloads. **Reset** clears all filters and keeps the selected year, sort and
column configuration. Closing the panel keeps the applied filters.

Filters combine with AND. A single-choice label type, such as experience level or
methodology, accepts any selected value; a multiple-choice type, such as expertise,
requires every selected value. Options come from the label types and values
editors manage in **Settings > Workflow > Review > Reviewer labels**.
Numeric ranges use nonnegative whole numbers. Blank bounds impose no limit,
equal bounds are valid (for example Completed 0-0), and percentage bounds are
limited to 0-100. A percentage with no denominator is not applicable, not zero,
so it does not match an active percentage range.

The poll filter only matches invited reviewers with recorded availability in
currently open, unexpired polls for that submission in the current journal.
It ignores the year selector; the displayed statistics still use the selected
year. Unknown submissions and submissions without such a poll return no matches.
Disabled accounts remain excluded. Filtering does not expand dashboard access.

The result count shows matching reviewers out of the unfiltered reviewer total.
Filters, sorting and columns are kept in the URL across sorting, year changes,
tabs, opening a reviewer, Back and label saves, and are not saved to the account.
Invalid URL filter shapes/ranges display an error rather than silently showing an
unfiltered grid.

```bash
php plugins/generic/groupReview/tests/ReviewerGridTest.php <journal path>
```

This transactional integration script checks parsing, combined filters,
inclusive bounds, zero/null percentages, visibility, URL state, page rendering
and journal-scoped poll availability. Its temporary journal and fixtures are
rolled back. The page is built from OJS's own list panel, filter and table components.

Run the integration checks inside an installed OJS 3.4 environment:

```bash
php plugins/generic/groupReview/tests/MonitoringTest.php <journal path>
```

The checks use the existing fixture generator inside a database transaction, cover
page rendering, access policies, URL defaults, data contracts, statistics and label
saves, and roll back test records afterward. Use a development or staging journal.

## Configurable participation forms

From a submission's **Group Review** tab, an assigned RGL opens **Reviewer
participation** and creates the current review round's form, which they then
lead. Its reviewers are the RGMs assigned to the submission at that time; journal
editors can open and edit it. **No meeting** hides the meeting
fields, which are then neither required nor counted. Submitting can include a
comment, kept in the form's **History**; the Activity Log records who edited or
submitted and when. **Cancel Form** keeps the answers but stops them counting, and
the round can then have a new form.

Editors and managers can open **Settings > Workflow > Review > Participation
form**. Check a field to make it required to submit the form; a
draft can always be saved. Unchecked fields stay visible and optional. The four
fields are meeting attendance, meeting comments, feedback response and feedback
response comments. Reviewer names, per-reviewer Other comments and General
comments for editors always remain on the form. These comments can be left blank.

Editors can add, rename or remove feedback response options. At least one option
must remain; names are unique ignoring case and have a 150-character maximum.
Editors can also change each field's name and description. Settings are
journal-scoped, require editor permissions and are saved with POST and CSRF
protection. Attendance choices are fixed.

Configuration is snapshotted at a form's first successful save, including draft
saves. Subsequent journal changes apply only to unsaved future forms. Saved forms
keep their original fields, requirements, option identities and names, including
when edited or resubmitted. History and answer counts continue to display
removed or renamed feedback response options using their historical names.
An unsaved form opened before a settings change must be reloaded before saving;
other validation errors keep entered reviewer text on screen and do not save
partial changes.

```bash
php plugins/generic/groupReview/tests/ParticipationConfigurationTest.php <journal path>
```

This integration script checks journal isolation, required-field enforcement,
option management, atomic validation, configuration snapshots, history, editor
permissions, CSRF and actual template rendering. Fixtures and settings are rolled
back.

## Reference letters

**Generate Reference** on a reviewer page drafts a letter for the selected year from
the template in **Settings > Workflow > Review > Reviewer Reference**, where a
letterhead image can also be uploaded. The draft can be edited and given a file
name, then opened as a PDF with **Preview** or saved with **Download**.

## Participation and statistics tests

```bash
php plugins/generic/groupReview/tests/ParticipationServiceTest.php
php plugins/generic/groupReview/tests/ParticipationFormTest.php <journal path>
php plugins/generic/groupReview/tests/ReviewerStatsServiceTest.php <journal path>
```

The form and statistics integration scripts copy the supplied journal's
configuration into a temporary journal, freshly seed the existing test data,
and roll back the entire transaction on success or failure. No users or
submissions from the source journal are copied into that journal. As with the
fixture generator, run on development/staging from mid-February onwards, when
there is enough current-year history to generate the fixtures.

Statistics expectations come from fixture inputs, not the service's output,
and include hand-counted checks for the fixed cases. Every returned value is
compared for the current year and all time: reviewer rows and single-row lookup,
Overview including labels, years, attendance/contribution counts and history.
The script reports each differing value with its full path, expected value and
actual value, then finishes with an `OVERALL: PASS` or `OVERALL: FAIL` summary.
Additional cases cover an empty journal, overdue open polls, timezone year
boundaries, recommendations and reverted declines.

To verify that changing an expected count is caught:

```bash
php plugins/generic/groupReview/tests/ReviewerStatsServiceTest.php <journal path> --mutate-expected
```

This command **must exit with code 1**, reporting
`currentYear.reviewers.test_rgm_21.invited: expected 5, got 4`.
The temporary records are still rolled back. The flag changes only an in-memory
expectation; it does not modify the fixture generator or saved counts.

## Compatibility

- OJS **3.4.x only**
- Not compatible with OJS 3.2, OJS 3.3, OJS 3.5, OMP or OPS
- The plugin refuses activation outside OJS 3.4.x

No OJS or PKP source file is patched, replaced or copied. The core template replacement and bundled copies of jQuery and Bootstrap from the student implementation remain removed. The student notification callback is present, but its report explicitly requires a core OJS URL-hook patch for clickable poll tasks; see Known limits.

## Required OJS user groups

The project documentation requires these journal-scoped groups to be created manually:

| Group | Abbreviation | Documented role basis |
| --- | --- | --- |
| Review Group Leader | `RGL` | Section Editor |
| Review Group Member | `RGM` | Section Editor |

The abbreviations must be exactly `RGL` and `RGM` in the journal's current locale or English. Version 2.4 does not silently fall back to ordinary Reviewers when either group is missing.

Because Section Editor is a broad OJS permission level, journal managers must only enrol trusted users in these groups. The plugin narrows its own operations by checking the exact group, journal, submission, stage, poll and user on every protected request. Selected RGM stage assignments are created with recommend-only enabled and metadata editing disabled.

## Setup

1. Back up the OJS database and `plugins/generic/groupReview` directory.
2. Confirm the `RGL` and `RGM` groups exist in the target journal.
3. Enrol the intended people in those groups.
4. Assign the selected RGL to the submission in External Review under the RGL group.
5. Upload this archive with the plugin's **Upgrade** action and keep the plugin enabled.
6. Open **Settings > Workflow > Review**, enable group review meetings and save the defaults.
7. Enable Acron and reload scheduled tasks if automatic reminders are required.
8. Open the current External Review round as its assigned RGL and select **Group Review**.

Uploading plugin code and running migrations affect the shared OJS installation. Perform the first upgrade and workflow test on staging with outbound mail redirected to controlled addresses.

## Authorization model

- A leader must belong to `RGL` and be assigned to that exact submission under the RGL group in External Review.
- An invitee must be active, belong to the journal's `RGM` group and have a membership record for that exact poll.
- Submission, review-round, slot, member and poll identifiers are checked within the current journal.
- Create, update, availability, finalize, resend and cancel actions require POST and a valid CSRF token.
- Finalization accepts only invited RGM users who marked themselves available for the selected slot.

## Upgrade notes from 2.4.0.1

- Back up the database, then run the plugin's **Upgrade** action to 2.5.0.0 before using the new pages
- The migration adds tables for participation forms and reviewer labels. Existing poll tables and settings are unchanged.

## Upgrade notes from 2.3.0.0

- The existing normalized plugin tables and migrated settings are retained.
- New polls use RGL/RGM authorization and participant assignment.
- Existing open polls created under 2.3 should be cancelled and recreated after confirming the stored members belong to RGM.
- Standard OJS review assignments created by earlier 2.x testing are editorial records and are not removed automatically. Review and remove test assignments manually in staging if appropriate.
- The upgrade migration is idempotent and refreshes the default email templates while preserving journal-specific overrides.

## Dates and reminders

Date/time inputs are interpreted in the poll's IANA time zone and stored in UTC. The plugin validates future deadlines, unique meeting times, times after the deadline, minimum lead time, meeting duration, reminder lead time, URL format and member-count limits.

The scheduled task is attached through `AcronPlugin::parseCronTab`. It expires overdue polls and sends at most one reminder to each nonrespondent. If Acron is disabled, reminders are not sent; overdue polls still expire when accessed.

## Staging acceptance checks

- A current RGL assigned to the submission can manage its poll, including a poll created by another RGL. Removed or disabled users must be denied.
- The invitation list contains RGM users and excludes ordinary OJS Reviewers who are not in RGM.
- An invited RGM can record and revise availability; an uninvited RGM cannot access the poll.
- Finalization accepts only invited RGM users available at the chosen time.
- Selected users appear in the submission's Participants list as Review Group Members, not in the OJS Reviewers panel.
- No new row is created in `review_assignments` by finalization.
- The **Feedback Draft** discussion contains the RGL and selected RGM users and shows the meeting schedule.
- Each selected RGM receives a supported new-discussion task notification that opens the submission workflow.
- Selected and non-selected messages go only to controlled staging addresses.
- A new review round can have its own poll and closed/cancelled rounds do not expose the active poll controls.

## Known limits

- English locale only.
- The plugin coordinates the in-scope group review meeting workflow; it does not implement the Test Plan's out-of-scope screening, leader-selection or author-progress functions.
- Poll invitations/reminders create the student task types and send linked email. The task labels work through OJS's supported message hook. Direct poll links remain available through email and the plugin dashboard without requiring an OJS core modification.
- Editing an open poll replaces its slots, clears availability and reinvites all current members.
- The broad permissions inherited from the documented Section Editor-based RGL/RGM groups remain an operational risk. A future OJS-integrated role model should reduce those permissions without changing the visible RGL/RGM workflow.

## Rollback

Keep a matched database backup and copy of the previous plugin directory. If the staging upgrade fails, restore both together and clear OJS caches. Disabling the plugin does not remove stage participants, discussions, notifications or sent mail created by successful tests.
