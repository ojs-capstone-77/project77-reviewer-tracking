<?php

/**
 * @file tests/SecurityTest.php
 *
 * php plugins/generic/groupReview/tests/SecurityTest.php <development journal path>
 * Uses isolated journal fixtures and rolls back every database change.
 */

use APP\core\Application;
use APP\facades\Repo;
use APP\file\PublicFileManager;
use APP\plugins\generic\groupReview\classes\GroupReviewService;
use APP\plugins\generic\groupReview\classes\ParticipationService;
use APP\plugins\generic\groupReview\classes\ReviewerLabelService;
use APP\plugins\generic\groupReview\classes\security\authorization\EditorRequiredPolicy;
use APP\plugins\generic\groupReview\classes\security\authorization\InviteeRequiredPolicy;
use APP\plugins\generic\groupReview\classes\security\authorization\LeaderRequiredPolicy;
use APP\plugins\generic\groupReview\classes\security\authorization\ParticipationAccessPolicy;
use APP\plugins\generic\groupReview\GroupReviewPlugin;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\DB;
use PKP\cliTool\CommandLineTool;
use PKP\core\Registry;
use PKP\db\DAORegistry;
use PKP\file\TemporaryFileManager;
use PKP\security\authorization\AuthorizationPolicy;
use PKP\security\Role;

require_once __DIR__ . '/MonitoringTest.php';
require_once __DIR__ . '/TestJournal.php';

class SecurityPageRequest extends MonitoringPageRequest
{
    public string $operation = 'overview';
    public bool $anonymous = false;
    public function getRequestedOp() { return $this->operation; }
    public function getUser(): ?\PKP\user\User { return $this->anonymous ? null : parent::getUser(); }
}

class SecurityTestTool extends CommandLineTool
{
    private int $checks = 0;
    private array $files = [];
    private array $directories = [];

    private function check(bool $condition, string $name): void
    {
        if (!$condition) {
            throw new RuntimeException($name);
        }
        $this->checks++;
    }

