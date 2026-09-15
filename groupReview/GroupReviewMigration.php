<?php

/**
 * @file GroupReviewMigration.php
 *
 * Plugin-owned schema for group review coordination.
 */

namespace APP\plugins\generic\groupReview;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use APP\plugins\generic\groupReview\classes\GroupReviewService;

class GroupReviewMigration extends Migration
{
    private const EMAIL_KEYS = [
        'GROUP_REVIEW_POLL_CREATED',
        'GROUP_REVIEW_POLL_CLOSING',
        'GROUP_REVIEW_THANKS_INVITED_RGMS',
        'GROUP_REVIEW_THANKS_RGMS',
    ];

    public function up(): void
    {
        $this->refreshEmailTemplates();
        $this->createSessionsTable();
        $this->createSlotsTable();
        $this->createMembersTable();
        $this->createAvailabilityTable();
        $this->createReminderLogTable();
        $this->migrateLegacyContextSettings();
        $this->migrateLegacyData();
    }

    public function down(): void
    {
        Schema::dropIfExists('group_review_reminder_log');
        Schema::dropIfExists('group_review_availability');
        Schema::dropIfExists('group_review_members');
        Schema::dropIfExists('group_review_slots');
        Schema::dropIfExists('group_review_sessions');

        if (Schema::hasTable('email_templates')) {
            DB::table('email_templates')->whereIn('email_key', self::EMAIL_KEYS)->delete();
        }
        if (Schema::hasTable('email_templates_default_data')) {
            DB::table('email_templates_default_data')->whereIn('email_key', self::EMAIL_KEYS)->delete();
        }
    }

    /**
     * Force OJS's installer to load the current defaults. Preserve journal
     * overrides, translate student-version variables, and update only the
     * exact stock 2.3 assignment sentence when it is present.
     */
    private function refreshEmailTemplates(): void
    {
        if (Schema::hasTable('email_templates') && Schema::hasTable('email_templates_settings')) {
            $settings = DB::table('email_templates_settings as ets')
                ->join('email_templates as et', 'et.email_id', '=', 'ets.email_id')
                ->whereIn('et.email_key', self::EMAIL_KEYS)
                ->whereIn('ets.setting_name', ['subject', 'body'])
                ->select('ets.email_template_setting_id', 'ets.setting_value')
                ->get();

            foreach ($settings as $setting) {
                $updated = str_replace(
                    [
                        '{$rgmName}',
                        '{$manuscriptTitle}',
                        '{$submissionUrl}',
                        'An OJS review assignment and Review-stage discussion have been created for you.',
                    ],
                    [
                        '{$recipientName}',
                        '{$submissionTitle}',
                        '{$groupReviewUrl}',
                        'You have been added to the submission as a Review Group Member and included in the Review-stage discussion.',
                    ],
                    (string) $setting->setting_value
                );
                if ($updated !== (string) $setting->setting_value) {
                    DB::table('email_templates_settings')
                        ->where('email_template_setting_id', $setting->email_template_setting_id)
                        ->update(['setting_value' => $updated]);
                }
            }
        }

        if (Schema::hasTable('email_templates_default_data')) {
            DB::table('email_templates_default_data')
                ->whereIn('email_key', self::EMAIL_KEYS)
                ->delete();
        }
    }

