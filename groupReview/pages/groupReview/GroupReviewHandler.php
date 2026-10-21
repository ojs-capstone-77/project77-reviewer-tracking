<?php

/**
 * @file pages/groupReview/GroupReviewHandler.php
 */

namespace APP\plugins\generic\groupReview\pages\groupReview;

use APP\core\Application;
use APP\facades\Repo;
use APP\file\PublicFileManager;
use APP\handler\Handler;
use APP\notification\NotificationManager;
use APP\plugins\generic\groupReview\classes\forms\ReferenceTemplateForm;
use APP\plugins\generic\groupReview\classes\GroupReviewService;
use APP\plugins\generic\groupReview\classes\mail\GroupReviewParticipationSubmitted;
use APP\plugins\generic\groupReview\classes\mail\GroupReviewPollCreated;
use APP\plugins\generic\groupReview\classes\mail\GroupReviewPollThankInvitees;
use APP\plugins\generic\groupReview\classes\mail\GroupReviewPollThankRgms;
use APP\plugins\generic\groupReview\classes\notification\Notification as GroupReviewNotification;
use APP\plugins\generic\groupReview\classes\ParticipationConfiguration;
use APP\plugins\generic\groupReview\classes\ParticipationService;
use APP\plugins\generic\groupReview\classes\ReviewerGridService;
use APP\plugins\generic\groupReview\classes\ReviewerLabelService;
use APP\plugins\generic\groupReview\classes\ReviewerStatsService;
use APP\plugins\generic\groupReview\classes\security\authorization\EditorRequiredPolicy;
use APP\plugins\generic\groupReview\classes\security\authorization\InviteeRequiredPolicy;
use APP\plugins\generic\groupReview\classes\security\authorization\LeaderRequiredPolicy;
use APP\plugins\generic\groupReview\classes\security\authorization\ParticipationAccessPolicy;
use APP\plugins\generic\groupReview\GroupReviewPlugin;
use APP\template\TemplateManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use PKP\core\Core;
use PKP\core\PKPString;
use PKP\db\DAORegistry;
use PKP\notification\PKPNotification;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\security\authorization\ContextRequiredPolicy;
use PKP\security\authorization\UserRequiredPolicy;
use PKP\security\Role;
use PKP\security\Validation;
use PKP\submission\reviewRound\ReviewRound;
use PKP\submission\reviewRound\ReviewRoundDAO;
use Throwable;

class GroupReviewHandler extends Handler
{
    public $_isBackendPage = true;

    private GroupReviewPlugin $plugin;
    private GroupReviewService $service;
    private ?string $participationValidationError = null;

    private const LEADER_OPERATIONS = [
        'create', 'store', 'invite', 'storeInvitations', 'view', 'edit', 'update',
        'selectMeetingMembers', 'reviewMessages', 'finalize', 'cancel', 'resend',
    ];
    private const INVITEE_OPERATIONS = ['availability', 'saveAvailability'];
    private const PARTICIPATION_OPERATIONS = [
        'participation', 'participationForm', 'saveParticipationForm', 'participationRecorded',
        'storeParticipationForm', 'cancelParticipationForm',
    ];
    private const PARTICIPATION_PAGE_SIZE = 2;
    private const PARTICIPATION_DATE_FORMAT = 'j F Y, H:i';
    private const MONITORING_DATE_FORMAT = 'j F Y';
    private const REVIEWERS_OPERATIONS = ['reviewers'];
    private const MONITORING_OPERATIONS = ['overview', 'reviewer', 'saveLabels', 'reference', 'generateReferencePdf', 'saveParticipationSettings', 'addLabelType', 'renameLabelType', 'removeLabelType', 'addLabelValue', 'removeLabelValue'];
    private const MONITORING_SORT_COLUMNS = ['name', 'invited', 'available', 'availablePercent', 'selected', 'selectedPercent', 'completed', 'current', 'attended', 'attendedPercent'];

