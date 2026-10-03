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
use APP\plugins\generic\groupReview\classes\mail\GroupReviewParticipationSubmitted;
use APP\plugins\generic\groupReview\classes\mail\GroupReviewPollCreated;
use APP\plugins\generic\groupReview\classes\mail\GroupReviewPollThankInvitees;
use APP\plugins\generic\groupReview\classes\mail\GroupReviewPollThankRgms;
use APP\plugins\generic\groupReview\classes\notification\Notification as GroupReviewNotification;
use APP\plugins\generic\groupReview\classes\ParticipationService;
use APP\plugins\generic\groupReview\classes\ReviewerLabelService;
use APP\plugins\generic\groupReview\classes\ReviewerStatsService;
use APP\plugins\generic\groupReview\classes\security\authorization\EditorRequiredPolicy;
use APP\plugins\generic\groupReview\classes\security\authorization\InviteeRequiredPolicy;
use APP\plugins\generic\groupReview\classes\security\authorization\LeaderRequiredPolicy;
use APP\plugins\generic\groupReview\classes\security\authorization\ParticipationAccessPolicy;
use APP\plugins\generic\groupReview\GroupReviewPlugin;
use APP\template\TemplateManager;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use PKP\core\Core;
use PKP\core\JSONMessage;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\security\authorization\ContextRequiredPolicy;
use PKP\security\authorization\UserRequiredPolicy;
use PKP\security\Role;
use PKP\security\Validation;
use Throwable;

class GroupReviewHandler extends Handler
{
    public $_isBackendPage = true;

    private GroupReviewPlugin $plugin;
    private GroupReviewService $service;

    private const LEADER_OPERATIONS = [
        'create', 'store', 'invite', 'storeInvitations', 'view', 'edit', 'update',
        'selectMeetingMembers', 'reviewMessages', 'finalize', 'cancel', 'resend',
        'saveParticipation',
    ];
    private const INVITEE_OPERATIONS = ['availability', 'saveAvailability'];
    private const PARTICIPATION_OPERATIONS = [
        'participation', 'participationForm', 'saveParticipationForm', 'participationRecorded',
    ];
    private const PARTICIPATION_READ_OPERATIONS = ['getParticipation'];
    private const PARTICIPATION_DATE_FORMAT = 'j F Y, H:i';
    private const MONITORING_DATE_FORMAT = 'j F Y';
    private const MONITORING_OPERATIONS = ['overview', 'reviewers', 'reviewer', 'saveLabels'];
    private const MONITORING_SORT_COLUMNS = ['name', 'invited', 'available', 'selected', 'completed', 'current', 'attended'];

