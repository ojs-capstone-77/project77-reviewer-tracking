<?php

/**
 * @file classes/ReviewerStatsService.php
 *
 * The counts shown on the editor dashboard, worked out as defined in
 * docs/Reviewer-Monitoring-Definitions.md. All data for a journal is loaded
 * with a fixed number of queries and aggregated in PHP.
 */

namespace APP\plugins\generic\groupReview\classes;

use APP\facades\Repo;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Illuminate\Support\Facades\DB;
use PKP\decision\Decision;

class ReviewerStatsService
{
    /** Attendance answers that count towards "attendance recorded". */
    private const RECORDED_ATTENDANCE = [
        ParticipationService::ATTENDANCE_ATTENDED,
        ParticipationService::ATTENDANCE_APOLOGY,
        ParticipationService::ATTENDANCE_NO_APOLOGY,
    ];

    /** Editorial decisions that end a review round. Recommendations are not decisions. */
    private const ROUND_ENDING_DECISIONS = [
        Decision::ACCEPT,
        Decision::PENDING_REVISIONS,
        Decision::RESUBMIT,
        Decision::DECLINE,
        Decision::NEW_EXTERNAL_ROUND,
        Decision::CANCEL_REVIEW_ROUND,
    ];

    /** Editorial decisions that reopen a round after it ended. */
    private const ROUND_REOPENING_DECISIONS = [
        Decision::REVERT_DECLINE,
    ];

