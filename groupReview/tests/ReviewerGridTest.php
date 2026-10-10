<?php

/**
 * php plugins/generic/groupReview/tests/ReviewerGridTest.php <journal path>
 * All fixtures, test journal and label changes are rolled back.
 */

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\groupReview\classes\ReviewerGridService;
use APP\plugins\generic\groupReview\GroupReviewPlugin;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\DB;
use PKP\cliTool\CommandLineTool;

require_once __DIR__ . '/MonitoringTest.php';
require_once __DIR__ . '/TestJournal.php';

class ReviewerGridTestTool extends CommandLineTool
{
    private int $checks = 0;

    private function check(bool $condition, string $name): void
    {
        if (!$condition) {
            throw new RuntimeException($name);
        }
        $this->checks++;
    }

    private function render($handler, $request, $manager, string $op = 'reviewers'): string
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
            $editor = Repo::user()->getByUsername('test_editor_01');
            $request = new MonitoringPageRequest($context, $editor);
            $grid = new ReviewerGridService();
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
            $this->check($grid->settings($request)['columns'] === array_keys(ReviewerGridService::COLUMNS), 'All columns visible by default');
            $request->vars = ['columns' => ['']];
            $this->check($grid->settings($request)['columns'] === [], 'No numeric columns is a valid choice');
            foreach ([
                ['search' => []], ['search' => str_repeat('a', 101)],
                ['labels' => 'novice'], ['labels' => ['unknown' => ['novice']]],
                ['labels' => ['expertise' => [['bad']]]], ['labels' => ['expertise' => ['unknown']]],
                ['ranges' => [] + ['unknown' => ['min' => '0']]],
                ['ranges' => ['invited' => ['min' => '-1']]],
                ['ranges' => ['completed' => ['min' => '5', 'max' => '2']]],
                ['ranges' => ['availablePercent' => ['max' => '101']]],
                ['ranges' => ['current' => ['min' => []]]],
                ['ranges' => ['current' => ['min' => '2.5']]],
                ['columns' => ['unknown']], ['columns' => 'name'],
                ['availableSubmissionId' => []], ['availableSubmissionId' => '0'],
            ] as $vars) {
                $request->vars = $vars;
                $settings = $grid->settings($request);
                $this->check((bool) $settings['errors'], 'Invalid filter has visible errors: ' . json_encode($vars));
                $this->check($grid->filter([['userId' => 1]], [], $settings) === [], 'Invalid filters never silently show all rows');
            }
            $base = [
                'userId' => 1, 'name' => 'Elena Reviewer', 'invited' => 4, 'available' => 2,
                'availablePercent' => 50, 'selected' => 1, 'selectedPercent' => 50,
                'attended' => 1, 'attendedPercent' => 100, 'completed' => 0, 'current' => 1,
            ];
            $labels = [1 => ['experience_level' => ['novice'], 'methodology' => ['quantitative'], 'expertise' => ['education', 'statistics']]];
            $request->vars = ['search' => 'ELENA', 'labels' => ['experience_level' => ['novice', 'experienced'], 'expertise' => ['education', 'statistics']]];
            $this->check(count($grid->filter([$base], $labels, $grid->settings($request))) === 1, 'Name is case insensitive, single labels OR and expertise AND');
            $labels[1]['expertise'] = ['education'];
            $this->check($grid->filter([$base], $labels, $grid->settings($request)) === [], 'Expertise requires every selected value');
            foreach (ReviewerGridService::COLUMNS as $column => $name) {
                $value = $base[$column];
                $request->vars = ['ranges' => [$column => ['min' => (string) $value, 'max' => (string) $value]]];
                $settings = $grid->settings($request);
                $this->check(count($grid->filter([$base], [], $settings)) === 1, "{$column}: inclusive equal bounds, including zero");
                $request->vars = ['ranges' => [$column => ['max' => (string) $value]]];
                $this->check(count($grid->filter([$base], [], $grid->settings($request))) === 1, "{$column}: blank minimum");
                $request->vars = ['ranges' => [$column => ['min' => (string) $value]]];
                $this->check(count($grid->filter([$base], [], $grid->settings($request))) === 1, "{$column}: blank maximum");
                $changed = array_merge($base, [$column => $value + 1]);
                $this->check($grid->filter([$changed], [], $settings) === [], "{$column}: outside range excluded");
                if (str_ends_with($column, 'Percent')) {
                    $this->check($grid->filter([array_merge($base, [$column => null])], [], $settings) === [], "{$column}: not applicable does not match zero");
                }
            }
            $request->vars = ['search' => 'not found'];
            $this->check($grid->filter([$base], [], $grid->settings($request)) === [], 'Unmatched name returns no rows');
            $request->vars = [];
            $this->check(count($grid->filter([$base], [], $grid->settings($request), [1])) === 1, 'Available reviewer included');
            $this->check($grid->filter([$base], [], $grid->settings($request), []) === [], 'Unknown poll availability yields no matches');
            $request->vars = ['year' => 'all', 'search' => 'test rgm 21', 'columns' => ['invited', 'completed'], 'ranges' => ['invited' => ['min' => '5', 'max' => '5']], 'sort' => 'completed', 'dir' => 'asc'];
            $html = $this->render($handler, $request, $manager);
            $rows = $manager->getTemplateVars('reviewers');
            $this->check(count($rows) === 1 && $rows[0]['userId'] === $fixture['users']['test_rgm_21']['id'], 'Handler applies combined search/year/range');
            $this->check($manager->getTemplateVars('totalReviewers') === 37, 'Unfiltered count excludes disabled and editor-only accounts');
            $this->check(str_contains($html, '1 of 37 reviewers'), 'Result count rendered: ' . substr($html, strpos($html, 'role="status"'), 200));
            $this->check(!str_contains($html, '>Available %</a>') && str_contains($html, '>Completed</a>'), 'Column selection changes headers');
            $params = $grid->parameters($grid->settings($request));
            $this->check($params['ranges']['invited']['min'] === 5, 'Normalized query keeps zero/nonzero boundaries');
            parse_str(parse_url($manager->getTemplateVars('sortUrls')['name'], PHP_URL_QUERY), $query);
            $this->check($query['search'] === 'test rgm 21' && $query['year'] === 'all' && $query['ranges']['invited']['max'] === '5', 'Sort links preserve every filter');
            $previous = $request->vars;
            parse_str(parse_url($manager->getTemplateVars('overviewUrl'), PHP_URL_QUERY), $overviewQuery);
            $request->vars = $overviewQuery;
            $this->render($handler, $request, $manager, 'overview');
            parse_str(parse_url($manager->getTemplateVars('reviewersUrl'), PHP_URL_QUERY), $tabQuery);
            $this->check($tabQuery['search'] === 'test rgm 21' && $tabQuery['columns'] === ['invited', 'completed'], 'Overview tab keeps grid filters and columns without filtering Overview stats');
            $request->vars = $previous;
            $this->render($handler, $request, $manager);
            parse_str(parse_url($rows[0]['url'], PHP_URL_QUERY), $query);
            $request->vars = $query;
            $this->render($handler, $request, $manager, 'reviewer');
            parse_str(parse_url($manager->getTemplateVars('backUrl'), PHP_URL_QUERY), $back);
            $this->check($back['search'] === 'test rgm 21' && $back['columns'] === ['invited', 'completed'], 'Reviewer Back preserves filters and columns');
            $this->check($back['sort'] === 'completed' && $back['dir'] === 'asc', 'Reviewer Back preserves sorting');
            $request->post = $request->csrf = true;
            $request->vars += ['experience_level' => 'novice', 'methodology' => 'quantitative', 'expertise' => ['education']];
            $handler->saveLabels([], $request);
            parse_str(http_build_query($request->redirectParams), $redirect);
            $this->check($redirect['search'] === 'test rgm 21' && $redirect['columns'] === ['invited', 'completed'], 'Label save retains grid state');
            $request->post = false;
            $request->vars = ['year' => 'all', 'columns' => ['']];
            $html = $this->render($handler, $request, $manager);
            $this->check(!str_contains($html, 'class="grpMon__sortLink" href="' . htmlspecialchars($manager->getTemplateVars('sortUrls')['completed'], ENT_QUOTES) . '"'), 'All numeric columns can be hidden');
            $open = array_values($fixture['polls'])[6];
            $ids = $grid->availableReviewerIds($contextId, $open['submissionId']);
            $this->check($ids === [$fixture['users']['test_rgm_21']['id']], 'Only available respondents in current open poll');
            $this->check($grid->availableReviewerIds($contextId + 999, $open['submissionId']) === [], 'Availability journal scoped');
            $this->check($grid->availableReviewerIds($contextId, PHP_INT_MAX) === [], 'Unknown submission has no matches');
            $this->check($grid->availableReviewerIds($contextId, array_values($fixture['polls'])[0]['submissionId']) === [], 'Finalized polls excluded');
            $request->vars = ['year' => (string) (gmdate('Y') - 1), 'availableSubmissionId' => (string) $open['submissionId']];
            $this->render($handler, $request, $manager);
            $rows = $manager->getTemplateVars('reviewers');
            $this->check(count($rows) === 1 && $rows[0]['userId'] === $ids[0], 'Open poll filter ignores year but uses selected-year counts');
            DB::table('group_review_sessions')->where('session_id', $open['sessionId'])->update(['deadline_utc' => '2000-01-01 00:00:00']);
            $this->check($grid->availableReviewerIds($contextId, $open['submissionId']) === [], 'Deadline-expired open poll excluded');
            $request->vars = ['ranges' => ['completed' => ['min' => '2', 'max' => '1']]];
            $html = $this->render($handler, $request, $manager);
            $this->check(str_contains($html, 'role="alert"') && $manager->getTemplateVars('reviewers') === [], 'Invalid range visibly rejected in template');
            echo "Passed {$this->checks} reviewer grid checks.\n";
        } finally {
            DB::rollBack();
            echo "Filtering fixtures rolled back.\n";
        }
    }
}

try {
    ob_start();
    (new ReviewerGridTestTool($argv))->execute();
    ob_end_flush();
} catch (Throwable $error) {
    ob_end_flush();
    fwrite(STDERR, "FAIL {$error}\n");
    exit(1);
}