    public function __construct(GroupReviewPlugin $plugin)
    {
        parent::__construct();
        $this->plugin = $plugin;
        $this->service = new GroupReviewService();

        $this->addRoleAssignment(
            [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR],
            ['index', 'participation', 'participationForm', 'saveParticipationForm', 'participationRecorded']
        );
        $this->addRoleAssignment(
            [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR],
            self::LEADER_OPERATIONS
        );
        $this->addRoleAssignment(Role::ROLE_ID_SUB_EDITOR, self::INVITEE_OPERATIONS);
        $this->addRoleAssignment(
            [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_REVIEWER],
            self::PARTICIPATION_READ_OPERATIONS
        );
        $this->addRoleAssignment(Role::ROLE_ID_MANAGER, self::MONITORING_OPERATIONS);
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
        } elseif (in_array($operation, self::PARTICIPATION_OPERATIONS, true)) {
            $this->addPolicy(new ParticipationAccessPolicy($request));
        } elseif (in_array($operation, self::MONITORING_OPERATIONS, true)) {
            $this->addPolicy(new EditorRequiredPolicy($request));
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

    public function reviewers($args, $request): void
    {
        $contextId = (int) $request->getContext()->getId();
        $stats = new ReviewerStatsService();
        $years = $stats->getYears($contextId);
        $year = $this->monitoringYear($request, $years);
        [$sort, $dir] = $this->monitoringSortAndDir($request);

        $rows = $stats->getReviewerRows($contextId, $year);
        $labels = (new ReviewerLabelService())->getLabels($contextId, array_column($rows, 'userId'));

        $reviewers = [];
        foreach ($rows as $row) {
            $reviewers[] = $this->reviewerTableRow($row, $labels[$row['userId']] ?? [], $request, $year);
        }
        $reviewers = $this->sortReviewerRows($reviewers, $sort, $dir);

        $this->display($request, 'reviewers.tpl', [
            'pageTitle' => __('plugins.generic.groupReview.monitoring.reviewers.title'),
            'monitoringTabsResource' => $this->plugin->getTemplateResource('monitoringTabs.tpl'),
            'year' => $year ?? 'all',
            'yearOptions' => $this->monitoringYearOptions($years),
            'overviewUrl' => $request->getRouter()->url($request, null, 'groupReview', 'overview', null, ['year' => $year ?? 'all']),
            'reviewersUrl' => $request->getRouter()->url($request, null, 'groupReview', 'reviewers', null, ['year' => $year ?? 'all']),
            'reviewersActionUrl' => $request->getRouter()->url($request, null, 'groupReview', 'reviewers'),
            'sort' => $sort,
            'dir' => $dir,
            'sortUrls' => $this->monitoringSortUrls($request, $year, $sort, $dir),
            'reviewers' => $reviewers,
        ]);
    }

    public function overview($args, $request): void
    {
        $contextId = (int) $request->getContext()->getId();
        $stats = new ReviewerStatsService();
        $years = $stats->getYears($contextId);
        $year = $this->monitoringYear($request, $years);
        $overview = $stats->getOverview($contextId, $year);
        $labelService = new ReviewerLabelService();
        $labels = [];

        foreach (ReviewerLabelService::TYPES as $type => $definition) {
            $counts = $overview['labels'][$type];
            $values = [];
            foreach (array_keys($definition['values']) as $value) {
                $values[] = [
                    'label' => $labelService->name($type, $value),
                    'total' => $counts['values'][$value]['total'],
                    'active' => $counts['values'][$value]['active'],
                ];
            }
            $values[] = [
                'label' => __('plugins.generic.groupReview.labels.notSet'),
                'total' => $counts['notSet']['total'],
                'active' => $counts['notSet']['active'],
            ];
            $labels[] = [
                'name' => $labelService->name($type),
                'values' => $values,
            ];
        }

        $this->display($request, 'overview.tpl', [
            'pageTitle' => __('plugins.generic.groupReview.monitoring.dashboardTitle'),
            'monitoringTabsResource' => $this->plugin->getTemplateResource('monitoringTabs.tpl'),
            'year' => $year ?? 'all',
            'yearOptions' => $this->monitoringYearOptions($years),
            'overviewUrl' => $request->getRouter()->url($request, null, 'groupReview', 'overview', null, ['year' => $year ?? 'all']),
            'reviewersUrl' => $request->getRouter()->url($request, null, 'groupReview', 'reviewers', null, ['year' => $year ?? 'all']),
            'overviewActionUrl' => $request->getRouter()->url($request, null, 'groupReview', 'overview'),
            'live' => $overview['live'],
            'activity' => $overview['activity'],
            'labels' => $labels,
        ]);
    }

    public function reviewer($args, $request): void
    {
        $contextId = (int) $request->getContext()->getId();
        $reviewerId = $this->monitoringReviewerId($request);
        $stats = new ReviewerStatsService();
        $years = $stats->getYears($contextId);
        $year = $this->monitoringYear($request, $years);
        $row = $stats->getReviewerRow($contextId, $reviewerId, $year);
        if (!$row) {
            $this->displayMessage($request, 'plugins.generic.groupReview.error.reviewerNotFound');
            return;
        }

        $labelService = new ReviewerLabelService();
        $labels = $labelService->getLabels($contextId, [$reviewerId])[$reviewerId]
            ?? array_fill_keys(array_keys(ReviewerLabelService::TYPES), []);
        $reviewerStats = array_merge(
            $row,
            $this->reviewerTableRow($row, $labels, $request, $year)
        );

        $this->display($request, 'reviewer.tpl', [
            'pageTitle' => $row['name'],
            'monitoringTabsResource' => $this->plugin->getTemplateResource('monitoringTabs.tpl'),
            'year' => $year ?? 'all',
            'yearOptions' => $this->monitoringYearOptions($years),
            'overviewUrl' => $request->getRouter()->url($request, null, 'groupReview', 'overview', null, ['year' => $year ?? 'all']),
            'reviewersUrl' => $request->getRouter()->url($request, null, 'groupReview', 'reviewers', null, ['year' => $year ?? 'all']),
            'reviewerUrl' => $request->getRouter()->url($request, null, 'groupReview', 'reviewer'),
            'reviewer' => [
                'userId' => $row['userId'],
                'name' => $row['name'],
                'reviewerSince' => $row['reviewerSince'] === null ? null : $this->service->formatUtc($row['reviewerSince'], 'UTC', self::MONITORING_DATE_FORMAT),
                'lastActivity' => $row['lastActivity'] === null ? null : $this->service->formatUtc($row['lastActivity'], 'UTC', self::MONITORING_DATE_FORMAT),
                'labels' => $this->reviewerLabelsList($labels),
            ],
            'stats' => $reviewerStats,
            'attendanceCounts' => $this->monitoringCounts($stats->getAttendanceCounts($contextId, $reviewerId, $year), ParticipationService::ATTENDANCE_OPTIONS),
            'contributionCounts' => $this->monitoringCounts($stats->getContributionCounts($contextId, $reviewerId, $year), ParticipationService::CONTRIBUTION_OPTIONS),
            'history' => $this->reviewerHistory($stats->getHistory($contextId, $reviewerId, $year), $request),
            'labelOptions' => $this->reviewerLabelOptions(),
            'labelValues' => $labels,
            'labelHistory' => $labelService->getHistory($contextId, $reviewerId),
            'labelSaveError' => (bool) $request->getUserVar('labelSaveError'),
            'saveLabelsUrl' => $request->getRouter()->url($request, null, 'groupReview', 'saveLabels'),
            'backUrl' => $request->getRouter()->url($request, null, 'groupReview', 'reviewers', null, ['year' => $year ?? 'all']),
        ]);
    }

    public function saveLabels($args, $request): void
    {
        if (!$request->isPost() || !$request->checkCSRF()) {
            $this->displayMessage($request, 'plugins.generic.groupReview.error.invalidRequest');
            return;
        }

        $contextId = (int) $request->getContext()->getId();
        $reviewerId = $this->monitoringReviewerId($request);
        $stats = new ReviewerStatsService();
        $year = $this->monitoringYear($request, $stats->getYears($contextId));

        try {
            if ($reviewerId < 1 || !$stats->getReviewerRow($contextId, $reviewerId, null)) {
                $this->displayMessage($request, 'plugins.generic.groupReview.error.reviewerNotFound');
                return;
            }

            $values = [];
            foreach (ReviewerLabelService::TYPES as $type => $definition) {
                $posted = $request->getUserVar($type);
                if ($definition['multiple']) {
                    if ($posted !== null && !is_array($posted)) {
                        throw new InvalidArgumentException("The {$type} values must be a list.");
                    }
                    $values[$type] = $posted ?? [];
                } else {
                    if ($posted !== null && !is_scalar($posted)) {
                        throw new InvalidArgumentException("The {$type} value must be a scalar.");
                    }
                    $value = (string) ($posted ?? '');
                    $values[$type] = $value === '' ? [] : [$value];
                }
            }

            (new ReviewerLabelService())->setLabels(
                $contextId,
                $reviewerId,
                $values,
                $request->getUserVar('note'),
                (int) $request->getUser()->getId()
            );
        } catch (Throwable $e) {
            error_log('Group Review label save failed: ' . $e->getMessage());
            $request->redirect(null, 'groupReview', 'reviewer', null, [
                'reviewerId' => $reviewerId,
                'year' => $year ?? 'all',
                'labelSaveError' => 1,
            ]);
            return;
        }

        $request->redirect(null, 'groupReview', 'reviewer', null, [
            'reviewerId' => $reviewerId,
            'year' => $year ?? 'all',
        ]);
    }

    public function participation($args, $request): void
    {
        $contextId = (int) $request->getContext()->getId();
        $submissionId = (int) $request->getUserVar('submissionId');
        $userId = (int) $request->getUser()->getId();
        // Journal editors see every form; an RGL only the forms they lead.
        $leaderUserId = $this->service->isJournalEditor($contextId, $submissionId, $userId) ? null : $userId;
        $submission = $submissionId ? Repo::submission()->get($submissionId) : null;
        $publication = $submission ? $submission->getCurrentPublication() : null;
        $participation = new ParticipationService();
        $sessions = [];
        foreach ($participation->getFormSessionIds($contextId, $submissionId, $leaderUserId) as $sessionId) {
            $bundle = $this->service->getBundle($contextId, $sessionId);
            if (!$bundle) {
                continue;
            }
            $session = $this->participationSessionData(
                $bundle,
                $participation->getForm($contextId, $sessionId)['form']
            );
            $session['openUrl'] = $request->getRouter()->url(
                $request,
                null,
                'groupReview',
                'participationForm',
                null,
                ['sessionId' => $sessionId, 'page' => 0]
            );
            $sessions[] = $session;
        }

        $this->display($request, 'participation.tpl', [
            'pageTitle' => 'Reviewer Participation Recording',
            'submissionTitle' => $submission ? $submission->getLocalizedTitle() : '',
            'firstAuthor' => $publication ? $publication->getShortAuthorString() : '',
            'sessions' => $sessions,
            'backUrl' => $this->participationBackUrl($request, $submissionId),
        ]);
    }

    public function participationForm($args, $request): void
    {
        $contextId = (int) $request->getContext()->getId();
        $sessionId = (int) $request->getUserVar('sessionId');
        $page = max(0, (int) $request->getUserVar('page'));

        $bundle = $this->participationBundle($contextId, $sessionId);
        if (!$bundle) {
            $this->displayMessage($request, 'plugins.generic.groupReview.participation.error.formNotFound');
            return;
        }

        $saved = (new ParticipationService())->getForm($contextId, $sessionId);
        $session = $this->participationSessionData($bundle, $saved['form']);
        $reviewers = $this->participationReviewers($bundle);
        $pageSize = 2;
        $pageCount = max(1, (int) ceil(count($reviewers) / $pageSize));
        $page = min($page, $pageCount - 1);

        // Every reviewer is in the form; paging only shows and hides columns.
        $formReviewers = [];
        foreach ($reviewers as $index => $reviewer) {
            $record = $saved['reviewers'][$reviewer['id']] ?? null;
            $formReviewers[] = array_merge($reviewer, [
                'page' => intdiv($index, $pageSize),
                'attendance' => $record['attendance'] ?? ParticipationService::ATTENDANCE_NOT_RECORDED,
                'attendanceNote' => $record['attendance_other'] ?? '',
                'meetingComments' => $record['contribution_comments'] ?? '',
                'contributionChecked' => array_fill_keys($record['shaping_feedback_types'] ?? [], true),
                'feedbackComments' => $record['shaping_feedback_comments'] ?? '',
                'otherComments' => $record['other_contribution'] ?? '',
            ]);
        }
        $pages = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $startIndex = $i * $pageSize + 1;
            $endIndex = min($startIndex + $pageSize - 1, count($reviewers));
            $pages[] = [
                'index' => $i,
                'rangeLabel' => ($startIndex >= $endIndex ? 'Reviewer ' . $startIndex : "Reviewers {$startIndex}-{$endIndex}")
                    . ' of ' . count($reviewers),
            ];
        }
        $emptySlots = count($reviewers) % $pageSize ? $pageSize - count($reviewers) % $pageSize : 0;
        $isSubmitted = $session['status'] === ParticipationService::STATUS_SUBMITTED;

        $this->display($request, 'participationForm.tpl', [
            'pageTitle' => 'Reviewer Participation Recording',
            'sessionId' => $sessionId,
            'session' => $session,
            'generalComments' => $saved['form']['general_comments'] ?? '',
            'saveError' => (bool) $request->getUserVar('saveError'),
            'isSubmitted' => $isSubmitted,
            'reviewers' => $formReviewers,
            'pages' => $pages,
            'page' => $page,
            'pageCount' => $pageCount,
            'lastPage' => $pageCount - 1,
            'columnCount' => $pageSize + 1,
            'reviewerSlots' => range(1, $pageSize),
            'emptyReviewerSlots' => $emptySlots ? range(1, $emptySlots) : [],
            'attendanceOptions' => $this->participationAttendanceOptions(),
            'contributionOptions' => $this->participationContributionOptions(),
            'cancelUrl' => $this->participationListUrl($request, (int) $bundle['poll']['submission_id']),
            'saveUrl' => $request->getRouter()->url($request, null, 'groupReview', 'saveParticipationForm'),
        ]);
    }