    private function createSessionsTable(): void
    {
        if (Schema::hasTable('group_review_sessions')) {
            return;
        }

        Schema::create('group_review_sessions', function (Blueprint $table) {
            $table->bigInteger('session_id')->autoIncrement();

            $table->bigInteger('context_id');
            $table->foreign('context_id', 'grp_session_context_fk')
                ->references('journal_id')->on('journals')->onDelete('cascade');

            $table->bigInteger('submission_id');
            $table->foreign('submission_id', 'grp_session_submission_fk')
                ->references('submission_id')->on('submissions')->onDelete('cascade');

            $table->bigInteger('review_round_id');
            $table->foreign('review_round_id', 'grp_session_round_fk')
                ->references('review_round_id')->on('review_rounds')->onDelete('cascade');

            $table->bigInteger('leader_user_id')->nullable();
            $table->foreign('leader_user_id', 'grp_session_leader_fk')
                ->references('user_id')->on('users')->onDelete('set null');

            $table->dateTime('deadline_utc');
            $table->string('timezone', 64)->default('UTC');
            $table->unsignedInteger('meeting_duration_minutes')->default(30);
            $table->boolean('send_reminder')->default(false);
            $table->unsignedInteger('reminder_before_hours')->default(24);
            $table->smallInteger('status')->default(0);
            $table->bigInteger('selected_slot_id')->nullable();
            $table->string('meeting_url', 2048)->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->dateTime('finalized_at')->nullable();

            $table->index(['context_id', 'status'], 'grp_session_context_status_idx');
            $table->index(['context_id', 'submission_id', 'review_round_id'], 'grp_session_submission_round_idx');
            $table->index(['leader_user_id'], 'grp_session_leader_idx');
        });
    }

    private function createSlotsTable(): void
    {
        if (Schema::hasTable('group_review_slots')) {
            return;
        }

        Schema::create('group_review_slots', function (Blueprint $table) {
            $table->bigInteger('slot_id')->autoIncrement();
            $table->bigInteger('session_id');
            $table->foreign('session_id', 'grp_slot_session_fk')
                ->references('session_id')->on('group_review_sessions')->onDelete('cascade');
            $table->dateTime('start_time_utc');
            $table->dateTime('created_at');

            $table->unique(['session_id', 'start_time_utc'], 'grp_slot_session_time_unique');
            $table->index(['session_id'], 'grp_slot_session_idx');
        });
    }

    private function createMembersTable(): void
    {
        if (Schema::hasTable('group_review_members')) {
            return;
        }

        Schema::create('group_review_members', function (Blueprint $table) {
            $table->bigInteger('member_id')->autoIncrement();
            $table->bigInteger('session_id');
            $table->foreign('session_id', 'grp_member_session_fk')
                ->references('session_id')->on('group_review_sessions')->onDelete('cascade');
            $table->bigInteger('user_id');
            $table->foreign('user_id', 'grp_member_user_fk')
                ->references('user_id')->on('users')->onDelete('cascade');
            $table->boolean('selected')->default(false);
            $table->dateTime('invited_at');
            $table->dateTime('responded_at')->nullable();

            $table->unique(['session_id', 'user_id'], 'grp_member_session_user_unique');
            $table->index(['user_id'], 'grp_member_user_idx');
        });
    }

    private function createAvailabilityTable(): void
    {
        if (Schema::hasTable('group_review_availability')) {
            return;
        }

        Schema::create('group_review_availability', function (Blueprint $table) {
            $table->bigInteger('availability_id')->autoIncrement();
            $table->bigInteger('session_id');
            $table->foreign('session_id', 'grp_avail_session_fk')
                ->references('session_id')->on('group_review_sessions')->onDelete('cascade');
            $table->bigInteger('slot_id');
            $table->foreign('slot_id', 'grp_avail_slot_fk')
                ->references('slot_id')->on('group_review_slots')->onDelete('cascade');
            $table->bigInteger('user_id');
            $table->foreign('user_id', 'grp_avail_user_fk')
                ->references('user_id')->on('users')->onDelete('cascade');
            $table->dateTime('created_at');

            $table->unique(['slot_id', 'user_id'], 'grp_avail_slot_user_unique');
            $table->index(['session_id', 'user_id'], 'grp_avail_session_user_idx');
        });
    }

    private function createReminderLogTable(): void
    {
        if (Schema::hasTable('group_review_reminder_log')) {
            return;
        }

        Schema::create('group_review_reminder_log', function (Blueprint $table) {
            $table->bigInteger('reminder_id')->autoIncrement();
            $table->bigInteger('session_id');
            $table->foreign('session_id', 'grp_reminder_session_fk')
                ->references('session_id')->on('group_review_sessions')->onDelete('cascade');
            $table->bigInteger('user_id');
            $table->foreign('user_id', 'grp_reminder_user_fk')
                ->references('user_id')->on('users')->onDelete('cascade');
            $table->dateTime('sent_at');

            $table->unique(['session_id', 'user_id'], 'grp_reminder_session_user_unique');
        });
    }

