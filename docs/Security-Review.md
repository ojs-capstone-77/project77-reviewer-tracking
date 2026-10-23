# Group Review plugin security review

Task: **[SECURITY] - codebase security review and fixes (60)**

Reviewed: 22 October 2026, against upstream commit `85f8291` and the changes accompanying this report.

## Scope and outcome

Reviewed plugin page routing, authorization policies, journal scoping, SQL queries,
templates, participation/configuration validation, labels, reference letters,
letterhead uploads, email substitution, scheduled reminders, CSRF protection and
access-denial logging. The deployment threat model includes an authenticated user
with privileges in one journal trying to affect a different journal on the same
OJS installation.

Three confirmed findings were fixed. No user-controlled SQL interpolation was
identified in the reviewed queries. Additional request-shape, HTML-substitution
and PDF-rendering safeguards were added. This is a code review with targeted
regression checks, not certification that the entire OJS installation is secure.

## Findings and fixes

| Severity | Location | Finding | Resolution |
| --- | --- | --- | --- |
| High | `groupReview/GroupReviewPlugin.php`, letterhead context hooks | An editor could submit a forged `uploadName`, then remove that letterhead. Core file removal joins the supplied name to the journal public directory without enforcing containment, allowing traversal to another writable file or journal. | Only the exact plugin-owned PNG/JPEG/GIF names are accepted. Metadata-only changes must match the existing filename and cannot overwrite server-generated metadata. Deletion revalidates the stored name before touching files. |
| High | `groupReview/GroupReviewPlugin.php`, letterhead upload | Ownership of a temporary upload was checked, but its contents and MIME type were not constrained. Core's generic move helper also accepts HTML, so an editor could publish active content on the shared OJS origin. | Validate ownership again at save time, require matching PNG/JPEG/GIF image bytes and MIME type, enforce 5 MB / 20 million pixel / 10,000 pixel-per-side limits, and publish under a server-generated image filename. Reject invalid data explicitly instead of retaining a success-shaped value. |
| Low | `groupReview/classes/GroupReviewService.php`, `logAccessDenied()` | Access-denial messages containing internal user, submission and form IDs were written below the web-accessible plugin directory. | Send messages to PHP's server-managed error log and remove embedded line breaks. No new public log file is created. Previously deployed files require administrator cleanup as described below. |

Reference-letter scalar substitutions are now escaped before insertion into HTML,
and composed content is purified using OJS's HTML sanitizer. Internally generated
contribution-list markup remains supported. PDF rendering explicitly disables
remote fetching, PHP and JavaScript, and restricts local resources to the plugin
templates directory. Valid image upload, removal, metadata editing, data-URI
embedding and actual PDF generation are covered by regression tests.

## SQL injection checks

Plugin persistence uses Laravel's query builder and bound values. The reviewed
`LOWER(...)` raw predicates in `ReviewerLabelService` use `?` bindings, not
concatenated input. Reviewers-table sort fields and directions are allowlisted;
filter bounds are validated integers. Journal IDs are supplied to reads and
writes, including participation forms and label definitions.

An integration check stores SQL-shaped text as a literal label name, verifies
that another journal is unchanged, and checks that SQL-shaped search text cannot
broaden the reviewers result set. Malformed identifiers are rejected before
authorization policies can cast them to integers. No SQL-injection exploit was
confirmed; these are verification and defense-in-depth changes, not a claim that
an SQL-injection vulnerability was found.

## Submitted input and XSS checks

| Input surface | Server-side control |
| --- | --- |
| Poll, submission, review-round, form, slot and reviewer IDs | Positive integers, checked before authorization; service reads scoped to the current journal. |
| Member, slot and selected-user lists | Arrays of positive integers, at most 100 entries; eligibility, invitation and slot membership checked by the relevant workflow. |
| Poll scheduling | Existing timezone, date, duration, reminder, slot-count, uniqueness and timing validation retained. Meeting URLs restricted to HTTP/HTTPS. |
| Participation answers and comments | Existing reviewer membership, attendance/feedback allowlists, text types and 5,000-character bounds retained. Submission checks required fields; draft saves keep their existing behavior. |
| Participation settings | Existing required-field, option-key, option-label and configuration-snapshot validation retained. Submitted forms retain their historical configuration. |
| Reviewer labels | Known journal-specific label definitions, scalar/list shapes, key and text lengths checked. Label definition limits match the 255-character database columns. |
| Grid search, labels, ranges, columns, year and ordering | Existing bounded search, known label/column keys and numerical-range validation retained. Invalid year/order values keep their documented defaults. |
| Reference content and filenames | Bounded scalar input, OJS HTML purification, escaped substitutions and existing download-filename cleanup. PDF sandbox restrictions described above. |
| Letterhead metadata and uploads | File ownership, immutable stored filename, raster type/content and image resource limits. |
| Mail/discussion text | Existing escaped mailable variables and escaped generated discussion text reviewed. Rich email-template functionality remains under OJS mail handling; actual external email delivery was not exercised by the security script. |

Normal Smarty output remains escaped. Intentionally formatted reference HTML is
sanitized before rendering. Invalid request shapes are denied and logged without
logging the supplied values.

## Endpoint access and CSRF

All page operations additionally require the current journal, an authenticated
user and OJS's context access policy. Core handles login sessions and disabled
accounts; this review does not replace or reimplement those controls.