    /**
     * Save every reviewer and the form-level fields, then return to the form
     * on the page the user was viewing, or submit.
     */
    public function saveParticipationForm($args, $request): void
    {
        if (!$request->isPost() || !$request->checkCSRF()) {
            $this->displayMessage($request, 'plugins.generic.groupReview.error.invalidRequest');
            return;
        }

        $contextId = (int) $request->getContext()->getId();
        $sessionId = (int) $request->getUserVar('sessionId');
        $page = max(0, (int) $request->getUserVar('page'));
        $submit = $request->getUserVar('formAction') === 'submit';

        $reviewers = [];
        $posted = $request->getUserVar('reviewers');
        foreach (is_array($posted) ? $posted : [] as $reviewerUserId => $fields) {
            if (!is_array($fields)) {
                continue;
            }
            $reviewers[] = [
                'reviewer_user_id' => $reviewerUserId,
                'attendance' => $fields['attendance'] ?? null,
                'attendance_other' => $fields['attendanceOther'] ?? null,
                'contribution_comments' => $fields['contributionComments'] ?? null,
                'shaping_feedback_types' => $fields['shapingFeedbackTypes'] ?? [],
                'shaping_feedback_comments' => $fields['shapingFeedbackComments'] ?? null,
                'other_contribution' => $fields['otherContribution'] ?? null,
            ];
        }

        try {
            $bundle = $this->participationBundle($contextId, $sessionId);
            if (!$bundle) {
                throw new InvalidArgumentException('The group review session has no participation form.');
            }
            (new ParticipationService())->saveForm(
                $contextId,
                $sessionId,
                (int) $request->getUser()->getId(),
                $reviewers,
                [
                    'general_comments' => $request->getUserVar('generalComments'),
                    'submission_comment' => $request->getUserVar('submissionComment'),
                ],
                $submit
            );
        } catch (Throwable $e) {
            error_log('Group Review participation form save failed: ' . $e->getMessage());
            $request->redirect(null, 'groupReview', 'participationForm', null, [
                'sessionId' => $sessionId,
                'page' => $page,
                'saveError' => 1,
            ]);
            return;
        }

        $this->logParticipationEvent($request, $bundle, $submit, (string) $request->getUserVar('submissionComment'));

        if ($submit) {
            $this->notifyParticipationEditors($request, $bundle, (string) $request->getUserVar('submissionComment'));
            $request->redirect(null, 'groupReview', 'participationRecorded', null, ['sessionId' => $sessionId]);
            return;
        }

        $request->redirect(null, 'groupReview', 'participationForm', null, [
            'sessionId' => $sessionId,
            'page' => $page,
        ]);
    }

