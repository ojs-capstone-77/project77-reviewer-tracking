<?php

/**
 * @file tests/testData.php
 *
 * Creates or removes group review test data in a journal, for local
 * development and testing. Every run on the same day creates identical data; dates are
 * relative to today.
 *
 * Run:
 * docker exec -it -u 100:101 -e XDEBUG_MODE=off ojsdev_app php /var/www/html/plugins/generic/groupReview/tests/testData.php <journal path> seed
 * docker exec -it -u 100:101 -e XDEBUG_MODE=off ojsdev_app php /var/www/html/plugins/generic/groupReview/tests/testData.php <journal path> clear
 *
 * The journal path is the part of the journal's URL after "index.php/". For example,
 * for http://localhost:8080/index.php/test it is "test":
 * docker exec -it -u 100:101 -e XDEBUG_MODE=off ojsdev_app php /var/www/html/plugins/generic/groupReview/tests/testData.php test seed
 *
 * "seed" removes any existing test data first. Test accounts use the password "password".
 *
 * Roles
 *   Creates the Review Group Leader (RGL) and Review Group Member (RGM) roles if missing.
 *
 * Users (named by highest role: editor > RGL > RGM)
 *   test_editor_01-02  Journal editor only (01 is the assigned editor on every test submission
 *                      except 005; 02 is never assigned)
 *   test_editor_03     Journal editor + RGL + RGM
 *   test_editor_04     Journal editor + RGM
 *   test_rgl_01-04     RGL + RGM
 *   test_rgl_05-06     RGL only
 *   test_rgm_01-30     RGM only
 *
 * Fixed set: submissions 001-009, using only test_editor_03, test_rgl_01, test_rgl_02,
 * test_rgl_05 and test_rgm_21-30, so expected counts for these people can be worked out by hand.
 *   001  Two rounds. Round 1 led by test_rgl_01: finalised, form submitted (attended, apology,
 *        no apology, other), decided (new round). Round 2 led by test_rgl_02: finalised, form
 *        draft, not yet decided.
 *   002  Led by test_rgl_05 (leads only): finalised, no form, not yet decided.
 *   003  Led by test_rgl_01: expired.
 *   004  Led by test_rgl_02: cancelled.
 *   005  Led by test_editor_03, who is also its only assigned journal editor: proposed times
 *        either side of New Year, meeting in January, form submitted with an N.A. attendance,
 *        accepted.
 *   006  Led by test_rgl_02: still open, meeting in the future, so ignored by all counts.
 *   007  Led by test_rgl_05: draft, no invitations, so ignored by all counts.
 *   008  Last year, led by test_rgl_01: finalised, form submitted, accepted.
 *   009  Led by test_rgl_02: finalised, form submitted, then the round was cancelled, so it
 *        counts as neither Completed nor Current.
 *   Special people: test_rgm_25 is always available but never selected; test_rgm_26 always
 *   responds with no times; test_rgm_27 never responds; test_rgm_28 has since lost the RGM role;
 *   test_rgm_29 is disabled; test_rgm_30 is never invited.
 *
 * Generated set: submissions 010-033 (about a dozen a year), using test_editor_04, test_rgl_03,
 * test_rgl_04, test_rgl_06 and test_rgm_01-20. Some reviewers are invited often and some rarely,
 * and some are available more often than others.
 */

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\groupReview\classes\GroupReviewService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PKP\cliTool\CommandLineTool;
use PKP\db\DAORegistry;
use PKP\decision\Decision;
use PKP\security\Role;
use PKP\security\Validation;
use PKP\submission\PKPSubmission;

require dirname(__FILE__, 5) . '/tools/bootstrap.php';

class GroupReviewTestDataTool extends CommandLineTool
{
    private const PASSWORD = 'password';
    private const TITLE_PREFIX = 'Test submission ';
    private const RANDOM_SEED = 20260928;
    private const DAY = 86400;

    private const STATUS_OPEN = 0;
    private const STATUS_FINALIZED = 1;
    private const STATUS_CANCELLED = 2;
    private const STATUS_EXPIRED = 3;
    private const STATUS_DRAFT = 4;

    private string $journalPath = '';
    private string $mode = '';
    private $context;
    private int $contextId = 0;
    private string $locale = 'en';
    private GroupReviewService $service;

    /** @var array<string, int> */
    private array $groups = [];

    /** @var array<string, int> username => user ID */
    private array $users = [];

    private int $submissionNumber = 0;

    /** @var array<int, string> submission ID => assigned editor's username */
    private array $submissionEditors = [];
    /** Midnight UTC today. All dates are relative to this, so runs on the same day are identical. */
    private int $today = 0;
    private int $thisYearStart = 0;
    private int $thisYearLatest = 0;

    public function __construct($argv = [])
    {
        parent::__construct($argv);
        if (count($this->argv) !== 2 || !in_array($this->argv[1], ['seed', 'clear'], true)) {
            $this->usage();
            exit(1);
        }
        [$this->journalPath, $this->mode] = $this->argv;
        $this->service = new GroupReviewService();
    }

