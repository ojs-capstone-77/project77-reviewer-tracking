<?php

/**
 * php plugins/generic/groupReview/tests/ParticipationConfigurationTest.php <journal path> [--snapshot]
 * Transactional tests: journal settings, forms, options and fixtures roll back.
 */

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\groupReview\classes\ParticipationConfiguration;
use APP\plugins\generic\groupReview\classes\ParticipationService;
use APP\plugins\generic\groupReview\classes\ReviewerStatsService;
use APP\plugins\generic\groupReview\classes\security\authorization\EditorRequiredPolicy;
use APP\plugins\generic\groupReview\GroupReviewPlugin;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\DB;
use PKP\cliTool\CommandLineTool;
use PKP\security\authorization\AuthorizationPolicy;

require_once __DIR__ . '/MonitoringTest.php';
require_once __DIR__ . '/TestJournal.php';

class ParticipationConfigurationTestTool extends CommandLineTool
{
    private int $checks = 0;

    private function same($expected, $actual, string $name): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($name . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
        $this->checks++;
    }

    private function rejected(callable $action, string $name): void
    {
        try {
            $action();
        } catch (InvalidArgumentException $error) {
            $this->checks++;
            return;
        }
        throw new RuntimeException($name . ': expected validation failure');
    }

    private function render($handler, $request, string $op): string
    {
        ob_start();
        try {
            $handler->$op([], $request);
            return ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    public function execute()
    {
        DB::beginTransaction();
        try {
            $path = groupReviewTestJournal($this->argv[0] ?? '');
            $context = Application::getContextDAO()->getByPath($path);
            $contextId = (int) $context->getId();
            $seed = new GroupReviewTestDataTool([__FILE__, $path, 'seed']);
            $seed->execute();
            $fixture = $seed->getFixture();
            $configuration = new ParticipationConfiguration();
            $service = new ParticipationService();
            $poll = array_values($fixture['polls'])[2];
            $roundId = (int) DB::table('group_review_sessions')->where('session_id', $poll['sessionId'])->value('review_round_id');
            $reviewerId = $fixture['users']['test_rgm_22']['id'];
            $reviewerIds = [$reviewerId, $fixture['users']['test_rgm_23']['id']];
            $formId = $service->createForm($contextId, $poll['submissionId'], $roundId, $fixture['users'][$poll['leader']]['id'], $reviewerIds);
            $editor = Repo::user()->getByUsername('test_editor_01');
            $editorId = (int) $editor->getId();
            $data = ['reviewer_user_id' => $reviewerId, 'attendance' => 'not_recorded'];
            $this->same(ParticipationConfiguration::defaults(), $configuration->get($contextId), 'Default journal settings');
            $this->same(4, count($configuration->get($contextId)['fields']), 'All four configurable fields');
            $original = $configuration->get($contextId);
            $text = $original['text'];
            foreach ([
                ['invalid', [['label' => 'Valid']]],
                [[[]], [['label' => 'Valid']]],
                [['other_contribution'], [['label' => 'Valid']]],
                [['general_comments'], [['label' => 'Valid']]],
                [[], []], [[], [['label' => '  ']]],
                [[], [['label' => 'Same'], ['label' => 'same']]],
                [[], [['label' => []]]], [[], [['label' => str_repeat('a', 151)]]],
            ] as [$fields, $options]) {
                $this->rejected(fn () => $configuration->save($contextId, $fields, $options, $text), 'Invalid settings rejected');
                $this->same($original, $configuration->get($contextId), 'Invalid settings leave stored configuration unchanged');
            }
            $this->rejected(fn () => $configuration->save($contextId, [], [['label' => 'Valid']], ['attendance' => ['label' => ' ']] + $text), 'Empty field name rejected');
            $this->same($original, $configuration->get($contextId), 'Invalid field name leaves stored configuration unchanged');
            $options = [];
            foreach ($original['options'] as $id => $label) {
                $options[] = ['id' => $id, 'label' => $label];
            }
            foreach (ParticipationConfiguration::FIELDS as $field => $label) {
                $configuration->save($contextId, [$field], $options, $text);
                DB::beginTransaction();
                $blocked = $service->saveForm($contextId, $formId, $editorId, [$data], [], true);
                $this->same(true, in_array($field, array_column($blocked['missing'], 'field'), true), "{$label} enforced on submit");
                $this->same('draft', $service->getForm($contextId, $formId)['form']['status'], "{$label}: blocked submit leaves the form unsubmitted");
                DB::rollBack();
                DB::beginTransaction();
                $service->saveForm($contextId, $formId, $editorId, [$data]);
                $this->same('draft', $service->getForm($contextId, $formId)['form']['status'], "{$label} not required to save a draft");
                DB::rollBack();
            }
            $custom = $configuration->save($contextId, ['shaping_feedback_types'], [['label' => 'Peer feedback']], $text);
            $key = array_key_first($custom['options']);
            $this->same(['Peer feedback'], array_values($configuration->get($contextId)['options']), 'New shaping option loads');
            $this->same(ParticipationConfiguration::defaults(), $configuration->get($contextId + 999999), 'Settings are journal scoped');
            $this->rejected(fn () => $service->saveForm($contextId, $formId, $editorId, [
                $data + ['shaping_feedback_types' => ['invented']],
            ]), 'Unconfigured shaping option rejected');
            $token = ParticipationConfiguration::token($custom);
            $renamed = $configuration->save($contextId, [], [['id' => $key, 'label' => 'Renamed feedback']], $text);
            $this->same(false, array_key_exists($key, $renamed['options']), 'Rename has distinct historical identity');
            $this->rejected(fn () => $service->saveForm($contextId, $formId, $editorId, [$data], [
                'configuration_token' => $token,
            ]), 'Settings changed while unsaved form was open');
            $custom = $configuration->save($contextId, [], [['label' => 'Peer feedback']], $text);
            $key = array_key_first($custom['options']);
            $data += [
                'shaping_feedback_types' => [$key], 'contribution_comments' => 'Clear analysis',
                'other_contribution' => 'Other reviewer comment',
            ];
            $service->saveForm($contextId, $formId, $editorId, [$data], ['general_comments' => 'General editor comment'], true);
            $saved = $service->getForm($contextId, $formId);
            $this->same($custom, $configuration->forForm($contextId, $formId), 'First save snapshots configuration');
            $this->same('Clear analysis', $saved['reviewers'][$reviewerId]['contribution_comments'], 'Comments round trip');
            $this->same('Other reviewer comment', $saved['reviewers'][$reviewerId]['other_contribution'], 'Other field retained');
            $this->same('General editor comment', $saved['form']['general_comments'], 'General comments retained');
            $configuration->save($contextId, array_keys(ParticipationConfiguration::FIELDS), [['label' => 'Future option']], $text);
            $this->same($saved, $service->getForm($contextId, $formId), 'Configuration changes do not modify submitted data');
            $this->same($custom, $configuration->forForm($contextId, $formId), 'Submitted form retains old options and requirements');
            $service->saveForm($contextId, $formId, $editorId, [$data], [], true);
            $this->same([$key], $service->getForm($contextId, $formId)['reviewers'][$reviewerId]['shaping_feedback_types'], 'Removed option can still be resubmitted on original form');
            $this->same('Peer feedback', (new ReviewerStatsService())->getContributionLabels($contextId, $reviewerId, null)[$key], 'Dashboard answer counts use historical option names');
            $this->same(1, (new ReviewerStatsService())->getContributionCounts($contextId, $reviewerId, null)[$key], 'Removed shaping option still counts in reviewer statistics');
            $history = (new ReviewerStatsService())->getHistory($contextId, $reviewerId, null);
            $entry = array_values(array_filter($history, fn ($entry) => $entry['submissionId'] === $poll['submissionId']))[0];
            $this->same('Peer feedback', $entry['participation']['shapingFeedbackLabels'][$key], 'History preserves removed option name');
            $this->same('Clear analysis', $entry['participation']['contributionComments'], 'History retains configurable field values');

            $request = new MonitoringPageRequest($context, $editor);
            $plugin = new class extends GroupReviewPlugin {
                public function getTemplateResource($template = null, $inCore = false)
                {
                    return 'file:' . dirname(__DIR__) . '/templates/' . $template;
                }
            };
            $handler = new MonitoringPageHandler($plugin);
            $manager = TemplateManager::getManager($request);
            $manager->unregisterPlugin('function', 'csrf');
            $manager->registerPlugin('function', 'csrf', fn () => '');
            $manager->assign([
                'configuration' => $configuration->get($contextId),
                'configurableFields' => ParticipationConfiguration::FIELDS,
                'saveUrl' => '',
            ]);
            $settingsHtml = $manager->fetch($plugin->getTemplateResource('settings/participation.tpl'));
            $this->same(true, str_contains($settingsHtml, 'Future option'), 'Settings tab loads current configuration');
            $request->vars = ['formId' => $formId];
            $html = $this->render($handler, $request, 'participationForm');
            $this->same(true, str_contains($html, 'Peer feedback') && !str_contains($html, 'Future option'), 'Saved form renders original options');
            $this->same(true, str_contains($html, 'Other comments') && str_contains($html, 'General comments for the editors'), 'Locked fields always rendered');
            $this->same(true, str_contains($html, 'Other reviewer comment') && str_contains($html, 'General editor comment'), 'Locked comments load after configuration changes');
            $this->same(true, str_contains($html, 'Clear analysis'), 'Saved values render');
            foreach (['test_editor_01' => true, 'test_editor_03' => true, 'test_rgl_01' => false, 'test_rgm_21' => false] as $username => $allowed) {
                $access = new MonitoringPageRequest($context, Repo::user()->getByUsername($username));
                $policy = new EditorRequiredPolicy($access);
                $this->same($allowed ? AuthorizationPolicy::AUTHORIZATION_PERMIT : AuthorizationPolicy::AUTHORIZATION_DENY, $policy->effect(), 'Editor-only settings: ' . $username);
            }
            $request->post = true;
            $request->csrf = false;
            $before = $configuration->get($contextId);
            $this->render($handler, $request, 'saveParticipationSettings');
            $this->same($before, $configuration->get($contextId), 'Settings CSRF enforced');
            $request->csrf = true;
            $request->vars = ['requiredFields' => [], 'options' => [['label' => 'After handler save']], 'text' => $text];
            $handler->saveParticipationSettings([], $request);
            $this->same(['After handler save'], array_values($configuration->get($contextId)['options']), 'Settings handler saves journal configuration');
            $request->vars = ['requiredFields' => [], 'options' => [], 'text' => $text];
            $before = $configuration->get($contextId);
            $handler->saveParticipationSettings([], $request);
            $this->same($before, $configuration->get($contextId), 'Invalid settings leave the configuration unchanged');

            // A new form picks up the current configuration.
            $service->cancelForm($contextId, $formId);
            $futureId = $service->createForm($contextId, $poll['submissionId'], $roundId, $fixture['users'][$poll['leader']]['id'], $reviewerIds);
            $text['contribution_comments'] = ['label' => 'What went well', 'description' => 'Name one thing.'];
            $current = $configuration->save($contextId, array_keys(ParticipationConfiguration::FIELDS), [['label' => 'Future option']], $text);
            $this->same($current, $configuration->forForm($contextId, $futureId), 'New form uses new settings');
            $request->vars = ['formId' => $futureId];
            $futureHtml = $this->render($handler, $request, 'participationForm');
            $this->same(true, str_contains($futureHtml, 'Future option') && !str_contains($futureHtml, 'Peer feedback'), 'Future form renders updated options only');
            $this->same(true, str_contains($futureHtml, 'Required'), 'Future form displays required status');
            $this->same(true, str_contains($futureHtml, 'What went well') && str_contains($futureHtml, 'Name one thing.'), 'Future form shows the renamed field and its description');
            $postedReviewers = [];
            foreach ($reviewerIds as $id) {
                $postedReviewers[$id] = [
                    'attendance' => 'attended', 'shapingFeedbackTypes' => [array_key_first($current['options'])],
                    'contributionComments' => '0',
                    'shapingFeedbackComments' => 'Feedback', 'otherContribution' => 'Always available',
                ];
            }
            $request->vars = [
                'formId' => $futureId, 'reviewers' => $postedReviewers,
                'configurationToken' => ParticipationConfiguration::token($current),
                'generalComments' => 'Always present', 'formAction' => 'save',
            ];
            $invalid = $request->vars;
            $invalid['reviewers'][$reviewerId]['contributionComments'] = '  ';
            $invalid['formAction'] = 'submit';
            $invalid['submissionComment'] = 'Typed before submitting';
            $request->vars = $invalid;
            DB::beginTransaction();
            (new MonitoringPageHandler($plugin))->saveParticipationForm([], $request);
            $this->same(1, $request->redirectParams['submitBlocked'] ?? null, 'Blocked submit returns to the form');
            $blocked = $service->getForm($contextId, $futureId);
            $this->same('draft', $blocked['form']['status'], 'Blocked submit does not submit the form');
            $this->same('Always available', $blocked['reviewers'][$reviewerId]['other_contribution'], 'Blocked submit keeps the entered answers');
            $this->same('Always present', $blocked['form']['general_comments'], 'Blocked submit keeps the general comments');
            $request->vars = ['formId' => $futureId, 'submitBlocked' => 1];
            $errorHtml = $this->render(new MonitoringPageHandler($plugin), $request, 'participationForm');
            $this->same(true, str_contains($errorHtml, 'What went well:'), 'Form names the missing required field as the form calls it');
            $this->same(true, str_contains($errorHtml, 'Typed before submitting'), 'Blocked submit restores the submission comment');
            DB::rollBack();
            $request->vars = $invalid;
            $request->vars['reviewers'] = $postedReviewers;
            $request->vars['formAction'] = 'save';
            $handler = new MonitoringPageHandler($plugin);
            $handler->saveParticipationForm([], $request);
            $futureSaved = $service->getForm($contextId, $futureId);
            $this->same('draft', $futureSaved['form']['status'], 'Configured form saves through actual handler');
            $this->same('0', $futureSaved['reviewers'][$reviewerId]['contribution_comments'], 'Required text accepts zero rather than treating it as empty');
            $this->same(2, count($futureSaved['reviewers']), 'All reviewers saved');
            $configuration->save($contextId, [], [['label' => 'Later option']], $text);
            $this->same($current, $configuration->forForm($contextId, $futureId), 'Saved draft also retains its original configuration');
            $request->vars = ['formId' => $futureId];
            $draftHtml = $this->render(new MonitoringPageHandler($plugin), $request, 'participationForm');
            $this->same(true, str_contains($draftHtml, 'Future option') && !str_contains($draftHtml, 'Later option'), 'Draft reload retains old options after settings change');
            DB::beginTransaction();
            $partial = $service->saveForm($contextId, $futureId, $editorId, [
                ['reviewer_user_id' => $reviewerId, 'attendance' => 'attended', 'contribution_comments' => ''],
            ], [], true);
            $this->same(true, $partial['missing'] !== [] && $partial['status'] === 'draft', 'Submitting one reviewer cannot bypass required fields');
            DB::rollBack();
            $request->vars = [
                'formId' => $futureId, 'reviewers' => [],
                'configurationToken' => ParticipationConfiguration::token($current),
            ];
            $errorHtml = $this->render(new MonitoringPageHandler($plugin), $request, 'saveParticipationForm');
            $this->same(true, str_contains($errorHtml, 'Include every reviewer'), 'Omitted reviewer rows cannot bypass required validation');
            $this->same($futureSaved, $service->getForm($contextId, $futureId), 'Rejected incomplete form preserves saved data');
            (new \APP\plugins\generic\groupReview\GroupReviewMigration())->up();
            $this->same($futureSaved, $service->getForm($contextId, $futureId), 'Repeated upgrade preserves all participation fields and snapshots');
            if (($this->argv[1] ?? '') === '--snapshot') {
                file_put_contents('/tmp/group-review-configuration.html', $settingsHtml);
                file_put_contents('/tmp/group-review-configured-form.html', $html);
                file_put_contents('/tmp/group-review-future-form.html', $futureHtml);
            }
            echo "Passed {$this->checks} participation configuration checks.\n";
        } finally {
            DB::rollBack();
            echo "Configuration fixtures rolled back.\n";
        }
    }
}

try {
    ob_start();
    (new ParticipationConfigurationTestTool($argv))->execute();
    ob_end_flush();
} catch (Throwable $error) {
    ob_end_flush();
    fwrite(STDERR, "FAIL {$error}\n");
    exit(1);
}