    public function participationRecorded($args, $request): void
    {
        $contextId = (int) $request->getContext()->getId();
        $sessionId = (int) $request->getUserVar('sessionId');
        $bundle = $this->participationBundle($contextId, $sessionId);
        if (!$bundle) {
            $this->displayMessage($request, 'plugins.generic.groupReview.participation.error.formNotFound');
            return;
        }
        $session = $this->participationSessionData(
            $bundle,
            (new ParticipationService())->getForm($contextId, $sessionId)['form']
        );

        $this->display($request, 'participationRecorded.tpl', [
            'pageTitle' => 'Reviewer Participation Recording',
            'sessionId' => $sessionId,
            'session' => $session,
            'backUrl' => $this->participationListUrl($request, (int) $bundle['poll']['submission_id']),
        ]);
    }

    public function saveParticipation($args, $request): JSONMessage
    {
        if (!$request->isPost() || !$request->checkCSRF()) {
            return $this->participationResponse(
                false,
                'invalid_request',
                __('plugins.generic.groupReview.error.invalidRequest')
            );
        }

        $context = $request->getContext();
        $user = $request->getUser();
        $sessionId = (int) $request->getUserVar('pollId');
        $data = [
            'reviewer_user_id' => $request->getUserVar('reviewerUserId'),
            'attendance' => $request->getUserVar('attendance'),
            'attendance_other' => $request->getUserVar('attendanceOther'),
            'contribution_comments' => $request->getUserVar('contributionComments'),
            'shaping_feedback_types' => $request->getUserVar('shapingFeedbackTypes'),
            'shaping_feedback_comments' => $request->getUserVar('shapingFeedbackComments'),
            'other_contribution' => $request->getUserVar('otherContribution'),
            'status' => $request->getUserVar('status'),
        ];

        try {
            $saved = (new ParticipationService())->saveForSession(
                (int) $context->getId(),
                $sessionId,
                (int) $user->getId(),
                $data
            );
        } catch (InvalidArgumentException $e) {
            return $this->participationResponse(false, 'validation_failed', $e->getMessage());
        } catch (Throwable $e) {
            error_log('Group Review participation save failed: ' . $e->getMessage());
            return $this->participationResponse(
                false,
                'save_failed',
                __('plugins.generic.groupReview.participation.error.saveFailed')
            );
        }

        $response = $this->participationResponse(
            true,
            $saved['created'] ? 'created' : 'updated',
            __('plugins.generic.groupReview.participation.saved')
        );
        $response->setAdditionalAttributes([
            'code' => $saved['created'] ? 'created' : 'updated',
            'participationId' => $saved['participation_id'],
            'recordStatus' => $saved['status'],
        ]);
        return $response;
    }

