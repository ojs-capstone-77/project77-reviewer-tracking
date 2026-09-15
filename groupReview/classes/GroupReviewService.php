<?php

/**
 * @file classes/GroupReviewService.php
 *
 * Journal-scoped data access and workflow integration.
 */

namespace APP\plugins\generic\groupReview\classes;

use APP\facades\Repo;
use APP\notification\Notification;
use APP\notification\NotificationManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PKP\core\Core;
use PKP\core\PKPApplication;
use PKP\db\DAORegistry;
use PKP\facades\Locale;
use PKP\submission\PKPSubmission;
use PKP\submission\reviewRound\ReviewRoundDAO;
use PKP\user\User;
use PKP\userGroup\UserGroup;

class GroupReviewService
{
    private const RGL_ABBREVIATION = 'RGL';
    private const RGM_ABBREVIATION = 'RGM';

    public const STATUS_OPEN = 0;
    public const STATUS_FINALIZED = 1;
    public const STATUS_CANCELLED = 2;
    public const STATUS_EXPIRED = 3;
    public const STATUS_DRAFT = 4;

    public function isEnabled($context): bool
    {
        return $context && (bool) $context->getData('groupReviewEnabled');
    }

    /**
     * A leader must be assigned to this submission through the journal's RGL
     * user group. Journal enrollment alone is not enough.
     */
    public function isLeader(int $contextId, int $submissionId, int $userId): bool
    {
        $submission = Repo::submission()->get($submissionId);
        if (!$submission
            || (int) $submission->getContextId() !== $contextId
            || (int) $submission->getStageId() !== WORKFLOW_STAGE_ID_EXTERNAL_REVIEW
            || (int) $submission->getStatus() !== PKPSubmission::STATUS_QUEUED) {
            return false;
        }

        $rglGroupId = $this->getUserGroupIdByAbbreviation($contextId, self::RGL_ABBREVIATION);
        if (!$rglGroupId) {
            return false;
        }

        $user = Repo::user()->get($userId);
        if (!$user || $user->getDisabled()
            || !DB::table('user_user_groups')
                ->where('user_group_id', $rglGroupId)
                ->where('user_id', $userId)
                ->exists()) {
            return false;
        }

        $stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO');
        $assignments = $stageAssignmentDao->getBySubmissionAndStageId(
            $submissionId,
            WORKFLOW_STAGE_ID_EXTERNAL_REVIEW,
            $rglGroupId,
            $userId
        );

        return (bool) $assignments->next();
    }

    public function isInvited(int $contextId, int $sessionId, int $userId): bool
    {
        return $this->rgmIsEligible($contextId, $userId)
            && DB::table('group_review_members as gm')
            ->join('group_review_sessions as gs', 'gs.session_id', '=', 'gm.session_id')
            ->where('gm.session_id', $sessionId)
            ->where('gm.user_id', $userId)
            ->where('gs.context_id', $contextId)
            ->exists();
    }

