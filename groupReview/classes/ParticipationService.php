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
    public const ATTENDANCE_NOT_RECORDED = 'not_recorded';
    public const ATTENDANCE_OTHER = 'other';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_CANCELLED = 'cancelled';

    /** Submission event log types for participation form activity. */
    public const LOG_FORM_EDITED = 0xA0000001;
    public const LOG_FORM_SUBMITTED = 0xA0000002;

    public const ATTENDANCE_OPTIONS = [
        self::ATTENDANCE_NOT_RECORDED => 'Not recorded',
        self::ATTENDANCE_ATTENDED => 'Attended',
        self::ATTENDANCE_APOLOGY => 'Did not attend, prior apology',
        self::ATTENDANCE_NO_APOLOGY => 'Did not attend, no prior apology',
        self::ATTENDANCE_OTHER => 'Other',
    ];

    public const MEETING_FIELDS = ['attendance', 'contribution_comments'];

    public const CONTRIBUTION_OPTIONS = [
        'offered_creating_draft' => 'Offered to create the feedback draft',
        'selected_creating_draft' => 'Was selected to create the feedback draft',
        'uploaded_notes' => 'Uploaded their notes or comments',
        'created_draft' => 'Created the feedback draft',
        'commented_on_draft' => 'Commented on the feedback draft',
        'did_not_contribute' => 'Did not contribute to shaping the response',
        'other' => 'Other',
    ];

    public function createForm(int $contextId, int $submissionId, int $reviewRoundId, int $leaderUserId, array $reviewerIds): int
    {
        return DB::transaction(function () use ($contextId, $submissionId, $reviewRoundId, $leaderUserId, $reviewerIds): int {
            DB::table('review_rounds')->where('review_round_id', $reviewRoundId)->lockForUpdate()->first();
            if ($this->getActiveFormId($contextId, $submissionId, $reviewRoundId)) {
                throw new InvalidArgumentException('The review round already has a participation form.');
            }

            return (int) DB::table('group_review_participation_forms')->insertGetId([
                'context_id' => $contextId,
                'submission_id' => $submissionId,
                'review_round_id' => $reviewRoundId,
                'leader_user_id' => $leaderUserId,
                'reviewer_ids' => json_encode(array_values(array_unique($reviewerIds)), JSON_THROW_ON_ERROR),
                'status' => self::STATUS_DRAFT,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ], 'form_id');
        });
    }

    public function getActiveFormId(int $contextId, int $submissionId, int $reviewRoundId): ?int
    {
        $formId = DB::table('group_review_participation_forms')
            ->where('context_id', $contextId)
            ->where('submission_id', $submissionId)
            ->where('review_round_id', $reviewRoundId)
            ->where('status', '!=', self::STATUS_CANCELLED)
            ->value('form_id');

        return $formId === null ? null : (int) $formId;
    }

    public function cancelForm(int $contextId, int $formId): void
    {
        $cancelled = DB::table('group_review_participation_forms')
            ->where('context_id', $contextId)
            ->where('form_id', $formId)
            ->where('status', '!=', self::STATUS_CANCELLED)
            ->update(['status' => self::STATUS_CANCELLED]);
        if (!$cancelled) {
            throw new InvalidArgumentException('The participation form was not found or is already cancelled.');
        }
    }

    /**
     * Save a participation form.
     *
     * Each reviewer entry is upserted, then the form-level record is updated
     * with who saved it and when. Submitting records who submitted and the
     * optional comment; submitted forms stay editable and can be resubmitted.
     * Submission, review round and leader identifiers are derived from the
     * form rather than trusted from request data.
     *
     * @param array<int, array<string, mixed>> $reviewers
     * @param array{has_meeting?:bool, general_comments?:?string, submission_comment?:?string, configuration_token?:?string} $form
     *
     * @return array{
     *   status:string,
     *   reviewers:array<int, array{participation_id:int, created:bool}>,
     *   missing:array<int, array{field:string, reviewerUserId:int}>
     * }
     */
    public function saveForm(
        int $contextId,
        int $formId,
        int $userId,
        array $reviewers,
        array $form = [],
        bool $submit = false
    ): array {
        return DB::transaction(function () use ($contextId, $formId, $userId, $reviewers, $form, $submit): array {
            $record = DB::table('group_review_participation_forms')
                ->where('context_id', $contextId)
                ->where('form_id', $formId)
                ->lockForUpdate()
                ->first();
            if (!$record) {
                throw new InvalidArgumentException('The participation form was not found.');
            }
            if ($record->status === self::STATUS_CANCELLED) {
                throw new InvalidArgumentException('A cancelled participation form is read-only.');
            }

            $configuration = (new ParticipationConfiguration())->forForm($contextId, $formId);
            if (isset($form['configuration_token']) && $form['configuration_token'] !== ParticipationConfiguration::token($configuration)) {
                throw new InvalidArgumentException('The form configuration changed. Reload the form before saving.');
            }
            $this->saveFormRecord($record, $userId, $form, $configuration);
            $saved = [];
            foreach ($reviewers as $data) {
                $reviewerUserId = $this->positiveInteger(
                    $data['reviewer_user_id'] ?? null,
                    'reviewer_user_id'
                );
                $saved[$reviewerUserId] = $this->saveReviewer($contextId, $record, $reviewerUserId, $data);
            }

            $missing = [];
            if ($submit) {
                $missing = $this->missingRequired($contextId, $formId);
                if (!$missing) {
                    $this->recordSubmission($record, $userId, $form);
                }
            }

            $current = $this->getFormRecord($contextId, $formId);

            return [
                'status' => (string) $current['status'],
                'reviewers' => $saved,
                'missing' => $missing,
            ];
        });
    }

    public function missingRequired(int $contextId, int $formId): array
    {
        $saved = $this->getForm($contextId, $formId);
        if (!$saved['form']) {
            return [];
        }

        $required = array_keys(array_filter($this->requirements($contextId, $formId, true)['fields']));
        $missing = [];
        foreach ($required as $field) {
            foreach (json_decode($saved['form']['reviewer_ids'], true) as $reviewerUserId) {
                $record = $saved['reviewers'][(int) $reviewerUserId] ?? [];
                if ($this->isUnanswered($field, $record[$field] ?? null)) {
                    $missing[] = ['field' => $field, 'reviewerUserId' => (int) $reviewerUserId];
                }
            }
        }

        return $missing;
    }

    /**
     * Return the form-level record and the per-reviewer records keyed by
     * reviewer user ID. The form is null when it isn't in the journal.
     *
     * @return array{form:?array, reviewers:array<int, array>}
     */
    public function getForm(int $contextId, int $formId): array
    {
        $reviewers = [];
        foreach ($this->getForForm($contextId, $formId) as $record) {
            $reviewers[(int) $record['reviewer_user_id']] = $record;
        }

        return [
            'form' => $this->getFormRecord($contextId, $formId),
            'reviewers' => $reviewers,
        ];
    }

    /**
     * Return the submission's participation forms, newest first, optionally
     * only those led by one user.
     *
     * @return int[]
     */
    public function getFormIds(int $contextId, int $submissionId, ?int $leaderUserId = null): array
    {
        $query = DB::table('group_review_participation_forms')
            ->where('context_id', $contextId)
            ->where('submission_id', $submissionId);
        if ($leaderUserId !== null) {
            $query->where('leader_user_id', $leaderUserId);
        }

        return $query->orderByDesc('form_id')
            ->limit(100)
            ->pluck('form_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Create a record and return its identifier.
     *
     * @param array{
     *   form_id:int, review_round_id:int, submission_id:int,
     *   reviewer_user_id:int, leader_user_id:int, attendance:string,
     *   attendance_other?:?string, contribution_comments?:?string,
     *   shaping_feedback_types?:string[], shaping_feedback_comments?:?string,
     *   other_contribution?:?string
     * } $data
     */
    public function create(int $contextId, array $data, bool $submit = true): int
    {
        $record = $this->validate($contextId, $data, $this->requirements($contextId, (int) $data['form_id'], $submit));
        $now = gmdate('Y-m-d H:i:s');

        return (int) DB::table('group_review_participation')->insertGetId([
            'context_id' => $contextId,
            'form_id' => $record['form_id'],
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

    /** Return all records for a participation form, ordered by reviewer. */
    public function getForForm(int $contextId, int $formId): array
    {
        return DB::table('group_review_participation')
            ->where('context_id', $contextId)
            ->where('form_id', $formId)
            ->orderBy('reviewer_user_id')
            ->orderBy('participation_id')
            ->get()
            ->map(fn ($row): array => $this->hydrate((array) $row))
            ->all();
    }

    /** Update a record and return whether a journal-scoped row was changed. */
    public function update(int $contextId, int $participationId, array $data, bool $submit = true): bool
    {
        $current = $this->get($contextId, $participationId);
        if (!$current) {
            return false;
        }

        $record = $this->validate($contextId, array_merge($current, $data), $this->requirements($contextId, (int) $current['form_id'], $submit));
        $updated = DB::table('group_review_participation')
            ->where('context_id', $contextId)
            ->where('participation_id', $participationId)
            ->update([
                'form_id' => $record['form_id'],
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
    private function saveReviewer(int $contextId, object $form, int $reviewerUserId, array $data): array
    {
        if (!in_array($reviewerUserId, json_decode($form->reviewer_ids, true), true)) {
            throw new InvalidArgumentException('The reviewer must be one of the participation form\'s reviewers.');
        }

        $record = array_merge($data, [
            'form_id' => (int) $form->form_id,
            'review_round_id' => (int) $form->review_round_id,
            'submission_id' => (int) $form->submission_id,
            'reviewer_user_id' => $reviewerUserId,
            'leader_user_id' => (int) $form->leader_user_id,
        ]);
        $existing = DB::table('group_review_participation')
            ->where('context_id', $contextId)
            ->where('form_id', (int) $form->form_id)
            ->where('reviewer_user_id', $reviewerUserId)
            ->first();

        if ($existing) {
            $participationId = (int) $existing->participation_id;
            $this->update($contextId, $participationId, $record, false);
            return ['participation_id' => $participationId, 'created' => false];
        }

        return ['participation_id' => $this->create($contextId, $record, false), 'created' => true];
    }

    /**
     * Update the form-level record. Only form fields present in $form are
     * changed. A form stays submitted once submitted, even when edited later.
     */
    private function saveFormRecord(object $record, int $userId, array $form, array $configuration): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $values = [
            'updated_at' => $now,
            'updated_by' => $userId,
            'configuration' => json_encode($configuration, JSON_THROW_ON_ERROR),
        ];
        if (array_key_exists('has_meeting', $form)) {
            $values['has_meeting'] = (bool) $form['has_meeting'];
        }
        if (array_key_exists('general_comments', $form)) {
            $values['general_comments'] = $this->text($form['general_comments'], 'general comments');
        }

        DB::table('group_review_participation_forms')
            ->where('form_id', (int) $record->form_id)
            ->update($values);
    }

    private function recordSubmission(object $record, int $userId, array $form): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $comment = $this->text($form['submission_comment'] ?? null, 'submission comment');
        $submissions = json_decode($record->submissions ?? '[]', true);
        $submissions[] = ['submitted_at' => $now, 'submitted_by' => $userId, 'comment' => $comment];

        DB::table('group_review_participation_forms')
            ->where('form_id', (int) $record->form_id)
            ->update([
                'status' => self::STATUS_SUBMITTED,
                'submitted_at' => $now,
                'submitted_by' => $userId,
                'submission_comment' => $comment,
                'submissions' => json_encode($submissions, JSON_THROW_ON_ERROR),
            ]);
    }

    public function getFormRecord(int $contextId, int $formId): ?array
    {
        $row = DB::table('group_review_participation_forms')
            ->where('context_id', $contextId)
            ->where('form_id', $formId)
            ->first();

        return $row ? (array) $row : null;
    }

    private function requirements(int $contextId, int $formId, bool $submit): array
    {
        $configuration = (new ParticipationConfiguration())->forForm($contextId, $formId);
        if (!$submit) {
            $configuration['fields'] = array_fill_keys(array_keys($configuration['fields']), false);
        } elseif (!DB::table('group_review_participation_forms')->where('form_id', $formId)->value('has_meeting')) {
            foreach (self::MEETING_FIELDS as $field) {
                $configuration['fields'][$field] = false;
            }
        }

        return $configuration;
    }

    private function validate(int $contextId, array $data, ?array $configuration = null): array
    {
        if ($contextId <= 0) {
            throw new InvalidArgumentException('The context ID must be positive.');
        }

        foreach (['form_id', 'review_round_id', 'submission_id', 'reviewer_user_id', 'leader_user_id'] as $key) {
            $data[$key] = $this->positiveInteger($data[$key] ?? null, $key);
        }
        if (!isset($data['attendance']) || !in_array($data['attendance'], array_keys(self::ATTENDANCE_OPTIONS), true)) {
            throw new InvalidArgumentException('The attendance value is invalid.');
        }

        $configuration ??= ParticipationConfiguration::defaults();
        $shapingTypes = $this->validateList($data['shaping_feedback_types'] ?? [], array_keys($configuration['options']), 'shaping feedback types');
        $attendanceOther = $data['attendance'] === self::ATTENDANCE_OTHER
            ? $this->text($data['attendance_other'] ?? null, 'attendance details')
            : null;

        $record = [
            'form_id' => (int) $data['form_id'],
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
        foreach ($configuration['fields'] as $field => $required) {
            if ($required && $this->isUnanswered($field, $record[$field])) {
                throw new InvalidArgumentException($configuration['text'][$field]['label'] . ' is required.');
            }
        }
        return $record;
    }

    private function isUnanswered(string $field, mixed $value): bool
    {
        return $value === null || $value === '' || $value === []
            || ($field === 'attendance' && $value === self::ATTENDANCE_NOT_RECORDED);
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