    public function __construct(GroupReviewPlugin $plugin)
    {
        parent::__construct();
        $this->plugin = $plugin;
        $this->service = new GroupReviewService();

        $this->addRoleAssignment(
            [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR],
            ['index', ...self::PARTICIPATION_OPERATIONS]
        );
        $this->addRoleAssignment(
            [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR],
            self::LEADER_OPERATIONS
        );
        $this->addRoleAssignment(Role::ROLE_ID_SUB_EDITOR, self::INVITEE_OPERATIONS);
        $this->addRoleAssignment(Role::ROLE_ID_MANAGER, self::MONITORING_OPERATIONS);
        $this->addRoleAssignment([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR], self::REVIEWERS_OPERATIONS);
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
        } elseif (in_array($operation, self::REVIEWERS_OPERATIONS, true)) {
            $this->addPolicy(new EditorRequiredPolicy($request, true));
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
        $grid = new ReviewerGridService();
        $userId = (int) $request->getUser()->getId();
        $isEditor = $this->service->isJournalEditorUser($contextId, $userId);
        if (!$isEditor) {
            $ledAvailableIds = $grid->availableReviewerIds($contextId, null, $userId);
            $rows = array_values(array_filter($rows, fn (array $row): bool => in_array($row['userId'], $ledAvailableIds, true)));
        }
        $filters = $grid->settings($request);
        $filterParams = array_merge($grid->parameters($filters), ['sort' => $sort, 'dir' => $dir]);

        $reviewers = [];
        foreach ($rows as $row) {
            $reviewers[] = ($isEditor ? [] : ['url' => null]) + $this->reviewerTableRow($row, $labels[$row['userId']] ?? [], $request, $year, $filterParams);
        }
        $totalReviewers = count($reviewers);
        $availableIds = $filters['availableSubmissionId'] === '' ? null
            : $grid->availableReviewerIds($contextId, $filters['availableSubmissionId']);
        $maxima = [];
        foreach (array_keys(ReviewerGridService::COLUMNS) as $column) {
            $maxima[$column] = str_ends_with($column, 'Percent') ? 100 : max(1, (int) max([0, ...array_column($reviewers, $column)]));
        }
        $reviewers = $grid->filter($reviewers, $labels, $filters, $availableIds);
        $reviewers = $this->sortReviewerRows($reviewers, $sort, $dir);

        $activeRanges = [];
        foreach ($filters['ranges'] as $column => $range) {
            if ($range['min'] !== '' || $range['max'] !== '') {
                $activeRanges[$column] = [
                    min($range['min'] === '' ? 0 : $range['min'], $maxima[$column]),
                    min($range['max'] === '' ? $maxima[$column] : $range['max'], $maxima[$column]),
                ];
            }
        }
        $hasFilters = $activeRanges || array_filter($filters['labels']) || $filters['availableSubmissionId'] !== '';
        TemplateManager::getManager($request)->setState([
            'grpFilters' => (object) $activeRanges,
            'grpLabels' => $filters['labels'],
            'grpColumns' => $filters['columns'],
            'grpShowFilters' => $hasFilters || $request->getUserVar('showFilters'),
        ]);
        $columns = [];
        foreach (ReviewerGridService::COLUMNS as $column => $label) {
            $columns[] = [
                'key' => $column,
                'label' => __($label),
                'isPercent' => str_ends_with($column, 'Percent'),
                'max' => $maxima[$column],
                'value' => $activeRanges[$column] ?? [0, $maxima[$column]],
            ];
        }

        $this->display($request, 'reviewers.tpl', [
            'pageTitle' => __('plugins.generic.groupReview.monitoring.reviewers.title'),
            'monitoringTabsResource' => $this->plugin->getTemplateResource('monitoringTabs.tpl'),
            'year' => $year ?? 'all',
            'yearOptions' => $this->monitoringYearOptions($years),
            'overviewUrl' => $request->getRouter()->url($request, null, 'groupReview', 'overview', null, ReviewerGridService::urlParameters(array_merge($filterParams, ['year' => $year ?? 'all']))),
            'reviewersUrl' => $request->getRouter()->url($request, null, 'groupReview', 'reviewers', null, ReviewerGridService::urlParameters(array_merge($filterParams, ['year' => $year ?? 'all']))),
            'reviewersActionUrl' => $request->getRouter()->url($request, null, 'groupReview', 'reviewers'),
            'sort' => $sort,
            'dir' => $dir,
            'sortOrders' => $this->monitoringSortOrders($sort, $dir),
            'reviewers' => $reviewers,
            'gridFilters' => $filters,
            'columns' => $columns,
            'labelFilters' => $this->reviewerLabelOptions($contextId),
            'totalReviewers' => $totalReviewers,
            'gridFilterParams' => $filterParams,
            'isEditor' => $isEditor,
        ]);
    }