    public function usage()
    {
        echo "Creates or removes group review test data.\n"
            . "Usage: {$this->scriptName} <journal path> seed|clear\n";
    }

    public function execute()
    {
        $this->context = Application::getContextDAO()->getByPath($this->journalPath);
        if (!$this->context) {
            echo "Error: no journal with path \"{$this->journalPath}\".\n";
            exit(1);
        }
        $this->contextId = (int) $this->context->getId();
        $this->locale = $this->context->getPrimaryLocale();

        $removed = $this->clear();
        $this->report('Removed', "{$removed['submissions']} test submissions, {$removed['users']} test users");
        if ($this->mode === 'clear') {
            return;
        }

        $year = (int) gmdate('Y');
        $this->thisYearStart = gmmktime(0, 0, 0, 1, 10, $year);
        $this->today = gmmktime(0, 0, 0, (int) gmdate('n'), (int) gmdate('j'), $year);
        $this->thisYearLatest = $this->today - 30 * self::DAY;
        if ($this->thisYearLatest - $this->thisYearStart < 30 * self::DAY) {
            echo "Error: too early in the year to create this year's test data. Run it from mid-February.\n";
            exit(1);
        }

        mt_srand(self::RANDOM_SEED);
        $this->report('Roles', $this->ensureRoles());
        $this->createUsers();
        $this->report('Users', count($this->users) . ' created, password "' . self::PASSWORD . '"');
        $this->fixedSet($year);
        $fixed = $this->submissionNumber;
        $this->generatedSet($year);
        $this->report('Submissions', "{$this->submissionNumber} created ({$fixed} fixed, " . ($this->submissionNumber - $fixed) . ' generated)');
        $this->report('Labels', $this->labels());
        $this->finalUserStates();
    }

    /** One line of output per step, in the same format. */
    private function report(string $step, string $message): void
    {
        echo str_pad("{$step}:", 13) . $message . "\n";
    }

    // Setup

    /** @return string what was done, for the output */
    private function ensureRoles(): string
    {
        [$this->groups['rgl'], $rglCreated] = $this->ensureRole('RGL', 'Review Group Leader', [WORKFLOW_STAGE_ID_SUBMISSION, WORKFLOW_STAGE_ID_EXTERNAL_REVIEW, WORKFLOW_STAGE_ID_EDITING]);
        [$this->groups['rgm'], $rgmCreated] = $this->ensureRole('RGM', 'Review Group Member', [WORKFLOW_STAGE_ID_SUBMISSION, WORKFLOW_STAGE_ID_EXTERNAL_REVIEW]);

        $this->groups['editor'] = (int) DB::table('user_groups as ug')
            ->join('user_group_settings as ugs', 'ugs.user_group_id', '=', 'ug.user_group_id')
            ->where('ug.context_id', $this->contextId)
            ->where('ugs.setting_name', 'nameLocaleKey')
            ->where('ugs.setting_value', 'default.groups.name.editor')
            ->value('ug.user_group_id');
        $author = Repo::userGroup()->getByRoleIds([Role::ROLE_ID_AUTHOR], $this->contextId)->first();
        $this->groups['author'] = $author ? (int) $author->getId() : 0;

        if (!$this->groups['editor'] || !$this->groups['author']) {
            echo "Error: the journal needs OJS's default Journal editor and Author roles.\n";
            exit(1);
        }

        return 'RGL ' . ($rglCreated ? 'created' : 'already exists') . ', RGM ' . ($rgmCreated ? 'created' : 'already exists');
    }

    /**
     * Find a role by abbreviation, or create it on the Section Editor permission level.
     *
     * @return array{0:int, 1:bool} user group ID, whether it was created
     */
    private function ensureRole(string $abbrev, string $name, array $stageIds): array
    {
        $existing = $this->service->getUserGroupIdByAbbreviation($this->contextId, $abbrev);
        if ($existing) {
            return [$existing, false];
        }

        $userGroup = Repo::userGroup()->newDataObject();
        $userGroup->setData('contextId', $this->contextId);
        $userGroup->setData('roleId', Role::ROLE_ID_SUB_EDITOR);
        $userGroup->setData('isDefault', false);
        $userGroup->setData('showTitle', true);
        $userGroup->setData('permitSelfRegistration', false);
        $userGroup->setData('permitMetadataEdit', false);
        $userGroup->setData('recommendOnly', false);
        $userGroup->setData('name', [$this->locale => $name]);
        $userGroup->setData('abbrev', [$this->locale => $abbrev]);
        $userGroupId = Repo::userGroup()->add($userGroup);

        foreach ($stageIds as $stageId) {
            DB::table('user_group_stage')->insert([
                'context_id' => $this->contextId,
                'user_group_id' => $userGroupId,
                'stage_id' => $stageId,
            ]);
        }

        return [$userGroupId, true];
    }