    private function invoke($object, string $method, ...$args)
    {
        return (new ReflectionMethod($object, $method))->invoke($object, ...$args);
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

    private function temporaryFile(int $userId, string $content, string $mime): int
    {
        $manager = new TemporaryFileManager();
        $path = tempnam($manager->getBasePath(), 'grp-security-');
        $this->files[] = $path;
        file_put_contents($path, $content);
        $dao = DAORegistry::getDAO('TemporaryFileDAO');
        $file = $dao->newDataObject();
        $file->setUserId($userId);
        $file->setServerFileName(basename($path));
        $file->setOriginalFileName('security-fixture');
        $file->setFileType($mime);
        $file->setFileSize(strlen($content));
        $file->setDateUploaded(gmdate('Y-m-d H:i:s'));
        return (int) $dao->insertObject($file);
    }

    public function execute()
    {
        $original = Application::get()->getRequest();
        DB::beginTransaction();
        try {
            $path = groupReviewTestJournal($this->argv[0] ?? '');
            $context = Application::getContextDAO()->getByPath($path);
            $contextId = (int) $context->getId();
            $seed = new GroupReviewTestDataTool([__FILE__, $path, 'seed']);
            $seed->execute();
            $fixture = $seed->getFixture();
            $otherPath = groupReviewTestJournal($this->argv[0]);
            $otherContext = Application::getContextDAO()->getByPath($otherPath);
            $editor = Repo::user()->getByUsername('test_editor_01');
            $plugin = new class extends GroupReviewPlugin {
                public function getPluginPath() { return dirname(__DIR__); }
                public function getTemplateResource($template = null, $inCore = false)
                {
                    return 'file:' . dirname(__DIR__) . '/templates/' . $template;
                }
            };
            $handler = new MonitoringPageHandler($plugin);
            $request = new SecurityPageRequest($context, $editor);
            Registry::set('request', $request);
            $manager = TemplateManager::getManager($request);
            $manager->unregisterPlugin('function', 'csrf');
            $manager->registerPlugin('function', 'csrf', fn () => '');

            // Every publicly routed page operation must have a role assignment.
            $editorOps = ['overview', 'reviewer', 'saveLabels', 'reference', 'generateReferencePdf',
                'saveParticipationSettings', 'addLabelType', 'renameLabelType', 'removeLabelType', 'addLabelValue', 'removeLabelValue'];
            $leaderOps = ['create', 'store', 'invite', 'storeInvitations', 'view', 'edit', 'update',
                'selectMeetingMembers', 'reviewMessages', 'finalize', 'cancel', 'resend'];
            $participationOps = ['participation', 'participationForm', 'saveParticipationForm', 'participationRecorded',
                'storeParticipationForm', 'cancelParticipationForm'];
            $all = ['index', 'reviewers', 'availability', 'saveAvailability', ...$editorOps, ...$leaderOps, ...$participationOps];
            foreach ($all as $op) {
                $roles = array_filter([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR], fn ($role): bool =>
                    in_array($op, $handler->getRoleAssignment($role) ?? [], true));
                $this->check((bool) $roles, "Role assignment exists: {$op}");
                $this->check(!in_array($op, $handler->getRoleAssignment(Role::ROLE_ID_AUTHOR) ?? [], true), "Author denied: {$op}");
                $this->check(!in_array($op, $handler->getRoleAssignment(Role::ROLE_ID_REVIEWER) ?? [], true), "Ordinary reviewer denied: {$op}");
            }
            foreach ($editorOps as $op) {
                $this->check(!in_array($op, $handler->getRoleAssignment(Role::ROLE_ID_SUB_EDITOR) ?? [], true), "Editor-only role: {$op}");
            }
            $public = array_filter((new ReflectionClass($handler))->getMethods(ReflectionMethod::IS_PUBLIC), fn ($method): bool =>
                $method->getDeclaringClass()->getName() === \APP\plugins\generic\groupReview\pages\groupReview\GroupReviewHandler::class
                && !in_array($method->name, ['__construct', 'authorize'], true));
            $this->check(!array_diff(array_map(fn ($method) => $method->name, $public), $all), 'Endpoint inventory covers every plugin page method');
            foreach (['test_editor_01' => true, 'test_editor_03' => true, 'test_rgl_01' => false, 'test_rgm_21' => false] as $username => $allowed) {
                $access = new SecurityPageRequest($context, Repo::user()->getByUsername($username));
                $this->check(((new EditorRequiredPolicy($access))->effect() === AuthorizationPolicy::AUTHORIZATION_PERMIT) === $allowed, 'Editor policy: ' . $username);
            }
            $foreign = new SecurityPageRequest($otherContext, $editor);
            $this->check((new EditorRequiredPolicy($foreign))->effect() === AuthorizationPolicy::AUTHORIZATION_DENY, 'Editor in journal A is not editor in B');
            $poll = array_values($fixture['polls'])[2];
            $leader = Repo::user()->getByUsername($poll['leader']);
            $leaderRequest = new SecurityPageRequest($context, $leader);
            $leaderRequest->vars = ['pollId' => $poll['sessionId']];
            $this->check((new LeaderRequiredPolicy($leaderRequest))->effect() === AuthorizationPolicy::AUTHORIZATION_PERMIT, 'Assigned poll leader allowed');
            $foreignLeader = new SecurityPageRequest($otherContext, $leader);
            $foreignLeader->vars = $leaderRequest->vars;
            $this->check((new LeaderRequiredPolicy($foreignLeader))->effect() === AuthorizationPolicy::AUTHORIZATION_DENY, 'Poll ID cannot cross journals');
            $unrelated = new SecurityPageRequest($context, Repo::user()->getByUsername('test_rgl_01'));
            $unrelated->vars = $leaderRequest->vars;
            $this->check((new LeaderRequiredPolicy($unrelated))->effect() === AuthorizationPolicy::AUTHORIZATION_DENY, 'Unassigned journal RGL denied');
            $member = Repo::user()->getByUsername('test_rgm_22');
            $memberRequest = new SecurityPageRequest($context, $member);
            $memberRequest->vars = ['pollId' => $poll['sessionId']];
            $this->check((new InviteeRequiredPolicy($memberRequest))->effect() === AuthorizationPolicy::AUTHORIZATION_PERMIT, 'Invited member allowed');
            $foreignMember = new SecurityPageRequest($otherContext, $member);
            $foreignMember->vars = $memberRequest->vars;
            $this->check((new InviteeRequiredPolicy($foreignMember))->effect() === AuthorizationPolicy::AUTHORIZATION_DENY, 'Invitee cannot cross journals');
            $outsider = new SecurityPageRequest($context, Repo::user()->getByUsername('test_rgm_28'));
            $outsider->vars = $memberRequest->vars;
            $this->check((new InviteeRequiredPolicy($outsider))->effect() === AuthorizationPolicy::AUTHORIZATION_DENY, 'Noninvited member denied');

            $roundId = (int) DB::table('group_review_sessions')->where('session_id', $poll['sessionId'])->value('review_round_id');
            $participation = new ParticipationService();
            $formId = $participation->createForm($contextId, $poll['submissionId'], $roundId, (int) $leader->getId(), [(int) $member->getId()]);
            $leaderRequest->vars = ['formId' => $formId];
            $this->check((new ParticipationAccessPolicy($leaderRequest))->effect() === AuthorizationPolicy::AUTHORIZATION_PERMIT, 'Form leader allowed');
            $unrelated->vars = ['formId' => $formId];
            $this->check((new ParticipationAccessPolicy($unrelated))->effect() === AuthorizationPolicy::AUTHORIZATION_DENY, 'Different leader cannot open participation');
            $foreignLeader->vars = ['formId' => $formId];
            $this->check((new ParticipationAccessPolicy($foreignLeader))->effect() === AuthorizationPolicy::AUTHORIZATION_DENY, 'Form ID cannot cross journals');
            $this->check($participation->getFormRecord((int) $otherContext->getId(), $formId) === null, 'Form read journal-scoped');
            $request->vars = ['formId' => $formId];
            $this->check((new ParticipationAccessPolicy($request))->effect() === AuthorizationPolicy::AUTHORIZATION_PERMIT, 'Journal editor may access participation form');
            $anonymous = new SecurityPageRequest($context, $editor);
            $anonymous->anonymous = true;
            $anonymous->vars = ['pollId' => $poll['sessionId'], 'formId' => $formId];
            foreach ([EditorRequiredPolicy::class, LeaderRequiredPolicy::class, InviteeRequiredPolicy::class, ParticipationAccessPolicy::class] as $policy) {
                $this->check((new $policy($anonymous))->effect() === AuthorizationPolicy::AUTHORIZATION_DENY, 'Unauthenticated request denied: ' . $policy);
            }

            foreach (['saveLabels', 'generateReferencePdf', 'saveParticipationSettings', 'addLabelType', 'renameLabelType',
                'removeLabelType', 'addLabelValue', 'removeLabelValue', 'saveParticipationForm', 'storeParticipationForm', 'cancelParticipationForm'] as $op) {
                $request->vars = [];
                $request->post = true;
                $request->csrf = false;
                $html = $this->render($handler, $request, $op);
                $this->check(str_contains($html, __('plugins.generic.groupReview.error.invalidRequest')), "CSRF guard: {$op}");
            }
            foreach (['store', 'storeInvitations', 'update', 'saveAvailability', 'resend', 'cancel', 'finalize', 'selectMeetingMembers', 'reviewMessages'] as $op) {
                try {
                    $handler->$op([], $request);
                    throw new RuntimeException("CSRF accepted: {$op}");
                } catch (RuntimeException $error) {
                    $this->check($error->getMessage() === __('plugins.generic.groupReview.error.invalidRequest'), "Poll CSRF guard: {$op}");
                }
            }
            $request->vars = ['pollId' => ['1']];
            $args = [];
            $this->check($handler->authorize($request, $args, []) === false, 'Malformed IDs denied before policy coercion');

            $payload = "' OR 1=1 -- <img src=x onerror=alert(1)>";
            $labels = new ReviewerLabelService();
            $labels->addType($contextId, $payload, false);
            $types = (new ReviewerLabelService())->getTypes($contextId);
            $matching = array_keys(array_filter($types, fn ($type): bool => $type['name'] === $payload));
            $this->check(count($matching) === 1, 'SQL/HTML payload stored literally as a label type');
            $this->check(!in_array($payload, array_column((new ReviewerLabelService())->getTypes((int) $otherContext->getId()), 'name'), true), 'Literal payload cannot affect another journal');
            $request->vars = ['year' => 'all', 'search' => "' OR 1=1 --"];
            $html = $this->render($handler, $request, 'reviewers');
            $this->check($manager->getTemplateVars('reviewers') === [], 'SQL-shaped search never broadens results');
            $this->check(!str_contains($html, '<img src=x onerror=alert(1)>'), 'Labels and search escaped by Smarty');
            $letter = $this->invoke($handler, 'renderReferenceLetter', '<p>{$reviewerName}</p><p>{$journalName}</p><script>alert(1)</script><p>{$contributionsList}</p>', [
                'reviewerName' => '<img src=x onerror=alert(1)>', 'journalName' => 'A & B',
                'contributionsList' => '<ul><li>Valid contribution</li></ul>',
            ]);
            $this->check(!str_contains($letter, '<script') && !str_contains($letter, '<img'), 'Reference template and plain-text tokens cannot inject HTML');
            $this->check(str_contains($letter, '&lt;img') && str_contains($letter, '<ul>'), 'Names escaped; generated contribution markup preserved');

            $badFile = $this->temporaryFile((int) $editor->getId(), '<html><script>alert(1)</script></html>', 'text/html');
            $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX4sAAAAASUVORK5CYII=');
            $goodFile = $this->temporaryFile((int) $editor->getId(), $png, 'image/png');
            $otherFile = $this->temporaryFile((int) $member->getId(), $png, 'image/png');
            foreach ([['temporaryFileId' => $badFile], ['temporaryFileId' => $otherFile],
                ['uploadName' => '../other-journal/logo.png'], ['temporaryFileId' => ['1']]] as $value) {
                $errors = [];
                $hookArgs = [&$errors, 'edit', ['groupReviewLetterhead' => $value], [], 'en'];
                $plugin->validateLetterhead('Context::validate', $hookArgs);
                $this->check(isset($errors['groupReviewLetterhead']), 'Actual context API rejects unsafe letterhead');
            }
            $errors = [];
            $hookArgs = [&$errors, 'edit', ['groupReviewLetterhead' => ['temporaryFileId' => $goodFile]], [], 'en'];
            $plugin->validateLetterhead('Context::validate', $hookArgs);
            $this->check(!$errors, 'Actual context API accepts owned raster image');
            $directory = (new PublicFileManager())->getContextFilesPath($contextId);
            $this->directories[] = $directory;
            $this->files[] = $directory . '/groupReviewLetterhead.png';
            $uploaded = clone $context;
            $args = [&$uploaded, $context, ['groupReviewLetterhead' => ['temporaryFileId' => $goodFile, 'altText' => 'Safe PNG']], $request];
            $plugin->saveLetterhead('Context::edit', $args);
            $metadata = $uploaded->getData('groupReviewLetterhead');
            $this->check($metadata['uploadName'] === 'groupReviewLetterhead.png' && $metadata['width'] === 1, 'Actual upload uses server-owned filename and measured dimensions');
            $letterhead = $this->invoke($handler, 'referenceLetterhead', $uploaded);
            $this->check(str_starts_with($letterhead['src'], 'data:image/png;base64,'), 'Letterhead reload embeds only the validated raster');
            $args = [&$uploaded, $uploaded, ['groupReviewLetterhead' => null], $request];
            $plugin->saveLetterhead('Context::edit', $args);
            $this->check(!file_exists($directory . '/groupReviewLetterhead.png'), 'Valid owned letterhead can be removed');
            $current = clone $context;
            $current->setData('groupReviewLetterhead', ['uploadName' => 'groupReviewLetterhead.png', 'width' => 1, 'height' => 1, 'name' => 'Original']);
            $updated = clone $current;
            $args = [&$updated, $current, ['groupReviewLetterhead' => ['uploadName' => 'groupReviewLetterhead.png', 'width' => 9999, 'name' => 'Forged', 'altText' => 'New alt']], $request];
            $plugin->saveLetterhead('Context::edit', $args);
            $this->check($updated->getData('groupReviewLetterhead')['width'] === 1 && $updated->getData('groupReviewLetterhead')['name'] === 'Original', 'Client cannot overwrite stored file metadata');
            $this->check($updated->getData('groupReviewLetterhead')['altText'] === 'New alt', 'Accessible alt text remains editable');
            $request->post = true;
            $request->csrf = true;
            $request->vars = ['reviewerId' => $member->getId(), 'letterContent' => '<p>Safe PDF</p><script>alert(1)</script>', 'fileName' => "safe\r\nInjected: value"];
            $pdf = $this->render($handler, $request, 'generateReferencePdf');
            $this->check(str_starts_with($pdf, '%PDF-'), 'Restricted PDF renderer still generates a real PDF');
            $log = tempnam(sys_get_temp_dir(), 'grp-security-log-');
            $this->files[] = $log;
            $oldLog = ini_set('error_log', $log);
            try {
                (new GroupReviewService())->logAccessDenied("test entry\nforged line");
            } finally {
                ini_set('error_log', $oldLog);
            }
            $this->check(str_contains(file_get_contents($log), 'Group Review access denied: test entry forged line'), 'Denials logged to configured server log without line injection');
            echo "Passed {$this->checks} plugin security integration checks.\n";
        } finally {
            Registry::set('request', $original);
            DB::rollBack();
            foreach ($this->files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            foreach ($this->directories as $directory) {
                if (is_dir($directory)) {
                    rmdir($directory);
                }
            }
            echo "Security fixtures rolled back.\n";
        }
    }
}

try {
    ob_start();
    (new SecurityTestTool($argv))->execute();
    ob_end_flush();
} catch (Throwable $error) {
    ob_end_flush();
    fwrite(STDERR, "FAIL {$error}\n");
    exit(1);
}
