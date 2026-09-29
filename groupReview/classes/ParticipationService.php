<?php

/**
 * @file classes/ParticipationService.php
 *
 * Data access for reviewer participation forms and their per-reviewer records.
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
    public const ATTENDANCE_NOT_RECORDED = 'not_recorded';
    public const ATTENDANCE_OTHER = 'other';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';

    /** Submission event log types for participation form activity. */
    public const LOG_FORM_EDITED = 0xA0000001;
    public const LOG_FORM_SUBMITTED = 0xA0000002;

    private const ATTENDANCE_VALUES = [
        self::ATTENDANCE_ATTENDED,
        self::ATTENDANCE_APOLOGY,
        self::ATTENDANCE_NO_APOLOGY,
        self::ATTENDANCE_NOT_APPLICABLE,
        self::ATTENDANCE_NOT_RECORDED,
        self::ATTENDANCE_OTHER,
    ];

    private const SHAPING_VALUES = [
        'uploaded_notes',
        'commented_on_draft',
        'offered_creating_draft',
        'created_draft',
        'did_not_contribute',
        'other',
    ];

    /**
     * Save the participation form for a group-review session.
     *
     * Each reviewer entry is upserted, then the form-level record is updated
     * with who saved it and when. Submitting records who submitted and the
     * optional comment; submitted forms stay editable and can be resubmitted.
     * Submission, review round and leader identifiers are derived from the
     * session rather than trusted from request data.
     *
     * @param array<int, array<string, mixed>> $reviewers
     * @param array{general_comments?:?string, submission_comment?:?string} $form
     *
     * @return array{
     *   status:string,
     *   reviewers:array<int, array{participation_id:int, created:bool}>
     * }
     */
    public function saveForm(
        int $contextId,
        int $sessionId,
        int $userId,
        array $reviewers,
        array $form = [],
        bool $submit = false
    ): array {
        return DB::transaction(function () use ($contextId, $sessionId, $userId, $reviewers, $form, $submit): array {
            $session = DB::table('group_review_sessions')
                ->where('context_id', $contextId)
                ->where('session_id', $sessionId)
                ->lockForUpdate()
                ->first();
            if (!$session) {
                throw new InvalidArgumentException('The group review session was not found.');
            }
            if (!$session->leader_user_id) {
                throw new InvalidArgumentException('The group review session has no leader.');
            }

            $saved = [];
            foreach ($reviewers as $data) {
                $reviewerUserId = $this->positiveInteger(
                    $data['reviewer_user_id'] ?? null,
                    'reviewer_user_id'
                );
                $saved[$reviewerUserId] = $this->saveReviewer($contextId, $session, $reviewerUserId, $data);
            }

            $this->saveFormRecord($contextId, $sessionId, $userId, $form, $submit);
            $current = $this->getFormRecord($contextId, $sessionId);

            return [
                'status' => (string) $current['status'],
                'reviewers' => $saved,
            ];
        });
    }

    /**
     * Save the record for a single review group member. Used by the
     * saveParticipation JSON endpoint; the full form uses saveForm().
     *
     * @return array{participation_id:int,created:bool,status:string}
     */
    public function saveForSession(
        int $contextId,
        int $sessionId,
        int $userId,
        array $data
    ): array {
        $status = $data['status'] ?? self::STATUS_DRAFT;
        if (!in_array($status, [self::STATUS_DRAFT, self::STATUS_SUBMITTED], true)) {
            throw new InvalidArgumentException('The participation status is invalid.');
        }
        unset($data['status']);

        $saved = $this->saveForm($contextId, $sessionId, $userId, [$data], [], $status === self::STATUS_SUBMITTED);
        $reviewer = reset($saved['reviewers']);

        return [
            'participation_id' => $reviewer['participation_id'],
            'created' => $reviewer['created'],
            'status' => $saved['status'],
        ];
    }

    /**
     * Return the form-level record and the per-reviewer records keyed by
     * reviewer user ID. The form is null until the form is first saved.
     *
     * @return array{form:?array, reviewers:array<int, array>}
     */
    public function getForm(int $contextId, int $sessionId): array
    {
        $reviewers = [];
        foreach ($this->getForSession($contextId, $sessionId) as $record) {
            $reviewers[(int) $record['reviewer_user_id']] = $record;
        }

        return [
            'form' => $this->getFormRecord($contextId, $sessionId),
            'reviewers' => $reviewers,
        ];
    }

    /**
     * Return the submission's finalized group-review sessions, newest first,
     * optionally only those led by one user. A session has a participation
     * form once its members are selected.
     *
     * @return int[]
     */
    public function getFormSessionIds(int $contextId, int $submissionId, ?int $leaderUserId = null): array
    {
        $query = DB::table('group_review_sessions')
            ->where('context_id', $contextId)
            ->where('submission_id', $submissionId)
            ->where('status', GroupReviewService::STATUS_FINALIZED);
        if ($leaderUserId !== null) {
            $query->where('leader_user_id', $leaderUserId);
        }

        return $query->orderByDesc('session_id')
            ->limit(100)
            ->pluck('session_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Create a record and return its identifier.
     *
     * @param array{
     *   session_id:int, review_round_id:int, submission_id:int,
     *   reviewer_user_id:int, leader_user_id:int, attendance:string,
     *   attendance_other?:?string, contribution_comments?:?string,
     *   shaping_feedback_types?:string[], shaping_feedback_comments?:?string,
     *   other_contribution?:?string
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
            'attendance_other' => $record['attendance_other'],
            'contribution_comments' => $record['contribution_comments'],
            'shaping_feedback_types' => json_encode($record['shaping_feedback_types'], JSON_THROW_ON_ERROR),
            'shaping_feedback_comments' => $record['shaping_feedback_comments'],
            'other_contribution' => $record['other_contribution'],
            'created_at' => $now,
            'updated_at' => $now,
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
                'attendance_other' => $record['attendance_other'],
                'contribution_comments' => $record['contribution_comments'],
                'shaping_feedback_types' => json_encode($record['shaping_feedback_types'], JSON_THROW_ON_ERROR),
                'shaping_feedback_comments' => $record['shaping_feedback_comments'],
                'other_contribution' => $record['other_contribution'],
                'updated_at' => gmdate('Y-m-d H:i:s'),
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

    /** @return array{participation_id:int, created:bool} */
    private function saveReviewer(int $contextId, object $session, int $reviewerUserId, array $data): array
    {
        $isSelectedMember = DB::table('group_review_members')
            ->where('session_id', (int) $session->session_id)
            ->where('user_id', $reviewerUserId)
            ->where('selected', true)
            ->exists();
        if (!$isSelectedMember) {
            throw new InvalidArgumentException('The reviewer must be a selected member of this group review.');
        }

        $record = array_merge($data, [
            'session_id' => (int) $session->session_id,
            'review_round_id' => (int) $session->review_round_id,
            'submission_id' => (int) $session->submission_id,
            'reviewer_user_id' => $reviewerUserId,
            'leader_user_id' => (int) $session->leader_user_id,
        ]);
        $existing = DB::table('group_review_participation')
            ->where('context_id', $contextId)
            ->where('session_id', (int) $session->session_id)
            ->where('reviewer_user_id', $reviewerUserId)
            ->first();

        if ($existing) {
            $participationId = (int) $existing->participation_id;
            $this->update($contextId, $participationId, $record);
            return ['participation_id' => $participationId, 'created' => false];
        }

        return ['participation_id' => $this->create($contextId, $record), 'created' => true];
    }

    /**
     * Upsert the form-level record. Only form fields present in $form are
     * changed. A form stays submitted once submitted, even when edited later.
     */
    private function saveFormRecord(int $contextId, int $sessionId, int $userId, array $form, bool $submit): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $values = [
            'updated_at' => $now,
            'updated_by' => $userId,
        ];
        if (array_key_exists('general_comments', $form)) {
            $values['general_comments'] = $this->text($form['general_comments'], 'general comments');
        }
        if ($submit) {
            $values['status'] = self::STATUS_SUBMITTED;
            $values['submitted_at'] = $now;
            $values['submitted_by'] = $userId;
            $values['submission_comment'] = $this->text($form['submission_comment'] ?? null, 'submission comment');
        }

        $existing = $this->getFormRecord($contextId, $sessionId);
        if ($existing) {
            DB::table('group_review_participation_forms')
                ->where('form_id', (int) $existing['form_id'])
                ->update($values);
            return;
        }

        DB::table('group_review_participation_forms')->insert(array_merge([
            'context_id' => $contextId,
            'session_id' => $sessionId,
            'status' => self::STATUS_DRAFT,
            'created_at' => $now,
        ], $values));
    }

    private function getFormRecord(int $contextId, int $sessionId): ?array
    {
        $row = DB::table('group_review_participation_forms')
            ->where('context_id', $contextId)
            ->where('session_id', $sessionId)
            ->first();

        return $row ? (array) $row : null;
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

        $shapingTypes = $this->validateList($data['shaping_feedback_types'] ?? [], self::SHAPING_VALUES, 'shaping feedback types');
        $attendanceOther = $data['attendance'] === self::ATTENDANCE_OTHER
            ? $this->text($data['attendance_other'] ?? null, 'attendance details')
            : null;

        return [
            'session_id' => (int) $data['session_id'],
            'review_round_id' => (int) $data['review_round_id'],
            'submission_id' => (int) $data['submission_id'],
            'reviewer_user_id' => (int) $data['reviewer_user_id'],
            'leader_user_id' => (int) $data['leader_user_id'],
            'attendance' => $data['attendance'],
            'attendance_other' => $attendanceOther,
            'contribution_comments' => $this->text($data['contribution_comments'] ?? null, 'contribution comments'),
            'shaping_feedback_types' => $shapingTypes,
            'shaping_feedback_comments' => $this->text($data['shaping_feedback_comments'] ?? null, 'shaping feedback comments'),
            'other_contribution' => $this->text($data['other_contribution'] ?? null, 'other contribution'),
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
        $row['shaping_feedback_types'] = json_decode((string) $row['shaping_feedback_types'], true, 512, JSON_THROW_ON_ERROR);

        return $row;
    }
}
