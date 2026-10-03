<?php

/**
 * Installed OJS integration tests for the current form-level participation API.
 * php plugins/generic/groupReview/tests/ParticipationFormTest.php <journal path>
 */

use APP\core\Application;
use APP\plugins\generic\groupReview\classes\ParticipationService;
use Illuminate\Support\Facades\DB;
use PKP\cliTool\CommandLineTool;

require_once dirname(__FILE__, 5) . '/tools/bootstrap.php';
require_once __DIR__ . '/testData.php';
require_once __DIR__ . '/TestJournal.php';

class ParticipationFormTestTool extends CommandLineTool
{
    private int $checks = 0;

    private function same($expected, $actual, string $name): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException("{$name}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
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
        throw new RuntimeException("{$name}: expected InvalidArgumentException");
    }

    public function execute()
    {
        $path = $this->argv[0] ?? '';
        DB::beginTransaction();
        try {
            $path = groupReviewTestJournal($path);
            $contextId = (int) Application::getContextDAO()->getByPath($path)->getId();
            $seed = new GroupReviewTestDataTool([__FILE__, $path, 'seed']);
            $seed->execute();
            $fixture = $seed->getFixture();
            // Fixed submission 002 is finalized, has selected members and no form.
            $poll = array_values($fixture['polls'])[2];
            $sessionId = $poll['sessionId'];
            $reviewerId = $fixture['users']['test_rgm_22']['id'];
            $otherId = $fixture['users']['test_rgm_23']['id'];
            $editorId = $fixture['users']['test_editor_01']['id'];
            $leaderId = $fixture['users'][$poll['leader']]['id'];
            $service = new ParticipationService();
            $this->same(['form' => null, 'reviewers' => []], $service->getForm($contextId, $sessionId), 'New form is empty');
            $data = [
                'reviewer_user_id' => $reviewerId,
                'attendance' => 'attended',
                'shaping_feedback_types' => ['uploaded_notes', 'uploaded_notes'],
                'contribution_comments' => '  Helpful notes  ',
                // The service must ignore request-supplied session metadata.
                'session_id' => PHP_INT_MAX, 'submission_id' => PHP_INT_MAX,
                'review_round_id' => PHP_INT_MAX, 'leader_user_id' => PHP_INT_MAX,
            ];
            $result = $service->saveForm($contextId, $sessionId, $editorId, [$data], ['general_comments' => '  Group comments  ']);
            $this->same('draft', $result['status'], 'First save creates draft');
            $this->same(true, $result['reviewers'][$reviewerId]['created'], 'First reviewer is inserted');
            $form = $service->getForm($contextId, $sessionId);
            $row = $form['reviewers'][$reviewerId];
            foreach (['session_id' => $sessionId, 'submission_id' => $poll['submissionId'], 'leader_user_id' => $leaderId] as $key => $value) {
                $this->same($value, (int) $row[$key], "Metadata derived from session: {$key}");
            }
            $this->same((int) DB::table('group_review_sessions')->where('session_id', $sessionId)->value('review_round_id'), (int) $row['review_round_id'], 'Review round derived from session');
            $this->same(['uploaded_notes'], $row['shaping_feedback_types'], 'Contributions normalized and hydrated');
            $this->same('Helpful notes', $row['contribution_comments'], 'Reviewer comments trimmed');
            $this->same('Group comments', $form['form']['general_comments'], 'General comments trimmed');
            $this->same($editorId, (int) $form['form']['updated_by'], 'Saver recorded');
            $this->same(null, $form['form']['submitted_at'], 'Draft has no submission time');
            $participationId = (int) $row['participation_id'];
            $data['attendance'] = 'other';
            $data['attendance_other'] = '  Joined late  ';
            $saved = $service->saveForSession($contextId, $sessionId, $leaderId, $data + ['status' => 'submitted']);
            $this->same(false, $saved['created'], 'Existing reviewer updated');
            $this->same($participationId, $saved['participation_id'], 'Upsert retains identifier');
            $this->same('submitted', $saved['status'], 'Endpoint submits form');
            $form = $service->getForm($contextId, $sessionId);
            $this->same($leaderId, (int) $form['form']['submitted_by'], 'Submitter recorded');
            $this->same(true, $form['form']['submitted_at'] !== null, 'Submission time recorded');
            $this->same('Joined late', $form['reviewers'][$reviewerId]['attendance_other'], 'Other attendance details saved');
            $saved = $service->saveForSession($contextId, $sessionId, $editorId, $data);
            $this->same('submitted', $saved['status'], 'Editing never reverts a submitted form to draft');
            $this->same('Group comments', $service->getForm($contextId, $sessionId)['form']['general_comments'], 'Omitted general comments preserved');
            $service->saveForm($contextId, $sessionId, $editorId, [$data], [
                'general_comments' => null, 'submission_comment' => '  Resubmitted  ',
            ], true);
            $form = $service->getForm($contextId, $sessionId);
            $this->same(null, $form['form']['general_comments'], 'Explicit null clears general comments');
            $this->same('Resubmitted', $form['form']['submission_comment'], 'Resubmission comment saved');
            $this->same($editorId, (int) $form['form']['submitted_by'], 'Resubmission updates submitter');
            $before = $service->getForm($contextId, $sessionId);
            $this->rejected(fn () => $service->saveForm($contextId, $sessionId, $editorId, [
                array_merge($data, ['contribution_comments' => 'Must roll back']),
                ['reviewer_user_id' => $fixture['users']['test_rgm_25']['id'], 'attendance' => 'attended'],
            ]), 'Unselected reviewer rejected');
            $this->same($before, $service->getForm($contextId, $sessionId), 'Invalid batch rolls back every reviewer');
            $this->rejected(fn () => $service->saveForm($contextId, $sessionId, $editorId, [$data], [
                'general_comments' => [],
            ]), 'Invalid general comments rejected');
            $this->same($before, $service->getForm($contextId, $sessionId), 'Invalid form metadata rolls back reviewer edits');
            $this->rejected(fn () => $service->saveForm($contextId + 1000, $sessionId, $editorId, [$data]), 'Session scoped to journal');
            $this->rejected(fn () => $service->saveForm($contextId, PHP_INT_MAX, $editorId, [$data]), 'Unknown session rejected');
            $this->rejected(fn () => $service->saveForSession($contextId, $sessionId, $editorId, $data + ['status' => 'published']), 'Invalid status rejected');
            $this->same(['form' => null, 'reviewers' => []], $service->getForm($contextId + 1000, $sessionId), 'Form reads scoped to journal');
            $this->same(null, $service->get($contextId + 1000, $participationId), 'Single record scoped to journal');
            $this->same(false, $service->update($contextId + 1000, $participationId, $data), 'Wrong-journal update denied');
            $this->same(false, $service->delete($contextId + 1000, $participationId), 'Wrong-journal delete denied');
            $service->saveForm($contextId, $sessionId, $editorId, [
                ['reviewer_user_id' => $otherId, 'attendance' => 'not_recorded'],
            ]);
            $this->same(2, count($service->getForSession($contextId, $sessionId)), 'Multiple reviewers retained');
            $ids = $service->getFormSessionIds($contextId, $poll['submissionId'], $leaderId);
            $this->same([$sessionId], $ids, 'Finalized session found for leader');
            $this->same([], $service->getFormSessionIds($contextId, $poll['submissionId'], $editorId), 'Other leader excluded');
            $this->same(true, $service->delete($contextId, $participationId), 'Record deleted');
            $this->same(null, $service->get($contextId, $participationId), 'Deleted record absent');
            echo "Passed {$this->checks} participation form checks.\n";
        } finally {
            DB::rollBack();
            echo "Participation fixtures rolled back.\n";
        }
    }
}

try {
    (new ParticipationFormTestTool($argv))->execute();
} catch (Throwable $error) {
    fwrite(STDERR, "FAIL {$error->getMessage()}\n");
    exit(1);
}