    /** @return array<string, string[]> username => roles */
    private function userSpecs(): array
    {
        $specs = [
            'test_editor_01' => ['editor'],
            'test_editor_02' => ['editor'],
            'test_editor_03' => ['editor', 'rgl', 'rgm'],
            'test_editor_04' => ['editor', 'rgm'],
        ];
        for ($i = 1; $i <= 6; $i++) {
            $specs[sprintf('test_rgl_%02d', $i)] = $i <= 4 ? ['rgl', 'rgm'] : ['rgl'];
        }
        for ($i = 1; $i <= 30; $i++) {
            $specs[sprintf('test_rgm_%02d', $i)] = ['rgm'];
        }

        return $specs;
    }

    private function createUsers(): void
    {
        foreach ($this->userSpecs() as $username => $roles) {
            // "test_rgl_05" is shown as "Test RGL 05".
            [, $role, $number] = explode('_', $username);
            $familyName = ($role === 'editor' ? 'Editor' : strtoupper($role)) . ' ' . $number;

            $user = Repo::user()->newDataObject();
            $user->setUsername($username);
            $user->setEmail("{$username}@example.test");
            $user->setPassword(Validation::encryptCredentials($username, self::PASSWORD));
            $user->setGivenName('Test', $this->locale);
            $user->setFamilyName($familyName, $this->locale);
            $user->setDateRegistered($this->date($this->today - 730 * self::DAY));
            $user->setMustChangePassword(false);
            $user->setDisabled(false);
            Repo::user()->add($user);
            $userId = (int) Repo::user()->getByUsername($username, true)->getId();
            $this->users[$username] = $userId;

            foreach ($roles as $role) {
                Repo::userGroup()->assignUserToGroup($userId, $this->groups[$role]);
            }
        }
    }

    // Fixed set