    /**
     * One row of counts per reviewer, sorted by name.
     *
     * @param ?int $year calendar year, or null for all time
     *
     * @return array<int, array{
     *   userId:int, name:string, invited:int, available:int, notAvailable:int,
     *   noResponse:int, selected:int, notSelected:int, timesLed:int, completed:int,
     *   submissionsReviewed:int, current:int, attended:int, attendanceRecorded:int, reviewerSince:?string,
     *   lastActivity:?string
     * }>
     */
    public function getReviewerRows(int $contextId, ?int $year): array
    {
        $data = $this->load($contextId);

        $rows = [];
        foreach ($data['reviewers'] as $userId => $name) {
            $rows[] = $this->reviewerRow($data, $userId, $name, $year);
        }
        usort($rows, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * The Overview's counts.
     *
     * - live: current RGM/RGL holders, current RGL holders, and review groups not yet decided
     *   (a cancelled round is decided, so it isn't Current; it isn't Completed either)
     * - activity: reviewer counts (each reviewer once) and review groups for the year
     * - labels: per label type, Total (live) and Active (in the year) for each value and for Not set
     *
     * @param ?int $year calendar year, or null for all time
     *
     * @return array{
     *   live: array{total:int, leaders:int, currentGroups:int},
     *   activity: array{invited:int, participated:int, notSelected:int, inactive:int, notInvited:int, reviewGroups:int, completed:int},
     *   labels: array<string, array{values: array<string, array{total:int, active:int}>, notSet: array{total:int, active:int}}>
     * }
     */
    public function getOverview(int $contextId, ?int $year): array
    {
        $data = $this->load($contextId);
        $polls = $data['polls'];
        $forms = $data['forms'];
        $holders = $data['roleHolders'];

        $live = [
            'total' => count($holders),
            'leaders' => count(array_filter($holders, fn (array $roles): bool => $roles['rgl'])),
            'currentGroups' => count(array_filter($forms, fn (array $form): bool => !$form['decided'])),
        ];

        $activity = ['invited' => 0, 'participated' => 0, 'notSelected' => 0, 'inactive' => 0, 'notInvited' => 0];
        $active = [];
        foreach (array_keys($data['reviewers']) as $userId) {
            $invited = $gaveTime = $participated = false;
            foreach ($polls as $poll) {
                if (!$this->inYear($poll, $year)) {
                    continue;
                }
                $member = $poll['members'][$userId] ?? null;
                if ($member) {
                    $invited = true;
                    $gaveTime = $gaveTime || $member['available'];
                }
            }
            foreach ($forms as $form) {
                if ($this->inGroup($form, $userId) && $this->inYear($form, $year)) {
                    $invited = true;
                    $participated = true;
                }
            }

            if (!$invited) {
                if (isset($holders[$userId])) {
                    $activity['notInvited']++;
                }
                continue;
            }
            $activity['invited']++;
            if ($participated) {
                $activity['participated']++;
            } elseif ($gaveTime) {
                $activity['notSelected']++;
            } else {
                $activity['inactive']++;
            }
            $active[$userId] = $participated || $gaveTime;
        }

        $groups = array_filter($forms, fn (array $form): bool => $this->inYear($form, $year));
        $activity['reviewGroups'] = count($groups);
        $activity['completed'] = count(array_filter($groups, fn (array $form): bool => $form['decided'] && !$form['cancelled']));

        return [
            'live' => $live,
            'activity' => $activity,
            'labels' => $this->labelCounts($contextId, array_keys($holders), $active),
        ];
    }

    /** @return int[] */
    public function getYears(int $contextId): array
    {
        $data = $this->load($contextId);
        $years = array_unique(array_merge(array_column($data['polls'], 'year'), array_column($data['forms'], 'year')));
        rsort($years);

        return $years;
    }

    public function getLeaderCountForYear(int $contextId, ?int $year): int
    {
        $data = $this->load($contextId);
        $leaders = [];
        foreach ($data['forms'] as $form) {
            if ($this->inYear($form, $year)) {
                $leaders[$form['leaderId']] = true;
            }
        }

        return count($leaders);
    }

    public function getReviewerRow(int $contextId, int $userId, ?int $year): ?array
    {
        $data = $this->load($contextId);
        if (!isset($data['reviewers'][$userId])) {
            return null;
        }

        return $this->reviewerRow($data, $userId, $data['reviewers'][$userId], $year);
    }

    public function getAttendanceCounts(int $contextId, int $userId, ?int $year): array
    {
        $data = $this->load($contextId);
        $counts = [];
        foreach ($data['forms'] as $form) {
            if (!$this->inYear($form, $year)) {
                continue;
            }
            $answer = $form['participation'][$userId]['attendance'] ?? null;
            if ($answer === null || $answer === ParticipationService::ATTENDANCE_NOT_RECORDED) {
                continue;
            }
            $counts[$answer] = ($counts[$answer] ?? 0) + 1;
        }

        return $counts;
    }

    public function getContributionCounts(int $contextId, int $userId, ?int $year): array
    {
        $data = $this->load($contextId);
        $counts = [];
        foreach ($data['forms'] as $form) {
            if (!$this->inYear($form, $year)) {
                continue;
            }
            foreach ($form['participation'][$userId]['shapingFeedbackTypes'] ?? [] as $value) {
                $counts[$value] = ($counts[$value] ?? 0) + 1;
            }
        }

        return $counts;
    }

    public function getHistory(int $contextId, int $userId, ?int $year): array
    {
        $data = $this->load($contextId);

        $entries = array_filter($data['forms'], fn (array $form): bool => $this->inGroup($form, $userId) && $this->inYear($form, $year));
        usort($entries, fn (array $a, array $b): int => strcmp($b['date'], $a['date']));

        $rounds = $this->rounds(array_values(array_unique(array_column($entries, 'reviewRoundId'))));
        $leaderNames = $this->userNames(array_values(array_unique(array_column($entries, 'leaderId'))));

        $history = [];
        foreach ($entries as $form) {
            $isLeader = $form['leaderId'] === $userId;
            $history[] = [
                'submissionId' => $form['submissionId'],
                'round' => $rounds[$form['reviewRoundId']] ?? null,
                'date' => $form['date'],
                'leaderName' => $leaderNames[$form['leaderId']] ?? '',
                'isLeader' => $isLeader,
                'participation' => $isLeader ? null : ($form['participation'][$userId] ?? null),
                'generalComments' => $isLeader ? $form['generalComments'] : null,
            ];
        }

        return $history;
    }

    public function getContributionLabels(int $contextId, int $userId, ?int $year): array
    {
        $labels = (new ParticipationConfiguration())->get($contextId)['options'];
        foreach ($this->getHistory($contextId, $userId, $year) as $entry) {
            $labels = array_replace($labels, $entry['participation']['shapingFeedbackLabels'] ?? []);
        }
        return $labels;
    }

    private function rounds(array $roundIds): array
    {
        if (!$roundIds) {
            return [];
        }

        $rounds = [];
        foreach (DB::table('review_rounds')->whereIn('review_round_id', $roundIds)->get(['review_round_id', 'round']) as $row) {
            $rounds[(int) $row->review_round_id] = (int) $row->round;
        }

        return $rounds;
    }

    private function userNames(array $userIds): array
    {
        $userIds = array_values(array_filter($userIds));
        if (!$userIds) {
            return [];
        }
        $names = [];
        foreach (Repo::user()->getCollector()->filterByUserIds($userIds)->getMany() as $user) {
            $names[(int) $user->getId()] = $user->getFullName();
        }

        return $names;
    }

    // Loading

    /**
     * Load every counted poll in the journal, and the reviewers.
     *
     * A poll counts when it's finalised or expired. A poll still marked open
     * after its deadline counts as expired, because the plugin only updates
     * its status when someone next views it.
     *
     * @return array{
     *   polls: array<int, array>,
     *   forms: array<int, array>,
     *   roleHolders: array<int, array{rgl:bool, rgm:bool}>,
     *   reviewers: array<int, string>
     * }
     */
    private function load(int $contextId): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $sessions = DB::table('group_review_sessions')
            ->where('context_id', $contextId)
            ->where(function ($query) use ($now) {
                $query->whereIn('status', [GroupReviewService::STATUS_FINALIZED, GroupReviewService::STATUS_EXPIRED])
                    ->orWhere(function ($query) use ($now) {
                        $query->where('status', GroupReviewService::STATUS_OPEN)->where('deadline_utc', '<=', $now);
                    });
            })
            ->get();
        $sessionIds = $sessions->pluck('session_id')->map(fn ($id): int => (int) $id)->all();

        $slotTimes = [];
        foreach (DB::table('group_review_slots')->whereIn('session_id', $sessionIds)->get() as $slot) {
            $slotTimes[(int) $slot->session_id][(int) $slot->slot_id] = (string) $slot->start_time_utc;
        }

        $available = [];
        foreach (DB::table('group_review_availability')->whereIn('session_id', $sessionIds)->select('session_id', 'user_id')->distinct()->get() as $row) {
            $available[(int) $row->session_id][(int) $row->user_id] = true;
        }

        $members = [];
        foreach (DB::table('group_review_members')->whereIn('session_id', $sessionIds)->get() as $row) {
            $sessionId = (int) $row->session_id;
            $userId = (int) $row->user_id;
            $members[$sessionId][$userId] = [
                'available' => isset($available[$sessionId][$userId]),
                'responded' => $row->responded_at !== null || isset($available[$sessionId][$userId]),
                'selected' => (bool) $row->selected,
                'invitedAt' => (string) $row->invited_at,
                'respondedAt' => $row->responded_at === null ? null : (string) $row->responded_at,
            ];
        }

        $formRows = DB::table('group_review_participation_forms')
            ->where('context_id', $contextId)
            ->where('status', '!=', ParticipationService::STATUS_CANCELLED)
            ->get();
        $submittedFormRows = $formRows->where('status', ParticipationService::STATUS_SUBMITTED);
        $submittedForms = $submittedFormRows->pluck('form_id')->map(fn ($id): int => (int) $id)->all();
        $generalComments = [];
        $configurations = [];
        $hasMeeting = [];
        foreach ($submittedFormRows as $row) {
            $generalComments[(int) $row->form_id] = $row->general_comments === null ? null : (string) $row->general_comments;
            $configurations[(int) $row->form_id] = $row->configuration === null ? ParticipationConfiguration::defaults()
                : json_decode($row->configuration, true, 512, JSON_THROW_ON_ERROR);
            $hasMeeting[(int) $row->form_id] = (bool) $row->has_meeting;
        }

        $participation = [];
        foreach (DB::table('group_review_participation')->whereIn('form_id', $submittedForms)->get([
            'form_id', 'reviewer_user_id', 'attendance', 'attendance_other', 'contribution_comments',
            'shaping_feedback_types', 'shaping_feedback_comments', 'other_contribution',
        ]) as $row) {
            if (!$hasMeeting[(int) $row->form_id]) {
                $row->attendance = ParticipationService::ATTENDANCE_NOT_RECORDED;
                $row->attendance_other = $row->contribution_comments = null;
            }
            $participation[(int) $row->form_id][(int) $row->reviewer_user_id] = [
                'attendance' => (string) $row->attendance,
                'attendanceOther' => $row->attendance_other === null ? null : (string) $row->attendance_other,
                'contributionComments' => $row->contribution_comments === null ? null : (string) $row->contribution_comments,
                'shapingFeedbackTypes' => json_decode((string) $row->shaping_feedback_types, true) ?: [],
                'shapingFeedbackComments' => $row->shaping_feedback_comments === null ? null : (string) $row->shaping_feedback_comments,
                'otherContribution' => $row->other_contribution === null ? null : (string) $row->other_contribution,
            ];
            $record = &$participation[(int) $row->form_id][(int) $row->reviewer_user_id];
            $options = $configurations[(int) $row->form_id]['options'];
            if ($options !== ParticipationService::CONTRIBUTION_OPTIONS) {
                $record['shapingFeedbackLabels'] = $options;
            }
            $record['fieldLabels'] = array_map(fn (array $text): string => $text['label'], $configurations[(int) $row->form_id]['text'] ?? ParticipationConfiguration::defaults()['text']);
            unset($record);
        }

        $polls = [];
        foreach ($sessions as $session) {
            $sessionId = (int) $session->session_id;
            $finalized = (int) $session->status === GroupReviewService::STATUS_FINALIZED;
            $times = $slotTimes[$sessionId] ?? [];
            $date = $finalized && isset($times[(int) $session->selected_slot_id])
                ? $times[(int) $session->selected_slot_id]
                : ($times ? max($times) : (string) $session->deadline_utc);

            $polls[$sessionId] = [
                'leaderId' => (int) $session->leader_user_id,
                'finalized' => $finalized,
                'year' => $this->localYear($date, (string) $session->timezone),
                'members' => $members[$sessionId] ?? [],
            ];
        }

        $latestDecisions = $this->latestDecisions($formRows->pluck('review_round_id')->map(fn ($id): int => (int) $id)->all());
        $forms = [];
        foreach ($formRows as $row) {
            $formId = (int) $row->form_id;
            $forms[$formId] = [
                'submissionId' => (int) $row->submission_id,
                'reviewRoundId' => (int) $row->review_round_id,
                'leaderId' => (int) $row->leader_user_id,
                'reviewerIds' => json_decode($row->reviewer_ids, true),
                'date' => (string) $row->created_at,
                'year' => $this->localYear((string) $row->created_at, date_default_timezone_get()),
                'decided' => in_array($latestDecisions[(int) $row->review_round_id] ?? null, self::ROUND_ENDING_DECISIONS, true),
                'cancelled' => ($latestDecisions[(int) $row->review_round_id] ?? null) === Decision::CANCEL_REVIEW_ROUND,
                'generalComments' => $generalComments[$formId] ?? null,
                'participation' => $participation[$formId] ?? [],
            ];
        }

        [$roleHolders, $reviewers] = $this->reviewers($contextId, $polls, $forms);

        return ['polls' => $polls, 'forms' => $forms, 'roleHolders' => $roleHolders, 'reviewers' => $reviewers];
    }

    /**
     * The latest round-ending or reopening decision on each review round. A round
     * is decided when it's a round-ending one, and cancelled when it's a cancellation.
     *
     * @param int[] $roundIds
     *
     * @return array<int, int> review round ID => decision
     */
    private function latestDecisions(array $roundIds): array
    {
        $latest = [];
        $rows = DB::table('edit_decisions')
            ->whereIn('review_round_id', $roundIds)
            ->whereIn('decision', array_merge(self::ROUND_ENDING_DECISIONS, self::ROUND_REOPENING_DECISIONS))
            ->orderBy('date_decided')
            ->orderBy('edit_decision_id')
            ->get(['review_round_id', 'decision']);
        foreach ($rows as $row) {
            $latest[(int) $row->review_round_id] = (int) $row->decision;
        }

        return $latest;
    }

    /**
     * Current RGM/RGL role holders, and every reviewer: role holders plus anyone
     * invited to or leading a counted poll, or in a review group. Disabled
     * accounts are left out.
     *
     * @return array{0: array<int, array{rgl:bool, rgm:bool}>, 1: array<int, string>}
     */
    private function reviewers(int $contextId, array $polls, array $forms): array
    {
        $groupService = new GroupReviewService();
        $groupIds = [
            'rgl' => (int) $groupService->getUserGroupIdByAbbreviation($contextId, 'RGL'),
            'rgm' => (int) $groupService->getUserGroupIdByAbbreviation($contextId, 'RGM'),
        ];

        $holders = [];
        foreach (DB::table('user_user_groups')->whereIn('user_group_id', array_filter($groupIds))->get(['user_id', 'user_group_id']) as $row) {
            $userId = (int) $row->user_id;
            $holders[$userId] ??= ['rgl' => false, 'rgm' => false];
            $holders[$userId][array_search((int) $row->user_group_id, $groupIds, true)] = true;
        }

        $userIds = array_keys($holders);
        foreach ($polls as $poll) {
            $userIds = array_merge($userIds, array_keys($poll['members']), [$poll['leaderId']]);
        }
        foreach ($forms as $form) {
            $userIds = array_merge($userIds, $form['reviewerIds'], [$form['leaderId']]);
        }
        $userIds = array_values(array_unique(array_filter($userIds)));
        if (!$userIds) {
            return [[], []];
        }

        // The collector only returns active accounts, which leaves out disabled ones.
        $reviewers = [];
        foreach (Repo::user()->getCollector()->filterByUserIds($userIds)->getMany() as $user) {
            $reviewers[(int) $user->getId()] = $user->getFullName();
        }

        return [array_intersect_key($holders, $reviewers), $reviewers];
    }

    // Counting

    private function reviewerRow(array $data, int $userId, string $name, ?int $year): array
    {
        $row = [
            'userId' => $userId,
            'name' => $name,
            'invited' => 0,
            'available' => 0,
            'notAvailable' => 0,
            'noResponse' => 0,
            'selected' => 0,
            'notSelected' => 0,
            'timesLed' => 0,
            'completed' => 0,
            'submissionsReviewed' => 0,
            'current' => 0,
            'attended' => 0,
            'attendanceRecorded' => 0,
            'reviewerSince' => null,
            'lastActivity' => null,
        ];
        foreach ($data['polls'] as $poll) {
            $member = $poll['members'][$userId] ?? null;

            // All time: when they started and when they were last active.
            if ($member) {
                $row['reviewerSince'] = $this->earliest($row['reviewerSince'], $member['invitedAt']);
                $row['lastActivity'] = $this->latest($row['lastActivity'], $member['invitedAt'], $member['respondedAt']);
            }

            if (!$this->inYear($poll, $year)) {
                continue;
            }
            if ($member) {
                $row['invited']++;
                if ($member['available']) {
                    $row['available']++;
                } elseif ($member['responded']) {
                    $row['notAvailable']++;
                } else {
                    $row['noResponse']++;
                }
                if ($poll['finalized'] && $member['selected']) {
                    $row['selected']++;
                }
            }
        }

        $completedSubmissions = [];
        foreach ($data['forms'] as $form) {
            if (!$this->inGroup($form, $userId)) {
                continue;
            }
            // All time: when they started, when they were last active, and Current.
            $row['reviewerSince'] = $this->earliest($row['reviewerSince'], $form['date']);
            $row['lastActivity'] = $this->latest($row['lastActivity'], $form['date']);
            if (!$form['decided']) {
                $row['current']++;
            }

            if (!$this->inYear($form, $year)) {
                continue;
            }
            if ($form['leaderId'] === $userId) {
                $row['timesLed']++;
            }
            if ($form['decided'] && !$form['cancelled']) {
                $row['completed']++;
                $completedSubmissions[$form['submissionId']] = true;
            }
            $answer = $form['participation'][$userId]['attendance'] ?? null;
            if ($answer !== null && in_array($answer, self::RECORDED_ATTENDANCE, true)) {
                $row['attendanceRecorded']++;
                if ($answer === ParticipationService::ATTENDANCE_ATTENDED) {
                    $row['attended']++;
                }
            }
        }
        $row['notSelected'] = $row['available'] - $row['selected'];
        $row['submissionsReviewed'] = count($completedSubmissions);

        return $row;
    }

    private function inGroup(array $form, int $userId): bool
    {
        return $form['leaderId'] === $userId || in_array($userId, $form['reviewerIds'], true);
    }

    /**
     * Label Total (current RGM/RGL holders with the value) and Active (of those,
     * active in the year), per value and for Not set.
     *
     * @param int[] $holderIds
     * @param array<int, bool> $active user ID => gave a time or was in a review group in the year
     */
    private function labelCounts(int $contextId, array $holderIds, array $active): array
    {
        $labelService = new ReviewerLabelService();
        $labels = $labelService->getLabels($contextId, $holderIds);
        $types = $labelService->getTypes($contextId);

        $counts = [];
        foreach ($types as $type => $definition) {
            $counts[$type] = [
                'values' => array_fill_keys(array_keys($definition['values']), ['total' => 0, 'active' => 0]),
                'notSet' => ['total' => 0, 'active' => 0],
            ];
            foreach ($labels as $userId => $userLabels) {
                $isActive = !empty($active[$userId]);
                if (!$userLabels[$type]) {
                    $counts[$type]['notSet']['total']++;
                    $counts[$type]['notSet']['active'] += $isActive ? 1 : 0;
                    continue;
                }
                foreach ($userLabels[$type] as $value) {
                    $counts[$type]['values'][$value]['total']++;
                    $counts[$type]['values'][$value]['active'] += $isActive ? 1 : 0;
                }
            }
        }

        return $counts;
    }

    /**
     * The calendar year of a UTC date in the poll's own timezone, or the site's for a
     * form, so a date on the morning of 1 January local time counts in the new year.
     */
    private function localYear(string $utcDate, string $timezone): int
    {
        try {
            $zone = new DateTimeZone($timezone !== '' ? $timezone : 'UTC');
        } catch (Exception $e) {
            $zone = new DateTimeZone('UTC');
        }

        return (int) (new DateTimeImmutable($utcDate, new DateTimeZone('UTC')))->setTimezone($zone)->format('Y');
    }

    private function inYear(array $poll, ?int $year): bool
    {
        return $year === null || $poll['year'] === $year;
    }

    private function earliest(?string $current, ?string ...$dates): ?string
    {
        foreach ($dates as $date) {
            if ($date !== null && ($current === null || $date < $current)) {
                $current = $date;
            }
        }

        return $current;
    }

    private function latest(?string $current, ?string ...$dates): ?string
    {
        foreach ($dates as $date) {
            if ($date !== null && ($current === null || $date > $current)) {
                $current = $date;
            }
        }

        return $current;
    }
}