    public function getParticipation($args, $request): JSONMessage
    {
        if ($request->isPost()) {
            return $this->participationResponse(
                false,
                'invalid_request',
                __('plugins.generic.groupReview.error.invalidRequest')
            );
        }

        $submissionId = $this->positiveParticipationId($request->getUserVar('submissionId'));
        $requestedReviewer = $request->getUserVar('reviewerUserId');
        $reviewerId = $requestedReviewer === null
            ? null
            : $this->positiveParticipationId($requestedReviewer);
        if ($submissionId === null || ($requestedReviewer !== null && $reviewerId === null)) {
            return $this->participationResponse(
                false,
                'invalid_request',
                __('plugins.generic.groupReview.participation.error.invalidFilter')
            );
        }

        try {
            $contextId = (int) $request->getContext()->getId();
            $submission = Repo::submission()->get($submissionId);
            if (!$submission || (int) $submission->getContextId() !== $contextId) {
                return $this->participationResponse(
                    false,
                    'not_found',
                    __('plugins.generic.groupReview.participation.error.notFound')
                );
            }

            $user = $request->getUser();
            $userId = (int) $user->getId();
            $isManager = $user->hasRole([Role::ROLE_ID_MANAGER], $contextId);
            $qualityEditorGroupId = $isManager
                ? null
                : $this->service->getUserGroupIdByAbbreviation($contextId, 'QRE');
            $isQualityEditor = $qualityEditorGroupId !== null
                && DB::table('user_groups')
                    ->where('user_group_id', $qualityEditorGroupId)
                    ->where('role_id', Role::ROLE_ID_SUB_EDITOR)
                    ->exists()
                && DB::table('user_user_groups')
                    ->where('user_group_id', $qualityEditorGroupId)
                    ->where('user_id', $userId)
                    ->exists();
            $canReadAll = $isManager
                || $isQualityEditor
                || $this->service->isLeader($contextId, $submissionId, $userId);
            $participation = new ParticipationService();
            $reviewerId = $participation->readableReviewerId($reviewerId, $userId, $canReadAll);
            $records = $participation->getForSubmission($contextId, $submissionId, $reviewerId);
        } catch (DomainException $e) {
            return $this->participationResponse(
                false,
                'forbidden',
                __('plugins.generic.groupReview.participation.error.forbidden')
            );
        } catch (Throwable $e) {
            error_log('Group Review participation retrieval failed: ' . $e->getMessage());
            return $this->participationResponse(
                false,
                'read_failed',
                __('plugins.generic.groupReview.participation.error.readFailed')
            );
        }

        $response = $this->participationResponse(
            true,
            'ok',
            __('plugins.generic.groupReview.participation.retrieved')
        );
        $response->setAdditionalAttributes(['code' => 'ok', 'records' => $records]);
        return $response;
    }

    private function positiveParticipationId(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }

    /** Return the session bundle when the session has a participation form. */
    private function participationBundle(int $contextId, int $sessionId): ?array
    {
        $bundle = $sessionId ? $this->service->getBundle($contextId, $sessionId) : null;
        if (!$bundle || (int) $bundle['poll']['status'] !== GroupReviewService::STATUS_FINALIZED) {
            return null;
        }

        return $bundle;
    }

