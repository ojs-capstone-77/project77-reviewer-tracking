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
    }

    public function expected(?int $year): array
    {
        $polls = array_filter($this->fixture['polls'], function (array $poll): bool {
            return in_array($poll['status'], [1, 3], true)
                || ($poll['status'] === 0 && ($poll['deadline'] ?? $poll['created'] + 864000) <= time());
        });
        $reviewers = array_filter($this->fixture['users'], function (array $user, string $username) use ($polls): bool {
            if ($user['disabled'] ?? false) {
                return false;
            }
            return array_intersect($user['roles'], ['rgl', 'rgm'])
                || array_filter($polls, fn ($poll): bool => $poll['leader'] === $username || array_key_exists($username, $poll['invited']));
        }, ARRAY_FILTER_USE_BOTH);
        $holders = array_filter($reviewers, fn ($user): bool => (bool) array_intersect($user['roles'], ['rgl', 'rgm']));
        $filtered = array_filter($polls, fn ($poll): bool => $year === null || $this->year($poll) === $year);
        $groups = array_filter($filtered, fn ($poll): bool => $poll['status'] === 1);
        $rows = $attendance = $contributions = $histories = $active = [];
        foreach ($reviewers as $username => $user) {
            $invitations = array_filter($filtered, fn ($poll): bool => array_key_exists($username, $poll['invited']));
            $available = array_filter($invitations, fn ($poll): bool => !empty($poll['invited'][$username]));
            $unavailable = array_filter($invitations, fn ($poll): bool => $poll['invited'][$username] === []);
            $selected = array_filter($groups, fn ($poll): bool => in_array($username, $poll['selected'] ?? [], true));
            $led = array_filter($groups, fn ($poll): bool => $poll['leader'] === $username);
            $joined = $selected + $led;
            $allJoined = array_filter($polls, fn ($poll): bool => $poll['status'] === 1
                && ($poll['leader'] === $username || in_array($username, $poll['selected'] ?? [], true)));
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
            foreach ($allJoined as $poll) {
                if ($poll['leader'] === $username) {
                    $starts[] = $poll['created'];
                }
                if ($this->meeting($poll) <= time()) {
                    $activityDates[] = $this->meeting($poll);
                }
            }
            $attendance[$username] = $contributions[$username] = $histories[$username] = [];
            foreach ($filtered as $sessionId => $poll) {
                if (!($poll['form']['submitted'] ?? false)) {
                    continue;
                }
                $answer = $this->fixture['answers'][$sessionId][$username] ?? null;
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
            uasort($joined, fn ($a, $b): int => $this->meeting($b) <=> $this->meeting($a));
            foreach ($joined as $sessionId => $poll) {
                $isLeader = $poll['leader'] === $username;
                $submitted = $poll['form']['submitted'] ?? false;
                $histories[$username][] = [
                    'submissionId' => $poll['submissionId'],
                    'round' => $poll['round'],
                    'date' => gmdate('Y-m-d H:i:s', $this->meeting($poll)),
                    'timezone' => $poll['timezone'],
                    'leaderName' => $this->fixture['users'][$poll['leader']]['name'],
                    'isLeader' => $isLeader,
                    'participation' => !$isLeader && $submitted ? ($this->fixture['answers'][$sessionId][$username] ?? null) : null,
                    'generalComments' => $isLeader && $submitted ? ($this->fixture['generalComments'][$sessionId] ?? null) : null,
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
                'completed' => count(array_filter($joined, fn ($poll): bool => $this->completed($poll))),
                'current' => count(array_filter($allJoined, fn ($poll): bool => empty($poll['decision']))),
                'attended' => $attendance[$username]['attended'] ?? 0,
                'attendanceRecorded' => array_sum(array_intersect_key($attendance[$username], array_flip([
                    'attended', 'did_not_attend_with_apology', 'did_not_attend_without_apology',
                ]))),
                'reviewerSince' => $starts ? gmdate('Y-m-d H:i:s', min($starts)) : null,
                'lastActivity' => $activityDates ? gmdate('Y-m-d H:i:s', max($activityDates)) : null,
            ];
            $active[$username] = (bool) ($available || $led);
        }
        uasort($rows, fn ($a, $b): int => strcasecmp($a['name'], $b['name']));
        $activity = ['invited' => 0, 'participated' => 0, 'notSelected' => 0, 'inactive' => 0, 'notInvited' => 0];
        foreach ($rows as $username => $row) {
            if ($row['selected'] || $row['timesLed']) {
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
        $activity['completed'] = count(array_filter($groups, fn ($poll): bool => $this->completed($poll)));
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
        $years = array_unique(array_map(fn ($poll): int => $this->year($poll), $polls));
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
                    'currentGroups' => count(array_filter($polls, fn ($poll): bool => $poll['status'] === 1 && empty($poll['decision']))),
                ],
                'activity' => $activity,
                'labels' => $labels,
            ],
        ];
    }

    private function completed(array $poll): bool
    {
        return !empty($poll['decision']) && $poll['decision'] !== \PKP\decision\Decision::CANCEL_REVIEW_ROUND;
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