    private function fixedSet(int $year): void
    {
        $lastYear = $year - 1;
        $at = fn (float $fraction): int => (int) ($this->thisYearStart + $fraction * ($this->thisYearLatest - $this->thisYearStart));

        // 001: two rounds led by different RGLs.
        [$id, $rounds] = $this->submission(2);
        $created = $at(0.1);
        $this->poll($id, $rounds[1], 'test_rgl_01', [
            'status' => self::STATUS_FINALIZED,
            'created' => $created,
            'slots' => $this->slots($created, 3),
            'meetingSlot' => 0,
            'invited' => [
                'test_rgm_21' => [0, 1], 'test_rgm_22' => [0], 'test_rgm_23' => [0, 2], 'test_rgm_24' => [0],
                'test_rgm_25' => [0], 'test_rgm_26' => [], 'test_rgm_27' => null, 'test_rgm_28' => [0], 'test_editor_03' => [0],
            ],
            'selected' => ['test_rgm_21', 'test_rgm_22', 'test_rgm_23', 'test_rgm_24', 'test_rgm_28', 'test_editor_03'],
            'provisionMembers' => false,
            'decision' => Decision::NEW_EXTERNAL_ROUND,
            'form' => ['submitted' => true, 'records' => [
                'test_rgm_21' => ['attended', ['uploaded_notes', 'commented_on_draft']],
                'test_rgm_22' => ['attended', ['created_draft']],
                'test_rgm_23' => ['did_not_attend_with_apology', ['commented_on_draft']],
                'test_rgm_24' => ['did_not_attend_without_apology', ['did_not_contribute']],
                'test_rgm_28' => ['attended', ['uploaded_notes']],
                'test_editor_03' => ['other', ['commented_on_draft'], 'Joined for the second half'],
            ]],
        ]);
        $created = $at(0.6);
        $this->poll($id, $rounds[2], 'test_rgl_02', [
            'status' => self::STATUS_FINALIZED,
            'created' => $created,
            'slots' => $this->slots($created, 2),
            'meetingSlot' => 0,
            'invited' => [
                'test_rgm_21' => [0], 'test_rgm_22' => [0, 1], 'test_rgm_24' => null, 'test_rgm_25' => [0, 1],
                'test_rgm_26' => [], 'test_rgm_27' => null, 'test_rgl_01' => [0],
            ],
            'selected' => ['test_rgm_21', 'test_rgm_22', 'test_rgl_01'],
            'form' => ['submitted' => false, 'records' => [
                'test_rgm_21' => ['attended', []],
                'test_rgm_22' => [$this->notRecorded(), []],
                'test_rgl_01' => [$this->notRecorded(), []],
            ]],
        ]);

        // 002: led by an RGL who only leads; finalised with no form.
        [$id, $rounds] = $this->submission(1);
        $created = $at(0.3);
        $this->poll($id, $rounds[1], 'test_rgl_05', [
            'status' => self::STATUS_FINALIZED,
            'created' => $created,
            'slots' => $this->slots($created, 2),
            'meetingSlot' => 1,
            'invited' => ['test_rgm_22' => [1], 'test_rgm_23' => [1], 'test_rgm_25' => [1], 'test_rgm_26' => [], 'test_rgm_27' => null],
            'selected' => ['test_rgm_22', 'test_rgm_23'],
            'form' => null,
        ]);

        // 003: expired.
        [$id, $rounds] = $this->submission(1);
        $created = $at(0.4);
        $this->poll($id, $rounds[1], 'test_rgl_01', [
            'status' => self::STATUS_EXPIRED,
            'created' => $created,
            'slots' => $this->slots($created, 2),
            'invited' => [
                'test_rgm_21' => [0], 'test_rgm_25' => [0], 'test_rgm_26' => [], 'test_rgm_27' => null,
                'test_rgm_28' => [1], 'test_rgm_29' => [0],
            ],
        ]);

        // 004: cancelled, so excluded from all counts.
        [$id, $rounds] = $this->submission(1);
        $created = $at(0.5);
        $this->poll($id, $rounds[1], 'test_rgl_02', [
            'status' => self::STATUS_CANCELLED,
            'created' => $created,
            'slots' => $this->slots($created, 2),
            'invited' => ['test_rgm_21' => [0], 'test_rgm_24' => [0], 'test_rgm_27' => null],
        ]);

        // 005: the RGL is also the only assigned journal editor. Proposed times either side
        // of New Year; the meeting falls in January.
        [$id, $rounds] = $this->submission(1, 'test_editor_03');
        $this->poll($id, $rounds[1], 'test_editor_03', [
            'status' => self::STATUS_FINALIZED,
            'created' => gmmktime(9, 0, 0, 12, 10, $lastYear),
            'slots' => [gmmktime(0, 0, 0, 12, 30, $lastYear), gmmktime(0, 0, 0, 1, 2, $year)],
            'meetingSlot' => 1,
            'invited' => [
                'test_rgm_22' => [1], 'test_rgm_23' => [0, 1], 'test_rgm_24' => [1], 'test_rgm_25' => [1],
                'test_rgm_26' => [], 'test_rgm_27' => null,
            ],
            'selected' => ['test_rgm_22', 'test_rgm_23', 'test_rgm_24'],
            'decision' => Decision::ACCEPT,
            'form' => ['submitted' => true, 'at' => gmmktime(0, 0, 0, 1, 9, $year), 'records' => [
                'test_rgm_22' => ['attended', ['uploaded_notes']],
                'test_rgm_23' => ['not_applicable', ['commented_on_draft']],
                'test_rgm_24' => ['attended', ['created_draft']],
            ]],
        ]);

        // 006: still open, meeting in the future.
        [$id, $rounds] = $this->submission(1);
        $created = $this->today - 3 * self::DAY;
        $this->poll($id, $rounds[1], 'test_rgl_02', [
            'status' => self::STATUS_OPEN,
            'created' => $created,
            'deadline' => $this->today + 5 * self::DAY,
            'slots' => [$this->today + 10 * self::DAY, $this->today + 11 * self::DAY],
            'invited' => ['test_rgm_21' => [0], 'test_rgm_22' => null, 'test_rgm_23' => null],
        ]);

        // 007: draft, no invitations yet.
        [$id, $rounds] = $this->submission(1);
        $this->poll($id, $rounds[1], 'test_rgl_05', [
            'status' => self::STATUS_DRAFT,
            'created' => $this->today - self::DAY,
            'deadline' => $this->today + 7 * self::DAY,
            'slots' => [$this->today + 14 * self::DAY, $this->today + 15 * self::DAY],
            'invited' => [],
        ]);

        // 008: last year.
        [$id, $rounds] = $this->submission(1);
        $created = gmmktime(9, 0, 0, 6, 10, $lastYear);
        $this->poll($id, $rounds[1], 'test_rgl_01', [
            'status' => self::STATUS_FINALIZED,
            'created' => $created,
            'slots' => $this->slots($created, 2),
            'meetingSlot' => 1,
            'invited' => ['test_rgm_21' => [1], 'test_rgm_22' => [0, 1], 'test_rgm_24' => [1], 'test_rgm_28' => [1], 'test_rgm_29' => [1]],
            'selected' => ['test_rgm_21', 'test_rgm_22', 'test_rgm_28'],
            'decision' => Decision::ACCEPT,
            'form' => ['submitted' => true, 'records' => [
                'test_rgm_21' => ['attended', ['uploaded_notes']],
                'test_rgm_22' => ['attended', ['created_draft']],
                'test_rgm_28' => ['attended', ['commented_on_draft']],
            ]],
        ]);

        // 009: the group met and the form was submitted, then the editor cancelled the round.
        [$id, $rounds] = $this->submission(1);
        $created = $at(0.7);
        $this->poll($id, $rounds[1], 'test_rgl_02', [
            'status' => self::STATUS_FINALIZED,
            'created' => $created,
            'slots' => $this->slots($created, 2),
            'meetingSlot' => 0,
            'invited' => ['test_rgm_21' => [0], 'test_rgm_22' => [0], 'test_rgm_24' => [0, 1], 'test_rgm_27' => null],
            'selected' => ['test_rgm_21', 'test_rgm_22', 'test_rgm_24'],
            'decision' => Decision::CANCEL_REVIEW_ROUND,
            'form' => ['submitted' => true, 'records' => [
                'test_rgm_21' => ['attended', ['uploaded_notes']],
                'test_rgm_22' => ['attended', ['commented_on_draft']],
                'test_rgm_24' => ['did_not_attend_with_apology', []],
            ]],
        ]);
    }

