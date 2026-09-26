<?php

/**
 * @file classes/ParticipationService.php
 *
 * Data access for reviewer participation records.
 */

namespace APP\plugins\generic\groupReview\classes;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ParticipationService
{
    public const ATTENDANCE_ATTENDED = 'attended';
    public const ATTENDANCE_APOLOGY = 'did_not_attend_with_apology';
    public const ATTENDANCE_NO_APOLOGY = 'did_not_attend_without_apology';
    public const ATTENDANCE_NOT_APPLICABLE = 'not_applicable';
    public const ATTENDANCE_OTHER = 'other';

    private const ATTENDANCE_VALUES = [
        self::ATTENDANCE_ATTENDED,
        self::ATTENDANCE_APOLOGY,
        self::ATTENDANCE_NO_APOLOGY,
        self::ATTENDANCE_NOT_APPLICABLE,
        self::ATTENDANCE_OTHER,
    ];

    private const CONTRIBUTION_VALUES = ['discussion', 'writing', 'analysis', 'editing', 'other'];
    private const SHAPING_VALUES = [
        'uploaded_notes',
        'commented_on_draft',
        'offered_creating_draft',
        'created_draft',
    ];
    private const STATUS_VALUES = ['draft', 'submitted'];

    /**
     * Save a selected reviewer's record for a group-review session.
     *
     * Submission, review round and leader identifiers are derived from the
     * authorized session rather than trusted from request data.
     *
     * @return array{participation_id:int,created:bool,status:string}
     */
    public function saveForSession(
        int $contextId,
        int $sessionId,
        int $leaderUserId,
        array $data
    ): array {
        return DB::transaction(function () use ($contextId, $sessionId, $leaderUserId, $data): array {
            $session = DB::table('group_review_sessions')
                ->where('context_id', $contextId)
                ->where('session_id', $sessionId)
                ->lockForUpdate()
                ->first();
            if (!$session) {
                throw new InvalidArgumentException('The group review session was not found.');
            }

            $reviewerUserId = $this->positiveInteger(
                $data['reviewer_user_id'] ?? null,
                'reviewer_user_id'
            );
            $isSelectedMember = DB::table('group_review_members')
                ->where('session_id', $sessionId)
                ->where('user_id', $reviewerUserId)
                ->where('selected', true)
                ->exists();
            if (!$isSelectedMember) {
                throw new InvalidArgumentException('The reviewer must be a selected member of this group review.');
            }

            $record = array_merge($data, [
                'session_id' => $sessionId,
                'review_round_id' => (int) $session->review_round_id,
                'submission_id' => (int) $session->submission_id,
                'reviewer_user_id' => $reviewerUserId,
                'leader_user_id' => $leaderUserId,
            ]);
            $existing = DB::table('group_review_participation')
                ->where('context_id', $contextId)
                ->where('session_id', $sessionId)
                ->where('reviewer_user_id', $reviewerUserId)
                ->first();
            if ($existing && (string) $existing->status === 'submitted') {
                throw new InvalidArgumentException('Submitted participation records cannot be changed by a Review Group Leader.');
            }

            if ($existing) {
                $participationId = (int) $existing->participation_id;
                $this->update($contextId, $participationId, $record);
                $created = false;
            } else {
                $participationId = $this->create($contextId, $record);
                $created = true;
            }

            return [
                'participation_id' => $participationId,
                'created' => $created,
                'status' => (string) ($record['status'] ?? 'draft'),
            ];
        });
    }

    /**
     * Create a record and return its identifier.
     *
     * @param array{
     *   session_id:int, review_round_id:int, submission_id:int,
     *   reviewer_user_id:int, leader_user_id:int, attendance:string,
     *   contribution_types?:string[], contribution_comments?:?string,
     *   shaping_feedback_types?:string[], shaping_feedback_comments?:?string,
     *   other_contribution?:?string, status?:string
     * } $data
     */
    public function create(int $contextId, array $data): int
    {
        $record = $this->validate($contextId, $data);
        $now = gmdate('Y-m-d H:i:s');

        return (int) DB::table('group_review_participation')->insertGetId([
            'context_id' => $contextId,
            'session_id' => $record['session_id'],
            'review_round_id' => $record['review_round_id'],
            'submission_id' => $record['submission_id'],
            'reviewer_user_id' => $record['reviewer_user_id'],
            'leader_user_id' => $record['leader_user_id'],
            'attendance' => $record['attendance'],
            'contribution_types' => json_encode($record['contribution_types'], JSON_THROW_ON_ERROR),
            'contribution_comments' => $record['contribution_comments'],
            'shaping_feedback_types' => json_encode($record['shaping_feedback_types'], JSON_THROW_ON_ERROR),
            'shaping_feedback_comments' => $record['shaping_feedback_comments'],
            'other_contribution' => $record['other_contribution'],
            'status' => $record['status'],
            'created_at' => $now,
            'updated_at' => $now,
            'submitted_at' => $record['status'] === 'submitted' ? $now : null,
        ], 'participation_id');
    }