| Operations | Plugin authorization |
| --- | --- |
| `index` | Context access, listing the user's own polls. |
| `create`, `store`, `invite`, `storeInvitations`, `view`, `edit`, `update`, `selectMeetingMembers`, `reviewMessages`, `finalize`, `cancel`, `resend` | Assigned RGL for the relevant submission. Poll IDs resolve only within the current journal. |
| `availability`, `saveAvailability` | Currently eligible, invited RGM in the journal. |
| `participation`, `participationForm`, `saveParticipationForm`, `participationRecorded`, `storeParticipationForm`, `cancelParticipationForm` | Journal editor, or the assigned RGL who leads that specific form/submission. |
| `reviewers` | Journal editor or journal RGL. The RGL's intentionally restricted available-reviewer view is retained; this is not general monitoring access. |
| `overview`, `reviewer`, `saveLabels`, `reference`, `generateReferencePdf`, `saveParticipationSettings`, `addLabelType`, `renameLabelType`, `removeLabelType`, `addLabelValue`, `removeLabelValue` | Journal editor/manager only. |
| Journal settings and reference-template context API | OJS's journal-management API authorization, plus the plugin's letterhead validation/save hooks. |

Every page mutation requires POST and a valid CSRF token, including PDF generation
and intermediate meeting-member/message actions. The security script inventories
every public plugin page method, checks its role assignment, exercises all four
plugin policies, denies anonymous and cross-journal requests, and tests CSRF
rejection on every page mutation. These are installed-OJS integration checks, not
an end-to-end browser session or penetration test of core OJS APIs.

## Regression evidence

PHP 8.2.28, OJS 3.4.0-10 and MariaDB 11.8 were used. The existing development
database had the older participation schema. Final integration runs therefore
used a separate disposable MariaDB container with a database copy and the current
plugin schema. A session-only `/tmp/security-isolated.php` wrapper selected that
connection and ran each unmodified test script; it is not part of the plugin.
After validation, the disposable database was removed and the pre-existing local
plugin runtime restored. The original database retained its one journal and
three users, with no temporary test journals remaining; the journal returned
HTTP 200. The reviewed changes are not a production deployment.

| Executed PHP command inside `ojsdev_app` | Result |
| --- | --- |
| `php plugins/generic/groupReview/tests/SecurityValidationTest.php` | Passed 119 checks. |
| `php /tmp/security-isolated.php SecurityTest.php` | Passed 170 checks, including actual safe-image upload and PDF generation. |
| `php plugins/generic/groupReview/tests/ParticipationServiceTest.php` | Passed 14 unit cases. |
| `php /tmp/security-isolated.php ParticipationConfigurationTest.php` | Passed 81 checks. |
| `php /tmp/security-isolated.php ParticipationFormTest.php` | Passed 51 checks. |
| `php /tmp/security-isolated.php MonitoringTest.php` | Passed 769 checks. |
| `php /tmp/security-isolated.php ReviewerStatsServiceTest.php` | Passed 6,720 checks for current year and all time. |
| `php /tmp/security-isolated.php ReviewerGridTest.php` | Passed 96 checks. |
| `php /tmp/group-review-composer.phar audit --working-dir=/var/www/html/plugins/generic/groupReview --locked --no-interaction --format=plain` | Passed: no published vulnerability advisories for locked plugin dependencies. |
| `php /tmp/group-review-phpstan.phar analyse --no-progress --memory-limit=1G --error-format=table` | Passed repository PHPStan level 5 rules using installed OJS source/stubs. |

PHP syntax checks passed for all non-vendor plugin PHP files. PHP-CS-Fixer
3.95.24 reported zero changes needed in the five changed production PHP files,
using the repository rules; `git diff --check` also passed.

`SecurityValidationTest.php` is also included in CI. It requires PHP with mbstring,
but does not require OJS or a database. To rerun the integration script on an
installed **disposable development** OJS instance with the current plugin schema:

```sh
docker exec -e XDEBUG_MODE=off ojsdev_app php /var/www/html/plugins/generic/groupReview/tests/SecurityTest.php test
```

Install the plugin's locked Composer dependencies first so the real PDF check can
run. The script uses temporary journal fixtures and a database transaction, rolls
back its fixtures, and removes its exact temporary image/log files. Do not run
fixture-generation scripts on production or alongside another test-data run:
the shared generator reserves the `test_*` usernames. Syntax, formatting and diff
checks must also pass before merging.

## Deployment precautions and remaining scope

1. Back up the database, private files and public journal files before upgrading.
   Validate the existing installation's schema against the current plugin version;
   the older development participation schema was not upgraded by this security
   work. Test installation/upgrade on a staging copy before OAPA deployment.
2. Privately archive and remove any legacy
   `plugins/generic/groupReview/logs/participation-access.log` from earlier
   installations. No such file was present in the inspected development runtime.
   Keep PHP logs outside the document root and restrict their readers.
3. Inspect existing `groupReviewLetterhead` settings/public files before deploying.
   Unexpected filenames or active files are now rejected, not silently migrated
   or deleted. An administrator must remove any unsafe legacy file and clear the
   corresponding setting before uploading a valid raster image.
4. Keep OJS and the plugin's Composer dependencies updated. The plugin dependency
   audit is a point-in-time advisory check, not an audit of OJS, other plugins,
   infrastructure, backups or the host OS.
5. Use HTTPS, protect private OJS files, disable production error display, prevent
   script execution in public upload directories, and configure appropriate
   content types and `X-Content-Type-Options: nosniff` at the web server. Those
   deployment controls are outside this plugin repository.