    /** Summary strip, list and footer details for a session's participation form. */
    private function participationSessionData(array $bundle, ?array $form): array
    {
        $poll = $bundle['poll'];
        $timezone = (string) $poll['timezone'];
        $meetingLabel = 'Not scheduled';
        foreach ($bundle['slots'] as $slot) {
            if ((int) $slot['slot_id'] === (int) $poll['selected_slot_id']) {
                $meetingLabel = $this->service->formatUtc($slot['start_time_utc'], $timezone, self::PARTICIPATION_DATE_FORMAT);
            }
        }
        $format = fn (?string $value): ?string => $value
            ? $this->service->formatUtc($value, $timezone, self::PARTICIPATION_DATE_FORMAT)
            : null;

        $submission = $bundle['submission'] ?? null;
        $publication = $submission ? $submission->getCurrentPublication() : null;

        return [
            'id' => (int) $poll['session_id'],
            'submissionId' => (int) $poll['submission_id'],
            'submissionTitle' => $submission ? $submission->getLocalizedTitle() : '',
            'firstAuthor' => $publication ? $publication->getShortAuthorString() : '',
            'round' => $this->participationRound($poll),
            'leaderName' => $bundle['leader'] ? $bundle['leader']->getFullName() : '',
            'meetingLabel' => $meetingLabel,
            'reviewerCount' => count($this->participationReviewers($bundle)),
            'status' => $form['status'] ?? ParticipationService::STATUS_DRAFT,
            'lastSaved' => $format($form['updated_at'] ?? null),
            'lastSavedBy' => $this->participationUserName($form['updated_by'] ?? null),
            'submittedAt' => $format($form['submitted_at'] ?? null),
            'submittedBy' => $this->participationUserName($form['submitted_by'] ?? null),
            'submissionComment' => (string) ($form['submission_comment'] ?? ''),
        ];
    }

    /** The review group list, remembering the submission it was opened from. */
    private function participationListUrl($request, int $submissionId): string
    {
        return $request->getRouter()->url($request, null, 'groupReview', 'participation', null, [
            'submissionId' => $submissionId,
        ]);
    }

    /** Back from the review group list to the submission's Group Review tab. */
    private function participationBackUrl($request, int $submissionId): string
    {
        if ($submissionId <= 0) {
            return $request->getRouter()->url($request, null, 'groupReview', 'index');
        }

        return $request->getDispatcher()->url(
            $request,
            Application::ROUTE_PAGE,
            $request->getContext()->getPath(),
            'workflow',
            'index',
            [$submissionId, WORKFLOW_STAGE_ID_EXTERNAL_REVIEW],
            null,
            'groupReview'
        );
    }

    private function participationRound(array $poll): int
    {
        return (int) DB::table('review_rounds')
            ->where('review_round_id', (int) $poll['review_round_id'])
            ->value('round');
    }

    /**
     * Add a participation form entry to the submission's Activity Log: who
     * edited or submitted it and when, plus the submission comment. What
     * changed is not recorded. A failure to log does not undo the save.
     */
    private function logParticipationEvent($request, array $bundle, bool $submit, string $comment): void
    {
        try {
            $round = $this->participationRound($bundle['poll']);
            $comment = trim($comment);
            if ($submit && $comment !== '') {
                $message = __('plugins.generic.groupReview.participation.log.submittedWithComment', [
                    'round' => $round,
                    'comment' => $comment,
                ]);
                $isTranslated = true;
            } else {
                $message = $submit
                    ? 'plugins.generic.groupReview.participation.log.submitted'
                    : 'plugins.generic.groupReview.participation.log.edited';
                $isTranslated = false;
            }

            Repo::eventLog()->add(Repo::eventLog()->newDataObject([
                'assocType' => Application::ASSOC_TYPE_SUBMISSION,
                'assocId' => (int) $bundle['poll']['submission_id'],
                'eventType' => $submit
                    ? ParticipationService::LOG_FORM_SUBMITTED
                    : ParticipationService::LOG_FORM_EDITED,
                'userId' => Validation::loggedInAs() ?? $request->getUser()->getId(),
                'round' => $round,
                'message' => $message,
                'isTranslated' => $isTranslated,
                'dateLogged' => Core::getCurrentDate(),
            ]));
        } catch (Throwable $e) {
            error_log('Group Review participation activity log failed: ' . $e->getMessage());
        }
    }

    /**
     * Notify the submission's assigned editors (OJS notification and email)
     * that the participation form was submitted, including the comment.
     * Failures are logged and do not undo the submission.
     */
    private function notifyParticipationEditors($request, array $bundle, string $comment): void
    {
        $context = $request->getContext();
        $contextId = (int) $context->getId();
        $submissionId = (int) $bundle['poll']['submission_id'];
        $submitter = $request->getUser();
        $comment = trim($comment);
        $formUrl = $request->getDispatcher()->url(
            $request,
            Application::ROUTE_PAGE,
            $context->getPath(),
            'groupReview',
            'participationForm',
            null,
            ['sessionId' => (int) $bundle['poll']['session_id']]
        );
        $notificationManager = new NotificationManager();
        $round = $this->participationRound($bundle['poll']);

        foreach ($this->participationEditors($contextId, $submissionId, (int) $submitter->getId()) as $editor) {
            try {
                $notificationManager->createNotification(
                    $request,
                    (int) $editor->getId(),
                    GroupReviewNotification::NOTIFICATION_TYPE_PARTICIPATION_SUBMITTED,
                    $contextId,
                    Application::ASSOC_TYPE_SUBMISSION,
                    $submissionId,
                    GroupReviewNotification::NOTIFICATION_LEVEL_TASK,
                    ['submitterName' => $submitter->getFullName(), 'round' => $round]
                );
            } catch (Throwable $e) {
                error_log('Group Review participation notification failed: ' . $e->getMessage());
            }

            $mailable = new GroupReviewParticipationSubmitted(
                $context,
                $editor->getFullName(),
                $bundle['submission'] ? $bundle['submission']->getLocalizedTitle() : '',
                $round,
                $submitter->getFullName(),
                $comment !== '' ? $comment : __('plugins.generic.groupReview.participation.noComment'),
                $formUrl
            );
            $this->sendMailable($context, $editor, $mailable, GroupReviewParticipationSubmitted::getEmailTemplateKey());
        }
    }