    /** Carry forward journal settings from the student version. */
    private function migrateLegacyContextSettings(): void
    {
        if (!Schema::hasTable('journal_settings')) {
            return;
        }

        $mapping = [
            'groupReviewApproach' => 'groupReviewEnabled',
            'defaultGroupReviewMeetingDuration' => 'groupReviewDefaultDuration',
            'defaultGroupReviewMeetingTimeSlots' => 'groupReviewDefaultSlots',
            'defaultGroupReviewPollReminderDuration' => 'groupReviewReminderHours',
            'defaultReviewGroupMeetingDayOffset' => 'groupReviewMinimumLeadDays',
        ];
        $legacyRows = DB::table('journal_settings')
            ->whereIn('setting_name', array_keys($mapping))
            ->get();

        foreach ($legacyRows as $legacy) {
            $newName = $mapping[$legacy->setting_name];
            $exists = DB::table('journal_settings')
                ->where('journal_id', $legacy->journal_id)
                ->where('locale', '')
                ->where('setting_name', $newName)
                ->exists();
            if ($exists) {
                continue;
            }

            $value = $legacy->setting_name === 'groupReviewApproach'
                ? ($legacy->setting_value === 'enabled' ? '1' : '0')
                : (string) $legacy->setting_value;
            DB::table('journal_settings')->insert([
                'journal_id' => (int) $legacy->journal_id,
                'locale' => '',
                'setting_name' => $newName,
                'setting_value' => $value,
            ]);
        }
    }

