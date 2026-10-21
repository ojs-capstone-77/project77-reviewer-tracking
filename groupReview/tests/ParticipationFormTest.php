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
            $roundId = (int) DB::table('group_review_sessions')->where('session_id', $poll['sessionId'])->value('review_round_id');
            $reviewerId = $fixture['users']['test_rgm_22']['id'];
            $otherId = $fixture['users']['test_rgm_23']['id'];
            $editorId = $fixture['users']['test_editor_01']['id'];
            $leaderId = $fixture['users'][$poll['leader']]['id'];
            $service = new ParticipationService();
            $formId = $service->createForm($contextId, $poll['submissionId'], $roundId, $leaderId, [$reviewerId, $otherId, $reviewerId]);
            $this->same($formId, $service->getActiveFormId($contextId, $poll['submissionId'], $roundId), 'Created form is the round\'s form');
            $this->same([$reviewerId, $otherId], json_decode($service->getFormRecord($contextId, $formId)['reviewer_ids'], true), 'Reviewers kept once each');
            $this->rejected(fn () => $service->createForm($contextId, $poll['submissionId'], $roundId, $leaderId, [$reviewerId]), 'One form per round');
            $this->same([], $service->getForm($contextId, $formId)['reviewers'], 'New form has no answers');
            $data = [
                'reviewer_user_id' => $reviewerId,
                'attendance' => 'attended',
                'shaping_feedback_types' => ['uploaded_notes', 'uploaded_notes'],
                'contribution_comments' => '  Helpful notes  ',
                // The service must ignore request-supplied form metadata.
                'form_id' => PHP_INT_MAX, 'submission_id' => PHP_INT_MAX,
                'review_round_id' => PHP_INT_MAX, 'leader_user_id' => PHP_INT_MAX,
            ];
            $result = $service->saveForm($contextId, $formId, $editorId, [$data], ['general_comments' => '  Group comments  ']);
            $this->same('draft', $result['status'], 'First save keeps draft');
            $this->same(true, $result['reviewers'][$reviewerId]['created'], 'First reviewer is inserted');
            $form = $service->getForm($contextId, $formId);
            $row = $form['reviewers'][$reviewerId];
            foreach (['form_id' => $formId, 'submission_id' => $poll['submissionId'], 'review_round_id' => $roundId, 'leader_user_id' => $leaderId] as $key => $value) {
                $this->same($value, (int) $row[$key], "Metadata derived from form: {$key}");
            }
            $this->same(['uploaded_notes'], $row['shaping_feedback_types'], 'Contributions normalized and hydrated');
            $this->same('Helpful notes', $row['contribution_comments'], 'Reviewer comments trimmed');
            $this->same('Group comments', $form['form']['general_comments'], 'General comments trimmed');
            $this->same($editorId, (int) $form['form']['updated_by'], 'Saver recorded');
            $this->same(null, $form['form']['submitted_at'], 'Draft has no submission time');
            $participationId = (int) $row['participation_id'];
            $data['attendance'] = 'other';
            $data['attendance_other'] = '  Joined late  ';
            $saved = $service->saveForm($contextId, $formId, $leaderId, [$data], [], true);
            $this->same(false, $saved['reviewers'][$reviewerId]['created'], 'Existing reviewer updated');
            $this->same($participationId, $saved['reviewers'][$reviewerId]['participation_id'], 'Upsert retains identifier');
            $this->same('submitted', $saved['status'], 'Submit marks the form submitted');
            $form = $service->getForm($contextId, $formId);
            $this->same($leaderId, (int) $form['form']['submitted_by'], 'Submitter recorded');
            $this->same(true, $form['form']['submitted_at'] !== null, 'Submission time recorded');
            $this->same('Joined late', $form['reviewers'][$reviewerId]['attendance_other'], 'Other attendance details saved');
            $saved = $service->saveForm($contextId, $formId, $editorId, [$data]);
            $this->same('submitted', $saved['status'], 'Editing never reverts a submitted form to draft');
            $this->same('Group comments', $service->getForm($contextId, $formId)['form']['general_comments'], 'Omitted general comments preserved');
            $service->saveForm($contextId, $formId, $editorId, [$data], [
                'general_comments' => null, 'submission_comment' => '  Resubmitted  ',
            ], true);
            $form = $service->getForm($contextId, $formId);
            $this->same(null, $form['form']['general_comments'], 'Explicit null clears general comments');
            $this->same('Resubmitted', $form['form']['submission_comment'], 'Resubmission comment saved');
            $this->same($editorId, (int) $form['form']['submitted_by'], 'Resubmission updates submitter');
            $this->same([null, 'Resubmitted'], array_column(json_decode($form['form']['submissions'], true), 'comment'), 'Each submission kept with its comment');
            $before = $service->getForm($contextId, $formId);
            $this->rejected(fn () => $service->saveForm($contextId, $formId, $editorId, [
                array_merge($data, ['contribution_comments' => 'Must roll back']),
                ['reviewer_user_id' => $fixture['users']['test_rgm_25']['id'], 'attendance' => 'attended'],
            ]), 'Reviewer not in the form rejected');
            $this->same($before, $service->getForm($contextId, $formId), 'Invalid batch rolls back every reviewer');
            $this->rejected(fn () => $service->saveForm($contextId, $formId, $editorId, [$data], [
                'general_comments' => [],
            ]), 'Invalid general comments rejected');
            $this->same($before, $service->getForm($contextId, $formId), 'Invalid form metadata rolls back reviewer edits');
            $this->rejected(fn () => $service->saveForm($contextId + 1000, $formId, $editorId, [$data]), 'Form scoped to journal');
            $this->rejected(fn () => $service->saveForm($contextId, PHP_INT_MAX, $editorId, [$data]), 'Unknown form rejected');
            $this->same(['form' => null, 'reviewers' => []], $service->getForm($contextId + 1000, $formId), 'Form reads scoped to journal');
            $this->same(null, $service->get($contextId + 1000, $participationId), 'Single record scoped to journal');
            $this->same(false, $service->update($contextId + 1000, $participationId, $data), 'Wrong-journal update denied');
            $this->same(false, $service->delete($contextId + 1000, $participationId), 'Wrong-journal delete denied');
            $service->saveForm($contextId, $formId, $editorId, [
                ['reviewer_user_id' => $otherId, 'attendance' => 'not_recorded'],
            ]);
            $this->same(2, count($service->getForForm($contextId, $formId)), 'Multiple reviewers retained');
            $this->same([$formId], $service->getFormIds($contextId, $poll['submissionId'], $leaderId), 'Form found for leader');
            $this->same([], $service->getFormIds($contextId, $poll['submissionId'], $editorId), 'Other leader excluded');

            $configuration = json_decode($service->getFormRecord($contextId, $formId)['configuration'], true);
            $configuration['fields']['attendance'] = true;
            DB::table('group_review_participation_forms')->where('form_id', $formId)->update(['configuration' => json_encode($configuration)]);
            $submissions = $service->getFormRecord($contextId, $formId)['submissions'];
            $blocked = $service->saveForm($contextId, $formId, $editorId, [
                ['reviewer_user_id' => $otherId, 'attendance' => 'not_recorded'],
            ], [], true);
            $this->same([['field' => 'attendance', 'reviewerUserId' => $otherId]], $blocked['missing'], 'Required attendance enforced on submit');
            $this->same($submissions, $service->getFormRecord($contextId, $formId)['submissions'], 'Blocked submit is not added to the history');
            $service->saveForm($contextId, $formId, $editorId, [
                ['reviewer_user_id' => $otherId, 'attendance' => 'not_recorded'],
            ]);
            $this->same('not_recorded', $service->getForm($contextId, $formId)['reviewers'][$otherId]['attendance'], 'Save without required attendance');
            $service->saveForm($contextId, $formId, $editorId, [
                ['reviewer_user_id' => $otherId, 'attendance' => 'not_recorded'],
            ], ['has_meeting' => false], true);
            $this->same(0, (int) $service->getFormRecord($contextId, $formId)['has_meeting'], 'No meeting submitted, without attendance');

            $service->cancelForm($contextId, $formId);
            $this->same(null, $service->getActiveFormId($contextId, $poll['submissionId'], $roundId), 'Cancelled form is not the round\'s form');
            $this->same(2, count($service->getForForm($contextId, $formId)), 'Cancelled form keeps its answers');
            $this->rejected(fn () => $service->saveForm($contextId, $formId, $editorId, [$data]), 'Cancelled form is read-only');
            $this->rejected(fn () => $service->cancelForm($contextId, $formId), 'Cancelled form cannot be cancelled again');
            $newFormId = $service->createForm($contextId, $poll['submissionId'], $roundId, $leaderId, [$reviewerId]);
            $service->cancelForm($contextId, $newFormId);
            $this->same('cancelled', $service->getFormRecord($contextId, $newFormId)['status'], 'A form can be cancelled before it is saved');
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