    public function overview($args, $request): void
    {
        $contextId = (int) $request->getContext()->getId();
        $stats = new ReviewerStatsService();
        $years = $stats->getYears($contextId);
        $year = $this->monitoringYear($request, $years);
        $overview = $stats->getOverview($contextId, $year);
        $filterParams = $this->monitoringGridParams($request);
        $labelService = new ReviewerLabelService();
        $labels = [];

        foreach ($labelService->getTypes($contextId) as $type => $definition) {
            $counts = $overview['labels'][$type];
            $values = [];
            foreach (array_keys($definition['values']) as $value) {
                $values[] = [
                    'label' => $labelService->name($contextId, $type, $value),
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
                'name' => $labelService->name($contextId, $type),
                'values' => $values,
            ];
        }

        $this->display($request, 'overview.tpl', [
            'pageTitle' => __('plugins.generic.groupReview.monitoring.dashboardTitle'),
            'monitoringTabsResource' => $this->plugin->getTemplateResource('monitoringTabs.tpl'),
            'year' => $year ?? 'all',
            'yearOptions' => $this->monitoringYearOptions($years),
            'overviewUrl' => $request->getRouter()->url($request, null, 'groupReview', 'overview', null, ReviewerGridService::urlParameters(array_merge($filterParams, ['year' => $year ?? 'all']))),
            'reviewersUrl' => $request->getRouter()->url($request, null, 'groupReview', 'reviewers', null, ReviewerGridService::urlParameters(array_merge($filterParams, ['year' => $year ?? 'all']))),
            'overviewActionUrl' => $request->getRouter()->url($request, null, 'groupReview', 'overview'),
            'gridFilterParams' => $filterParams,
            'gridStateResource' => $this->plugin->getTemplateResource('reviewerGridState.tpl'),
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
            ?? array_fill_keys(array_keys($labelService->getTypes($contextId)), []);
        $reviewerStats = array_merge(
            $row,
            $this->reviewerTableRow($row, $labels, $request, $year)
        );
        $filterParams = $this->monitoringGridParams($request);

        $this->display($request, 'reviewer.tpl', [
            'pageTitle' => $row['name'],
            'monitoringTabsResource' => $this->plugin->getTemplateResource('monitoringTabs.tpl'),
            'year' => $year ?? 'all',
            'yearOptions' => $this->monitoringYearOptions($years),
            'overviewUrl' => $request->getRouter()->url($request, null, 'groupReview', 'overview', null, ReviewerGridService::urlParameters(array_merge($filterParams, ['year' => $year ?? 'all']))),
            'reviewersUrl' => $request->getRouter()->url($request, null, 'groupReview', 'reviewers', null, ReviewerGridService::urlParameters(array_merge($filterParams, ['year' => $year ?? 'all']))),
            'reviewerUrl' => $request->getRouter()->url($request, null, 'groupReview', 'reviewer'),
            'reviewer' => [
                'userId' => $row['userId'],
                'name' => $row['name'],
                'reviewerSince' => $row['reviewerSince'] === null ? null : $this->service->formatUtc($row['reviewerSince'], 'UTC', self::MONITORING_DATE_FORMAT),
                'lastActivity' => $row['lastActivity'] === null ? null : $this->service->formatUtc($row['lastActivity'], 'UTC', self::MONITORING_DATE_FORMAT),
                'labels' => $this->reviewerLabelsList($contextId, $labels),
            ],
            'stats' => $reviewerStats,
            'attendanceCounts' => $this->monitoringCounts($stats->getAttendanceCounts($contextId, $reviewerId, $year), array_diff_key(ParticipationService::ATTENDANCE_OPTIONS, [ParticipationService::ATTENDANCE_NOT_RECORDED => true])),
            'contributionCounts' => $this->monitoringCounts($stats->getContributionCounts($contextId, $reviewerId, $year), $stats->getContributionLabels($contextId, $reviewerId, $year)),
            'history' => $this->reviewerHistory($stats->getHistory($contextId, $reviewerId, $year), $request),
            'labelOptions' => $this->reviewerLabelOptions($contextId, $reviewerId),
            'labelValues' => $labels,
            'labelHistory' => $labelService->getHistory($contextId, $reviewerId),
            'labelSaveError' => (bool) $request->getUserVar('labelSaveError'),
            'saveLabelsUrl' => $request->getRouter()->url($request, null, 'groupReview', 'saveLabels'),
            'referenceUrl' => $request->getRouter()->url($request, null, 'groupReview', 'reference', null, ['reviewerId' => $row['userId'], 'year' => $year ?? 'all']),
            'gridFilterParams' => $filterParams,
            'gridStateResource' => $this->plugin->getTemplateResource('reviewerGridState.tpl'),
            'backUrl' => $request->getRouter()->url($request, null, 'groupReview', 'reviewers', null, ReviewerGridService::urlParameters(array_merge(
                $filterParams,
                ['year' => $year ?? 'all']
            ))),
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
        $filterParams = $this->monitoringGridParams($request);

        try {
            if ($reviewerId < 1 || !$stats->getReviewerRow($contextId, $reviewerId, null)) {
                $this->displayMessage($request, 'plugins.generic.groupReview.error.reviewerNotFound');
                return;
            }

            $values = [];
            $types = (new ReviewerLabelService())->getTypes($contextId);
            foreach ($types as $type => $definition) {
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
            $request->redirect(null, 'groupReview', 'reviewer', null, ReviewerGridService::urlParameters(array_merge($filterParams, [
                'reviewerId' => $reviewerId,
                'year' => $year ?? 'all',
                'labelSaveError' => 1,
            ])));
            return;
        }

        $request->redirect(null, 'groupReview', 'reviewer', null, ReviewerGridService::urlParameters(array_merge($filterParams, [
            'reviewerId' => $reviewerId,
            'year' => $year ?? 'all',
        ])));
    }

    private function redirectToReviewerLabelsTab($request, bool $error = false): void
    {
        $request->redirect(
            null,
            'management',
            'settings',
            'workflow',
            $error ? ['labelsError' => 1] : null,
            'review/reviewerLabels'
        );
    }

    public function addLabelType($args, $request): void
    {
        if (!$request->isPost() || !$request->checkCSRF()) {
            $this->displayMessage($request, 'plugins.generic.groupReview.error.invalidRequest');
            return;
        }

        $contextId = (int) $request->getContext()->getId();
        try {
            $name = (string) $request->getUserVar('name');
            $multiple = (string) $request->getUserVar('multiple') === '1';
            (new ReviewerLabelService())->addType($contextId, $name, $multiple);
        } catch (Throwable $e) {
            error_log('Group Review add label type failed: ' . $e->getMessage());
            $this->redirectToReviewerLabelsTab($request, true);
            return;
        }

        $this->redirectToReviewerLabelsTab($request);
    }

    public function renameLabelType($args, $request): void
    {
        if (!$request->isPost() || !$request->checkCSRF()) {
            $this->displayMessage($request, 'plugins.generic.groupReview.error.invalidRequest');
            return;
        }

        $contextId = (int) $request->getContext()->getId();
        try {
            $typeKey = (string) $request->getUserVar('typeKey');
            $name = (string) $request->getUserVar('name');
            $values = $request->getUserVar('values');
            DB::transaction(function () use ($contextId, $typeKey, $name, $values): void {
                $labelService = new ReviewerLabelService();
                $labelService->renameType($contextId, $typeKey, $name);
                foreach (is_array($values) ? $values : [] as $valueKey => $label) {
                    $labelService->renameValue($contextId, $typeKey, (string) $valueKey, (string) $label);
                }
            });
        } catch (Throwable $e) {
            error_log('Group Review rename label type failed: ' . $e->getMessage());
            $this->redirectToReviewerLabelsTab($request, true);
            return;
        }

        $this->redirectToReviewerLabelsTab($request);
    }

    public function removeLabelType($args, $request): void
    {
        if (!$request->isPost() || !$request->checkCSRF()) {
            $this->displayMessage($request, 'plugins.generic.groupReview.error.invalidRequest');
            return;
        }

        $contextId = (int) $request->getContext()->getId();
        try {
            $typeKey = (string) $request->getUserVar('typeKey');
            (new ReviewerLabelService())->removeType($contextId, $typeKey);
        } catch (Throwable $e) {
            error_log('Group Review remove label type failed: ' . $e->getMessage());
            $this->redirectToReviewerLabelsTab($request, true);
            return;
        }

        $this->redirectToReviewerLabelsTab($request);
    }

    public function addLabelValue($args, $request): void
    {
        if (!$request->isPost() || !$request->checkCSRF()) {
            $this->displayMessage($request, 'plugins.generic.groupReview.error.invalidRequest');
            return;
        }

        $contextId = (int) $request->getContext()->getId();
        try {
            $typeKey = (string) $request->getUserVar('typeKey');
            $label = (string) $request->getUserVar('label');
            (new ReviewerLabelService())->addValue($contextId, $typeKey, $label);
        } catch (Throwable $e) {
            error_log('Group Review add label value failed: ' . $e->getMessage());
            $this->redirectToReviewerLabelsTab($request, true);
            return;
        }

        $this->redirectToReviewerLabelsTab($request);
    }

    public function removeLabelValue($args, $request): void
    {
        if (!$request->isPost() || !$request->checkCSRF()) {
            $this->displayMessage($request, 'plugins.generic.groupReview.error.invalidRequest');
            return;
        }

        $contextId = (int) $request->getContext()->getId();
        try {
            $typeKey = (string) $request->getUserVar('typeKey');
            $valueKey = (string) $request->getUserVar('valueKey');
            (new ReviewerLabelService())->removeValue($contextId, $typeKey, $valueKey);
        } catch (Throwable $e) {
            error_log('Group Review remove label value failed: ' . $e->getMessage());
            $this->redirectToReviewerLabelsTab($request, true);
            return;
        }

        $this->redirectToReviewerLabelsTab($request);
    }

    public function reference($args, $request): void
    {
        $context = $request->getContext();
        $contextId = (int) $context->getId();
        $reviewerId = $this->monitoringReviewerId($request);
        $stats = new ReviewerStatsService();
        $years = $stats->getYears($contextId);
        $year = $this->monitoringYear($request, $years);
        $row = $stats->getReviewerRow($contextId, $reviewerId, $year);
        if (!$row) {
            $this->displayMessage($request, 'plugins.generic.groupReview.error.reviewerNotFound');
            return;
        }

        $template = (string) ($context->getData('groupReviewReferenceTemplate') ?: ReferenceTemplateForm::defaultTemplate());
        $letter = $this->renderReferenceLetter($template, [
            'reviewerName' => $row['name'],
            'journalName' => $context->getLocalizedData('name'),
            'letterDate' => date('l jS F Y'),
            'reviewerSince' => $row['reviewerSince'] === null ? '' : $this->service->formatUtc($row['reviewerSince'], 'UTC', 'Y'),
            'year' => (string) ($year ?? date('Y')),
            'completedCount' => (string) $row['completed'],
            'submissionsReviewedCount' => (string) $row['submissionsReviewed'],
            'ledCount' => (string) $row['timesLed'],
            'leaderCountForYear' => (string) $stats->getLeaderCountForYear($contextId, $year),
            'contributionsList' => $this->referenceContributionsList($stats->getContributionCounts($contextId, $reviewerId, $year), $stats->getContributionLabels($contextId, $reviewerId, $year)),
        ]);

        $this->display($request, 'reference.tpl', [
            'pageTitle' => $row['name'],
            'reviewer' => [
                'userId' => $row['userId'],
                'name' => $row['name'],
            ],
            'year' => $year ?? 'all',
            'yearOptions' => $this->monitoringYearOptions($years),
            'referenceUrl' => $request->getRouter()->url($request, null, 'groupReview', 'reference'),
            'letterContent' => $letter,
            'fileName' => $this->referenceFileName($context, $row['name'], $year ?? (int) date('Y')),
            'generatePdfUrl' => $request->getRouter()->url($request, null, 'groupReview', 'generateReferencePdf'),
            'backUrl' => $request->getRouter()->url($request, null, 'groupReview', 'reviewer', null, [
                'reviewerId' => $row['userId'],
                'year' => $year ?? 'all',
            ]),
        ]);
    }

    public function generateReferencePdf($args, $request): void
    {
        if (!$request->isPost() || !$request->checkCSRF()) {
            $this->displayMessage($request, 'plugins.generic.groupReview.error.invalidRequest');
            return;
        }

        $contextId = (int) $request->getContext()->getId();
        $reviewerId = $this->monitoringReviewerId($request);
        $stats = new ReviewerStatsService();
        $row = $stats->getReviewerRow($contextId, $reviewerId, null);
        if ($reviewerId < 1 || !$row) {
            $this->displayMessage($request, 'plugins.generic.groupReview.error.reviewerNotFound');
            return;
        }

        $autoloadPath = $this->plugin->getPluginPath() . '/vendor/autoload.php';
        if (!file_exists($autoloadPath)) {
            $this->displayMessage($request, 'plugins.generic.groupReview.error.referencePdfUnavailable');
            return;
        }
        require_once $autoloadPath;

        $context = $request->getContext();
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'letterHtml' => PKPString::stripUnsafeHtml((string) $request->getUserVar('letterContent')),
            'letterhead' => $this->referenceLetterhead($context),
        ]);
        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $dompdf->loadHtml($templateMgr->fetch($this->plugin->getTemplateResource('referenceLetterPdf.tpl')));
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $output = $dompdf->output();

        $filename = $this->cleanFileName((string) $request->getUserVar('fileName'))
            ?: $this->referenceFileName($context, $row['name'], (int) ($request->getUserVar('year') ?: date('Y')));
        $filename = preg_match('/\.pdf$/i', $filename) ? $filename : $filename . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Length: ' . strlen($output));
        header('Content-Disposition: ' . ($request->getUserVar('preview') ? 'inline' : 'attachment') . ';filename="' . addcslashes($filename, '"\\') . '";filename*=UTF-8\'\'' . rawurlencode($filename));
        echo $output;
    }

    private function referenceFileName($context, string $reviewerName, int $year): string
    {
        return $this->cleanFileName(__('plugins.generic.groupReview.reference.fileName', [
            'journalAcronym' => (string) $context->getLocalizedAcronym(),
            'reviewerName' => $reviewerName,
            'year' => $year,
        ]));
    }

    private function cleanFileName(string $fileName): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/', '', $fileName), ' .');
    }

    private function referenceLetterhead($context): ?array
    {
        $image = $context->getData('groupReviewLetterhead');
        $path = empty($image['uploadName']) ? null
            : Core::getBaseDir() . '/' . (new PublicFileManager())->getContextFilesPath($context->getId()) . '/' . basename($image['uploadName']);
        if (!$path || !is_readable($path)) {
            return null;
        }

        return [
            'src' => 'data:' . mime_content_type($path) . ';base64,' . base64_encode((string) file_get_contents($path)),
            'alt' => (string) ($image['altText'] ?? ''),
        ];
    }

    private function renderReferenceLetter(string $template, array $values): string
    {
        $template = preg_replace('/<p>\s*\{\$contributionsList\}\s*<\/p>/', '{$contributionsList}', $template);

        $tokens = [];
        foreach ($values as $key => $value) {
            $tokens['{$' . $key . '}'] = $value;
        }

        return strtr($template, $tokens);
    }

    private function referenceContributionsList(array $counts, array $labels): string
    {
        $items = array_filter($this->monitoringCounts($counts, $labels), fn (array $item): bool => $item['count'] > 0);
        if (!$items) {
            return '<p>' . htmlspecialchars(__('plugins.generic.groupReview.monitoring.history.noAnswers'), ENT_QUOTES) . '</p>';
        }

        $html = '<ul>';
        foreach ($items as $item) {
            $html .= '<li>' . htmlspecialchars($item['label'], ENT_QUOTES) . '</li>';
        }
        $html .= '</ul>';

        return $html;
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
        foreach ($participation->getFormIds($contextId, $submissionId, $leaderUserId) as $formId) {
            $session = $this->participationSessionData($this->participationBundle($contextId, $formId));
            $session['openUrl'] = $request->getRouter()->url(
                $request,
                null,
                'groupReview',
                'participationForm',
                null,
                ['formId' => $formId, 'page' => 0]
            );
            $sessions[] = $session;
        }
        $reviewRound = $this->currentReviewRound($submissionId);
        $canCreate = $reviewRound
            && $this->service->isAssignedLeader($contextId, $submissionId, $userId)
            && !$participation->getActiveFormId($contextId, $submissionId, (int) $reviewRound->getId());

        $this->display($request, 'participation.tpl', [
            'pageTitle' => 'Reviewer Participation Recording',
            'submissionTitle' => $submission ? $submission->getLocalizedTitle() : '',
            'firstAuthor' => $publication ? $publication->getShortAuthorString() : '',
            'sessions' => $sessions,
            'createUrl' => $canCreate ? $request->getRouter()->url($request, null, 'groupReview', 'storeParticipationForm') : null,
            'submissionId' => $submissionId,
            'hasReviewers' => $canCreate && $this->participationReviewerIds($contextId, $submissionId),
            'backUrl' => $this->participationBackUrl($request, $submissionId),
        ]);
    }

    public function saveParticipationSettings($args, $request): void
    {
        if (!$request->isPost() || !$request->checkCSRF()) {
            $this->displayMessage($request, 'plugins.generic.groupReview.error.invalidRequest');
            return;
        }
        $service = new ParticipationConfiguration();
        $contextId = (int) $request->getContext()->getId();
        $notificationManager = new NotificationManager();
        try {
            $service->save($contextId, $request->getUserVar('requiredFields') ?? [], $request->getUserVar('options'), $request->getUserVar('text'));
            $notificationManager->createTrivialNotification((int) $request->getUser()->getId(), PKPNotification::NOTIFICATION_TYPE_SUCCESS, ['contents' => __('common.changesSaved')]);
        } catch (InvalidArgumentException $error) {
            $notificationManager->createTrivialNotification((int) $request->getUser()->getId(), PKPNotification::NOTIFICATION_TYPE_ERROR, ['contents' => $error->getMessage()]);
        }
        $request->redirect(null, 'management', 'settings', 'workflow', null, 'review/groupReviewParticipation');
    }

    public function participationForm($args, $request): void
    {
        $contextId = (int) $request->getContext()->getId();
        $formId = (int) $request->getUserVar('formId');
        $page = max(0, (int) $request->getUserVar('page'));

        $bundle = $this->participationBundle($contextId, $formId);
        if (!$bundle) {
            $this->displayMessage($request, 'plugins.generic.groupReview.participation.error.formNotFound');
            return;
        }

        $saved = (new ParticipationService())->getForm($contextId, $formId);
        $configuration = (new ParticipationConfiguration())->forForm($contextId, $formId);
        $session = $this->participationSessionData($bundle);
        $reviewers = $this->participationReviewers($bundle);
        $pageSize = self::PARTICIPATION_PAGE_SIZE;
        $pageCount = max(1, (int) ceil(count($reviewers) / $pageSize));
        $page = min($page, $pageCount - 1);

        // Every reviewer is in the form; paging only shows and hides columns.
        $formReviewers = [];
        foreach ($reviewers as $index => $reviewer) {
            $record = $saved['reviewers'][$reviewer['id']] ?? null;
            if ($this->participationValidationError !== null) {
                $posted = $request->getUserVar('reviewers');
                $fields = is_array($posted) && is_array($posted[$reviewer['id']] ?? null) ? $posted[$reviewer['id']] : [];
                $record = [];
                foreach ([
                    'attendance' => 'attendance', 'attendance_other' => 'attendanceOther',
                    'contribution_comments' => 'contributionComments', 'shaping_feedback_comments' => 'shapingFeedbackComments',
                    'other_contribution' => 'otherContribution',
                ] as $key => $input) {
                    $record[$key] = is_string($fields[$input] ?? null) ? $fields[$input] : '';
                }
                $record['shaping_feedback_types'] = is_array($fields['shapingFeedbackTypes'] ?? null) ? array_filter($fields['shapingFeedbackTypes'], 'is_string') : [];
            }
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
        $isCancelled = $session['status'] === ParticipationService::STATUS_CANCELLED;
        $missingRequired = [];
        $submissionComment = '';
        if ($request->getUserVar('submitBlocked') && !$isCancelled) {
            $missingRequired = $this->participationMissingMessages(
                (new ParticipationService())->missingRequired($contextId, $formId),
                $configuration,
                $reviewers
            );
            $sessionKey = 'groupReviewSubmissionComment' . $formId;
            $submissionComment = (string) $request->getSession()->getSessionVar($sessionKey);
            $request->getSession()->unsetSessionVar($sessionKey);
        }
        TemplateManager::getManager($request)->setState(['grpNoMeeting' => $this->participationValidationError !== null
            ? (bool) $request->getUserVar('noMeeting')
            : !$saved['form']['has_meeting']]);

        $this->display($request, 'participationForm.tpl', [
            'pageTitle' => 'Reviewer Participation Recording',
            'formId' => $formId,
            'session' => $session,
            'generalComments' => $this->participationValidationError !== null && is_string($request->getUserVar('generalComments'))
                ? $request->getUserVar('generalComments') : ($saved['form']['general_comments'] ?? ''),
            'validationError' => $this->participationValidationError,
            'missingRequired' => $missingRequired,
            'submissionComment' => $submissionComment,
            'saveError' => (bool) $request->getUserVar('saveError'),
            'isSubmitted' => $isSubmitted,
            'isCancelled' => $isCancelled,
            'history' => array_reverse(array_map(fn (array $entry): array => [
                'date' => $this->participationDate($entry['submitted_at']),
                'name' => $this->participationUserName($entry['submitted_by']),
                'comment' => $entry['comment'],
            ], json_decode($saved['form']['submissions'] ?? '[]', true))),
            'cancelFormUrl' => $isCancelled ? null : $request->getRouter()->url($request, null, 'groupReview', 'cancelParticipationForm', null, ['formId' => $formId]),
            'reviewers' => $formReviewers,
            'pages' => $pages,
            'page' => $page,
            'pageCount' => $pageCount,
            'lastPage' => $pageCount - 1,
            'columnCount' => $pageSize + 1,
            'reviewerSlots' => range(1, $pageSize),
            'emptyReviewerSlots' => $emptySlots ? range(1, $emptySlots) : [],
            'attendanceOptions' => $this->participationAttendanceOptions(),
            'contributionOptions' => $configuration['options'],
            'configuration' => $configuration,
            'configurationToken' => ParticipationConfiguration::token($configuration),
            'cancelUrl' => $this->participationListUrl($request, (int) $bundle['form']['submission_id']),
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
        $formId = (int) $request->getUserVar('formId');
        $page = max(0, (int) $request->getUserVar('page'));
        $submit = $request->getUserVar('formAction') === 'submit';

        $reviewers = [];
        $posted = $request->getUserVar('reviewers');
        $malformedReviewers = !is_array($posted);
        foreach (is_array($posted) ? $posted : [] as $reviewerUserId => $fields) {
            if (!is_array($fields)) {
                $malformedReviewers = true;
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
            $bundle = $this->participationBundle($contextId, $formId);
            if (!$bundle) {
                throw new InvalidArgumentException('The participation form was not found.');
            }
            $expected = array_column($this->participationReviewers($bundle), 'id');
            $actual = array_map(fn (array $row): int => (int) $row['reviewer_user_id'], $reviewers);
            sort($expected);
            sort($actual);
            if ($malformedReviewers || $expected !== $actual || !$expected) {
                throw new InvalidArgumentException('Include every reviewer in the participation form.');
            }
            $result = (new ParticipationService())->saveForm(
                $contextId,
                $formId,
                (int) $request->getUser()->getId(),
                $reviewers,
                [
                    'has_meeting' => !$request->getUserVar('noMeeting'),
                    'general_comments' => $request->getUserVar('generalComments'),
                    'submission_comment' => $request->getUserVar('submissionComment'),
                    'configuration_token' => $request->getUserVar('configurationToken'),
                ],
                $submit
            );
        } catch (InvalidArgumentException $e) {
            $this->participationValidationError = $e->getMessage();
            $this->participationForm($args, $request);
            return;
        } catch (Throwable $e) {
            error_log('Group Review participation form save failed: ' . $e->getMessage());
            $request->redirect(null, 'groupReview', 'participationForm', null, [
                'formId' => $formId,
                'page' => $page,
                'saveError' => 1,
            ]);
            return;
        }

        $submitted = $submit && !$result['missing'];
        $this->logParticipationEvent($request, $bundle, $submitted);

        if ($submit && !$submitted) {
            $request->getSession()->setSessionVar(
                'groupReviewSubmissionComment' . $formId,
                $this->scalarString($request->getUserVar('submissionComment'))
            );
            $reviewerIds = array_column($this->participationReviewers($bundle), 'id');
            $firstMissing = min(array_map(
                fn (array $item): int => (int) array_search($item['reviewerUserId'], $reviewerIds, true),
                $result['missing']
            ));
            $request->redirect(null, 'groupReview', 'participationForm', null, [
                'formId' => $formId,
                'page' => intdiv($firstMissing, self::PARTICIPATION_PAGE_SIZE),
                'submitBlocked' => 1,
            ]);
            return;
        }

        if ($submitted) {
            $this->notifyParticipationEditors($request, $bundle, (string) $request->getUserVar('submissionComment'));
            $request->redirect(null, 'groupReview', 'participationRecorded', null, ['formId' => $formId]);
            return;
        }

        $request->redirect(null, 'groupReview', 'participationForm', null, [
            'formId' => $formId,
            'page' => $page,
        ]);
    }

    public function participationRecorded($args, $request): void
    {
        $contextId = (int) $request->getContext()->getId();
        $formId = (int) $request->getUserVar('formId');
        $bundle = $this->participationBundle($contextId, $formId);
        if (!$bundle) {
            $this->displayMessage($request, 'plugins.generic.groupReview.participation.error.formNotFound');
            return;
        }

        $this->display($request, 'participationRecorded.tpl', [
            'pageTitle' => 'Reviewer Participation Recording',
            'session' => $this->participationSessionData($bundle),
            'backUrl' => $this->participationListUrl($request, (int) $bundle['form']['submission_id']),
        ]);
    }

    public function storeParticipationForm($args, $request): void
    {
        if (!$request->isPost() || !$request->checkCSRF()) {
            $this->displayMessage($request, 'plugins.generic.groupReview.error.invalidRequest');
            return;
        }
        $contextId = (int) $request->getContext()->getId();
        $submissionId = (int) $request->getUserVar('submissionId');

        try {
            $reviewRound = $this->currentReviewRound($submissionId);
            $leaderId = (int) $request->getUser()->getId();
            $reviewerIds = $this->participationReviewerIds($contextId, $submissionId);
            if (!$reviewRound || !$this->service->isAssignedLeader($contextId, $submissionId, $leaderId) || !$reviewerIds) {
                throw new InvalidArgumentException('Only an assigned Review Group Leader can create the form, once Members are assigned.');
            }
            $formId = (new ParticipationService())->createForm($contextId, $submissionId, (int) $reviewRound->getId(), $leaderId, $reviewerIds);
        } catch (Throwable $e) {
            error_log('Group Review participation form creation failed: ' . $e->getMessage());
            $this->displayMessage($request, 'plugins.generic.groupReview.participation.error.createFailed');
            return;
        }

        $request->redirect(null, 'groupReview', 'participationForm', null, ['formId' => $formId, 'page' => 0]);
    }

    public function cancelParticipationForm($args, $request): void
    {
        if (!$request->isPost() || !$request->checkCSRF()) {
            $this->displayMessage($request, 'plugins.generic.groupReview.error.invalidRequest');
            return;
        }
        $contextId = (int) $request->getContext()->getId();
        $formId = (int) $request->getUserVar('formId');
        $participation = new ParticipationService();
        $form = $participation->getFormRecord($contextId, $formId);

        try {
            $participation->cancelForm($contextId, $formId);
        } catch (Throwable $e) {
            error_log('Group Review participation form cancel failed: ' . $e->getMessage());
            $this->displayMessage($request, 'plugins.generic.groupReview.participation.error.cancelFailed');
            return;
        }
        $request->redirectUrl($this->participationListUrl($request, (int) $form['submission_id']));
    }

    private function participationReviewerIds(int $contextId, int $submissionId): array
    {
        return array_values(array_diff(
            $this->service->getAssignedUserIds($contextId, $submissionId, 'RGM'),
            $this->service->getAssignedUserIds($contextId, $submissionId, 'RGL')
        ));
    }

    private function currentReviewRound(int $submissionId): ?ReviewRound
    {
        $reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO'); /** @var ReviewRoundDAO $reviewRoundDao */
        return $reviewRoundDao->getLastReviewRoundBySubmissionId($submissionId, WORKFLOW_STAGE_ID_EXTERNAL_REVIEW);
    }

    /** Return the participation form with its submission and leader. */
    private function participationBundle(int $contextId, int $formId): ?array
    {
        $form = $formId ? (new ParticipationService())->getFormRecord($contextId, $formId) : null;
        if (!$form) {
            return null;
        }

        return [
            'form' => $form,
            'submission' => Repo::submission()->get((int) $form['submission_id']),
            'leader' => Repo::user()->get((int) $form['leader_user_id']),
        ];
    }

    /** Summary strip, list and footer details for a participation form. */
    private function participationSessionData(array $bundle): array
    {
        $form = $bundle['form'];
        $submission = $bundle['submission'] ?? null;
        $publication = $submission ? $submission->getCurrentPublication() : null;

        return [
            'id' => (int) $form['form_id'],
            'submissionTitle' => $submission ? $submission->getLocalizedTitle() : '',
            'firstAuthor' => $publication ? $publication->getShortAuthorString() : '',
            'round' => $this->participationRound($form),
            'leaderName' => $bundle['leader'] ? $bundle['leader']->getFullName() : '',
            'reviewerCount' => count($this->participationReviewers($bundle)),
            'status' => $form['status'],
            'lastSaved' => $form['updated_at'] ? $this->participationDate($form['updated_at']) : null,
            'lastSavedBy' => $this->participationUserName($form['updated_by']),
            'submittedAt' => $form['submitted_at'] ? $this->participationDate($form['submitted_at']) : null,
            'submittedBy' => $this->participationUserName($form['submitted_by']),
            'submissionComment' => (string) ($form['submission_comment'] ?? ''),
        ];
    }

    private function participationDate(string $utc): string
    {
        return $this->service->formatUtc($utc, date_default_timezone_get(), self::PARTICIPATION_DATE_FORMAT);
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

    private function participationRound(array $form): int
    {
        return (int) DB::table('review_rounds')
            ->where('review_round_id', (int) $form['review_round_id'])
            ->value('round');
    }

    /**
     * Add a participation form entry to the submission's Activity Log: who
     * edited or submitted it and when. What changed is not recorded. A failure
     * to log does not undo the save.
     */
    private function logParticipationEvent($request, array $bundle, bool $submit): void
    {
        try {
            $round = $this->participationRound($bundle['form']);
            $message = $submit
                ? 'plugins.generic.groupReview.participation.log.submitted'
                : 'plugins.generic.groupReview.participation.log.edited';
            $isTranslated = false;

            Repo::eventLog()->add(Repo::eventLog()->newDataObject([
                'assocType' => Application::ASSOC_TYPE_SUBMISSION,
                'assocId' => (int) $bundle['form']['submission_id'],
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
        $submissionId = (int) $bundle['form']['submission_id'];
        $submitter = $request->getUser();
        $comment = trim($comment);
        $formUrl = $request->getDispatcher()->url(
            $request,
            Application::ROUTE_PAGE,
            $context->getPath(),
            'groupReview',
            'participationForm',
            null,
            ['formId' => (int) $bundle['form']['form_id']]
        );
        $notificationManager = new NotificationManager();
        $round = $this->participationRound($bundle['form']);

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

    /** @return array<int, array{id:int, name:string}> The form's reviewers. */
    private function participationReviewers(array $bundle): array
    {
        $reviewers = [];
        foreach (json_decode($bundle['form']['reviewer_ids'], true) as $userId) {
            $reviewers[] = ['id' => (int) $userId, 'name' => $this->participationUserName($userId)];
        }

        return $reviewers;
    }

    private function participationMissingMessages(array $missing, array $configuration, array $reviewers): array
    {
        $names = array_column($reviewers, 'name', 'id');
        $byField = [];
        foreach ($missing as $item) {
            $byField[$item['field']][] = $names[$item['reviewerUserId']] ?? '';
        }

        $messages = [];
        foreach ($byField as $field => $reviewerNames) {
            $messages[] = __('plugins.generic.groupReview.participation.error.missingField', [
                'field' => $configuration['text'][$field]['label'],
                'reviewers' => implode(', ', $reviewerNames),
            ]);
        }

        return $messages;
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
            'members' => $this->pollMemberOptions((int) $context->getId(), (int) $bundle['poll']['submission_id']),
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

    private function pollMemberOptions(int $contextId, int $submissionId): array
    {
        $assigned = $this->participationReviewerIds($contextId, $submissionId);
        $members = [];
        foreach ($this->service->getRgmCandidates($contextId) as $member) {
            $member['assigned'] = in_array((int) $member['user_id'], $assigned, true);
            $members[] = $member;
        }
        usort($members, fn (array $a, array $b): int => $b['assigned'] <=> $a['assigned']);

        return $members;
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
        $order = explode('.', $this->scalarString($request->getUserVar('order')));
        if (count($order) === 2 && in_array($order[0], self::MONITORING_SORT_COLUMNS, true) && in_array($order[1], ['asc', 'desc'], true)) {
            return $order;
        }
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

    private function monitoringSortOrders(string $sort, string $dir): array
    {
        $orders = [];
        foreach (self::MONITORING_SORT_COLUMNS as $column) {
            $nextDir = $sort === $column
                ? ($dir === 'asc' ? 'desc' : 'asc')
                : ($column === 'name' ? 'asc' : 'desc');
            $orders[$column] = $column . '.' . $nextDir;
        }

        return $orders;
    }

    private function monitoringGridParams($request): array
    {
        $grid = new ReviewerGridService();
        [$sort, $dir] = $this->monitoringSortAndDir($request);
        return array_merge($grid->parameters($grid->settings($request)), ['sort' => $sort, 'dir' => $dir]);
    }

    private function sortReviewerRows(array $reviewers, string $sort, string $dir): array
    {
        $factor = $dir === 'desc' ? -1 : 1;
        usort($reviewers, function (array $a, array $b) use ($sort, $factor): int {
            if ($a[$sort] === null || $b[$sort] === null) {
                return ($a[$sort] === null) <=> ($b[$sort] === null);
            }
            $result = is_string($a[$sort]) ? strcasecmp($a[$sort], $b[$sort]) : $a[$sort] <=> $b[$sort];

            return $result * $factor;
        });

        return $reviewers;
    }

    private function reviewerTableRow(array $row, array $labels, $request, ?int $year, array $filterParams = []): array
    {
        $contextId = (int) $request->getContext()->getId();

        return [
            'userId' => $row['userId'],
            'name' => $row['name'],
            'labelsText' => $this->reviewerLabelsText($contextId, $labels),
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
                ReviewerGridService::urlParameters(array_merge($filterParams, ['reviewerId' => $row['userId'], 'year' => $year ?? 'all']))
            ),
        ];
    }

    private function reviewerLabelsText(int $contextId, array $labels): string
    {
        $service = new ReviewerLabelService();
        $names = [];
        foreach ($service->getTypes($contextId) as $type => $definition) {
            foreach ($labels[$type] ?? [] as $value) {
                $names[] = $service->name($contextId, $type, $value);
            }
        }

        return implode(', ', $names);
    }

    private function reviewerLabelsList(int $contextId, array $labels): array
    {
        $service = new ReviewerLabelService();
        $list = [];
        foreach ($service->getTypes($contextId) as $type => $definition) {
            $values = $labels[$type] ?? [];
            $list[] = [
                'name' => $service->name($contextId, $type),
                'valuesText' => $values
                    ? implode(', ', array_map(fn ($value) => $service->name($contextId, $type, $value), $values))
                    : __('plugins.generic.groupReview.labels.notSet'),
            ];
        }

        return $list;
    }

    private function reviewerLabelOptions(int $contextId, ?int $reviewerId = null): array
    {
        $service = new ReviewerLabelService();
        $options = [];
        $types = $reviewerId === null ? $service->getTypes($contextId) : $service->getTypesFor($contextId, $reviewerId);
        foreach ($types as $type => $definition) {
            $options[$type] = [
                'name' => $service->name($contextId, $type),
                'multiple' => $definition['multiple'],
                'options' => array_map(
                    fn ($value) => ['value' => $value, 'label' => $service->name($contextId, $type, $value)],
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
            $list[] = ['label' => $label, 'count' => $counts[$value] ?? 0];
        }

        return $list;
    }

    private function reviewerHistory(array $entries, $request): array
    {
        $history = [];
        $titles = [];
        foreach ($entries as $entry) {
            if (!array_key_exists($entry['submissionId'], $titles)) {
                $submission = Repo::submission()->get($entry['submissionId']);
                $titles[$entry['submissionId']] = $submission ? $submission->getLocalizedTitle() : '';
            }
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
                'submissionTitle' => $titles[$entry['submissionId']],
                'submissionUrl' => $request->getRouter()->url($request, null, 'workflow', 'access', $entry['submissionId']),
                'round' => $entry['round'],
                'date' => $this->participationDate($entry['date']),
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
        $labels = $record['fieldLabels'];
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
            $add('Review meeting', $labels['attendance'], $value);
        }
        $add('Review meeting', $labels['contribution_comments'], $record['contributionComments']);

        if ($record['shapingFeedbackTypes']) {
            $options = $record['shapingFeedbackLabels'] ?? ParticipationService::CONTRIBUTION_OPTIONS;
            $answerLabels = array_map(fn ($value) => $options[$value] ?? $value, $record['shapingFeedbackTypes']);
            $add('Feedback response', $labels['shaping_feedback_types'], implode(', ', $answerLabels));
        }
        $add('Feedback response', $labels['shaping_feedback_comments'], $record['shapingFeedbackComments']);
        $add('Other', $labels['other_contribution'], $record['otherContribution']);

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
