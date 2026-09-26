<?php

/**
 * @file pages/groupReview/GroupReviewHandler.php
 */

namespace APP\plugins\generic\groupReview\pages\groupReview;

use APP\core\Application;
use APP\facades\Repo;
use APP\handler\Handler;
use APP\notification\NotificationManager;
use APP\plugins\generic\groupReview\classes\GroupReviewService;
use APP\plugins\generic\groupReview\classes\mail\GroupReviewPollCreated;
use APP\plugins\generic\groupReview\classes\mail\GroupReviewPollThankInvitees;
use APP\plugins\generic\groupReview\classes\mail\GroupReviewPollThankRgms;
use APP\plugins\generic\groupReview\classes\notification\Notification as GroupReviewNotification;
use APP\plugins\generic\groupReview\classes\security\authorization\InviteeRequiredPolicy;
use APP\plugins\generic\groupReview\classes\security\authorization\LeaderRequiredPolicy;
use APP\plugins\generic\groupReview\GroupReviewPlugin;
use APP\template\TemplateManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\security\authorization\ContextRequiredPolicy;
use PKP\security\authorization\UserRequiredPolicy;
use PKP\security\Role;
use Throwable;

class GroupReviewHandler extends Handler
{
    public $_isBackendPage = true;

    private GroupReviewPlugin $plugin;
    private GroupReviewService $service;

    private const LEADER_OPERATIONS = [
        'create', 'store', 'invite', 'storeInvitations', 'view', 'edit', 'update',
        'selectMeetingMembers', 'reviewMessages', 'finalize', 'cancel', 'resend',
    ];
    private const INVITEE_OPERATIONS = ['availability', 'saveAvailability'];