    // Generated set

    private function generatedSet(int $year): void
    {
        $leaders = ['test_rgl_03', 'test_rgl_04', 'test_rgl_06'];
        $members = ['test_editor_04', 'test_rgl_03', 'test_rgl_04'];
        for ($i = 1; $i <= 20; $i++) {
            $members[] = sprintf('test_rgm_%02d', $i);
        }

        // A realistic spread: the first few members are invited often, most sometimes,
        // the last few rarely; and each tends to be available more or less often.
        $profiles = [];
        foreach ($members as $i => $username) {
            $profiles[$username] = [
                'weight' => $i < 6 ? 3 : ($i < 16 ? 2 : 1),
                'available' => [0.8, 0.6, 0.4][$i % 3],
            ];
        }

        // About a dozen submissions a year, like a small journal.
        for ($n = 0; $n < 24; $n++) {
            $thisYear = $n >= 12;
            $created = $thisYear
                ? mt_rand($this->thisYearStart, $this->thisYearLatest)
                : mt_rand(gmmktime(0, 0, 0, 2, 1, $year - 1), gmmktime(0, 0, 0, 10, 31, $year - 1));
            $latest = $thisYear ? $this->thisYearLatest : gmmktime(0, 0, 0, 11, 15, $year - 1);
            $roundCount = ($this->chance(0.3) && $created + 45 * self::DAY <= $latest) ? 2 : 1;

            [$id, $rounds] = $this->submission($roundCount);
            $leader = $leaders[mt_rand(0, count($leaders) - 1)];
            for ($round = 1; $round <= $roundCount; $round++) {
                if ($round === 2 && $this->chance(0.2)) {
                    $leader = $leaders[mt_rand(0, count($leaders) - 1)];
                }
                $this->generatedPoll($id, $rounds[$round], $leader, $profiles, $created + ($round - 1) * 45 * self::DAY, $round, $round === $roundCount, $thisYear);
            }
        }
    }

    private function generatedPoll(int $submissionId, int $roundId, string $leader, array $profiles, int $created, int $round, bool $isLastRound, bool $thisYear): void
    {
        $roll = mt_rand(1, 100);
        $status = $roll <= 85 ? self::STATUS_FINALIZED : ($roll <= 95 ? self::STATUS_EXPIRED : self::STATUS_CANCELLED);
        $slots = $this->slots($created, 3);

        $weights = array_map(fn (array $profile): int => $profile['weight'], $profiles);
        unset($weights[$leader]);
        $invited = [];
        foreach ($this->weightedSample($weights, mt_rand(6, 9)) as $username) {
            if ($this->chance($profiles[$username]['available'])) {
                $times = array_values(array_filter([0, 1, 2], fn () => $this->chance(0.6)));
                $invited[$username] = $times ?: [mt_rand(0, 2)];
            } else {
                $invited[$username] = $this->chance(0.5) ? [] : null;
            }
        }

        $spec = ['status' => $status, 'created' => $created, 'slots' => $slots, 'invited' => $invited];

        // Earlier rounds end with a new round. The last round is decided last year, and
        // usually this year; the rest are still under review.
        if (!$isLastRound) {
            $spec['decision'] = Decision::NEW_EXTERNAL_ROUND;
        } elseif (!$thisYear || $this->chance(0.75)) {
            $spec['decision'] = [Decision::ACCEPT, Decision::PENDING_REVISIONS, Decision::DECLINE][mt_rand(0, 2)];
        }
        if ($status === self::STATUS_FINALIZED) {
            $counts = [0, 0, 0];
            foreach ($invited as $times) {
                foreach ($times ?? [] as $t) {
                    $counts[$t]++;
                }
            }
            $meetingSlot = array_search(max($counts), $counts, true);
            $available = array_keys(array_filter($invited, fn ($times) => in_array($meetingSlot, $times ?? [], true)));
            if (!$available) {
                $spec['status'] = self::STATUS_EXPIRED;
            } else {
                shuffle($available);
                $selected = array_slice($available, 0, min(count($available), mt_rand(3, 5)));
                $spec['meetingSlot'] = $meetingSlot;
                $spec['selected'] = $selected;
                $spec['provisionMembers'] = $isLastRound;
                $spec['form'] = $this->generatedForm($selected, $round, $thisYear);
            }
        }

        $this->poll($submissionId, $roundId, $leader, $spec);
    }

