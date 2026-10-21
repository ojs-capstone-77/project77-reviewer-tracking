<?php

/**
 * Independent oracle built from fixture inputs, never from ReviewerStatsService
 * output or database aggregates. Unknown/extra result fields are checked too.
 */
class ReviewerStatsExpected
{
    private const LABELS = [
        'experience_level' => ['novice', 'intermediate', 'experienced'],
        'methodology' => ['quantitative', 'qualitative', 'mixed_methods'],
        'expertise' => ['education', 'statistics'],
    ];

    public function __construct(private array $fixture)
    {
        $this->fixture += ['forms' => [], 'decisions' => [], 'answers' => [], 'generalComments' => []];
    }

    public function expected(?int $year): array
    {
        $polls = array_filter($this->fixture['polls'], function (array $poll): bool {
            return in_array($poll['status'], [1, 3], true)
                || ($poll['status'] === 0 && ($poll['deadline'] ?? $poll['created'] + 864000) <= time());
        });
        $forms = $this->fixture['forms'];
        $inGroup = fn (array $form, string $username): bool => $form['leader'] === $username || in_array($username, $form['reviewers'], true);
        $reviewers = array_filter($this->fixture['users'], function (array $user, string $username) use ($polls, $forms, $inGroup): bool {
            if ($user['disabled'] ?? false) {
                return false;
            }
            return array_intersect($user['roles'], ['rgl', 'rgm'])
                || array_filter($polls, fn ($poll): bool => $poll['leader'] === $username || array_key_exists($username, $poll['invited']))
                || array_filter($forms, fn ($form): bool => $inGroup($form, $username));
        }, ARRAY_FILTER_USE_BOTH);
        $holders = array_filter($reviewers, fn ($user): bool => (bool) array_intersect($user['roles'], ['rgl', 'rgm']));
        $filtered = array_filter($polls, fn ($poll): bool => $year === null || $this->year($poll) === $year);
        $groups = array_filter($forms, fn ($form): bool => $year === null || $this->formYear($form) === $year);
        $rows = $attendance = $contributions = $histories = $active = [];
        foreach ($reviewers as $username => $user) {
            $invitations = array_filter($filtered, fn ($poll): bool => array_key_exists($username, $poll['invited']));
            $available = array_filter($invitations, fn ($poll): bool => !empty($poll['invited'][$username]));
            $unavailable = array_filter($invitations, fn ($poll): bool => $poll['invited'][$username] === []);
            $selected = array_filter($filtered, fn ($poll): bool => $poll['status'] === 1 && in_array($username, $poll['selected'] ?? [], true));
            $led = array_filter($groups, fn ($form): bool => $form['leader'] === $username);
            $joined = array_filter($groups, fn ($form): bool => $inGroup($form, $username));
            $allJoined = array_filter($forms, fn ($form): bool => $inGroup($form, $username));
            $starts = $activityDates = [];
            foreach ($polls as $poll) {
                if (array_key_exists($username, $poll['invited'])) {
                    $starts[] = $poll['created'];
                    $activityDates[] = $poll['created'];
                    if ($poll['invited'][$username] !== null) {
                        $activityDates[] = $poll['created'] + 172800;
                    }
                }
            }
            foreach ($allJoined as $form) {
                $starts[] = $form['created'];
                $activityDates[] = $form['created'];
            }
            $attendance[$username] = $contributions[$username] = $histories[$username] = [];
            foreach ($groups as $formId => $form) {
                if (!$form['submitted']) {
                    continue;
                }
                $answer = $this->fixture['answers'][$formId][$username] ?? null;
                if (!$answer) {
                    continue;
                }
                if ($answer['attendance'] !== 'not_recorded') {
                    $key = $answer['attendance'];
                    $attendance[$username][$key] = ($attendance[$username][$key] ?? 0) + 1;
                }
                foreach ($answer['shapingFeedbackTypes'] as $key) {
                    $contributions[$username][$key] = ($contributions[$username][$key] ?? 0) + 1;
                }
            }
            uasort($joined, fn ($a, $b): int => $b['created'] <=> $a['created']);
            foreach ($joined as $formId => $form) {
                $isLeader = $form['leader'] === $username;
                $answer = !$isLeader && $form['submitted'] ? ($this->fixture['answers'][$formId][$username] ?? null) : null;
                $histories[$username][] = [
                    'submissionId' => $form['submissionId'],
                    'round' => (int) \Illuminate\Support\Facades\DB::table('review_rounds')->where('review_round_id', $form['roundId'])->value('round'),
                    'date' => gmdate('Y-m-d H:i:s', $form['created']),
                    'leaderName' => $this->fixture['users'][$form['leader']]['name'],
                    'isLeader' => $isLeader,
                    'participation' => $answer ? $answer + ['fieldLabels' => array_map(fn (array $text): string => $text['label'], \APP\plugins\generic\groupReview\classes\ParticipationConfiguration::defaults()['text'])] : null,
                    'generalComments' => $isLeader && $form['submitted'] ? ($this->fixture['generalComments'][$formId] ?? null) : null,
                ];
            }
            $rows[$username] = [
                'userId' => $user['id'],
                'name' => $user['name'],
                'invited' => count($invitations),
                'available' => count($available),
                'notAvailable' => count($unavailable),
                'noResponse' => count($invitations) - count($available) - count($unavailable),
                'selected' => count($selected),
                'notSelected' => count($available) - count($selected),
                'timesLed' => count($led),
                'completed' => count(array_filter($joined, fn ($form): bool => $this->completed($form))),
                'submissionsReviewed' => count(array_unique(array_column(array_filter($joined, fn ($form): bool => $this->completed($form)), 'submissionId'))),
                'current' => count(array_filter($allJoined, fn ($form): bool => !$this->decided($form))),
                'attended' => $attendance[$username]['attended'] ?? 0,
                'attendanceRecorded' => array_sum(array_intersect_key($attendance[$username], array_flip([
                    'attended', 'did_not_attend_with_apology', 'did_not_attend_without_apology',
                ]))),
                'reviewerSince' => $starts ? gmdate('Y-m-d H:i:s', min($starts)) : null,
                'lastActivity' => $activityDates ? gmdate('Y-m-d H:i:s', max($activityDates)) : null,
            ];
            $active[$username] = (bool) ($available || $joined);
        }
        uasort($rows, fn ($a, $b): int => strcasecmp($a['name'], $b['name']));
        $activity = ['invited' => 0, 'participated' => 0, 'notSelected' => 0, 'inactive' => 0, 'notInvited' => 0];
        foreach ($rows as $username => $row) {
            if (array_filter($groups, fn ($form): bool => $inGroup($form, $username))) {
                $activity['participated']++;
                $activity['invited']++;
            } elseif ($row['available']) {
                $activity['notSelected']++;
                $activity['invited']++;
            } elseif ($row['invited']) {
                $activity['inactive']++;
                $activity['invited']++;
            } elseif (isset($holders[$username])) {
                $activity['notInvited']++;
            }
        }
        $activity['reviewGroups'] = count($groups);
        $activity['completed'] = count(array_filter($groups, fn ($form): bool => $this->completed($form)));
        $labels = [];
        foreach (self::LABELS as $type => $values) {
            $labels[$type] = ['values' => [], 'notSet' => ['total' => 0, 'active' => 0]];
            foreach ($values as $value) {
                $labels[$type]['values'][$value] = ['total' => 0, 'active' => 0];
            }
            foreach ($holders as $username => $user) {
                $values = $this->fixture['labels'][$username][$type] ?? [];
                foreach ($values ?: [null] as $value) {
                    if ($value === null) {
                        $counts = &$labels[$type]['notSet'];
                    } else {
                        $counts = &$labels[$type]['values'][$value];
                    }
                    $counts['total']++;
                    $counts['active'] += (int) $active[$username];
                    unset($counts);
                }
            }
        }
        $years = array_unique(array_merge(array_map(fn ($poll): int => $this->year($poll), $polls), array_map(fn ($form): int => $this->formYear($form), $forms)));
        rsort($years);
        return [
            'years' => $years,
            'rows' => $rows,
            'attendance' => $attendance,
            'contributions' => $contributions,
            'history' => $histories,
            'overview' => [
                'live' => [
                    'total' => count($holders),
                    'leaders' => count(array_filter($holders, fn ($user): bool => in_array('rgl', $user['roles'], true))),
                    'currentGroups' => count(array_filter($forms, fn ($form): bool => !$this->decided($form))),
                ],
                'activity' => $activity,
                'labels' => $labels,
            ],
        ];
    }

    private function decided(array $form): bool
    {
        return isset($this->fixture['decisions'][$form['roundId']]);
    }

    private function completed(array $form): bool
    {
        return $this->decided($form) && $this->fixture['decisions'][$form['roundId']] !== \PKP\decision\Decision::CANCEL_REVIEW_ROUND;
    }

    private function formYear(array $form): int
    {
        return (int) (new DateTimeImmutable('@' . $form['created']))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y');
    }

    private function meeting(array $poll): int
    {
        return $poll['status'] === 1 ? $poll['slots'][$poll['meetingSlot']] : max($poll['slots']);
    }

    private function year(array $poll): int
    {
        return (int) (new DateTimeImmutable('@' . $this->meeting($poll)))
            ->setTimezone(new DateTimeZone($poll['timezone']))->format('Y');
    }
}