    public function __construct(GroupReviewPlugin $plugin)
    {
        parent::__construct();
        $this->plugin = $plugin;
        $this->service = new GroupReviewService();

        $this->addRoleAssignment(
            [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR],
            ['index', 'participation', 'participationForm', 'participationRecorded']
        );
        $this->addRoleAssignment(
            [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR],
            self::LEADER_OPERATIONS
        );
        $this->addRoleAssignment(Role::ROLE_ID_SUB_EDITOR, self::INVITEE_OPERATIONS);
    }

    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextRequiredPolicy($request));
        $this->addPolicy(new UserRequiredPolicy($request));
        $this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));

        $operation = $request->getRequestedOp();
        if (in_array($operation, self::LEADER_OPERATIONS, true)) {
            $this->addPolicy(new LeaderRequiredPolicy($request));
        } elseif (in_array($operation, self::INVITEE_OPERATIONS, true)) {
            $this->addPolicy(new InviteeRequiredPolicy($request));
        }

        return parent::authorize($request, $args, $roleAssignments);
    }

    public function index($args, $request): void
    {
        $context = $request->getContext();
        $user = $request->getUser();
        $polls = $this->service->getPollsForUser((int) $context->getId(), (int) $user->getId());

        foreach ($polls as &$poll) {
            $poll['status_label'] = $this->service->statusLabel((int) $poll['status']);
            $poll['deadline_display'] = $this->service->formatUtc($poll['deadline_utc'], $poll['timezone']);
            $operation = $poll['is_leader']
                ? ((int) $poll['status'] === GroupReviewService::STATUS_DRAFT ? 'invite' : 'view')
                : 'availability';
            $poll['action_url'] = $request->getRouter()->url(
                $request,
                null,
                'groupReview',
                $operation,
                null,
                ['pollId' => (int) $poll['session_id']]
            );
        }

        $this->display($request, 'dashboard.tpl', [
            'pageTitle' => __('plugins.generic.groupReview.dashboard.title'),
            'polls' => $polls,
        ]);
    }

    public function participation($args, $request): void
    {
        $sessions = $this->participationSessions($request);
        foreach ($sessions as &$session) {
            $session['openUrl'] = $request->getRouter()->url(
                $request,
                null,
                'groupReview',
                'participationForm',
                null,
                ['sessionId' => $session['id'], 'page' => 0]
            );
        }
        unset($session);

        $this->display($request, 'participation.tpl', [
            'pageTitle' => 'Reviewer Participation Recording',
            'sessions' => $sessions,
            'backUrl' => $request->getRouter()->url($request, null, 'groupReview', 'index'),
        ]);
    }

    public function participationForm($args, $request): void
    {
        $sessionId = (int) $request->getUserVar('sessionId') ?: 1001;
        $page = (int) $request->getUserVar('page');
        if ($page < 0) {
            $page = 0;
        }

        $session = $this->participationSessionData($request, $sessionId);
        $reviewers = $this->participationReviewers();
        $pageSize = 2;
        $pageCount = (int) ceil(count($reviewers) / $pageSize);
        if ($page >= $pageCount) {
            $page = $pageCount - 1;
        }
        $pageReviewers = array_slice($reviewers, $page * $pageSize, $pageSize);
        $startIndex = $page * $pageSize + 1;
        $endIndex = $startIndex + count($pageReviewers) - 1;
        $rangeLabel = $startIndex === $endIndex ? (string) $startIndex : "{$startIndex}-{$endIndex}";
        $pageDots = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $pageDots[] = $i === $page;
        }
        $isLastPage = $page === $pageCount - 1;
        $isSubmitted = $session['status'] === 'submitted';
        $canSubmit = $isSubmitted || $isLastPage;

        $this->display($request, 'participationForm.tpl', [
            'pageTitle' => 'Reviewer Participation Recording',
            'sessionId' => $sessionId,
            'session' => $session,
            'isSubmitted' => $isSubmitted,
            'submitLabel' => $isSubmitted ? 'Resubmit' : 'Submit',
            'isLastPage' => $isLastPage,
            'canSubmit' => $canSubmit,
            'reviewerCount' => count($reviewers),
            'pageReviewers' => $pageReviewers,
            'columnCount' => $pageSize + 1,
            'reviewersOnPage' => count($pageReviewers),
            'reviewerSlots' => range(1, $pageSize),
            'emptyReviewerSlots' => count($pageReviewers) < $pageSize ? range(1, $pageSize - count($pageReviewers)) : [],
            'rangeLabel' => $rangeLabel,
            'pageDots' => $pageDots,
            'page' => $page,
            'pageCount' => $pageCount,
            'attendanceOptions' => $this->participationAttendanceOptions(),
            'contributionOptions' => $this->participationContributionOptions(),
            'previousUrl' => $page > 0
                ? $request->getRouter()->url($request, null, 'groupReview', 'participationForm', null, ['sessionId' => $sessionId, 'page' => $page - 1])
                : null,
            'nextUrl' => $page < $pageCount - 1
                ? $request->getRouter()->url($request, null, 'groupReview', 'participationForm', null, ['sessionId' => $sessionId, 'page' => $page + 1])
                : null,
            'cancelUrl' => $request->getRouter()->url($request, null, 'groupReview', 'participation'),
            'submitUrl' => $request->getRouter()->url($request, null, 'groupReview', 'participationRecorded', null, ['sessionId' => $sessionId]),
        ]);
    }

    public function participationRecorded($args, $request): void
    {
        $sessionId = (int) $request->getUserVar('sessionId') ?: 1001;
        $this->participationMarkSubmitted($request, $sessionId);
        $session = $this->participationSessionData($request, $sessionId);

        $this->display($request, 'participationRecorded.tpl', [
            'pageTitle' => 'Reviewer Participation Recording',
            'sessionId' => $sessionId,
            'session' => $session,
            'backUrl' => $request->getRouter()->url($request, null, 'groupReview', 'participation'),
        ]);
    }

    private function participationSessions($request): array
    {
        return [
            $this->participationSessionData($request, 1001),
            $this->participationSessionData($request, 1002),
        ];
    }

    private function participationSessionData($request, int $sessionId): array
    {
        $sessions = [
            1001 => [
                'id' => 1001,
                'submissionId' => 114,
                'round' => 2,
                'leaderName' => 'H. Whitfield',
                'meetingLabel' => 'Meeting today',
                'reviewerCount' => 5,
                'status' => 'draft',
                'lastSaved' => '19 September 2026, 16:40',
                'lastSavedBy' => 'H. Whitfield',
                'submittedAt' => null,
                'submittedBy' => null,
            ],
            1002 => [
                'id' => 1002,
                'submissionId' => 114,
                'round' => 1,
                'leaderName' => 'H. Whitfield',
                'meetingLabel' => 'Meeting completed',
                'reviewerCount' => 5,
                'status' => 'submitted',
                'lastSaved' => '13 September 2026, 09:12',
                'lastSavedBy' => 'H. Whitfield',
                'submittedAt' => '12 September 2026, 16:40',
                'submittedBy' => 'H. Whitfield',
            ],
        ];
        $session = $sessions[$sessionId] ?? $sessions[1001];

        if (in_array($session['id'], $this->participationSubmittedSessionIds($request), true)) {
            $session['status'] = 'submitted';
            if (!$session['submittedAt']) {
                $session['submittedAt'] = $session['lastSaved'];
                $session['submittedBy'] = $session['lastSavedBy'];
            }
        }

        return $session;
    }

    private function participationSubmittedSessionIds($request): array
    {
        $submitted = $request->getSession()->getSessionVar('groupReviewSubmittedSessions');
        return is_array($submitted) ? $submitted : [];
    }

    private function participationMarkSubmitted($request, int $sessionId): void
    {
        $submitted = $this->participationSubmittedSessionIds($request);
        if (!in_array($sessionId, $submitted, true)) {
            $submitted[] = $sessionId;
            $request->getSession()->setSessionVar('groupReviewSubmittedSessions', $submitted);
        }
    }

    private function participationAttendanceOptions(): array
    {
        return [
            'attended' => 'Attended',
            'apology' => 'Did not attend, prior apology',
            'no_apology' => 'Did not attend, no prior apology',
            'other' => 'Other',
        ];
    }

    private function participationContributionOptions(): array
    {
        return [
            'uploaded_notes' => 'Uploaded their notes or comments',
            'commented_draft' => 'Commented on the feedback draft',
            'offered_draft' => 'Offered to create the draft',
            'created_draft' => 'Created the draft',
            'did_not_contribute' => 'Did not contribute to shaping the response',
            'other' => 'Other',
        ];
    }

    private function participationReviewers(): array
    {
        return [
            [
                'name' => 'J. Alvarez',
                'attendance' => 'attended',
                'attendanceNote' => '',
                'meetingComments' => '',
                'contributionChecked' => ['uploaded_notes' => true, 'commented_draft' => true],
                'feedbackComments' => '',
                'otherComments' => '',
            ],
            [
                'name' => 'R. Osei',
                'attendance' => 'attended',
                'attendanceNote' => '',
                'meetingComments' => '',
                'contributionChecked' => ['offered_draft' => true, 'created_draft' => true],
                'feedbackComments' => '',
                'otherComments' => '',
            ],
            [
                'name' => 'M. Tan',
                'attendance' => 'apology',
                'attendanceNote' => '',
                'meetingComments' => '',
                'contributionChecked' => ['uploaded_notes' => true],
                'feedbackComments' => '',
                'otherComments' => '',
            ],
            [
                'name' => 'K. Novak',
                'attendance' => 'no_apology',
                'attendanceNote' => '',
                'meetingComments' => '',
                'contributionChecked' => ['did_not_contribute' => true],
                'feedbackComments' => '',
                'otherComments' => '',
            ],
            [
                'name' => 'P. Damini',
                'attendance' => 'other',
                'attendanceNote' => '',
                'meetingComments' => '',
                'contributionChecked' => ['commented_draft' => true],
                'feedbackComments' => '',
                'otherComments' => '',
            ],
        ];
    }

    public function create($args, $request): void
    {
        $context = $request->getContext();
        if (!$this->service->isEnabled($context)) {
            $this->displayMessage($request, 'plugins.generic.groupReview.error.notEnabled');
            return;
        }

        $submissionId = (int) $request->getUserVar('submissionId');
        $reviewRoundId = (int) $request->getUserVar('reviewRoundId');
        $valid = $this->service->getValidSubmissionRound((int) $context->getId(), $submissionId, $reviewRoundId);
        if (!$valid) {
            $this->displayMessage($request, 'plugins.generic.groupReview.error.invalidSubmissionRound');
            return;
        }

        $active = $this->service->getActiveForSubmissionRound(
            (int) $context->getId(),
            $submissionId,
            $reviewRoundId
        );
        if ($active) {
            $request->redirect(null, 'groupReview', 'view', null, ['pollId' => (int) $active['session_id']]);
        }

        [$submission] = $valid;
        $timezone = date_default_timezone_get() ?: 'UTC';
        $leadDays = max(0, $this->contextInt($context, 'groupReviewMinimumLeadDays', 10));
        $slotCount = max(1, min(20, (int) ($context->getData('groupReviewDefaultSlots') ?: 3)));
        $firstSlot = Carbon::now($timezone)->addDays($leadDays)->startOfHour()->addHours(3);
        $defaultDeadline = $firstSlot->copy()->subHours($leadDays === 0 ? 1 : 24);
        $slots = [];
        for ($i = 0; $i < $slotCount; $i++) {
            $slots[] = $firstSlot->copy()->addHours($i * 2)->format('Y-m-d\TH:i');
        }

        $form = [
            'submission_id' => $submissionId,
            'review_round_id' => $reviewRoundId,
            'deadline' => $defaultDeadline->format('Y-m-d\TH:i'),
            'timezone' => $timezone,
            'duration' => (int) ($context->getData('groupReviewDefaultDuration') ?: 30),
            'send_reminder' => false,
            'reminder_hours' => (int) ($context->getData('groupReviewReminderHours') ?: 24),
            'meeting_url' => '',
            'slots' => $slots,
            'member_ids' => [],
        ];

        $this->displayPollForm($request, $submission, $form, [], false);
    }

    public function store($args, $request): void
    {
        $this->requirePostAndCsrf($request);
        $context = $request->getContext();

        [$data, $errors, $form, $submission] = $this->readAndValidatePoll($request, false);
        if (!$this->service->isEnabled($context)) {
            $errors[] = __('plugins.generic.groupReview.error.notEnabled');
        }
        if ($data && $this->service->getActiveForSubmissionRound(
            (int) $context->getId(),
            $data['submission_id'],
            $data['review_round_id']
        )) {
            $errors[] = __('plugins.generic.groupReview.error.activePollExists');
        }

        if ($errors) {
            $this->displayPollForm($request, $submission, $form, $errors, false);
            return;
        }

        try {
            $pollId = $this->service->create(
                (int) $context->getId(),
                (int) $request->getUser()->getId(),
                $data
            );
        } catch (Throwable $e) {
            error_log('Group Review poll creation rejected: ' . $e->getMessage());
            $errors[] = __('plugins.generic.groupReview.error.activePollExists');
            $this->displayPollForm($request, $submission, $form, $errors, false);
            return;
        }
        $request->redirect(null, 'groupReview', 'invite', null, ['pollId' => $pollId]);
    }

    public function invite($args, $request): void
    {
        $context = $request->getContext();
        $pollId = (int) $request->getUserVar('pollId');
        $bundle = $this->service->getBundle((int) $context->getId(), $pollId);
        if (!$bundle || (int) $bundle['poll']['status'] !== GroupReviewService::STATUS_DRAFT) {
            $this->displayMessage($request, 'plugins.generic.groupReview.error.pollNotDraft');
            return;
        }

        $this->display($request, 'inviteForm.tpl', [
            'pageTitle' => __('plugins.generic.groupReview.invite.title'),
            'bundle' => $bundle,
            'members' => $this->service->getRgmCandidates((int) $context->getId()),
            'errors' => [],
            'selectedLookup' => [],
            'formUrl' => $request->getRouter()->url($request, null, 'groupReview', 'storeInvitations', null, ['pollId' => $pollId]),
            'cancelUrl' => $request->getRouter()->url($request, null, 'groupReview', 'cancel', null, ['pollId' => $pollId]),
        ]);
    }

    public function storeInvitations($args, $request): void
    {
        $this->requirePostAndCsrf($request);
        $context = $request->getContext();
        $pollId = (int) $request->getUserVar('pollId');
        $memberIds = $this->intArray($request->getUserVar('memberIds'));
        $bundle = $this->service->getBundle((int) $context->getId(), $pollId);
        $valid = (bool) $bundle && (int) $bundle['poll']['status'] === GroupReviewService::STATUS_DRAFT;
        foreach ($memberIds as $userId) {
            $valid = $valid && $this->service->rgmIsEligible((int) $context->getId(), $userId);
        }
        if (!$valid || !$memberIds || count($memberIds) > 100
            || !$this->service->inviteMembers((int) $context->getId(), $pollId, $memberIds)) {
            $this->displayMessage($request, 'plugins.generic.groupReview.error.members');
            return;
        }

        $bundle = $this->service->getBundle((int) $context->getId(), $pollId);
        $this->createPollNotifications(
            $request,
            $context,
            $pollId,
            $this->service->memberUsers($bundle),
            GroupReviewNotification::NOTIFICATION_TYPE_POLL_CREATED
        );
        $failures = $this->sendInvitations($request, $context, $bundle, $this->service->memberUsers($bundle));
        $this->display($request, 'created.tpl', [
            'pageTitle' => __('plugins.generic.groupReview.created.title'),
            'pollUrl' => $request->getRouter()->url($request, null, 'groupReview', 'availability', null, ['pollId' => $pollId]),
            'viewUrl' => $request->getRouter()->url($request, null, 'groupReview', 'view', null, ['pollId' => $pollId]),
            'backUrl' => $request->getRouter()->url(
                $request,
                null,
                'workflow',
                'index',
                [(int) $bundle['poll']['submission_id'], WORKFLOW_STAGE_ID_EXTERNAL_REVIEW]
            ),
            'mailFailures' => $failures,
        ]);
    }

    public function edit($args, $request): void
    {
        $context = $request->getContext();
        $pollId = (int) $request->getUserVar('pollId');
        $bundle = $this->service->getBundle((int) $context->getId(), $pollId);
        if (!$bundle || (int) $bundle['poll']['status'] !== GroupReviewService::STATUS_OPEN) {
            $this->displayMessage($request, 'plugins.generic.groupReview.error.pollNotOpen');
            return;
        }

        $poll = $bundle['poll'];
        $timezone = $poll['timezone'];
        $form = [
            'submission_id' => (int) $poll['submission_id'],
            'review_round_id' => (int) $poll['review_round_id'],
            'deadline' => Carbon::parse($poll['deadline_utc'], 'UTC')->setTimezone($timezone)->format('Y-m-d\TH:i'),
            'timezone' => $timezone,
            'duration' => (int) $poll['meeting_duration_minutes'],
            'send_reminder' => (bool) $poll['send_reminder'],
            'reminder_hours' => (int) $poll['reminder_before_hours'],
            'meeting_url' => (string) ($poll['meeting_url'] ?? ''),
            'slots' => array_map(
                fn (array $slot): string => Carbon::parse($slot['start_time_utc'], 'UTC')->setTimezone($timezone)->format('Y-m-d\TH:i'),
                $bundle['slots']
            ),
            'member_ids' => array_map(fn (array $member): int => (int) $member['user_id'], $bundle['members']),
        ];

        $this->displayPollForm($request, $bundle['submission'], $form, [], true, $pollId);
    }

    public function update($args, $request): void
    {
        $this->requirePostAndCsrf($request);
        $context = $request->getContext();
        $pollId = (int) $request->getUserVar('pollId');
        $poll = $this->service->get((int) $context->getId(), $pollId);

        [$data, $errors, $form, $submission] = $this->readAndValidatePoll($request);
        if (!$poll
            || (int) $poll['status'] !== GroupReviewService::STATUS_OPEN
            || !$data
            || (int) $poll['submission_id'] !== $data['submission_id']
            || (int) $poll['review_round_id'] !== $data['review_round_id']) {
            $errors[] = __('plugins.generic.groupReview.error.pollNotOpen');
        }

        if ($errors) {
            $this->displayPollForm($request, $submission, $form, $errors, true, $pollId);
            return;
        }

        try {
            $this->service->update((int) $context->getId(), $pollId, $data);
        } catch (Throwable $e) {
            $this->displayMessage($request, 'plugins.generic.groupReview.error.pollNotOpen');
            return;
        }
        $bundle = $this->service->getBundle((int) $context->getId(), $pollId);
        // Editing replaces all slots and clears all responses, so every member
        // needs the revised invitation, not only newly added members.
        $failures = $this->sendInvitations(
            $request,
            $context,
            $bundle,
            $this->service->memberUsers($bundle)
        );

        $request->redirect(null, 'groupReview', 'view', null, [
            'pollId' => $pollId,
            'updated' => 1,
            'mailFailures' => $failures,
        ]);
    }

    public function availability($args, $request): void
    {
        $context = $request->getContext();
        $pollId = (int) $request->getUserVar('pollId');
        $bundle = $this->service->getBundle((int) $context->getId(), $pollId);
        if (!$bundle) {
            $this->displayMessage($request, 'plugins.generic.groupReview.error.pollNotFound');
            return;
        }

        $userId = (int) $request->getUser()->getId();
        $selected = [];
        foreach ($bundle['availability'][$userId] ?? [] as $slotId => $available) {
            if ($available) {
                $selected[] = (int) $slotId;
            }
        }
        $this->prepareBundleForDisplay($bundle);

        $this->display($request, 'availability.tpl', [
            'pageTitle' => __('plugins.generic.groupReview.availability.title'),
            'bundle' => $bundle,
            'selectedSlotIds' => $selected,
            'saved' => (bool) $request->getUserVar('saved'),
            'formUrl' => $request->getRouter()->url(
                $request,
                null,
                'groupReview',
                'saveAvailability',
                null,
                ['pollId' => $pollId]
            ),
            'dashboardUrl' => $request->getRouter()->url($request, null, 'groupReview', 'index'),
        ]);
    }

    public function saveAvailability($args, $request): void
    {
        $this->requirePostAndCsrf($request);
        $context = $request->getContext();
        $pollId = (int) $request->getUserVar('pollId');
        $slotIds = $this->intArray($request->getUserVar('slotIds'));

        if (!$this->service->saveAvailability(
            (int) $context->getId(),
            $pollId,
            (int) $request->getUser()->getId(),
            $slotIds
        )) {
            $this->displayMessage($request, 'plugins.generic.groupReview.error.pollNotOpen');
            return;
        }

        $request->redirect(null, 'groupReview', 'availability', null, ['pollId' => $pollId, 'saved' => 1]);
    }

    public function view($args, $request): void
    {
        $context = $request->getContext();
        $pollId = (int) $request->getUserVar('pollId');
        $bundle = $this->service->getBundle((int) $context->getId(), $pollId);
        if (!$bundle) {
            $this->displayMessage($request, 'plugins.generic.groupReview.error.pollNotFound');
            return;
        }
        if ((int) $bundle['poll']['status'] === GroupReviewService::STATUS_DRAFT) {
            $request->redirect(null, 'groupReview', 'invite', null, ['pollId' => $pollId]);
            return;
        }

        $this->prepareBundleForDisplay($bundle);
        $poll = $bundle['poll'];
        $baseParams = ['pollId' => $pollId];

        $this->display($request, 'view.tpl', [
            'pageTitle' => __('plugins.generic.groupReview.view.title'),
            'bundle' => $bundle,
            'statusLabel' => $this->service->statusLabel((int) $poll['status']),
            'created' => (bool) $request->getUserVar('created'),
            'updated' => (bool) $request->getUserVar('updated'),
            'finalized' => (bool) $request->getUserVar('finalized'),
            'resent' => (bool) $request->getUserVar('resent'),
            'mailFailures' => (int) $request->getUserVar('mailFailures'),
            'editUrl' => $request->getRouter()->url($request, null, 'groupReview', 'edit', null, $baseParams),
            'selectMeetingMembersUrl' => $request->getRouter()->url($request, null, 'groupReview', 'selectMeetingMembers', null, $baseParams),
            'cancelUrl' => $request->getRouter()->url($request, null, 'groupReview', 'cancel', null, $baseParams),
            'resendUrl' => $request->getRouter()->url($request, null, 'groupReview', 'resend', null, $baseParams),
            'availabilityUrl' => $request->getRouter()->url($request, null, 'groupReview', 'availability', null, $baseParams),
            'dashboardUrl' => $request->getRouter()->url($request, null, 'groupReview', 'index'),
        ]);
    }

    public function resend($args, $request): void
    {
        $this->requirePostAndCsrf($request);
        $context = $request->getContext();
        $pollId = (int) $request->getUserVar('pollId');
        $bundle = $this->service->getBundle((int) $context->getId(), $pollId);
        if (!$bundle || (int) $bundle['poll']['status'] !== GroupReviewService::STATUS_OPEN) {
            $this->displayMessage($request, 'plugins.generic.groupReview.error.pollNotOpen');
            return;
        }

        $users = $this->service->memberUsers($bundle, null, false);
        $failures = $this->sendInvitations($request, $context, $bundle, $users);
        $request->redirect(null, 'groupReview', 'view', null, [
            'pollId' => $pollId,
            'resent' => count($users),
            'mailFailures' => $failures,
        ]);
    }

    public function cancel($args, $request): void
    {
        $this->requirePostAndCsrf($request);
        $context = $request->getContext();
        $pollId = (int) $request->getUserVar('pollId');
        $this->service->cancel((int) $context->getId(), $pollId);
        $request->redirect(null, 'groupReview', 'view', null, ['pollId' => $pollId]);
    }

    public function finalize($args, $request): void
    {
        $this->requirePostAndCsrf($request);
        $context = $request->getContext();
        $pollId = (int) $request->getUserVar('pollId');
        $slotId = (int) $request->getUserVar('slotId');
        $selectedUserIds = $this->intArray($request->getUserVar('selectedUserIds'));
        $meetingUrl = trim((string) $request->getUserVar('meetingUrl'));
        $notes = trim((string) $request->getUserVar('notes'));
        $mailContent = [
            'selectedBody' => $this->scalarString($request->getUserVar('selectedBody')),
            'unselectedBody' => $this->scalarString($request->getUserVar('unselectedBody')),
        ];

        if (!$this->validUrl($meetingUrl)
            || mb_strlen($notes) > 4000
            || $mailContent['selectedBody'] === ''
            || mb_strlen($mailContent['selectedBody']) > 100000
            || mb_strlen($mailContent['unselectedBody']) > 100000) {
            $this->displayMessage($request, 'plugins.generic.groupReview.error.invalidFinalization');
            return;
        }

        try {
            $bundle = $this->service->finalizeAndProvision(
                $request,
                $context,
                $pollId,
                $slotId,
                $selectedUserIds,
                $meetingUrl ?: null,
                $notes ?: null
            );
        } catch (Throwable $e) {
            error_log('Group Review provisioning failed: ' . $e->getMessage());
            $this->displayMessage($request, 'plugins.generic.groupReview.error.provisioningFailed');
            return;
        }
        if (!$bundle) {
            $this->displayMessage($request, 'plugins.generic.groupReview.error.invalidFinalization');
            return;
        }

        $failures = $this->sendFinalizationEmails($request, $context, $bundle, $mailContent);
        $request->redirect(null, 'groupReview', 'view', null, [
            'pollId' => $pollId,
            'finalized' => 1,
            'mailFailures' => $failures,
        ]);
    }

    public function selectMeetingMembers($args, $request): void
    {
        $this->requirePostAndCsrf($request);
        $context = $request->getContext();
        $pollId = (int) $request->getUserVar('pollId');
        $slotId = (int) $request->getUserVar('slotId');
        $bundle = $this->service->getBundle((int) $context->getId(), $pollId);
        if (!$bundle || (int) $bundle['poll']['status'] !== GroupReviewService::STATUS_OPEN) {
            $this->displayMessage($request, 'plugins.generic.groupReview.error.pollNotOpen');
            return;
        }
        $this->prepareBundleForDisplay($bundle);
        $slot = $this->findSlot($bundle, $slotId);
        if (!$slot) {
            $this->displayMessage($request, 'plugins.generic.groupReview.error.invalidFinalization');
            return;
        }
        $available = array_fill_keys($slot['available_user_ids'], true);
        $this->display($request, 'selectMembers.tpl', [
            'pageTitle' => __('plugins.generic.groupReview.selectMembers.title'),
            'bundle' => $bundle,
            'slot' => $slot,
            'availableLookup' => $available,
            'formUrl' => $request->getRouter()->url($request, null, 'groupReview', 'reviewMessages', null, ['pollId' => $pollId]),
            'backUrl' => $request->getRouter()->url($request, null, 'groupReview', 'view', null, ['pollId' => $pollId]),
        ]);
    }

    public function reviewMessages($args, $request): void
    {
        $this->requirePostAndCsrf($request);
        $context = $request->getContext();
        $pollId = (int) $request->getUserVar('pollId');
        $slotId = (int) $request->getUserVar('slotId');
        $selected = $this->intArray($request->getUserVar('selectedUserIds'));
        $bundle = $this->service->getBundle((int) $context->getId(), $pollId);
        if (!$bundle || (int) $bundle['poll']['status'] !== GroupReviewService::STATUS_OPEN) {
            $this->displayMessage($request, 'plugins.generic.groupReview.error.pollNotOpen');
            return;
        }
        $this->prepareBundleForDisplay($bundle);
        $slot = $this->findSlot($bundle, $slotId);
        $available = $slot ? array_map('intval', $slot['available_user_ids']) : [];
        if (!$slot || !$selected || array_diff($selected, $available)) {
            $this->displayMessage($request, 'plugins.generic.groupReview.error.invalidFinalization');
            return;
        }

        $selectedTemplate = $this->emailTemplateContent($context, GroupReviewPollThankRgms::getEmailTemplateKey());
        $unselectedTemplate = $this->emailTemplateContent($context, GroupReviewPollThankInvitees::getEmailTemplateKey());
        $this->display($request, 'reviewMessages.tpl', [
            'pageTitle' => __('plugins.generic.groupReview.reviewMessages.title'),
            'bundle' => $bundle,
            'slot' => $slot,
            'selectedUserIds' => $selected,
            'selectedLookup' => array_fill_keys($selected, true),
            'selectedTemplate' => $selectedTemplate,
            'unselectedTemplate' => $unselectedTemplate,
            'formUrl' => $request->getRouter()->url($request, null, 'groupReview', 'finalize', null, ['pollId' => $pollId]),
            'backUrl' => $request->getRouter()->url($request, null, 'groupReview', 'view', null, ['pollId' => $pollId]),
        ]);
    }

    /**
     * @return array{0:?array,1:array,2:array,3:mixed}
     */
    private function readAndValidatePoll($request, bool $requireMembers = true): array
    {
        $context = $request->getContext();
        $submissionId = (int) $this->scalarString($request->getUserVar('submissionId'));
        $reviewRoundId = (int) $this->scalarString($request->getUserVar('reviewRoundId'));
        $timezone = trim($this->scalarString($request->getUserVar('timezone')));
        $deadlineInput = trim($this->scalarString($request->getUserVar('deadline')));
        $rawSlots = $request->getUserVar('slots');
        $rawSlots = is_array($rawSlots) ? array_slice($rawSlots, 0, 21) : [$rawSlots];
        $slotInputs = [];
        $invalidSlotInput = false;
        foreach ($rawSlots as $rawSlot) {
            if (!is_scalar($rawSlot)) {
                $invalidSlotInput = true;
                continue;
            }
            $slot = trim((string) $rawSlot);
            if ($slot !== '') {
                $slotInputs[] = $slot;
            }
        }
        $memberIds = $this->intArray($request->getUserVar('memberIds'));
        $duration = (int) $this->scalarString($request->getUserVar('duration'));
        $sendReminder = (bool) (int) $this->scalarString($request->getUserVar('sendReminder'));
        $reminderHours = (int) $this->scalarString($request->getUserVar('reminderHours'));
        $meetingUrl = trim($this->scalarString($request->getUserVar('meetingUrl')));

        $form = [
            'submission_id' => $submissionId,
            'review_round_id' => $reviewRoundId,
            'deadline' => $deadlineInput,
            'timezone' => $timezone,
            'duration' => $duration,
            'send_reminder' => $sendReminder,
            'reminder_hours' => $reminderHours,
            'meeting_url' => $meetingUrl,
            'slots' => $slotInputs,
            'member_ids' => $memberIds,
        ];
        $errors = [];

        $valid = $this->service->getValidSubmissionRound(
            (int) $context->getId(),
            $submissionId,
            $reviewRoundId
        );
        $submission = $valid ? $valid[0] : null;
        if (!$valid) {
            $errors[] = __('plugins.generic.groupReview.error.invalidSubmissionRound');
        }

        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            $errors[] = __('plugins.generic.groupReview.error.timezone');
        }

        $deadlineUtc = $this->service->toUtc($deadlineInput, $timezone);
        if (!$deadlineUtc || Carbon::parse($deadlineUtc, 'UTC')->lte(Carbon::now('UTC')->addMinutes(5))) {
            $errors[] = __('plugins.generic.groupReview.error.deadline');
        }

        if ($duration < 5 || $duration > 480) {
            $errors[] = __('plugins.generic.groupReview.error.duration');
        }
        if ($reminderHours < 1 || $reminderHours > 720) {
            $errors[] = __('plugins.generic.groupReview.error.reminder');
        }
        if (count($slotInputs) < 1 || count($slotInputs) > 20) {
            $errors[] = __('plugins.generic.groupReview.error.slotsCount');
        }
        if ($invalidSlotInput) {
            $errors[] = __('plugins.generic.groupReview.error.slot');
        }

        $slotsUtc = [];
        foreach ($slotInputs as $slotInput) {
            $slotUtc = $this->service->toUtc($slotInput, $timezone);
            if (!$slotUtc) {
                $errors[] = __('plugins.generic.groupReview.error.slot');
                continue;
            }
            $slotsUtc[] = $slotUtc;
        }
        $slotsUtc = array_values(array_unique($slotsUtc));
        if (count($slotsUtc) !== count($slotInputs)) {
            $errors[] = __('plugins.generic.groupReview.error.duplicateSlots');
        }

        $leadDays = max(0, $this->contextInt($context, 'groupReviewMinimumLeadDays', 10));
        $minimumSlot = Carbon::now('UTC')->addDays($leadDays);
        foreach ($slotsUtc as $slotUtc) {
            $slot = Carbon::parse($slotUtc, 'UTC');
            if (($deadlineUtc && $slot->lte(Carbon::parse($deadlineUtc, 'UTC'))) || $slot->lt($minimumSlot)) {
                $errors[] = __('plugins.generic.groupReview.error.slotTiming', ['days' => $leadDays]);
                break;
            }
        }

        if (($requireMembers && !$memberIds) || count($memberIds) > 100) {
            $errors[] = __('plugins.generic.groupReview.error.members');
        } else {
            foreach ($memberIds as $userId) {
                if (!$this->service->rgmIsEligible((int) $context->getId(), $userId)) {
                    $errors[] = __('plugins.generic.groupReview.error.memberJournal');
                    break;
                }
            }
        }

        if (!$this->validUrl($meetingUrl)) {
            $errors[] = __('plugins.generic.groupReview.error.meetingUrl');
        }

        $data = $errors ? null : [
            'submission_id' => $submissionId,
            'review_round_id' => $reviewRoundId,
            'deadline_utc' => $deadlineUtc,
            'timezone' => $timezone,
            'duration' => $duration,
            'send_reminder' => $sendReminder,
            'reminder_hours' => $reminderHours,
            'meeting_url' => $meetingUrl,
            'slots_utc' => $slotsUtc,
            'member_ids' => $memberIds,
        ];

        return [$data, $errors, $form, $submission];
    }

    private function displayPollForm(
        $request,
        $submission,
        array $form,
        array $errors,
        bool $editing,
        int $pollId = 0
    ): void {
        $context = $request->getContext();
        $members = $this->service->getRgmCandidates((int) $context->getId());
        $selectedLookup = array_fill_keys($form['member_ids'], true);

        $this->display($request, 'pollForm.tpl', [
            'pageTitle' => $editing
                ? __('plugins.generic.groupReview.edit.title')
                : __('plugins.generic.groupReview.create.title'),
            'submission' => $submission,
            'form' => $form,
            'errors' => $errors,
            'editing' => $editing,
            'pollId' => $pollId,
            'members' => $members,
            'selectedLookup' => $selectedLookup,
            'formUrl' => $request->getRouter()->url(
                $request,
                null,
                'groupReview',
                $editing ? 'update' : 'store',
                null,
                $editing ? ['pollId' => $pollId] : null
            ),
            'dashboardUrl' => $request->getRouter()->url($request, null, 'groupReview', 'index'),
        ]);
    }

    private function prepareBundleForDisplay(array &$bundle): void
    {
        $timezone = $bundle['poll']['timezone'];
        $duration = (int) $bundle['poll']['meeting_duration_minutes'];
        foreach ($bundle['slots'] as &$slot) {
            $start = Carbon::parse($slot['start_time_utc'], 'UTC')->setTimezone($timezone);
            $slot['display'] = $start->format('D, j M Y, g:i a T');
            $slot['end_display'] = $start->copy()->addMinutes($duration)->format('g:i a T');
            $slot['available_user_ids'] = [];
            foreach ($bundle['members'] as $member) {
                if (!empty($bundle['availability'][(int) $member['user_id']][(int) $slot['slot_id']])) {
                    $slot['available_user_ids'][] = (int) $member['user_id'];
                }
            }
        }
        $bundle['poll']['deadline_display'] = $this->service->formatUtc(
            $bundle['poll']['deadline_utc'],
            $timezone
        );
    }

    private function sendInvitations($request, $context, array $bundle, array $users): int
    {
        $poll = $bundle['poll'];
        $submission = $bundle['submission'];
        $pollUrl = $request->getDispatcher()->url(
            $request,
            Application::ROUTE_PAGE,
            $context->getPath(),
            'groupReview',
            'availability',
            null,
            ['pollId' => (int) $poll['session_id']]
        );
        $deadline = $this->service->formatUtc($poll['deadline_utc'], $poll['timezone']);
        $leaderName = $bundle['leader'] ? $bundle['leader']->getFullName() : '';
        $failures = 0;

        foreach ($users as $user) {
            $mailable = new GroupReviewPollCreated(
                $context,
                $user->getFullName(),
                $pollUrl,
                $submission ? $submission->getLocalizedTitle() : '',
                $deadline,
                $leaderName
            );
            if (!$this->sendMailable($context, $user, $mailable, GroupReviewPollCreated::getEmailTemplateKey())) {
                $failures++;
            }
        }
        return $failures;
    }

    private function createPollNotifications($request, $context, int $pollId, array $users, int $type): void
    {
        $manager = new NotificationManager();
        foreach ($users as $user) {
            try {
                $manager->createNotification(
                    $request,
                    (int) $user->getId(),
                    $type,
                    (int) $context->getId(),
                    GroupReviewPlugin::ASSOC_TYPE_POLL,
                    $pollId,
                    GroupReviewNotification::NOTIFICATION_LEVEL_TASK
                );
            } catch (Throwable $e) {
                error_log('Group Review task notification failed: ' . $e->getMessage());
            }
        }
    }

    private function sendFinalizationEmails($request, $context, array $bundle, array $content): int
    {
        $poll = $bundle['poll'];
        $submissionTitle = $bundle['submission'] ? $bundle['submission']->getLocalizedTitle() : '';
        $selectedSlot = null;
        foreach ($bundle['slots'] as $slot) {
            if ((int) $slot['slot_id'] === (int) $poll['selected_slot_id']) {
                $selectedSlot = $slot;
                break;
            }
        }
        $meetingTime = $selectedSlot
            ? $this->service->formatUtc($selectedSlot['start_time_utc'], $poll['timezone'])
            : '';
        $groupReviewUrl = $request->getDispatcher()->url(
            $request,
            Application::ROUTE_PAGE,
            $context->getPath(),
            'workflow',
            'index',
            [(int) $poll['submission_id'], WORKFLOW_STAGE_ID_EXTERNAL_REVIEW]
        );
        $failures = 0;

        foreach ($this->service->memberUsers($bundle, true) as $user) {
            $mailable = new GroupReviewPollThankRgms(
                $context,
                $user->getFullName(),
                $submissionTitle,
                $meetingTime,
                (string) ($poll['meeting_url'] ?? ''),
                $groupReviewUrl
            );
            if (!$this->sendMailable($context, $user, $mailable, GroupReviewPollThankRgms::getEmailTemplateKey(), null, $content['selectedBody'])) {
                $failures++;
            }
        }
        foreach ($this->service->memberUsers($bundle, false) as $user) {
            $mailable = new GroupReviewPollThankInvitees(
                $context,
                $user->getFullName(),
                $submissionTitle
            );
            if (!$this->sendMailable($context, $user, $mailable, GroupReviewPollThankInvitees::getEmailTemplateKey(), null, $content['unselectedBody'])) {
                $failures++;
            }
        }

        return $failures;
    }

    private function sendMailable($context, $user, $mailable, string $templateKey, ?string $subject = null, ?string $body = null): bool
    {
        try {
            $template = Repo::emailTemplate()->getByKey((int) $context->getId(), $templateKey);
            if (!$template) {
                return false;
            }
            $mailable->from($context->getContactEmail(), $context->getContactName())
                ->to($user->getEmail(), $user->getFullName())
                ->subject($subject !== null && $subject !== '' ? $subject : $template->getLocalizedData('subject'))
                ->body($body !== null && $body !== '' ? $body : $template->getLocalizedData('body'));
            Mail::send($mailable);
            return true;
        } catch (Throwable $e) {
            error_log('Group Review email failed: ' . $e->getMessage());
            return false;
        }
    }

    private function requirePostAndCsrf($request): void
    {
        if (!$request->isPost() || !$request->checkCSRF()) {
            throw new \RuntimeException(__('plugins.generic.groupReview.error.invalidRequest'));
        }
    }

    private function intArray($value): array
    {
        $values = is_array($value) ? $value : ($value === null || $value === '' ? [] : [$value]);
        $values = array_slice($values, 0, 101);
        $values = array_filter($values, fn ($item): bool => is_scalar($item));
        $values = array_values(array_unique(array_filter(array_map('intval', $values), fn (int $id): bool => $id > 0)));
        sort($values);
        return $values;
    }

    private function scalarString($value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function findSlot(array $bundle, int $slotId): ?array
    {
        foreach ($bundle['slots'] as $slot) {
            if ((int) $slot['slot_id'] === $slotId) {
                return $slot;
            }
        }
        return null;
    }

    private function emailTemplateContent($context, string $key): array
    {
        $template = Repo::emailTemplate()->getByKey((int) $context->getId(), $key);
        return [
            'subject' => $template ? (string) $template->getLocalizedData('subject') : '',
            'body' => $template ? (string) $template->getLocalizedData('body') : '',
        ];
    }

    private function contextInt($context, string $key, int $default): int
    {
        $value = $context->getData($key);
        return $value === null || $value === '' ? $default : (int) $value;
    }

    private function validUrl(string $url): bool
    {
        if ($url === '') {
            return true;
        }
        if (mb_strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['https', 'http'], true);
    }

    private function display($request, string $template, array $vars): void
    {
        $this->setupTemplate($request);
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign($vars);
        $templateMgr->display($this->plugin->getTemplateResource($template));
    }

    private function displayMessage($request, string $messageKey): void
    {
        $this->display($request, 'message.tpl', [
            'pageTitle' => __('plugins.generic.groupReview.displayName'),
            'message' => __($messageKey),
            'dashboardUrl' => $request->getRouter()->url($request, null, 'groupReview', 'index'),
        ]);
    }
}