    private function generatedForm(array $selected, int $round, bool $thisYear): ?array
    {
        $roll = mt_rand(1, 100);
        $submitted = $roll <= ($thisYear ? 75 : 95);
        if (!$submitted && $roll > ($thisYear ? 90 : 97)) {
            return null;
        }

        $records = [];
        $drafter = $selected[0];
        foreach ($selected as $username) {
            $roll = mt_rand(1, 100);
            $attendance = match (true) {
                !$submitted && $this->chance(0.5) => $this->notRecorded(),
                $roll <= 82 => 'attended',
                $roll <= 90 => 'did_not_attend_with_apology',
                $roll <= 95 => 'did_not_attend_without_apology',
                $roll <= 97 => 'other',
                default => $round === 2 ? 'not_applicable' : 'attended',
            };
            $contributions = [];
            if ($attendance === 'attended' || $attendance === 'other') {
                if ($this->chance(0.7)) {
                    $contributions[] = 'uploaded_notes';
                }
                if ($this->chance(0.6)) {
                    $contributions[] = 'commented_on_draft';
                }
                if ($username === $drafter) {
                    $contributions[] = 'created_draft';
                } elseif ($this->chance(0.2)) {
                    $contributions[] = 'offered_creating_draft';
                }
            } elseif ($this->chance(0.5)) {
                $contributions[] = 'did_not_contribute';
            }
            $records[$username] = [$attendance, $contributions, $attendance === 'other' ? 'Test other attendance' : null];
        }

        return ['submitted' => $submitted, 'records' => $records];
    }

    /**
     * Pick $count different keys, each with probability proportional to its weight.
     *
     * @param array<string, int> $weights
     *
     * @return string[]
     */
    private function weightedSample(array $weights, int $count): array
    {
        $picked = [];
        while ($weights && count($picked) < $count) {
            $roll = mt_rand(1, array_sum($weights));
            foreach ($weights as $key => $weight) {
                $roll -= $weight;
                if ($roll <= 0) {
                    $picked[] = $key;
                    unset($weights[$key]);
                    break;
                }
            }
        }

        return $picked;
    }

    // Writing data

    /**
     * Create a submission in the review stage with a first author, assigned to
     * the given journal editor, with the given number of review rounds.
     *
     * @return array{0:int, 1:array<int, int>} submission ID, round number => review round ID
     */
    private function submission(int $roundCount, string $editor = 'test_editor_01'): array
    {
        $this->submissionNumber++;
        $number = sprintf('%03d', $this->submissionNumber);
        $sectionId = (int) DB::table('sections')->where('journal_id', $this->contextId)->orderBy('seq')->value('section_id');

        $submission = Repo::submission()->newDataObject([
            'contextId' => $this->contextId,
            'locale' => $this->locale,
            'stageId' => WORKFLOW_STAGE_ID_EXTERNAL_REVIEW,
            'submissionProgress' => '',
        ]);
        $publication = Repo::publication()->newDataObject([
            'title' => [$this->locale => self::TITLE_PREFIX . $number],
            'sectionId' => $sectionId,
        ]);
        $submissionId = Repo::submission()->add($submission, $publication, $this->context);
        $publicationId = (int) Repo::submission()->get($submissionId)->getData('currentPublicationId');

        $author = Repo::author()->newDataObject([
            'publicationId' => $publicationId,
            'givenName' => [$this->locale => 'Test'],
            'familyName' => [$this->locale => "Author {$number}"],
            'email' => "test_author_{$number}@example.test",
            'includeInBrowse' => true,
            'userGroupId' => $this->groups['author'],
            'seq' => 0,
        ]);
        $authorId = Repo::author()->add($author);
        Repo::publication()->edit(Repo::publication()->get($publicationId), ['primaryContactId' => $authorId]);

        $this->assign($submissionId, $this->groups['editor'], $this->users[$editor], false);
        $this->submissionEditors[$submissionId] = $editor;

        $reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO'); /** @var \PKP\submission\reviewRound\ReviewRoundDAO $reviewRoundDao */
        $rounds = [];
        for ($round = 1; $round <= $roundCount; $round++) {
            $rounds[$round] = (int) $reviewRoundDao->build($submissionId, WORKFLOW_STAGE_ID_EXTERNAL_REVIEW, $round)->getId();
        }

        return [$submissionId, $rounds];
    }

