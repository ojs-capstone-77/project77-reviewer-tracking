<?php

/**
 * php plugins/generic/groupReview/tests/ReviewerStatsServiceTest.php <journal path>
 * Add --mutate-expected to demonstrate a named, nonzero assertion failure.
 * Fresh fixtures and all database changes are rolled back, even on failure.
 */

use APP\core\Application;
use APP\plugins\generic\groupReview\classes\ReviewerStatsService;
use Illuminate\Support\Facades\DB;
use PKP\cliTool\CommandLineTool;

require_once dirname(__FILE__, 5) . '/tools/bootstrap.php';
require_once __DIR__ . '/testData.php';
require_once __DIR__ . '/ReviewerStatsExpected.php';
require_once __DIR__ . '/TestJournal.php';

class ReviewerStatsServiceTestTool extends CommandLineTool
{
    private int $checks = 0;

    private function same($expected, $actual, string $path): void
    {
        if (is_array($expected) && is_array($actual)) {
            $expectedKeys = array_keys($expected);
            $actualKeys = array_keys($actual);
            if (!array_is_list($expected)) {
                sort($expectedKeys);
                sort($actualKeys);
            }
            if ($expectedKeys !== $actualKeys) {
                throw new RuntimeException("{$path}: expected keys " . json_encode($expectedKeys)
                    . ', got ' . json_encode($actualKeys));
            }
            foreach ($expected as $key => $value) {
                $this->same($value, $actual[$key], "{$path}.{$key}");
            }
        } elseif ($expected !== $actual) {
            throw new RuntimeException("{$path}: expected " . var_export($expected, true)
                . ', got ' . var_export($actual, true));
        }
        $this->checks++;
    }

    public function execute()
    {
        if (count($this->argv) < 1 || count($this->argv) > 2
            || (isset($this->argv[1]) && $this->argv[1] !== '--mutate-expected')) {
            throw new RuntimeException('Usage: ReviewerStatsServiceTest.php <journal path> [--mutate-expected]');
        }
        DB::beginTransaction();
        try {
            $path = groupReviewTestJournal($this->argv[0]);
            $contextId = (int) Application::getContextDAO()->getByPath($path)->getId();
            $stats = new ReviewerStatsService();
            $empty = (new ReviewerStatsExpected(['users' => [], 'polls' => [], 'labels' => []]))->expected(null);
            $this->same([], $stats->getReviewerRows($contextId, null), 'empty.reviewers');
            $this->same([], $stats->getYears($contextId), 'empty.years');
            $this->same($empty['overview'], $stats->getOverview($contextId, null), 'empty.overview');
            $seed = new GroupReviewTestDataTool([__FILE__, $path, 'seed']);
            $seed->execute();
            $fixture = $seed->getFixture();
            $oracle = new ReviewerStatsExpected($fixture);
            $year = (int) gmdate('Y');
            foreach ([$year, null] as $filter) {
                $scope = $filter === null ? 'allTime' : 'currentYear';
                $expected = $oracle->expected($filter);
                // These hand-counted cases anchor the oracle to the documented fixed set.
                $this->same([
                    'invited' => $filter === null ? 5 : 4,
                    'selected' => $filter === null ? 4 : 3,
                    'completed' => $filter === null ? 2 : 1,
                    'current' => 1,
                    'attended' => $filter === null ? 3 : 2,
                ], array_intersect_key($expected['rows']['test_rgm_21'], array_flip([
                    'invited', 'selected', 'completed', 'current', 'attended',
                ])), "{$scope}.fixture.test_rgm_21");
                $this->same(36, $expected['overview']['live']['total'], "{$scope}.fixture.live.total");
                $this->same(7, $expected['overview']['live']['leaders'], "{$scope}.fixture.live.leaders");
                $this->same(5, $expected['rows']['test_rgm_25']['available'], "{$scope}.fixture.alwaysAvailable");
                $this->same(0, $expected['rows']['test_rgm_25']['selected'], "{$scope}.fixture.neverSelected");
                $this->same(5, $expected['rows']['test_rgm_26']['notAvailable'], "{$scope}.fixture.unavailable");
                $this->same(6, $expected['rows']['test_rgm_27']['noResponse'], "{$scope}.fixture.noResponse");
                if (isset($this->argv[1])) {
                    $expected['rows']['test_rgm_21']['invited']++;
                }
                $this->same($expected['years'], $stats->getYears($contextId), "{$scope}.years");
                $this->same($expected['overview'], $stats->getOverview($contextId, $filter), "{$scope}.overview");
                $actualRows = $stats->getReviewerRows($contextId, $filter);
                $this->same(array_column(array_values($expected['rows']), 'userId'), array_column($actualRows, 'userId'), "{$scope}.reviewerOrder");
                $actualRows = array_column($actualRows, null, 'userId');
                foreach ($expected['rows'] as $username => $row) {
                    $id = $row['userId'];
                    $this->same($row, $actualRows[$id], "{$scope}.reviewers.{$username}");
                    $this->same($row, $stats->getReviewerRow($contextId, $id, $filter), "{$scope}.reviewer.{$username}");
                    $this->same($expected['attendance'][$username], $stats->getAttendanceCounts($contextId, $id, $filter), "{$scope}.attendance.{$username}");
                    $this->same($expected['contributions'][$username], $stats->getContributionCounts($contextId, $id, $filter), "{$scope}.contributions.{$username}");
                    $this->same($expected['history'][$username], $stats->getHistory($contextId, $id, $filter), "{$scope}.history.{$username}");
                }
                $this->same(null, $stats->getReviewerRow($contextId, $fixture['users']['test_rgm_29']['id'], $filter), "{$scope}.disabled");
                $this->same(null, $stats->getReviewerRow($contextId, PHP_INT_MAX, $filter), "{$scope}.unknown");
                $this->same([], $stats->getHistory($contextId, PHP_INT_MAX, $filter), "{$scope}.unknownHistory");
            }
            $this->edgeCases($contextId, $fixture, $year);
            echo "Passed {$this->checks} reviewer statistics checks (current year and all time).\n";
        } finally {
            DB::rollBack();
            echo "Reviewer statistics fixtures rolled back.\n";
        }
    }

