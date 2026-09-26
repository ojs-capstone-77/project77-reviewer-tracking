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
- `groupReview/getParticipation` accepts a GET request with a required positive `submissionId` and optional positive `reviewerUserId`. Journal managers and members of the journal's `QRE` user group can read all records for that submission; assigned RGLs can read records for their submission; other journal users with access to this operation can only read their own records. Configure the Quality Review Editor user group with the `QRE` abbreviation and Section Editor permission level to enable its read access.
- The read endpoint returns an OJS JSONMessage with `status`, `code` and `records` (an array, empty when there are no matches). It returns `invalid_request`, `not_found`, `forbidden` or `read_failed` on errors. The participation mock-up does not yet call this endpoint.
- Submission, review-round, slot, member and poll identifiers are checked within the current journal.
- Create, update, availability, finalize, resend and cancel actions require POST and a valid CSRF token.
- Finalization accepts only invited RGM users who marked themselves available for the selected slot.

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