    /**
     * Create one poll with its proposed times, invitations, responses and, when
     * finalised, its selected members, participant assignments and form.
     *
     * In 'invited', each value lists the proposed times (by index) the person gave;
     * an empty list means they responded with no times, null means no response.
     */
    private function poll(int $submissionId, int $roundId, string $leader, array $spec): void
    {
        $leaderId = $this->users[$leader];
        $created = $spec['created'];
        $deadline = $spec['deadline'] ?? $created + 10 * self::DAY;
        $isFinalized = $spec['status'] === self::STATUS_FINALIZED;
        $selected = $spec['selected'] ?? [];

        $sessionId = (int) DB::table('group_review_sessions')->insertGetId([
            'context_id' => $this->contextId,
            'submission_id' => $submissionId,
            'review_round_id' => $roundId,
            'leader_user_id' => $leaderId,
            'deadline_utc' => $this->date($deadline),
            'timezone' => 'UTC',
            'meeting_duration_minutes' => 60,
            'send_reminder' => false,
            'reminder_before_hours' => 24,
            'status' => $spec['status'],
            'selected_slot_id' => null,
            'meeting_url' => $isFinalized ? 'https://example.test/meeting' : null,
            'notes' => null,
            'created_at' => $this->date($created),
            'updated_at' => $this->date($created),
            'finalized_at' => $isFinalized ? $this->date($deadline) : null,
        ], 'session_id');

        $slotIds = [];
        foreach ($spec['slots'] as $start) {
            $slotIds[] = (int) DB::table('group_review_slots')->insertGetId([
                'session_id' => $sessionId,
                'start_time_utc' => $this->date($start),
                'created_at' => $this->date($created),
            ], 'slot_id');
        }
        if ($isFinalized) {
            DB::table('group_review_sessions')
                ->where('session_id', $sessionId)
                ->update(['selected_slot_id' => $slotIds[$spec['meetingSlot']]]);
        }

        $respondedAt = $this->date($created + 2 * self::DAY);
        foreach ($spec['invited'] as $username => $times) {
            DB::table('group_review_members')->insert([
                'session_id' => $sessionId,
                'user_id' => $this->users[$username],
                'selected' => in_array($username, $selected, true),
                'invited_at' => $this->date($created),
                'responded_at' => $times === null ? null : $respondedAt,
            ]);
            foreach ($times ?? [] as $index) {
                DB::table('group_review_availability')->insert([
                    'session_id' => $sessionId,
                    'slot_id' => $slotIds[$index],
                    'user_id' => $this->users[$username],
                    'created_at' => $respondedAt,
                ]);
            }
        }

        // The RGL is assigned to the submission. Starting a new round removes earlier
        // RGMs, so only the latest round's members stay assigned.
        $this->assign($submissionId, $this->groups['rgl'], $leaderId, false);
        if ($isFinalized && ($spec['provisionMembers'] ?? true)) {
            foreach ($selected as $username) {
                $this->assign($submissionId, $this->groups['rgm'], $this->users[$username], true);
            }
        }

        if (!empty($spec['form'])) {
            $meeting = $spec['slots'][$spec['meetingSlot']];
            $this->form($sessionId, $submissionId, $roundId, $leaderId, $spec['form'], $spec['form']['at'] ?? $meeting + 5 * self::DAY);
        }

        if (!empty($spec['decision'])) {
            $this->decision($submissionId, $roundId, $spec['decision'], max($spec['slots']) + 21 * self::DAY);
        }
    }

    /**
     * Record the editor's decision on a review round. Accepted submissions move to
     * copyediting and declined ones are marked declined, as OJS does.
     */
    private function decision(int $submissionId, int $roundId, int $decision, int $at): void
    {
        DB::table('edit_decisions')->insert([
            'submission_id' => $submissionId,
            'review_round_id' => $roundId,
            'stage_id' => WORKFLOW_STAGE_ID_EXTERNAL_REVIEW,
            'round' => (int) DB::table('review_rounds')->where('review_round_id', $roundId)->value('round'),
            'editor_id' => $this->users[$this->submissionEditors[$submissionId]],
            'decision' => $decision,
            'date_decided' => $this->date(min($at, $this->today)),
        ]);

        if ($decision === Decision::ACCEPT) {
            DB::table('submissions')->where('submission_id', $submissionId)->update(['stage_id' => WORKFLOW_STAGE_ID_EDITING]);
        } elseif ($decision === Decision::DECLINE) {
            DB::table('submissions')->where('submission_id', $submissionId)->update(['status' => PKPSubmission::STATUS_DECLINED]);
        }
    }

    private function form(int $sessionId, int $submissionId, int $roundId, int $leaderId, array $form, int $at): void
    {
        $submitted = $form['submitted'];
        DB::table('group_review_participation_forms')->insert([
            'context_id' => $this->contextId,
            'session_id' => $sessionId,
            'status' => $submitted ? 'submitted' : 'draft',
            'general_comments' => $this->chance(0.3) ? 'Test general comment.' : null,
            'submission_comment' => null,
            'created_at' => $this->date($at),
            'updated_at' => $this->date($at),
            'updated_by' => $leaderId,
            'submitted_at' => $submitted ? $this->date($at) : null,
            'submitted_by' => $submitted ? $leaderId : null,
        ]);

        foreach ($form['records'] as $username => $record) {
            [$attendance, $contributions] = $record;
            DB::table('group_review_participation')->insert([
                'context_id' => $this->contextId,
                'session_id' => $sessionId,
                'review_round_id' => $roundId,
                'submission_id' => $submissionId,
                'reviewer_user_id' => $this->users[$username],
                'leader_user_id' => $leaderId,
                'attendance' => $attendance,
                'attendance_other' => $record[2] ?? null,
                'contribution_comments' => $this->chance(0.3) ? 'Test comment on contributions.' : null,
                'shaping_feedback_types' => json_encode($contributions),
                'shaping_feedback_comments' => null,
                'other_contribution' => null,
                'created_at' => $this->date($at),
                'updated_at' => $this->date($at),
            ]);
        }
    }

