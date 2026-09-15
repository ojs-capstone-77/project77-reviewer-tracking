<?php

/**
 * @file GroupReviewPlugin.php
 *
 * Self-contained group-review meeting coordination for OJS 3.4.x.
 */

namespace APP\plugins\generic\groupReview;

use APP\core\Application;
use APP\plugins\generic\groupReview\classes\GroupReviewService;
use APP\plugins\generic\groupReview\classes\mail\GroupReviewPollClosing;
use APP\plugins\generic\groupReview\classes\mail\GroupReviewPollCreated;
use APP\plugins\generic\groupReview\classes\mail\GroupReviewPollThankInvitees;
use APP\plugins\generic\groupReview\classes\mail\GroupReviewPollThankRgms;
use APP\plugins\generic\groupReview\classes\notification\Notification as GroupReviewNotification;
use APP\plugins\generic\groupReview\pages\groupReview\GroupReviewHandler;
use PKP\components\forms\FieldOptions;
use PKP\components\forms\FieldText;
use PKP\decision\Decision;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\submission\reviewRound\ReviewRoundDAO;
use stdClass;

class GroupReviewPlugin extends GenericPlugin
{
    public const ASSOC_TYPE_POLL = 0xA00003;
    /** @copydoc Plugin::register() */
    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!$success || Application::isUnderMaintenance()) {
            return $success;
        }

        if (!$this->isOjs34()) {
            return $success;
        }

        // OJS can register a generic plugin while the management request has no
        // journal-derived $mainContextId. Register the hooks unconditionally on
        // supported OJS versions, then enforce the actual request/submission
        // context in each operational callback.
        Hook::add('LoadHandler', [$this, 'setPageHandler']);
        Hook::add('Decision::add', [$this, 'decisionAdded']);
        Hook::add('Form::config::before', [$this, 'addToForm']);
        Hook::add('Schema::get::context', [$this, 'addToContextSchema']);
        Hook::add('Template::Workflow', [$this, 'addWorkflowTab']);
        Hook::add('TemplateManager::display', [$this, 'loadAssets']);
        Hook::add('AcronPlugin::parseCronTab', [$this, 'addScheduledTasks']);
        Hook::add('Mailer::Mailables', [$this, 'addMailables']);
        Hook::add('NotificationManager::getNotificationMessage', [$this, 'notificationMessage']);

        return true;
    }

    /**
     * Prevent accidental activation in OMP or an unsupported OJS series.
     */
    public function isOjs34(): bool
    {
        if (Application::getName() !== 'ojs2') {
            return false;
        }

        $version = \PKP\site\VersionCheck::getCurrentCodeVersion();
        return $version && (int) $version->getMajor() === 3 && (int) $version->getMinor() === 4;
    }

    public function getCanEnable()
    {
        return $this->isOjs34() && parent::getCanEnable();
    }

    /** Register the plugin-owned page handler. */
    public function setPageHandler($hookName, $args): bool
    {
        $page = &$args[0];
        $handler = &$args[3];
        $request = Application::get()->getRequest();
        $context = $request->getContext();

        if ($page !== 'groupReview' || !$context || !$this->getEnabled($context->getId())) {
            return false;
        }

        $handler = new GroupReviewHandler($this);
        return true;
    }

    /** Apply the poll and RGM-participant lifecycle documented by the project report. */
    public function decisionAdded($hookName, $args): bool
    {
        $decision = $args[0] ?? null;
        if (!$decision instanceof Decision) {
            return false;
        }

        $closingDecisions = [
            Decision::ACCEPT,
            Decision::NEW_EXTERNAL_ROUND,
            Decision::CANCEL_REVIEW_ROUND,
        ];
        $decisionType = $decision->getDecisionType()->getDecision();
        if (!in_array($decisionType, $closingDecisions, true)) {
            return false;
        }

        $submissionId = (int) $decision->getData('submissionId');
        $submission = $submissionId ? \APP\facades\Repo::submission()->get($submissionId) : null;
        if ($submission && $this->getEnabled((int) $submission->getContextId())) {
            GroupReviewService::closeActiveForSubmission(
                (int) $submission->getContextId(),
                $submissionId,
                GroupReviewService::STATUS_CANCELLED,
                $decisionType !== Decision::ACCEPT
            );
        }

        return false;
    }

    /** Add journal-level defaults to Workflow > Review settings. */
    public function addToForm($hookName, $form): void
    {
        import('lib.pkp.classes.components.forms.context.PKPReviewSetupForm');
        if (!defined('FORM_REVIEW_SETUP') || $form->id !== FORM_REVIEW_SETUP) {
            return;
        }

        $context = Application::get()->getRequest()->getContext();
        if (!$context || !$this->getEnabled((int) $context->getId())) {
            return;
        }

        $form->addField(new FieldOptions('groupReviewEnabled', [
            'label' => __('plugins.generic.groupReview.settings.enabled.label'),
            'description' => __('plugins.generic.groupReview.settings.enabled.description'),
            'type' => 'checkbox',
            'value' => (bool) $context->getData('groupReviewEnabled'),
            'options' => [
                ['value' => true, 'label' => __('common.enable')],
            ],
        ]))
            ->addField(new FieldText('groupReviewDefaultDuration', [
                'label' => __('plugins.generic.groupReview.settings.duration.label'),
                'description' => __('plugins.generic.groupReview.settings.duration.description'),
                'value' => (int) ($context->getData('groupReviewDefaultDuration') ?: 30),
                'size' => 'small',
                'showWhen' => 'groupReviewEnabled',
            ]))
            ->addField(new FieldText('groupReviewDefaultSlots', [
                'label' => __('plugins.generic.groupReview.settings.slots.label'),
                'description' => __('plugins.generic.groupReview.settings.slots.description'),
                'value' => (int) ($context->getData('groupReviewDefaultSlots') ?: 3),
                'size' => 'small',
                'showWhen' => 'groupReviewEnabled',
            ]))
            ->addField(new FieldText('groupReviewReminderHours', [
                'label' => __('plugins.generic.groupReview.settings.reminder.label'),
                'description' => __('plugins.generic.groupReview.settings.reminder.description'),
                'value' => (int) ($context->getData('groupReviewReminderHours') ?: 24),
                'size' => 'small',
                'showWhen' => 'groupReviewEnabled',
            ]))
            ->addField(new FieldText('groupReviewMinimumLeadDays', [
                'label' => __('plugins.generic.groupReview.settings.leadDays.label'),
                'description' => __('plugins.generic.groupReview.settings.leadDays.description'),
                'value' => $context->getData('groupReviewMinimumLeadDays') === null
                    ? 10
                    : (int) $context->getData('groupReviewMinimumLeadDays'),
                'size' => 'small',
                'showWhen' => 'groupReviewEnabled',
            ]));
    }

    /** Extend the context schema so OJS stores and validates these settings. */
    public function addToContextSchema($hookName, $args): bool
    {
        $schema = $args[0];

        $schema->properties->groupReviewEnabled = $this->schemaProperty('boolean', ['nullable']);
        $schema->properties->groupReviewDefaultDuration = $this->schemaProperty('integer', ['nullable', 'integer', 'min:5', 'max:480']);
        $schema->properties->groupReviewDefaultSlots = $this->schemaProperty('integer', ['nullable', 'integer', 'min:1', 'max:20']);
        $schema->properties->groupReviewReminderHours = $this->schemaProperty('integer', ['nullable', 'integer', 'min:1', 'max:720']);
        $schema->properties->groupReviewMinimumLeadDays = $this->schemaProperty('integer', ['nullable', 'integer', 'min:0', 'max:365']);

        return false;
    }

    private function schemaProperty(string $type, array $validation): stdClass
    {
        $property = new stdClass();
        $property->type = $type;
        $property->validation = $validation;
        return $property;
    }

    /**
     * Add a supported, isolated tab. No OJS template is replaced or copied.
     */
    public function addWorkflowTab($hookName, $args): bool
    {
        $templateMgr = $args[1];
        $output = &$args[2];
        $submission = $templateMgr->getTemplateVars('submission');
        $stageId = (int) $templateMgr->getTemplateVars('requestedStageId');
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $user = $request->getUser();

        if (!$submission
            || !$context
            || !$user
            || (int) $submission->getContextId() !== (int) $context->getId()
            || !$this->getEnabled((int) $context->getId())
            || $stageId !== WORKFLOW_STAGE_ID_EXTERNAL_REVIEW) {
            return false;
        }

        $reviewRoundDao = \PKP\db\DAORegistry::getDAO('ReviewRoundDAO');  /** @var ReviewRoundDAO $reviewRoundDao */
        $reviewRound = $reviewRoundDao->getLastReviewRoundBySubmissionId(
            $submission->getId(),
            WORKFLOW_STAGE_ID_EXTERNAL_REVIEW
        );
        if (!$reviewRound) {
            return false;
        }
        $requestedReviewRoundId = (int) (
            $templateMgr->getTemplateVars('reviewRoundId')
            ?: $request->getUserVar('reviewRoundId')
        );
        if ($requestedReviewRoundId && $requestedReviewRoundId !== (int) $reviewRound->getId()) {
            return false;
        }

        $service = new GroupReviewService();
        if (!$service->isEnabled($context)) {
            return false;
        }
        $poll = $service->getActiveForSubmissionRound(
            (int) $context->getId(),
            (int) $submission->getId(),
            (int) $reviewRound->getId()
        );
        $canLead = $service->isLeader(
            (int) $context->getId(),
            (int) $submission->getId(),
            (int) $user->getId()
        );
        $canManagePoll = $canLead;
        $isMember = $poll && $service->isInvited(
            (int) $context->getId(),
            (int) $poll['session_id'],
            (int) $user->getId()
        );

        if (!$canManagePoll && !$isMember) {
            return false;
        }

        $params = $poll
            ? ['pollId' => (int) $poll['session_id']]
            : ['submissionId' => (int) $submission->getId(), 'reviewRoundId' => (int) $reviewRound->getId()];
        $op = $poll
            ? ($canManagePoll
                ? ((int) $poll['status'] === GroupReviewService::STATUS_DRAFT ? 'invite' : 'view')
                : 'availability')
            : 'create';

        $templateMgr->assign([
            'groupReviewPoll' => $poll,
            'groupReviewCanLead' => $canManagePoll,
            'groupReviewUrl' => $request->getRouter()->url($request, null, 'groupReview', $op, null, $params),
            'groupReviewDashboardUrl' => $request->getRouter()->url($request, null, 'groupReview', 'index'),
        ]);
        $output .= $templateMgr->fetch($this->getTemplateResource('workflow/groupReviewTab.tpl'));
        return false;
    }

    /** Load one small, namespaced stylesheet on the backend. */
    public function loadAssets($hookName, $args): bool
    {
        $templateMgr = $args[0];
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context || !$this->getEnabled((int) $context->getId())) {
            return false;
        }
        $templateMgr->addStyleSheet(
            'groupReview',
            "{$request->getBaseUrl()}/{$this->getPluginPath()}/css/app.css",
            ['contexts' => ['backend']]
        );
        return false;
    }

    /** Let OJS's bundled Acron plugin discover the hourly task. */
    public function addScheduledTasks($hookName, $args): bool
    {
        $taskFiles = &$args[0];
        $taskFile = "{$this->getPluginPath()}/scheduledTasks.xml";
        if (file_exists($taskFile)) {
            $taskFiles[] = $taskFile;
        }
        return false;
    }

    public function addMailables($hookName, $args): void
    {
        $args[0]->push(GroupReviewPollCreated::class);
        $args[0]->push(GroupReviewPollClosing::class);
        $args[0]->push(GroupReviewPollThankRgms::class);
        $args[0]->push(GroupReviewPollThankInvitees::class);
    }

    /** Render the two poll task labels used by the student implementation. */
    public function notificationMessage($hookName, $args): void
    {
        $notification = $args[0];
        $message = &$args[1];
        if ($notification->getType() === GroupReviewNotification::NOTIFICATION_TYPE_POLL_CREATED) {
            $message = __('plugins.generic.groupReview.tasks.pollCreated.label');
        } elseif ($notification->getType() === GroupReviewNotification::NOTIFICATION_TYPE_POLL_CLOSING) {
            $message = __('plugins.generic.groupReview.tasks.pollClosing.label');
        }
    }


    public function getDisplayName()
    {
        return __('plugins.generic.groupReview.displayName');
    }

    public function getDescription()
    {
        return __('plugins.generic.groupReview.description');
    }

    public function getInstallMigration(): ?GroupReviewMigration
    {
        return $this->isOjs34() ? new GroupReviewMigration() : null;
    }

    public function getInstallEmailTemplatesFile(): ?string
    {
        return $this->isOjs34() ? "{$this->getPluginPath()}/emailTemplates.xml" : null;
    }
}
