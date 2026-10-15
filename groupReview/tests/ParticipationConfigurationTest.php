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
            $sessionId = $poll['sessionId'];
            $reviewerId = $fixture['users']['test_rgm_22']['id'];
            $editor = Repo::user()->getByUsername('test_editor_01');
            $editorId = (int) $editor->getId();
            $data = ['reviewer_user_id' => $reviewerId, 'attendance' => 'not_recorded'];
            $this->same(ParticipationConfiguration::defaults(), $configuration->get($contextId), 'Default journal settings');
            $this->same(7, count($configuration->get($contextId)['fields']), 'All seven configurable fields');
            $original = $configuration->get($contextId);
            foreach ([
                ['invalid', [['label' => 'Valid']]],
                [[[]], [['label' => 'Valid']]],
                [['other_contribution'], [['label' => 'Valid']]],
                [['general_comments'], [['label' => 'Valid']]],
                [[], []], [[], [['label' => '  ']]],
                [[], [['label' => 'Same'], ['label' => 'same']]],
                [[], [['label' => []]]], [[], [['label' => str_repeat('a', 151)]]],
            ] as [$fields, $options]) {
                $this->rejected(fn () => $configuration->save($contextId, $fields, $options), 'Invalid settings rejected');
                $this->same($original, $configuration->get($contextId), 'Invalid settings leave stored configuration unchanged');
            }
            $options = [];
            foreach ($original['options'] as $id => $label) {
                $options[] = ['id' => $id, 'label' => $label];
            }
            foreach (ParticipationConfiguration::FIELDS as $field => $label) {
                $configuration->save($contextId, [$field], $options);
                $before = $service->getForm($contextId, $sessionId);
                $this->rejected(fn () => $service->saveForm($contextId, $sessionId, $editorId, [$data]), "{$label} enforced on save");
                $this->same($before, $service->getForm($contextId, $sessionId), "{$label}: invalid save is atomic");
                $this->rejected(fn () => $service->saveForm($contextId, $sessionId, $editorId, [$data], [], true), "{$label} enforced on submit");
            }
            $custom = $configuration->save($contextId, ['shaping_feedback_types'], [['label' => 'Peer feedback']]);
            $key = array_key_first($custom['options']);
            $this->same(['Peer feedback'], array_values($configuration->get($contextId)['options']), 'New shaping option loads');
            $this->same(ParticipationConfiguration::defaults(), $configuration->get($contextId + 999999), 'Settings are journal scoped');
            $this->rejected(fn () => $service->saveForm($contextId, $sessionId, $editorId, [
                $data + ['shaping_feedback_types' => ['invented']],
            ]), 'Unconfigured shaping option rejected');
            $token = ParticipationConfiguration::token($custom);
            $renamed = $configuration->save($contextId, [], [['id' => $key, 'label' => 'Renamed feedback']]);
            $this->same(false, array_key_exists($key, $renamed['options']), 'Rename has distinct historical identity');
            $this->rejected(fn () => $service->saveForm($contextId, $sessionId, $editorId, [$data], [
                'configuration_token' => $token,
            ]), 'Settings changed while unsaved form was open');
            $custom = $configuration->save($contextId, [], [['label' => 'Peer feedback']]);
            $key = array_key_first($custom['options']);
            $data += [
                'shaping_feedback_types' => [$key], 'contribution_types' => ['discussion', 'writing'],
                'strengths' => 'Clear analysis', 'development_opportunities' => 'Time management',
                'other_contribution' => 'Other reviewer comment',
            ];
            $service->saveForm($contextId, $sessionId, $editorId, [$data], ['general_comments' => 'General editor comment'], true);
            $saved = $service->getForm($contextId, $sessionId);
            $this->same($custom, $configuration->forForm($contextId, $sessionId), 'First save snapshots configuration');
            $this->same(['discussion', 'writing'], $saved['reviewers'][$reviewerId]['contribution_types'], 'Contribution types round trip');
            $this->same('Clear analysis', $saved['reviewers'][$reviewerId]['strengths'], 'Strengths round trip');
            $this->same('Time management', $saved['reviewers'][$reviewerId]['development_opportunities'], 'Development opportunities round trip');
            $this->same('Other reviewer comment', $saved['reviewers'][$reviewerId]['other_contribution'], 'Other field retained');
            $this->same('General editor comment', $saved['form']['general_comments'], 'General comments retained');
            $configuration->save($contextId, array_keys(ParticipationConfiguration::FIELDS), [['label' => 'Future option']]);
            $this->same($saved, $service->getForm($contextId, $sessionId), 'Configuration changes do not modify submitted data');
            $this->same($custom, $configuration->forForm($contextId, $sessionId), 'Submitted form retains old options and requirements');
            $service->saveForm($contextId, $sessionId, $editorId, [$data], [], true);
            $this->same([$key], $service->getForm($contextId, $sessionId)['reviewers'][$reviewerId]['shaping_feedback_types'], 'Removed option can still be resubmitted on original form');
            $this->same('Peer feedback', (new ReviewerStatsService())->getContributionLabels($contextId, $reviewerId, null)[$key], 'Dashboard answer counts use historical option names');
            $this->same(1, (new ReviewerStatsService())->getContributionCounts($contextId, $reviewerId, null)[$key], 'Removed shaping option still counts in reviewer statistics');
            $history = (new ReviewerStatsService())->getHistory($contextId, $reviewerId, null);
            $entry = array_values(array_filter($history, fn ($entry) => $entry['submissionId'] === $poll['submissionId']))[0];
            $this->same('Peer feedback', $entry['participation']['shapingFeedbackLabels'][$key], 'History preserves removed option name');
            $this->same('Clear analysis', $entry['participation']['strengths'], 'History retains configurable field values');
            $this->same('Time management', $entry['participation']['developmentOpportunities'], 'History retains development opportunities');

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
            $settingsHtml = $this->render($handler, $request, 'participationSettings');
            $this->same(true, str_contains($settingsHtml, 'Future option'), 'Settings template loads current configuration');
            $request->vars = ['sessionId' => $sessionId];
            $html = $this->render($handler, $request, 'participationForm');
            $this->same(true, str_contains($html, 'Peer feedback') && !str_contains($html, 'Future option'), 'Saved form renders original options');
            $this->same(true, str_contains($html, 'Other comments') && str_contains($html, 'General comments for the editors'), 'Locked fields always rendered');
            $this->same(true, str_contains($html, 'Other reviewer comment') && str_contains($html, 'General editor comment'), 'Locked comments load after configuration changes');
            $this->same(true, str_contains($html, 'Clear analysis') && str_contains($html, 'Time management'), 'New fields render saved values');
            $legacy = array_values($fixture['polls'])[0]['sessionId'];
            $legacyBefore = $service->getForm($contextId, $legacy);
            $this->same(ParticipationConfiguration::defaults(true), $configuration->forForm($contextId, $legacy), 'Pre-upgrade forms use original field set');
            $request->vars = ['sessionId' => $legacy];
            $legacyHtml = $this->render($handler, $request, 'participationForm');
            $this->same(false, str_contains($legacyHtml, '[strengths]'), 'Legacy forms do not gain new fields');
            $this->same($legacyBefore, $service->getForm($contextId, $legacy), 'Loading legacy forms never rewrites records');
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
            $request->vars = ['requiredFields' => [], 'options' => [['label' => 'After handler save']]];
            $handler->saveParticipationSettings([], $request);
            $this->same(['saved' => 1], $request->redirectParams, 'Settings successful save redirects');
            $this->same(['After handler save'], array_values($configuration->get($contextId)['options']), 'Settings handler saves journal configuration');
            $request->vars = ['requiredFields' => [], 'options' => []];
            $errorHtml = $this->render($handler, $request, 'saveParticipationSettings');
            $this->same(true, str_contains($errorHtml, 'At least one shaping feedback option'), 'Invalid settings show explicit validation error');

            // A distinct future session picks up the current configuration.
            $futurePoll = (array) DB::table('group_review_sessions')->where('session_id', $sessionId)->first();
            unset($futurePoll['session_id']);
            $futureId = (int) DB::table('group_review_sessions')->insertGetId($futurePoll, 'session_id');
            foreach (DB::table('group_review_members')->where('session_id', $sessionId)->get() as $member) {
                $row = (array) $member;
                unset($row['member_id']);
                $row['session_id'] = $futureId;
                DB::table('group_review_members')->insert($row);
            }
            $current = $configuration->save($contextId, array_keys(ParticipationConfiguration::FIELDS), [['label' => 'Future option']]);
            $this->same($current, $configuration->forForm($contextId, $futureId), 'Future session uses new settings');
            $request->vars = ['sessionId' => $futureId];
            $futureHtml = $this->render($handler, $request, 'participationForm');
            $this->same(true, str_contains($futureHtml, 'Future option') && !str_contains($futureHtml, 'Peer feedback'), 'Future form renders updated options only');
            $this->same(true, str_contains($futureHtml, 'Required'), 'Future form displays required status');
            $postedReviewers = [];
            foreach (DB::table('group_review_members')->where('session_id', $futureId)->where('selected', true)->pluck('user_id') as $id) {
                $postedReviewers[$id] = [
                    'attendance' => 'attended', 'shapingFeedbackTypes' => [array_key_first($current['options'])],
                    'contributionTypes' => ['discussion'], 'strengths' => '0',
                    'developmentOpportunities' => 'More time', 'contributionComments' => 'Notes',
                    'shapingFeedbackComments' => 'Feedback', 'otherContribution' => 'Always available',
                ];
            }
            $request->vars = [
                'sessionId' => $futureId, 'reviewers' => $postedReviewers,
                'configurationToken' => ParticipationConfiguration::token($current),
                'generalComments' => 'Always present', 'formAction' => 'save',
            ];
            $invalid = $request->vars;
            $invalid['reviewers'][$reviewerId]['strengths'] = '  ';
            $request->vars = $invalid;
            $handler = new MonitoringPageHandler($plugin);
            $errorHtml = $this->render($handler, $request, 'saveParticipationForm');
            $this->same(true, str_contains($errorHtml, 'Strengths demonstrated is required.'), 'Form handler names missing required field');
            $this->same(true, str_contains($errorHtml, 'Always available') && str_contains($errorHtml, 'Always present'), 'Failed save preserves entered comments');
            $this->same(['form' => null, 'reviewers' => []], $service->getForm($contextId, $futureId), 'Handler failure saves nothing');
            $request->vars['reviewers'] = $postedReviewers;
            $handler = new MonitoringPageHandler($plugin);
            $handler->saveParticipationForm([], $request);
            $futureSaved = $service->getForm($contextId, $futureId);
            $this->same('draft', $futureSaved['form']['status'], 'Configured form saves through actual handler');
            $this->same('0', $futureSaved['reviewers'][$reviewerId]['strengths'], 'Required text accepts zero rather than treating it as empty');
            $this->same(2, count($futureSaved['reviewers']), 'All selected reviewers saved');
            $configuration->save($contextId, [], [['label' => 'Later option']]);
            $this->same($current, $configuration->forForm($contextId, $futureId), 'Saved draft also retains its original configuration');
            $request->vars = ['sessionId' => $futureId];
            $draftHtml = $this->render(new MonitoringPageHandler($plugin), $request, 'participationForm');
            $this->same(true, str_contains($draftHtml, 'Future option') && !str_contains($draftHtml, 'Later option'), 'Draft reload retains old options after settings change');
            $this->rejected(fn () => $service->saveForSession($contextId, $futureId, $editorId, [
                'reviewer_user_id' => $reviewerId, 'attendance' => 'attended', 'strengths' => '',
            ]), 'Single-reviewer JSON save cannot bypass required fields');
            $request->vars = [
                'sessionId' => $futureId, 'reviewers' => [],
                'configurationToken' => ParticipationConfiguration::token($current),
            ];
            $errorHtml = $this->render(new MonitoringPageHandler($plugin), $request, 'saveParticipationForm');
            $this->same(true, str_contains($errorHtml, 'Include every selected reviewer'), 'Omitted reviewer rows cannot bypass required validation');
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