    /**
     * Journal editors (manager-role user groups) assigned to the submission,
     * other than the user who submitted the form.
     */
    private function participationEditors(int $contextId, int $submissionId, int $submitterId): array
    {
        $userIds = DB::table('stage_assignments as sa')
            ->join('user_groups as ug', 'ug.user_group_id', '=', 'sa.user_group_id')
            ->where('sa.submission_id', $submissionId)
            ->where('ug.context_id', $contextId)
            ->where('ug.role_id', Role::ROLE_ID_MANAGER)
            ->where('sa.user_id', '!=', $submitterId)
            ->distinct()
            ->pluck('sa.user_id');

        $editors = [];
        foreach ($userIds as $userId) {
            $user = Repo::user()->get((int) $userId);
            if ($user && !$user->getDisabled()) {
                $editors[] = $user;
            }
        }

        return $editors;
    }

    /** @return array<int, array{id:int, name:string}> Selected members, by name. */
    private function participationReviewers(array $bundle): array
    {
        $reviewers = [];
        foreach ($bundle['members'] as $member) {
            if ((bool) $member['selected']) {
                $reviewers[] = ['id' => (int) $member['user_id'], 'name' => $member['name']];
            }
        }

        return $reviewers;
    }

    private function participationUserName(mixed $userId): string
    {
        $user = $userId ? Repo::user()->get((int) $userId) : null;
        return $user ? $user->getFullName() : '';
    }

    private function participationAttendanceOptions(): array
    {
        return ParticipationService::ATTENDANCE_OPTIONS;
    }