    private function assign(int $submissionId, int $userGroupId, int $userId, bool $recommendOnly): void
    {
        $exists = DB::table('stage_assignments')
            ->where('submission_id', $submissionId)
            ->where('user_group_id', $userGroupId)
            ->where('user_id', $userId)
            ->exists();
        if (!$exists) {
            $stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO'); /** @var \PKP\stageAssignment\StageAssignmentDAO $stageAssignmentDao */
            $stageAssignmentDao->build($submissionId, $userGroupId, $userId, $recommendOnly, false);
        }
    }

    /**
     * Reviewer labels, once the labels table exists. One in six experience levels and one in
     * six methodologies are left unset.
     *
     * @return string what was done, for the output
     */
    private function labels(): string
    {
        if (!Schema::hasTable('group_review_reviewer_labels')) {
            return "skipped, the labels table doesn't exist yet";
        }
        $count = 0;

        foreach ($this->userSpecs() as $username => $roles) {
            if (!array_intersect($roles, ['rgl', 'rgm'])) {
                continue;
            }
            $count++;
            $senior = in_array('rgl', $roles, true);
            // Every sixth reviewer has no experience level, and a different sixth no methodology.
            $values = [
                'experience_level' => $count % 6 === 0 ? [] : [$senior ? ['intermediate', 'experienced', 'experienced'][mt_rand(0, 2)] : ['novice', 'novice', 'intermediate', 'experienced'][mt_rand(0, 3)]],
                'methodology' => $count % 6 === 3 ? [] : [['quantitative', 'qualitative', 'mixed_methods'][mt_rand(0, 2)]],
                'expertise' => array_values(array_filter(['education', 'statistics'], fn () => $this->chance(0.4))),
            ];
            foreach ($values as $type => $list) {
                foreach ($list as $value) {
                    DB::table('group_review_reviewer_labels')->insert([
                        'context_id' => $this->contextId,
                        'user_id' => $this->users[$username],
                        'label_type' => $type,
                        'value' => $value,
                    ]);
                }
            }
        }

        return "set for {$count} reviewers";
    }

    /** test_rgm_28 has since lost the RGM role; test_rgm_29 is disabled. */
    private function finalUserStates(): void
    {
        DB::table('user_user_groups')
            ->where('user_group_id', $this->groups['rgm'])
            ->where('user_id', $this->users['test_rgm_28'])
            ->delete();
        DB::table('users')->where('user_id', $this->users['test_rgm_29'])->update(['disabled' => 1]);
    }

    // Removing data

    /** @return array{submissions:int, users:int} */
    private function clear(): array
    {
        $candidates = DB::table('publication_settings as ps')
            ->join('publications as p', 'p.publication_id', '=', 'ps.publication_id')
            ->join('submissions as s', 's.submission_id', '=', 'p.submission_id')
            ->where('s.context_id', $this->contextId)
            ->where('ps.setting_name', 'title')
            ->where('ps.setting_value', 'like', self::TITLE_PREFIX . '%')
            ->select('s.submission_id', 'ps.setting_value')
            ->get();
        $submissionIds = [];
        foreach ($candidates as $row) {
            if (preg_match('/^' . preg_quote(self::TITLE_PREFIX, '/') . '\d{3}$/', (string) $row->setting_value)) {
                $submissionIds[(int) $row->submission_id] = true;
            }
        }
        foreach (array_keys($submissionIds) as $submissionId) {
            $submission = Repo::submission()->get($submissionId);
            if ($submission) {
                Repo::submission()->delete($submission);
            }
        }

        $userIds = [];
        foreach (array_keys($this->userSpecs()) as $username) {
            $user = Repo::user()->getByUsername($username, true);
            if ($user) {
                $userIds[$username] = (int) $user->getId();
            }
        }
        foreach (['group_review_reviewer_labels', 'group_review_reviewer_label_history'] as $table) {
            if ($userIds && Schema::hasTable($table)) {
                DB::table($table)->where('context_id', $this->contextId)->whereIn('user_id', array_values($userIds))->delete();
            }
        }
        $deletedUsers = 0;
        foreach ($userIds as $username => $userId) {
            try {
                Repo::user()->delete(Repo::user()->get($userId, true));
                $deletedUsers++;
            } catch (Throwable $e) {
                $this->report('Warning', "could not delete {$username}: {$e->getMessage()}");
            }
        }

        return ['submissions' => count($submissionIds), 'users' => $deletedUsers];
    }

    // Helpers

    /** Proposed meeting times two weeks after the poll opens, a day apart. */
    private function slots(int $created, int $count): array
    {
        $first = $created + 14 * self::DAY;
        return array_map(fn (int $i) => $first + $i * self::DAY, range(0, $count - 1));
    }

    private function notRecorded(): string
    {
        $constant = 'APP\plugins\generic\groupReview\classes\ParticipationService::ATTENDANCE_NOT_RECORDED';
        return defined($constant) ? (string) constant($constant) : 'attended';
    }

    private function chance(float $probability): bool
    {
        return mt_rand(1, 10000) <= $probability * 10000;
    }

    private function date(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }
}

$tool = new GroupReviewTestDataTool($argv ?? []);
$tool->execute();