    private function edgeCases(int $contextId, array $fixture, int $year): void
    {
    $stats = new ReviewerStatsService();
    $polls = array_values($fixture['polls']);
    $id = $fixture['users']['test_rgm_21']['id'];
    $before = $stats->getReviewerRow($contextId, $id, null);
    // Fixed 006 is open, but becomes counted as expired without a status update.
    $open = $polls[6];
    DB::table('group_review_sessions')->where('session_id', $open['sessionId'])
        ->update(['deadline_utc' => gmdate('Y-m-d H:i:s', time() - 1)]);
    $after = $stats->getReviewerRow($contextId, $id, null);
    $this->same($before['invited'] + 1, $after['invited'], 'overdueOpen.invited');
    $this->same($before['available'] + 1, $after['available'], 'overdueOpen.available');
    $this->same($before['selected'], $after['selected'], 'overdueOpen.selected');
    $this->same($before['completed'], $after['completed'], 'overdueOpen.completed');
    DB::table('group_review_sessions')->where('session_id', $open['sessionId'])
        ->update(['deadline_utc' => gmdate('Y-m-d H:i:s', $open['deadline'])]);

    // UTC 31 December is already 1 January in the poll's own timezone.
    $boundary = $polls[5];
    $slotId = (int) DB::table('group_review_sessions')->where('session_id', $boundary['sessionId'])->value('selected_slot_id');
    $original = DB::table('group_review_slots')->where('slot_id', $slotId)->value('start_time_utc');
    DB::table('group_review_slots')->where('slot_id', $slotId)->update([
        'start_time_utc' => ($year - 1) . '-12-31 14:30:00',
    ]);
    DB::table('group_review_sessions')->where('session_id', $boundary['sessionId'])->update(['timezone' => 'Australia/Sydney']);
    $member = $fixture['users']['test_rgm_22']['id'];
    $this->same(5, $stats->getReviewerRow($contextId, $member, $year)['invited'], 'timezone.localYear.invited');
    DB::table('group_review_sessions')->where('session_id', $boundary['sessionId'])->update(['timezone' => 'UTC']);
    $this->same(4, $stats->getReviewerRow($contextId, $member, $year)['invited'], 'timezone.utcYear.invited');
    $this->same(6, $stats->getReviewerRow($contextId, $member, null)['invited'], 'timezone.allTime.invited');
    DB::table('group_review_slots')->where('slot_id', $slotId)->update(['start_time_utc' => $original]);

    // A recommendation after a decision must not reopen the round; a reverted decline must.
    $finalized = $polls[0];
    $roundId = (int) DB::table('group_review_sessions')->where('session_id', $finalized['sessionId'])->value('review_round_id');
    $decision = (array) DB::table('edit_decisions')->where('review_round_id', $roundId)->first();
    unset($decision['edit_decision_id']);
    $decision['date_decided'] = gmdate('Y-m-d H:i:s');
    $decision['decision'] = \PKP\decision\Decision::RECOMMEND_ACCEPT;
    $newId = DB::table('edit_decisions')->insertGetId($decision, 'edit_decision_id');
    $this->same(1, $stats->getReviewerRow($contextId, $id, $year)['completed'], 'recommendation.completed');
    $this->same(1, $stats->getReviewerRow($contextId, $id, $year)['current'], 'recommendation.current');
    DB::table('edit_decisions')->where('edit_decision_id', $newId)->update([
        'decision' => \PKP\decision\Decision::DECLINE,
    ]);
    $decision['decision'] = \PKP\decision\Decision::REVERT_DECLINE;
    DB::table('edit_decisions')->insert($decision);
    $this->same(0, $stats->getReviewerRow($contextId, $id, $year)['completed'], 'revertedDecline.completed');
    $this->same(2, $stats->getReviewerRow($contextId, $id, $year)['current'], 'revertedDecline.current');
    }
}

try {
    (new ReviewerStatsServiceTestTool($argv))->execute();
} catch (Throwable $error) {
    fwrite(STDERR, "FAIL {$error->getMessage()}\n");
    exit(1);
}