    /**
     * Import data from the student version without altering or depending on its
     * tables. The legacy tables remain untouched as a rollback safety net.
     */
    private function migrateLegacyData(): void
    {
        if (!Schema::hasTable('group_review_polls') || DB::table('group_review_sessions')->exists()) {
            return;
        }

        $sessionMap = [];
        $slotMap = [];
        $now = gmdate('Y-m-d H:i:s');

        foreach (DB::table('group_review_polls')->orderBy('poll_id')->get() as $legacy) {
            $submission = DB::table('submissions')
                ->select('context_id')
                ->where('submission_id', $legacy->submission_id)
                ->first();
            if (!$submission) {
                continue;
            }

            $groupReviewService = new GroupReviewService();
            $rglGroupId = $groupReviewService->getUserGroupIdByAbbreviation(
                (int) $submission->context_id,
                'RGL'
            );
            $leaderAssignment = null;
            if ($rglGroupId) {
                $stageAssignmentDao = \PKP\db\DAORegistry::getDAO('StageAssignmentDAO');
                $assignments = $stageAssignmentDao->getBySubmissionAndStageId(
                    (int) $legacy->submission_id,
                    WORKFLOW_STAGE_ID_EXTERNAL_REVIEW,
                    $rglGroupId
                );
                $leaderAssignment = $assignments->next();
            }
            $leader = $leaderAssignment ? (int) $leaderAssignment->getUserId() : null;

            $newId = DB::table('group_review_sessions')->insertGetId([
                'context_id' => (int) $submission->context_id,
                'submission_id' => (int) $legacy->submission_id,
                'review_round_id' => (int) $legacy->review_round_id,
                'leader_user_id' => $leader ? (int) $leader : null,
                'deadline_utc' => $legacy->deadline,
                'timezone' => 'UTC',
                'meeting_duration_minutes' => (int) $legacy->meeting_duration_minutes,
                'send_reminder' => (bool) $legacy->send_reminder,
                'reminder_before_hours' => (int) $legacy->reminder_before_hours,
                'status' => (int) $legacy->status === 0 ? 0 : 1,
                'selected_slot_id' => null,
                'meeting_url' => null,
                'notes' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'finalized_at' => (int) $legacy->status === 0 ? null : $now,
            ], 'session_id');
            $sessionMap[(int) $legacy->poll_id] = (int) $newId;
        }

        if (Schema::hasTable('group_review_poll_options')) {
            foreach (DB::table('group_review_poll_options')->orderBy('option_id')->get() as $legacySlot) {
                $newSessionId = $sessionMap[(int) $legacySlot->poll_id] ?? null;
                if (!$newSessionId) {
                    continue;
                }
                $newSlotId = DB::table('group_review_slots')->insertGetId([
                    'session_id' => $newSessionId,
                    'start_time_utc' => $legacySlot->value,
                    'created_at' => $now,
                ], 'slot_id');
                $slotMap[(int) $legacySlot->option_id] = (int) $newSlotId;
            }
        }

        if (Schema::hasTable('group_review_poll_invitations')
            && Schema::hasTable('group_review_poll_user_invitations')) {
            $legacyMembers = DB::table('group_review_poll_user_invitations as pui')
                ->join('group_review_poll_invitations as pi', 'pi.invitation_id', '=', 'pui.invitation_id')
                ->join('user_user_groups as uug', 'uug.user_user_group_id', '=', 'pui.user_user_group_id')
                ->select('pi.poll_id', 'uug.user_id', 'uug.user_group_id')
                ->distinct()
                ->get();

            foreach ($legacyMembers as $legacyMember) {
                $newSessionId = $sessionMap[(int) $legacyMember->poll_id] ?? null;
                if (!$newSessionId) {
                    continue;
                }
                $session = DB::table('group_review_sessions')
                    ->where('session_id', $newSessionId)
                    ->first();
                if (!$session) {
                    continue;
                }
                $rgmGroupId = (new GroupReviewService())->getUserGroupIdByAbbreviation(
                    (int) $session->context_id,
                    'RGM'
                );
                if (!$rgmGroupId || (int) $legacyMember->user_group_id !== $rgmGroupId) {
                    continue;
                }
                DB::table('group_review_members')->updateOrInsert(
                    ['session_id' => $newSessionId, 'user_id' => (int) $legacyMember->user_id],
                    ['selected' => false, 'invited_at' => $now, 'responded_at' => null]
                );
            }
        }

        if (Schema::hasTable('group_review_poll_user_options')) {
            $legacyAvailability = DB::table('group_review_poll_user_options as puo')
                ->join('group_review_poll_options as po', 'po.option_id', '=', 'puo.option_id')
                ->join('user_user_groups as uug', 'uug.user_user_group_id', '=', 'puo.user_user_group_id')
                ->select('po.poll_id', 'puo.option_id', 'uug.user_id', 'uug.user_group_id')
                ->distinct()
                ->get();

            foreach ($legacyAvailability as $legacyAnswer) {
                $newSessionId = $sessionMap[(int) $legacyAnswer->poll_id] ?? null;
                $newSlotId = $slotMap[(int) $legacyAnswer->option_id] ?? null;
                if (!$newSessionId || !$newSlotId) {
                    continue;
                }
                $session = DB::table('group_review_sessions')
                    ->where('session_id', $newSessionId)
                    ->first();
                if (!$session) {
                    continue;
                }
                $rgmGroupId = (new GroupReviewService())->getUserGroupIdByAbbreviation(
                    (int) $session->context_id,
                    'RGM'
                );
                if (!$rgmGroupId || (int) $legacyAnswer->user_group_id !== $rgmGroupId) {
                    continue;
                }
                $memberExists = DB::table('group_review_members')
                    ->where('session_id', $newSessionId)
                    ->where('user_id', (int) $legacyAnswer->user_id)
                    ->exists();
                if (!$memberExists) {
                    continue;
                }
                DB::table('group_review_availability')->updateOrInsert(
                    ['slot_id' => $newSlotId, 'user_id' => (int) $legacyAnswer->user_id],
                    ['session_id' => $newSessionId, 'created_at' => $now]
                );
                DB::table('group_review_members')
                    ->where('session_id', $newSessionId)
                    ->where('user_id', (int) $legacyAnswer->user_id)
                    ->update(['responded_at' => $now]);
            }
        }
    }
}