    private function participationContributionOptions(): array
    {
        return ParticipationService::CONTRIBUTION_OPTIONS;
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

    private function participationResponse(bool $success, string $code, string $message): JSONMessage
    {
        $response = new JSONMessage($success, $message);
        $response->setAdditionalAttributes(['code' => $code]);
        return $response;
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

    /** @param int[] $availableYears */
    private function monitoringYear($request, array $availableYears): ?int
    {
        $year = $request->getUserVar('year');
        $currentYear = (int) gmdate('Y');
        if ($year === null) {
            return $currentYear;
        }
        if ($year === 'all') {
            return null;
        }

        if ((!is_string($year) && !is_int($year))
            || !preg_match('/^\d{4}$/', (string) $year)) {
            return $currentYear;
        }

        $year = (int) $year;

        return in_array($year, array_merge($availableYears, [$currentYear]), true)
            ? $year
            : $currentYear;
    }

    private function monitoringReviewerId($request): int
    {
        $reviewerId = $request->getUserVar('reviewerId');
        if (!is_string($reviewerId) && !is_int($reviewerId)) {
            return 0;
        }

        $reviewerId = filter_var($reviewerId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $reviewerId === false ? 0 : (int) $reviewerId;
    }

    /**
     * @param int[] $availableYears
     *
     * @return array<int, array{value:int|string, label:string}>
     */
    private function monitoringYearOptions(array $availableYears): array
    {
        $years = array_values(array_unique(array_merge($availableYears, [(int) gmdate('Y')])));
        rsort($years);

        return array_merge(
            [[
                'value' => 'all',
                'label' => __('plugins.generic.groupReview.monitoring.year.allTime'),
            ]],
            array_map(fn (int $year): array => ['value' => $year, 'label' => (string) $year], $years)
        );
    }

    /** @return array{string, string} */
    private function monitoringSortAndDir($request): array
    {
        $requestedSort = $request->getUserVar('sort');
        $requestedDir = $request->getUserVar('dir');
        $sort = $this->scalarString($requestedSort);
        $dir = $this->scalarString($requestedDir);
        if (($requestedSort !== null && ($sort === '' || !in_array($sort, self::MONITORING_SORT_COLUMNS, true)))
            || ($requestedDir !== null && ($dir === '' || !in_array($dir, ['asc', 'desc'], true)))) {
            return ['completed', 'desc'];
        }

        return [
            $sort !== '' ? $sort : 'completed',
            $dir !== '' ? $dir : 'desc',
        ];
    }

    private function monitoringSortUrls($request, ?int $year, string $sort, string $dir): array
    {
        $urls = [];
        foreach (self::MONITORING_SORT_COLUMNS as $column) {
            $nextDir = $sort === $column
                ? ($dir === 'asc' ? 'desc' : 'asc')
                : ($column === 'name' ? 'asc' : 'desc');
            $urls[$column] = $request->getRouter()->url(
                $request,
                null,
                'groupReview',
                'reviewers',
                null,
                ['year' => $year ?? 'all', 'sort' => $column, 'dir' => $nextDir]
            );
        }

        return $urls;
    }

    private function sortReviewerRows(array $reviewers, string $sort, string $dir): array
    {
        $factor = $dir === 'desc' ? -1 : 1;
        usort($reviewers, function (array $a, array $b) use ($sort, $factor): int {
            $result = is_string($a[$sort]) ? strcasecmp($a[$sort], $b[$sort]) : $a[$sort] <=> $b[$sort];

            return $result * $factor;
        });

        return $reviewers;
    }

    private function reviewerTableRow(array $row, array $labels, $request, ?int $year): array
    {
        return [
            'userId' => $row['userId'],
            'name' => $row['name'],
            'labelsText' => $this->reviewerLabelsText($labels),
            'completed' => $row['completed'],
            'current' => $row['current'],
            'attended' => $row['attended'],
            'attendedPercent' => $this->monitoringPercent($row['attended'], $row['attendanceRecorded']),
            'invited' => $row['invited'],
            'available' => $row['available'],
            'availablePercent' => $this->monitoringPercent($row['available'], $row['invited']),
            'selected' => $row['selected'],
            'selectedPercent' => $this->monitoringPercent($row['selected'], $row['available']),
            'url' => $request->getRouter()->url(
                $request,
                null,
                'groupReview',
                'reviewer',
                null,
                ['reviewerId' => $row['userId'], 'year' => $year ?? 'all']
            ),
        ];
    }

    private function reviewerLabelsText(array $labels): string
    {
        $service = new ReviewerLabelService();
        $names = [];
        foreach (ReviewerLabelService::TYPES as $type => $definition) {
            foreach ($labels[$type] ?? [] as $value) {
                $names[] = $service->name($type, $value);
            }
        }

        return implode(', ', $names);
    }

    private function reviewerLabelsList(array $labels): array
    {
        $service = new ReviewerLabelService();
        $list = [];
        foreach (ReviewerLabelService::TYPES as $type => $definition) {
            $values = $labels[$type] ?? [];
            $list[] = [
                'name' => $service->name($type),
                'valuesText' => $values
                    ? implode(', ', array_map(fn ($value) => $service->name($type, $value), $values))
                    : __('plugins.generic.groupReview.labels.notSet'),
            ];
        }

        return $list;
    }

    private function reviewerLabelOptions(): array
    {
        $service = new ReviewerLabelService();
        $options = [];
        foreach (ReviewerLabelService::TYPES as $type => $definition) {
            $options[$type] = [
                'name' => $service->name($type),
                'multiple' => $definition['multiple'],
                'options' => array_map(
                    fn ($value) => ['value' => $value, 'label' => $service->name($type, $value)],
                    array_keys($definition['values'])
                ),
            ];
        }

        return $options;
    }

    private function monitoringCounts(array $counts, array $optionLabels): array
    {
        $list = [];
        foreach ($optionLabels as $value => $label) {
            if (!empty($counts[$value])) {
                $list[] = ['label' => $label, 'count' => $counts[$value]];
            }
        }

        return $list;
    }

    private function reviewerHistory(array $entries, $request): array
    {
        $history = [];
        foreach ($entries as $entry) {
            $answers = $this->historyAnswers($entry['participation']);
            $sections = [];
            foreach ($answers as $answer) {
                $sections[$answer['section']][] = [
                    'label' => $answer['label'],
                    'value' => $answer['value'],
                ];
            }
            $history[] = [
                'submissionId' => $entry['submissionId'],
                'submissionUrl' => $request->getRouter()->url($request, null, 'workflow', 'access', $entry['submissionId']),
                'round' => $entry['round'],
                'date' => $this->service->formatUtc($entry['date'], $entry['timezone'], self::PARTICIPATION_DATE_FORMAT),
                'leaderName' => $entry['leaderName'],
                'isLeader' => $entry['isLeader'],
                'answers' => $answers,
                'sections' => array_map(
                    fn (string $section, array $rows): array => ['section' => $section, 'rows' => $rows],
                    array_keys($sections),
                    array_values($sections)
                ),
                'generalComments' => $entry['generalComments'],
            ];
        }

        return $history;
    }

    /** @return array<int, array{section:string, label:string, value:string}> */
    private function historyAnswers(?array $record): array
    {
        if (!$record) {
            return [];
        }

        $answers = [];
        $add = function (string $section, string $label, ?string $value) use (&$answers) {
            if ($value === null || $value === '') {
                return;
            }
            $answers[] = ['section' => $section, 'label' => $label, 'value' => $value];
        };

        if ($record['attendance'] !== ParticipationService::ATTENDANCE_NOT_RECORDED) {
            $value = ParticipationService::ATTENDANCE_OPTIONS[$record['attendance']] ?? $record['attendance'];
            if ($record['attendance'] === ParticipationService::ATTENDANCE_OTHER && $record['attendanceOther']) {
                $value .= ': ' . $record['attendanceOther'];
            }
            $add('Review meeting', 'Meeting attendance', $value);
        }
        $add('Review meeting', 'Comments on contributions', $record['contributionComments']);

        if ($record['shapingFeedbackTypes']) {
            $labels = array_map(fn ($value) => ParticipationService::CONTRIBUTION_OPTIONS[$value] ?? $value, $record['shapingFeedbackTypes']);
            $add('Feedback response', 'Contributions', implode(', ', $labels));
        }
        $add('Feedback response', 'Comments on contributions', $record['shapingFeedbackComments']);
        $add('Other', 'Other comments', $record['otherContribution']);

        return $answers;
    }

    private function monitoringPercent(int $numerator, int $denominator): ?int
    {
        return $denominator > 0 ? (int) round($numerator / $denominator * 100) : null;
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