    public function get(int $contextId, int $sessionId): ?array
    {
        $row = DB::table('group_review_sessions')
            ->where('context_id', $contextId)
            ->where('session_id', $sessionId)
            ->first();

        if (!$row) {
            return null;
        }

        $poll = (array) $row;
        if ((int) $poll['status'] === self::STATUS_OPEN
            && Carbon::parse($poll['deadline_utc'], 'UTC')->lte(Carbon::now('UTC'))) {
            DB::table('group_review_sessions')
                ->where('context_id', $contextId)
                ->where('session_id', $sessionId)
                ->where('status', self::STATUS_OPEN)
                ->update([
                    'status' => self::STATUS_EXPIRED,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            $poll['status'] = self::STATUS_EXPIRED;
        }

        return $poll;
    }

    public function getForSubmissionRound(int $contextId, int $submissionId, int $reviewRoundId): ?array
    {
        $row = DB::table('group_review_sessions')
            ->where('context_id', $contextId)
            ->where('submission_id', $submissionId)
            ->where('review_round_id', $reviewRoundId)
            ->orderByDesc('session_id')
            ->first();

        return $row ? $this->get($contextId, (int) $row->session_id) : null;
    }

    public function getActiveForSubmissionRound(int $contextId, int $submissionId, int $reviewRoundId): ?array
    {
        $row = DB::table('group_review_sessions')
            ->where('context_id', $contextId)
            ->where('submission_id', $submissionId)
            ->where('review_round_id', $reviewRoundId)
            ->whereIn('status', [self::STATUS_DRAFT, self::STATUS_OPEN])
            ->orderByDesc('session_id')
            ->first();

        if (!$row) {
            return null;
        }

        $poll = $this->get($contextId, (int) $row->session_id);
        return $poll && in_array((int) $poll['status'], [self::STATUS_DRAFT, self::STATUS_OPEN], true)
            ? $poll
            : null;
    }

    /**
     * Close open polls for a submission and, for a replaced or cancelled
     * review round, remove only participants assigned through the journal's
     * RGM group. The report retains those participants when the submission is
     * accepted.
     */
    public static function closeActiveForSubmission(
        int $contextId,
        int $submissionId,
        int $status,
        bool $removeRgmParticipants = false
    ): void
    {
        DB::transaction(function () use ($contextId, $submissionId, $status, $removeRgmParticipants) {
            DB::table('group_review_sessions')
                ->where('context_id', $contextId)
                ->where('submission_id', $submissionId)
                ->whereIn('status', [self::STATUS_DRAFT, self::STATUS_OPEN])
                ->update([
                    'status' => $status,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);

            if (!$removeRgmParticipants) {
                return;
            }

            $rgmGroupId = (new self())->getUserGroupIdByAbbreviation(
                $contextId,
                self::RGM_ABBREVIATION
            );
            if (!$rgmGroupId) {
                return;
            }

            DB::table('stage_assignments')
                ->where('submission_id', $submissionId)
                ->where('user_group_id', $rgmGroupId)
                ->delete();
        });
    }

    /**
     * Validate that submission, current review round and journal match.
     *
     * @return array{0:object,1:object}|null
     */
    public function getValidSubmissionRound(int $contextId, int $submissionId, int $reviewRoundId): ?array
    {
        $submission = Repo::submission()->get($submissionId);
        if (!$submission
            || (int) $submission->getContextId() !== $contextId
            || (int) $submission->getStageId() !== WORKFLOW_STAGE_ID_EXTERNAL_REVIEW
            || (int) $submission->getStatus() !== PKPSubmission::STATUS_QUEUED) {
            return null;
        }

        $reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO');
        $round = $reviewRoundDao->getById($reviewRoundId);
        $latest = $reviewRoundDao->getLastReviewRoundBySubmissionId(
            $submissionId,
            WORKFLOW_STAGE_ID_EXTERNAL_REVIEW
        );

        if (!$round
            || !$latest
            || (int) $round->getSubmissionId() !== $submissionId
            || (int) $round->getStageId() !== WORKFLOW_STAGE_ID_EXTERNAL_REVIEW
            || (int) $latest->getId() !== $reviewRoundId) {
            return null;
        }

        return [$submission, $round];
    }

    public function getUserGroupIdByAbbreviation(int $contextId, string $abbreviation): ?int
    {
        $groups = Repo::userGroup()->getCollector()
            ->filterByContextIds([$contextId])
            ->getMany();

        $locales = array_values(array_unique(array_filter([
            Locale::getLocale(),
            'en',
        ])));

        foreach ($groups as $group) {
            if (!$group instanceof UserGroup) {
                continue;
            }
            foreach ($locales as $locale) {
                if (strcasecmp(trim((string) $group->getAbbrev($locale)), $abbreviation) === 0) {
                    return (int) $group->getId();
                }
            }
        }

        return null;
    }

    /**
     * Return active users enrolled in the journal's Review Group Member group.
     */
    public function getRgmCandidates(int $contextId): array
    {
        $rgmGroupId = $this->getUserGroupIdByAbbreviation($contextId, self::RGM_ABBREVIATION);
        if (!$rgmGroupId) {
            return [];
        }

        $ids = DB::table('users as u')
            ->join('user_user_groups as uug', 'uug.user_id', '=', 'u.user_id')
            ->where('uug.user_group_id', $rgmGroupId)
            ->where('u.disabled', 0)
            ->distinct()
            ->orderBy('u.user_id')
            ->pluck('u.user_id');

        $members = [];
        foreach ($ids as $id) {
            $user = Repo::user()->get((int) $id);
            if (!$user) {
                continue;
            }
            $members[] = [
                'user_id' => (int) $user->getId(),
                'name' => $user->getFullName(),
                'email' => $user->getEmail(),
            ];
        }

        usort($members, fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $members;
    }

    public function rgmIsEligible(int $contextId, int $userId): bool
    {
        $user = Repo::user()->get($userId);
        $rgmGroupId = $this->getUserGroupIdByAbbreviation($contextId, self::RGM_ABBREVIATION);
        return $user
            && !$user->getDisabled()
            && $rgmGroupId
            && DB::table('user_user_groups')
                ->where('user_group_id', $rgmGroupId)
                ->where('user_id', $userId)
                ->exists();
    }

    public function create(int $contextId, int $leaderUserId, array $data): int
    {
        return DB::transaction(function () use ($contextId, $leaderUserId, $data) {
            $now = gmdate('Y-m-d H:i:s');

            // Serialize poll creation on the OJS review-round row. A check in
            // the handler alone is race-prone when two requests arrive before
            // either has inserted its session.
            $round = DB::table('review_rounds')
                ->where('review_round_id', $data['review_round_id'])
                ->where('submission_id', $data['submission_id'])
                ->lockForUpdate()
                ->first();
            if (!$round) {
                throw new \RuntimeException('The review round is no longer available.');
            }

            DB::table('group_review_sessions')
                ->where('context_id', $contextId)
                ->where('submission_id', $data['submission_id'])
                ->where('review_round_id', $data['review_round_id'])
                ->whereIn('status', [self::STATUS_DRAFT, self::STATUS_OPEN])
                ->where('deadline_utc', '<=', $now)
                ->update([
                    'status' => self::STATUS_EXPIRED,
                    'updated_at' => $now,
                ]);
            if (DB::table('group_review_sessions')
                ->where('context_id', $contextId)
                ->where('submission_id', $data['submission_id'])
                ->where('review_round_id', $data['review_round_id'])
                ->whereIn('status', [self::STATUS_DRAFT, self::STATUS_OPEN])
                ->exists()) {
                throw new \RuntimeException('An unfinished group review poll already exists for this round.');
            }

            $sessionId = DB::table('group_review_sessions')->insertGetId([
                'context_id' => $contextId,
                'submission_id' => $data['submission_id'],
                'review_round_id' => $data['review_round_id'],
                'leader_user_id' => $leaderUserId,
                'deadline_utc' => $data['deadline_utc'],
                'timezone' => $data['timezone'],
                'meeting_duration_minutes' => $data['duration'],
                'send_reminder' => $data['send_reminder'],
                'reminder_before_hours' => $data['reminder_hours'],
                'status' => self::STATUS_DRAFT,
                'selected_slot_id' => null,
                'meeting_url' => $data['meeting_url'] ?: null,
                'notes' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'finalized_at' => null,
            ], 'session_id');

            foreach ($data['slots_utc'] as $slot) {
                DB::table('group_review_slots')->insert([
                    'session_id' => $sessionId,
                    'start_time_utc' => $slot,
                    'created_at' => $now,
                ]);
            }

            return (int) $sessionId;
        });
    }

    /**
     * Complete the report's second creation step. Until this commits, the poll
     * is a private draft and no RGM can access it or receive an invitation.
     */
    public function inviteMembers(int $contextId, int $sessionId, array $memberIds): bool
    {
        $members = array_values(array_unique(array_map('intval', $memberIds)));
        sort($members);
        if (!$members || count($members) > 100) {
            return false;
        }

        return DB::transaction(function () use ($contextId, $sessionId, $members) {
            $poll = DB::table('group_review_sessions')
                ->where('context_id', $contextId)
                ->where('session_id', $sessionId)
                ->lockForUpdate()
                ->first();
            if (!$poll || (int) $poll->status !== self::STATUS_DRAFT) {
                return false;
            }
            foreach ($members as $userId) {
                if (!$this->rgmIsEligible($contextId, $userId)) {
                    return false;
                }
            }

            $now = gmdate('Y-m-d H:i:s');
            foreach ($members as $userId) {
                DB::table('group_review_members')->insert([
                    'session_id' => $sessionId,
                    'user_id' => $userId,
                    'selected' => false,
                    'invited_at' => $now,
                    'responded_at' => null,
                ]);
            }
            return (bool) DB::table('group_review_sessions')
                ->where('context_id', $contextId)
                ->where('session_id', $sessionId)
                ->where('status', self::STATUS_DRAFT)
                ->update(['status' => self::STATUS_OPEN, 'updated_at' => $now]);
        });
    }

    /**
     * Editing replaces the slot set and therefore clears availability. It also
     * resets reminders so non-respondents can be reminded about the revised poll.
     *
     * @return int[] all current member IDs; every member must receive a fresh
     * invitation because editing clears every availability response.
     */
    public function update(int $contextId, int $sessionId, array $data): array
    {
        return DB::transaction(function () use ($contextId, $sessionId, $data) {
            $current = DB::table('group_review_sessions')
                ->where('context_id', $contextId)
                ->where('session_id', $sessionId)
                ->lockForUpdate()
                ->first();
            if (!$current || (int) $current->status !== self::STATUS_OPEN) {
                throw new \RuntimeException('The group review poll is no longer open.');
            }

            $now = gmdate('Y-m-d H:i:s');

            DB::table('group_review_sessions')
                ->where('context_id', $contextId)
                ->where('session_id', $sessionId)
                ->where('status', self::STATUS_OPEN)
                ->update([
                    'deadline_utc' => $data['deadline_utc'],
                    'timezone' => $data['timezone'],
                    'meeting_duration_minutes' => $data['duration'],
                    'send_reminder' => $data['send_reminder'],
                    'reminder_before_hours' => $data['reminder_hours'],
                    'meeting_url' => $data['meeting_url'] ?: null,
                    'updated_at' => $now,
                ]);

            DB::table('group_review_availability')->where('session_id', $sessionId)->delete();
            DB::table('group_review_reminder_log')->where('session_id', $sessionId)->delete();
            DB::table('group_review_slots')->where('session_id', $sessionId)->delete();
            DB::table('group_review_members')->where('session_id', $sessionId)->delete();

            foreach ($data['slots_utc'] as $slot) {
                DB::table('group_review_slots')->insert([
                    'session_id' => $sessionId,
                    'start_time_utc' => $slot,
                    'created_at' => $now,
                ]);
            }
            foreach ($data['member_ids'] as $userId) {
                DB::table('group_review_members')->insert([
                    'session_id' => $sessionId,
                    'user_id' => $userId,
                    'selected' => false,
                    'invited_at' => $now,
                    'responded_at' => null,
                ]);
            }

            return array_values($data['member_ids']);
        });
    }

    public function saveAvailability(
        int $contextId,
        int $sessionId,
        int $userId,
        array $selectedSlotIds
    ): bool {
        return DB::transaction(function () use ($contextId, $sessionId, $userId, $selectedSlotIds) {
            $poll = DB::table('group_review_sessions')
                ->where('context_id', $contextId)
                ->where('session_id', $sessionId)
                ->lockForUpdate()
                ->first();
            if (!$poll || (int) $poll->status !== self::STATUS_OPEN) {
                return false;
            }

            $isInvited = DB::table('group_review_members')
                ->where('session_id', $sessionId)
                ->where('user_id', $userId)
                ->exists();
            $validSlotIds = DB::table('group_review_slots')
                ->where('session_id', $sessionId)
                ->pluck('slot_id')
                ->map(fn($id) => (int) $id)
                ->all();
            if (!$isInvited || array_diff($selectedSlotIds, $validSlotIds)) {
                return false;
            }

            $now = gmdate('Y-m-d H:i:s');
            DB::table('group_review_availability')
                ->where('session_id', $sessionId)
                ->where('user_id', $userId)
                ->delete();

            foreach ($selectedSlotIds as $slotId) {
                DB::table('group_review_availability')->insert([
                    'session_id' => $sessionId,
                    'slot_id' => $slotId,
                    'user_id' => $userId,
                    'created_at' => $now,
                ]);
            }

            DB::table('group_review_members')
                ->where('session_id', $sessionId)
                ->where('user_id', $userId)
                ->update(['responded_at' => $now]);

            return true;
        });
    }

    /**
     * Finalize plugin data and verify every selected member marked themselves
     * available for the chosen slot.
     */
    public function finalize(
        int $contextId,
        int $sessionId,
        int $slotId,
        array $selectedUserIds,
        ?string $meetingUrl,
        ?string $notes
    ): bool {
        $selected = array_values(array_unique(array_map('intval', $selectedUserIds)));
        sort($selected);
        if (!$selected) {
            return false;
        }

        return DB::transaction(function () use ($contextId, $sessionId, $slotId, $selected, $meetingUrl, $notes) {
            $poll = DB::table('group_review_sessions')
                ->where('context_id', $contextId)
                ->where('session_id', $sessionId)
                ->lockForUpdate()
                ->first();
            if (!$poll || (int) $poll->status !== self::STATUS_OPEN) {
                return false;
            }

            $slotExists = DB::table('group_review_slots')
                ->where('session_id', $sessionId)
                ->where('slot_id', $slotId)
                ->exists();
            $members = DB::table('group_review_members')
                ->where('session_id', $sessionId)
                ->whereIn('user_id', $selected)
                ->pluck('user_id')
                ->map(fn($id) => (int) $id)
                ->all();
            $available = DB::table('group_review_availability')
                ->where('session_id', $sessionId)
                ->where('slot_id', $slotId)
                ->whereIn('user_id', $selected)
                ->pluck('user_id')
                ->map(fn($id) => (int) $id)
                ->all();
            sort($members);
            sort($available);
            if (!$slotExists
                || $members !== $selected
                || $available !== $selected) {
                return false;
            }
            foreach ($selected as $userId) {
                if (!$this->rgmIsEligible($contextId, $userId)) {
                    return false;
                }
            }

            $now = gmdate('Y-m-d H:i:s');
            $updated = DB::table('group_review_sessions')
                ->where('context_id', $contextId)
                ->where('session_id', $sessionId)
                ->where('status', self::STATUS_OPEN)
                ->update([
                    'status' => self::STATUS_FINALIZED,
                    'selected_slot_id' => $slotId,
                    'meeting_url' => $meetingUrl ?: null,
                    'notes' => $notes ?: null,
                    'updated_at' => $now,
                    'finalized_at' => $now,
                ]);

            if (!$updated) {
                return false;
            }

            DB::table('group_review_members')
                ->where('session_id', $sessionId)
                ->update(['selected' => false]);
            DB::table('group_review_members')
                ->where('session_id', $sessionId)
                ->whereIn('user_id', $selected)
                ->update(['selected' => true]);
            return true;
        });
    }

    /**
     * Commit poll selection and all OJS workflow records together. Nested DAO
     * writes use the same Laravel connection, so any exception rolls the whole
     * operation back and a retry cannot duplicate the discussion.
     */
    public function finalizeAndProvision(
        $request,
        $context,
        int $sessionId,
        int $slotId,
        array $selectedUserIds,
        ?string $meetingUrl,
        ?string $notes
    ): ?array {
        return DB::transaction(function () use (
            $request,
            $context,
            $sessionId,
            $slotId,
            $selectedUserIds,
            $meetingUrl,
            $notes
        ) {
            if (!$this->finalize(
                (int) $context->getId(),
                $sessionId,
                $slotId,
                $selectedUserIds,
                $meetingUrl,
                $notes
            )) {
                return null;
            }

            $bundle = $this->getBundle((int) $context->getId(), $sessionId);
            if (!$bundle) {
                throw new \RuntimeException('The finalized group review poll could not be loaded.');
            }
            $this->provisionSelectedGroupMembers($request, $context, $bundle, $selectedUserIds);
            return $this->getBundle((int) $context->getId(), $sessionId);
        });
    }

    public function cancel(int $contextId, int $sessionId): bool
    {
        return (bool) DB::table('group_review_sessions')
            ->where('context_id', $contextId)
            ->where('session_id', $sessionId)
            ->whereIn('status', [self::STATUS_DRAFT, self::STATUS_OPEN])
            ->update([
                'status' => self::STATUS_CANCELLED,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
    }

    /**
     * Restore the poll if participant or discussion creation failed.
     * The provisioning work itself runs in a transaction, so no partial stage
     * assignments, notifications or discussion records survive.
     */
    public function rollbackFinalization(int $contextId, int $sessionId): void
    {
        DB::transaction(function () use ($contextId, $sessionId) {
            DB::table('group_review_sessions')
                ->where('context_id', $contextId)
                ->where('session_id', $sessionId)
                ->where('status', self::STATUS_FINALIZED)
                ->update([
                    'status' => self::STATUS_OPEN,
                    'selected_slot_id' => null,
                    'finalized_at' => null,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            DB::table('group_review_members')
                ->where('session_id', $sessionId)
                ->update(['selected' => false]);
        });
    }

    public function getBundle(int $contextId, int $sessionId): ?array
    {
        $poll = $this->get($contextId, $sessionId);
        if (!$poll) {
            return null;
        }

        $slots = DB::table('group_review_slots')
            ->where('session_id', $sessionId)
            ->orderBy('start_time_utc')
            ->get()
            ->map(fn($row) => (array) $row)
            ->all();

        $memberRows = DB::table('group_review_members')
            ->where('session_id', $sessionId)
            ->orderBy('member_id')
            ->get();
        $members = [];
        foreach ($memberRows as $row) {
            $user = Repo::user()->get((int) $row->user_id);
            if (!$user) {
                continue;
            }
            $members[] = array_merge((array) $row, [
                'name' => $user->getFullName(),
                'email' => $user->getEmail(),
            ]);
        }
        usort($members, fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        $availability = [];
        foreach (DB::table('group_review_availability')->where('session_id', $sessionId)->get() as $row) {
            $availability[(int) $row->user_id][(int) $row->slot_id] = true;
        }

        $submission = Repo::submission()->get((int) $poll['submission_id']);
        $leader = $poll['leader_user_id'] ? Repo::user()->get((int) $poll['leader_user_id']) : null;

        return [
            'poll' => $poll,
            'slots' => $slots,
            'members' => $members,
            'availability' => $availability,
            'submission' => $submission,
            'leader' => $leader,
        ];
    }

    public function getPollsForUser(int $contextId, int $userId): array
    {
        $rows = DB::table('group_review_sessions as gs')
            ->leftJoin('group_review_members as gm', function ($join) use ($userId) {
                $join->on('gm.session_id', '=', 'gs.session_id')
                    ->where('gm.user_id', '=', $userId);
            })
            ->where('gs.context_id', $contextId)
            ->where(function ($query) use ($userId) {
                $query->whereNotNull('gm.member_id')
                    ->orWhereIn('gs.submission_id', function ($assigned) use ($userId) {
                        $assigned->select('submission_id')
                            ->from('stage_assignments')
                            ->where('user_id', $userId);
                    });
            })
            ->select('gs.*')
            ->distinct()
            ->orderByDesc('gs.session_id')
            ->limit(100)
            ->get();

        $polls = [];
        foreach ($rows as $row) {
            $poll = $this->get($contextId, (int) $row->session_id);
            if (!$poll) {
                continue;
            }
            $submission = Repo::submission()->get((int) $poll['submission_id']);
            $poll['submission_title'] = $submission ? $submission->getLocalizedTitle() : '';
            $poll['is_leader'] = $this->isLeader($contextId, (int) $poll['submission_id'], $userId);
            $poll['is_invited'] = $this->isInvited($contextId, (int) $poll['session_id'], $userId);
            if (!$poll['is_leader'] && !$poll['is_invited']) {
                continue;
            }
            $polls[] = $poll;
        }

        return $polls;
    }

    public function statusLabel(int $status): string
    {
        $keys = [
            self::STATUS_OPEN => 'plugins.generic.groupReview.status.open',
            self::STATUS_FINALIZED => 'plugins.generic.groupReview.status.finalized',
            self::STATUS_CANCELLED => 'plugins.generic.groupReview.status.cancelled',
            self::STATUS_EXPIRED => 'plugins.generic.groupReview.status.expired',
            self::STATUS_DRAFT => 'plugins.generic.groupReview.status.draft',
        ];
        return __($keys[$status] ?? 'common.unknown');
    }

    public function formatUtc(string $value, string $timezone, string $format = 'Y-m-d H:i T'): string
    {
        try {
            return Carbon::parse($value, 'UTC')->setTimezone($timezone)->format($format);
        } catch (\Throwable $e) {
            return Carbon::parse($value, 'UTC')->format($format);
        }
    }

    public function toUtc(string $localValue, string $timezone): ?string
    {
        try {
            $date = Carbon::createFromFormat('Y-m-d\\TH:i', $localValue, $timezone);
            if (!$date || $date->format('Y-m-d\\TH:i') !== $localValue) {
                return null;
            }
            return $date->setTimezone('UTC')->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Add selected RGM users as Review-stage participants and open the shared
     * Feedback Draft discussion described by the project documentation.
     */
    public function provisionSelectedGroupMembers(
        $request,
        $context,
        array $bundle,
        array $selectedUserIds
    ): array {
        $poll = $bundle['poll'];
        $submission = $bundle['submission'];
        $slot = null;
        foreach ($bundle['slots'] as $candidate) {
            if ((int) $candidate['slot_id'] === (int) $poll['selected_slot_id']) {
                $slot = $candidate;
                break;
            }
        }
        if (!$submission || !$slot) {
            throw new \RuntimeException('Unable to provision group members without a valid submission and meeting slot.');
        }

        $rgmGroupId = $this->getUserGroupIdByAbbreviation((int) $context->getId(), self::RGM_ABBREVIATION);
        if (!$rgmGroupId) {
            throw new \RuntimeException('The Review Group Member (RGM) user group is not configured for this journal.');
        }

        $stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO');
        $notificationMgr = new NotificationManager();
        $addedUserIds = [];

        DB::transaction(function () use (
            $request,
            $context,
            $poll,
            $submission,
            $selectedUserIds,
            $rgmGroupId,
            $stageAssignmentDao,
            $notificationMgr,
            $slot,
            &$addedUserIds
        ) {
            foreach ($selectedUserIds as $userId) {
                $userId = (int) $userId;
                if (!$this->rgmIsEligible((int) $context->getId(), $userId)) {
                    throw new \RuntimeException('A selected user is no longer an eligible Review Group Member.');
                }

                $existing = $stageAssignmentDao->getBySubmissionAndStageId(
                    (int) $submission->getId(),
                    WORKFLOW_STAGE_ID_EXTERNAL_REVIEW,
                    $rgmGroupId,
                    $userId
                );
                if (!$existing->next()) {
                    $assignment = $stageAssignmentDao->newDataObject();
                    $assignment->setSubmissionId((int) $submission->getId());
                    $assignment->setStageId(WORKFLOW_STAGE_ID_EXTERNAL_REVIEW);
                    $assignment->setUserGroupId($rgmGroupId);
                    $assignment->setUserId($userId);
                    $assignment->setRecommendOnly(true);
                    $assignment->setCanChangeMetadata(false);
                    $stageAssignmentDao->insertObject($assignment);
                    $addedUserIds[] = $userId;
                }
            }

            $queryId = $this->createDiscussion(
                $request,
                $selectedUserIds,
                (int) $submission->getId(),
                $poll,
                $slot['start_time_utc']
            );

            foreach ($selectedUserIds as $userId) {
                $notificationMgr->createNotification(
                    $request,
                    (int) $userId,
                    Notification::NOTIFICATION_TYPE_NEW_QUERY,
                    (int) $context->getId(),
                    PKPApplication::ASSOC_TYPE_QUERY,
                    $queryId,
                    Notification::NOTIFICATION_LEVEL_TASK
                );
            }
        });

        return $addedUserIds;
    }

    private function createDiscussion(
        $request,
        array $participants,
        int $submissionId,
        array $poll,
        string $meetingStartUtc
    ): int {
        $queryDao = DAORegistry::getDAO('QueryDAO');
        $query = $queryDao->newDataObject();
        $query->setAssocType(PKPApplication::ASSOC_TYPE_SUBMISSION);
        $query->setAssocId($submissionId);
        $query->setStageId(WORKFLOW_STAGE_ID_EXTERNAL_REVIEW);
        $query->setSequence(REALLY_BIG_NUMBER);
        $queryDao->insertObject($query);
        $queryDao->resequence(PKPApplication::ASSOC_TYPE_SUBMISSION, $submissionId);

        $allParticipants = array_values(array_unique(array_merge(
            array_map('intval', $participants),
            [(int) $request->getUser()->getId()]
        )));
        foreach ($allParticipants as $participantId) {
            $queryDao->insertParticipant($query->getId(), $participantId);
        }

        $meetingTime = $this->formatUtc($meetingStartUtc, $poll['timezone']);
        $safeUrl = $poll['meeting_url'] ? htmlspecialchars($poll['meeting_url'], ENT_QUOTES, 'UTF-8') : '';
        $safeNotes = $poll['notes'] ? nl2br(htmlspecialchars($poll['notes'], ENT_QUOTES, 'UTF-8')) : '';
        $contents = '<p>' . htmlspecialchars(__('plugins.generic.groupReview.discussion.scheduled', ['meetingTime' => $meetingTime]), ENT_QUOTES, 'UTF-8') . '</p>';
        if ($safeUrl) {
            $contents .= '<p><a href="' . $safeUrl . '">' . htmlspecialchars(__('plugins.generic.groupReview.meetingLink'), ENT_QUOTES, 'UTF-8') . '</a></p>';
        }
        if ($safeNotes) {
            $contents .= '<p>' . $safeNotes . '</p>';
        }

        $noteDao = DAORegistry::getDAO('NoteDAO');
        $note = $noteDao->newDataObject();
        $note->setUserId($request->getUser()->getId());
        $note->setAssocType(PKPApplication::ASSOC_TYPE_QUERY);
        $note->setAssocId($query->getId());
        $note->setTitle(__('plugins.generic.groupReview.discussion.title'));
        $note->setContents($contents);
        $note->setDateCreated(Core::getCurrentDate());
        $noteDao->insertObject($note);

        return (int) $query->getId();
    }

    public function memberUsers(array $bundle, ?bool $selected = null, ?bool $responded = null): array
    {
        $users = [];
        foreach ($bundle['members'] as $member) {
            if ($selected !== null && (bool) $member['selected'] !== $selected) {
                continue;
            }
            if ($responded !== null && (bool) $member['responded_at'] !== $responded) {
                continue;
            }
            $user = Repo::user()->get((int) $member['user_id']);
            if ($user instanceof User
                && $this->rgmIsEligible((int) $bundle['poll']['context_id'], (int) $member['user_id'])) {
                $users[] = $user;
            }
        }
        return $users;
    }
}
