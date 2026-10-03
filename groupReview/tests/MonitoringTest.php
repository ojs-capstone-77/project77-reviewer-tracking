<?php

/**
 * Run in the installed OJS container:
 * php plugins/generic/groupReview/tests/MonitoringTest.php <journal path>
 *
 * Test data and label changes are rolled back, leaving existing journal data intact.
 */

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\groupReview\classes\GroupReviewService;
use APP\plugins\generic\groupReview\classes\ParticipationService;
use APP\plugins\generic\groupReview\classes\ReviewerLabelService;
use APP\plugins\generic\groupReview\classes\ReviewerStatsService;
use APP\plugins\generic\groupReview\classes\security\authorization\EditorRequiredPolicy;
use APP\plugins\generic\groupReview\GroupReviewPlugin;
use APP\plugins\generic\groupReview\pages\groupReview\GroupReviewHandler;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\DB;
use PKP\cliTool\CommandLineTool;
use PKP\core\Registry;
use PKP\security\authorization\AuthorizationPolicy;
use PKP\security\Role;

require_once dirname(__FILE__, 5) . '/tools/bootstrap.php';
require_once __DIR__ . '/testData.php';

class MonitoringPageRequest extends \APP\core\Request
{
    public array $vars = [];
    public bool $post = false;
    public bool $csrf = false;
    public ?array $redirectParams = null;

    public function __construct(private \APP\journal\Journal $journal, private \PKP\user\User $editor)
    {
        $this->setRouter(Application::get()->getRequest()->getRouter());
    }

    public function getContext(): ?\APP\journal\Journal
    {
        return $this->journal;
    }

    public function getUser(): ?\PKP\user\User
    {
        return $this->editor;
    }

    public function getUserVar($key)
    {
        return $this->vars[$key] ?? null;
    }

    public function isPost()
    {
        return $this->post;
    }

    public function checkCSRF()
    {
        return $this->csrf;
    }

    public function redirect($context = null, $page = null, $op = null, $path = null, $params = null, $anchor = null)
    {
        $this->redirectParams = $params;
    }
}

class MonitoringPageHandler extends GroupReviewHandler
{
    public function setupTemplate($request)
    {
        TemplateManager::getManager($request)->assign([
            'pageComponent' => 'Page',
            'userRoles' => [Role::ROLE_ID_MANAGER],
        ]);
    }
}

class MonitoringTestTool extends CommandLineTool
{
    private int $checks = 0;