    /** Return one record belonging to the journal, or null when it is absent. */
    public function get(int $contextId, int $participationId): ?array
    {
        $row = DB::table('group_review_participation')
            ->where('context_id', $contextId)
            ->where('participation_id', $participationId)
            ->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    /** Return all records for a group-review session, ordered by reviewer. */
    public function getForSession(int $contextId, int $sessionId): array
    {
        return DB::table('group_review_participation')
            ->where('context_id', $contextId)
            ->where('session_id', $sessionId)
            ->orderBy('reviewer_user_id')
            ->orderBy('participation_id')
            ->get()
            ->map(fn ($row): array => $this->hydrate((array) $row))
            ->all();
    }

    /** Return journal- and submission-scoped records, optionally for one reviewer. */
    public function getForSubmission(int $contextId, int $submissionId, ?int $reviewerUserId = null): array
    {
        $query = DB::table('group_review_participation')
            ->where('context_id', $contextId)
            ->where('submission_id', $submissionId);
        if ($reviewerUserId !== null) {
            $query->where('reviewer_user_id', $reviewerUserId);
        }

        return $query->orderBy('reviewer_user_id')
            ->orderBy('participation_id')
            ->get()
            ->map(fn ($row): array => $this->hydrate((array) $row))
            ->all();
    }

    /**
     * Restrict non-privileged readers to their own records even if no filter
     * was supplied. A request for another reviewer's records is forbidden.
     */
    public function readableReviewerId(?int $requestedReviewerId, int $userId, bool $canReadAll): ?int
    {
        if (!$canReadAll && $requestedReviewerId !== null && $requestedReviewerId !== $userId) {
            throw new \DomainException('You cannot view another reviewer\'s participation records.');
        }

        return $canReadAll ? $requestedReviewerId : $userId;
    }

    /** Update a record and return whether a journal-scoped row was changed. */
    public function update(int $contextId, int $participationId, array $data): bool
    {
        $current = $this->get($contextId, $participationId);
        if (!$current) {
            return false;
        }

        $record = $this->validate($contextId, array_merge($current, $data));
        $now = gmdate('Y-m-d H:i:s');
        $updated = DB::table('group_review_participation')
            ->where('context_id', $contextId)
            ->where('participation_id', $participationId)
            ->update([
                'session_id' => $record['session_id'],
                'review_round_id' => $record['review_round_id'],
                'submission_id' => $record['submission_id'],
                'reviewer_user_id' => $record['reviewer_user_id'],
                'leader_user_id' => $record['leader_user_id'],
                'attendance' => $record['attendance'],
                'contribution_types' => json_encode($record['contribution_types'], JSON_THROW_ON_ERROR),
                'contribution_comments' => $record['contribution_comments'],
                'shaping_feedback_types' => json_encode($record['shaping_feedback_types'], JSON_THROW_ON_ERROR),
                'shaping_feedback_comments' => $record['shaping_feedback_comments'],
                'other_contribution' => $record['other_contribution'],
                'status' => $record['status'],
                'updated_at' => $now,
                'submitted_at' => $record['status'] === 'submitted'
                    ? ($current['submitted_at'] ?? $now)
                    : null,
            ]);

        return (bool) $updated;
    }

    /** Delete a journal-scoped participation record. */
    public function delete(int $contextId, int $participationId): bool
    {
        return (bool) DB::table('group_review_participation')
            ->where('context_id', $contextId)
            ->where('participation_id', $participationId)
            ->delete();
    }

    private function validate(int $contextId, array $data): array
    {
        if ($contextId <= 0) {
            throw new InvalidArgumentException('The context ID must be positive.');
        }

        foreach (['session_id', 'review_round_id', 'submission_id', 'reviewer_user_id', 'leader_user_id'] as $key) {
            $data[$key] = $this->positiveInteger($data[$key] ?? null, $key);
        }
        if (!isset($data['attendance']) || !in_array($data['attendance'], self::ATTENDANCE_VALUES, true)) {
            throw new InvalidArgumentException('The attendance value is invalid.');
        }

        $contributionTypes = $this->validateList($data['contribution_types'] ?? [], self::CONTRIBUTION_VALUES, 'contribution types');
        $shapingTypes = $this->validateList($data['shaping_feedback_types'] ?? [], self::SHAPING_VALUES, 'shaping feedback types');
        $status = $data['status'] ?? 'draft';
        if (!in_array($status, self::STATUS_VALUES, true)) {
            throw new InvalidArgumentException('The participation status is invalid.');
        }

        return [
            'session_id' => (int) $data['session_id'],
            'review_round_id' => (int) $data['review_round_id'],
            'submission_id' => (int) $data['submission_id'],
            'reviewer_user_id' => (int) $data['reviewer_user_id'],
            'leader_user_id' => (int) $data['leader_user_id'],
            'attendance' => $data['attendance'],
            'contribution_types' => $contributionTypes,
            'contribution_comments' => $this->text($data['contribution_comments'] ?? null, 'contribution comments'),
            'shaping_feedback_types' => $shapingTypes,
            'shaping_feedback_comments' => $this->text($data['shaping_feedback_comments'] ?? null, 'shaping feedback comments'),
            'other_contribution' => $this->text($data['other_contribution'] ?? null, 'other contribution'),
            'status' => $status,
        ];
    }

    private function positiveInteger(mixed $value, string $label): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new InvalidArgumentException("The {$label} must be a positive integer.");
        }

        return (int) $value;
    }

    private function validateList(mixed $value, array $allowed, string $label): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException("The {$label} must be an array.");
        }
        foreach ($value as $item) {
            if (!is_string($item) || !in_array($item, $allowed, true)) {
                throw new InvalidArgumentException("The {$label} contain an invalid value.");
            }
        }

        return array_values(array_unique($value));
    }

    private function text(mixed $value, string $label): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || mb_strlen($value) > 5000) {
            throw new InvalidArgumentException("The {$label} must be a string of 5000 characters or fewer.");
        }

        return trim($value);
    }

    private function hydrate(array $row): array
    {
        foreach (['contribution_types', 'shaping_feedback_types'] as $field) {
            $row[$field] = json_decode((string) $row[$field], true, 512, JSON_THROW_ON_ERROR);
        }

        return $row;
    }
}