    private function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
        $this->checks++;
    }

    private function invoke(GroupReviewHandler $handler, string $method, ...$args)
    {
        return (new ReflectionMethod($handler, $method))->invoke($handler, ...$args);
    }

    public function execute()
    {
        $path = $this->argv[0] ?? '';
        $context = Application::getContextDAO()->getByPath($path);
        if (!$context) {
            throw new RuntimeException('Provide an existing journal path.');
        }
        $contextId = (int) $context->getId();
        $before = DB::table('users')->count();
        DB::beginTransaction();
        try {
            (new GroupReviewTestDataTool([__FILE__, $path, 'seed']))->execute();
            $handler = new GroupReviewHandler(new GroupReviewPlugin());
            $request = new class {
                public array $vars = [];
                public function getUserVar($key)
                {
                    return $this->vars[$key] ?? null;
                }
                public function getRouter()
                {
                    return new class {
                        public function url($request, $context, $page, $op, $args = null, $params = []): string
                        {
                            return $op . '?' . http_build_query($params);
                        }
                    };
                }
            };
            $year = (int) gmdate('Y');
            foreach ([null, '', 'garbage', [], '2026abc', '9999'] as $value) {
                $request->vars = ['year' => $value];
                $this->check($this->invoke($handler, 'monitoringYear', $request, [$year - 1]) === $year, 'Invalid year must default to current year');
            }
            $request->vars = ['year' => 'all'];
            $this->check($this->invoke($handler, 'monitoringYear', $request, [$year]) === null, 'All time removes filter');
            $request->vars = ['year' => (string) ($year - 1)];
            $this->check($this->invoke($handler, 'monitoringYear', $request, [$year - 1]) === $year - 1, 'Valid year retained');
            $options = $this->invoke($handler, 'monitoringYearOptions', [$year - 1]);
            $this->check($options[0]['value'] === 'all' && $options[1]['value'] === $year && isset($options[1]['label']), 'Year option contract');
            foreach ([[], ['sort' => []], ['sort' => 'invalid', 'dir' => 'asc'], ['sort' => 'name', 'dir' => 'invalid']] as $vars) {
                $request->vars = $vars;
                $this->check($this->invoke($handler, 'monitoringSortAndDir', $request) === ['completed', 'desc'], 'Default sorting must be Completed highest first');
            }
            $request->vars = ['sort' => 'name', 'dir' => 'asc'];
            $this->check($this->invoke($handler, 'monitoringSortAndDir', $request) === ['name', 'asc'], 'Valid sort retained');
            $urls = $this->invoke($handler, 'monitoringSortUrls', $request, null, 'completed', 'desc');
            $this->check(str_contains($urls['completed'], 'dir=asc') && str_contains($urls['completed'], 'year=all'), 'Sort links reverse direction and retain year');
            foreach ([[], '1abc', '-1', '0'] as $id) {
                $request->vars = ['reviewerId' => $id];
                $this->check($this->invoke($handler, 'monitoringReviewerId', $request) === 0, 'Invalid reviewer ID rejected');
            }
            $this->check($this->invoke($handler, 'monitoringPercent', 2, 3) === 67, 'Percentages round to whole numbers');
            $this->check($this->invoke($handler, 'monitoringPercent', 0, 0) === null, 'Zero denominator has no percentage');

            $service = new GroupReviewService();
            foreach (['test_editor_01' => true, 'test_editor_03' => true, 'test_rgl_01' => false, 'test_rgm_21' => false] as $username => $allowed) {
                $user = Repo::user()->getByUsername($username);
                $this->check($service->isJournalEditorUser($contextId, (int) $user->getId()) === $allowed, "Editor access: {$username}");
                $accessRequest = new class($context, $user) {
                    public function __construct(private $context, private $user)
                    {
                    }
                    public function getContext()
                    {
                        return $this->context;
                    }
                    public function getUser()
                    {
                        return $this->user;
                    }
                };
                $expected = $allowed ? AuthorizationPolicy::AUTHORIZATION_PERMIT : AuthorizationPolicy::AUTHORIZATION_DENY;
                $this->check((new EditorRequiredPolicy($accessRequest))->effect() === $expected, "Authorization policy: {$username}");
            }
            foreach (['overview', 'reviewers', 'reviewer', 'saveLabels'] as $op) {
                $this->check(in_array($op, $handler->getRoleAssignment(Role::ROLE_ID_MANAGER), true), "Manager route: {$op}");
                $this->check(!in_array($op, $handler->getRoleAssignment(Role::ROLE_ID_SUB_EDITOR), true), "RGL/RGM route denied: {$op}");
                $this->check(!in_array($op, $handler->getRoleAssignment(Role::ROLE_ID_AUTHOR) ?? [], true), "Author route denied: {$op}");
            }

            $stats = new ReviewerStatsService();
            $rows = $stats->getReviewerRows($contextId, $year);
            $this->check(count($rows) >= 39, 'Current and historical reviewers included');
            $disabled = Repo::user()->getByUsername('test_rgm_29', true);
            $this->check($stats->getReviewerRow($contextId, (int) $disabled->getId(), $year) === null, 'Disabled reviewer not found');
            $this->check($stats->getReviewerRow($contextId, PHP_INT_MAX, $year) === null, 'Unknown reviewer not found');
            foreach ($rows as $row) {
                $this->check($row['invited'] === $row['available'] + $row['notAvailable'] + $row['noResponse'], 'Invitation partition');
                $this->check($row['available'] === $row['selected'] + $row['notSelected'], 'Availability partition');
                $all = $stats->getReviewerRow($contextId, $row['userId'], null);
                $this->check($row['current'] === $all['current'] && $row['reviewerSince'] === $all['reviewerSince'], 'Current and since ignore year');
                $tableRow = $this->invoke($handler, 'reviewerTableRow', $row, [], $request, $year);
                $this->check(str_contains($tableRow['url'], "year={$year}") && array_key_exists('attendedPercent', $tableRow), 'Table row contract and View year');
            }
            $overview = $stats->getOverview($contextId, $year);
            $a = $overview['activity'];
            $this->check($a['invited'] === $a['participated'] + $a['notSelected'] + $a['inactive'], 'Overview invitation partition');
            $reviewer = Repo::user()->getByUsername('test_rgm_21');
            $reviewerId = (int) $reviewer->getId();
            $counts = $stats->getAttendanceCounts($contextId, $reviewerId, null);
            $this->check(!isset($counts[ParticipationService::ATTENDANCE_NOT_RECORDED]), 'Not recorded omitted');
            $history = $stats->getHistory($contextId, $reviewerId, null);
            $this->check(count($history) > 0, 'Selected reviewer has history');
            $dates = array_column($history, 'date');
            $sorted = $dates;
            rsort($sorted);
            $this->check($dates === $sorted, 'History newest first');
            $formatted = $this->invoke($handler, 'reviewerHistory', $history, $request);
            $this->check(isset($formatted[0]['answers'], $formatted[0]['submissionId'], $formatted[0]['leaderName']), 'History template contract');
            $this->check(count($stats->getContributionCounts($contextId, $reviewerId, null)) > 0, 'Contribution counts populated');

            $labels = new ReviewerLabelService();
            $editorId = (int) Repo::user()->getByUsername('test_editor_01')->getId();
            $values = ['experience_level' => ['experienced'], 'methodology' => ['qualitative'], 'expertise' => ['statistics']];
            $labels->setLabels($contextId, $reviewerId, $values, 'Monitoring test', $editorId);
            $saved = $labels->getLabels($contextId, [$reviewerId])[$reviewerId];
            $this->check($saved === $values, 'Saved labels immediately readable');
            $historyBefore = count($labels->getHistory($contextId, $reviewerId));
            foreach ([
                ['unknown' => ['x']],
                ['experience_level' => ['invalid']],
                ['experience_level' => ['novice', 'experienced']],
                ['expertise' => [['statistics']]],
                ['expertise' => 'statistics'],
            ] as $invalid) {
                try {
                    $labels->setLabels($contextId, $reviewerId, $invalid, null, $editorId);
                    throw new RuntimeException('Invalid label input was accepted');
                } catch (InvalidArgumentException $e) {
                    $this->check($labels->getLabels($contextId, [$reviewerId])[$reviewerId] === $saved, 'Invalid input leaves labels unchanged');
                    $this->check(count($labels->getHistory($contextId, $reviewerId)) === $historyBefore, 'Invalid input leaves history unchanged');
                }
            }

            $plugin = new class extends GroupReviewPlugin {
                public function getTemplateResource($template = null, $inCore = false)
                {
                    return 'file:' . dirname(__DIR__) . '/templates/' . $template;
                }
            };
            $editor = Repo::user()->getByUsername('test_editor_01');
            $pageRequest = new MonitoringPageRequest($context, $editor);
            $pageHandler = new MonitoringPageHandler($plugin);
            $manager = TemplateManager::getManager($pageRequest);
            $manager->unregisterPlugin('function', 'csrf');
            $manager->registerPlugin('function', 'csrf', fn () => '');
            foreach (['overview', 'reviewers', 'reviewer'] as $op) {
                $pageRequest->vars = ['year' => 'all', 'reviewerId' => (string) $reviewerId];
                ob_start();
                try {
                    $pageHandler->$op([], $pageRequest);
                    $html = ob_get_contents();
                } finally {
                    ob_end_clean();
                }
                $this->check(str_contains($html, 'grpMon__page'), "{$op} template renders");
                foreach (['year', 'yearOptions', 'overviewUrl', 'reviewersUrl'] as $variable) {
                    $this->check($manager->getTemplateVars($variable) !== null, "{$op} assigns {$variable}");
                }
                $this->check($manager->getTemplateVars('year') === 'all', "{$op} all-time contract");
            }
            foreach (['reviewer', 'stats', 'attendanceCounts', 'contributionCounts', 'history', 'labelOptions', 'labelValues', 'labelHistory', 'saveLabelsUrl', 'backUrl'] as $variable) {
                $this->check($manager->getTemplateVars($variable) !== null, "Reviewer assigns {$variable}");
            }
            $pageRequest->vars = ['year' => 'invalid', 'sort' => 'invalid', 'dir' => 'asc'];
            ob_start();
            try {
                $pageHandler->reviewers([], $pageRequest);
            } finally {
                ob_end_clean();
            }
            $this->check($manager->getTemplateVars('year') === $year, 'Page falls back to current year');
            $this->check($manager->getTemplateVars('sort') === 'completed' && $manager->getTemplateVars('dir') === 'desc', 'Page falls back to Completed highest first');
            foreach ($manager->getTemplateVars('reviewers') as $tableRow) {
                foreach (['userId', 'name', 'labelsText', 'completed', 'current', 'attended', 'attendedPercent', 'invited', 'available', 'availablePercent', 'selected', 'selectedPercent', 'url'] as $field) {
                    $this->check(array_key_exists($field, $tableRow), "Reviewers row includes {$field}");
                }
            }
            $pageRequest->post = true;
            $pageRequest->csrf = true;
            $pageRequest->vars = [
                'reviewerId' => (string) $reviewerId,
                'year' => 'all',
                'experience_level' => 'novice',
                'methodology' => 'quantitative',
                'expertise' => ['education', 'statistics'],
                'note' => 'Saved through handler',
            ];
            $pageHandler->saveLabels([], $pageRequest);
            $this->check($pageRequest->redirectParams === ['reviewerId' => $reviewerId, 'year' => 'all'], 'Successful save redirects to same reviewer/year');
            $afterSave = $labels->getLabels($contextId, [$reviewerId])[$reviewerId];
            $this->check($afterSave['experience_level'] === ['novice'], 'Handler persists labels');
            ob_start();
            try {
                $pageHandler->reviewer([], $pageRequest);
                $html = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            $this->check($manager->getTemplateVars('labelValues') === $afterSave, 'Reviewer page refreshes saved labels');
            $this->check(str_contains($html, 'Novice'), 'Saved labels render on reviewer page');
            $pageRequest->vars['expertise'] = 'statistics';
            $pageHandler->saveLabels([], $pageRequest);
            $this->check(($pageRequest->redirectParams['labelSaveError'] ?? null) === 1, 'Malformed form displays save error');
            $this->check($labels->getLabels($contextId, [$reviewerId])[$reviewerId] === $afterSave, 'Malformed form does not save');
            $pageRequest->vars['expertise'] = ['education'];
            $pageRequest->vars['experience_level'] = 'experienced';
            $pageRequest->csrf = false;
            ob_start();
            try {
                $pageHandler->saveLabels([], $pageRequest);
                $html = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            $this->check(str_contains($html, __('plugins.generic.groupReview.error.invalidRequest')), 'Invalid CSRF is reported');
            $this->check($labels->getLabels($contextId, [$reviewerId])[$reviewerId] === $afterSave, 'Invalid CSRF does not save');
            $pageRequest->csrf = true;
            $pageRequest->vars['reviewerId'] = (string) PHP_INT_MAX;
            ob_start();
            try {
                $pageHandler->saveLabels([], $pageRequest);
                $html = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            $this->check(str_contains($html, __('plugins.generic.groupReview.error.reviewerNotFound')), 'Unknown reviewer save shows not found');
            ob_start();
            try {
                $pageHandler->reviewer([], $pageRequest);
                $html = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            $this->check(str_contains($html, __('plugins.generic.groupReview.error.reviewerNotFound')), 'Unknown reviewer page shows not found');
            $originalRequest = Application::get()->getRequest();
            $context->setData('groupReviewEnabled', true);
            $menuPlugin = new class extends GroupReviewPlugin {
                public function getEnabled($contextId = null)
                {
                    return true;
                }
            };
            try {
                Registry::set('request', $pageRequest);
                $manager->setState(['menu' => ['submissions' => ['name' => 'Submissions']]]);
                $menuPlugin->addMonitoringMenuItem('TemplateManager::setupBackendPage', []);
                $menu = $manager->getState('menu');
                $this->check(isset($menu['submissions'], $menu['groupReview']), 'Editor sidebar adds Group Review and preserves other items');
                $this->check(str_contains($menu['groupReview']['url'], 'overview'), 'Sidebar opens Overview');
                foreach (['test_rgl_01', 'test_rgm_21'] as $username) {
                    $deniedRequest = new MonitoringPageRequest($context, Repo::user()->getByUsername($username));
                    Registry::set('request', $deniedRequest);
                    $manager->setState(['menu' => []]);
                    $menuPlugin->addMonitoringMenuItem('TemplateManager::setupBackendPage', []);
                    $this->check(!isset($manager->getState('menu')['groupReview']), "Sidebar hidden for {$username}");
                }
            } finally {
                Registry::set('request', $originalRequest);
            }
        } finally {
            DB::rollBack();
        }
        $this->check(DB::table('users')->count() === $before, 'Fixtures rolled back');
        echo "Passed {$this->checks} monitoring checks; test data rolled back.\n";
    }
}

$tool = new MonitoringTestTool($argv ?? []);
try {
    $tool->execute();
} catch (Throwable $e) {
    fwrite(STDERR, $e . "\n");
    exit(1);
}
