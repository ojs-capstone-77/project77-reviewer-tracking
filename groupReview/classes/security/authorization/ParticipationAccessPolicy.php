<?php

/**
 * @file classes/security/authorization/ParticipationAccessPolicy.php
 *
 * Permits the request only for a journal editor, or the review group leader of the
 * specific participation form being opened.
 */

namespace APP\plugins\generic\groupReview\classes\security\authorization;

use APP\plugins\generic\groupReview\classes\GroupReviewService;
use APP\plugins\generic\groupReview\classes\ParticipationService;
use PKP\security\authorization\AuthorizationPolicy;

class ParticipationAccessPolicy extends AuthorizationPolicy
{
    private $request;

    public function __construct($request)
    {
        parent::__construct('plugins.generic.groupReview.authorization.participation');
        $this->request = $request;
    }

    public function effect()
    {
        $context = $this->request->getContext();
        $user = $this->request->getUser();
        if (!$context || !$user) {
            return self::AUTHORIZATION_DENY;
        }

        $contextId = (int) $context->getId();
        $userId = (int) $user->getId();
        $operation = $this->request->getRequestedOp();
        $service = new GroupReviewService();

        // The review group list and Create have no form; they are opened from a submission.
        $formId = (int) $this->request->getUserVar('formId');
        $form = $formId ? (new ParticipationService())->getFormRecord($contextId, $formId) : null;
        $submissionId = $formId
            ? (int) ($form['submission_id'] ?? 0)
            : (int) $this->request->getUserVar('submissionId');
        if (!$submissionId) {
            $service->logAccessDenied(sprintf(
                'Denied user #%d on operation "%s": form #%d does not resolve to a submission in context #%d.',
                $userId,
                $operation,
                $formId,
                $contextId
            ));

            return self::AUTHORIZATION_DENY;
        }

        // Journal editors see every form; an RGL only the forms they lead.
        if ($service->isJournalEditor($contextId, $submissionId, $userId)
            || ($service->isAssignedLeader($contextId, $submissionId, $userId)
                && (!$form || (int) $form['leader_user_id'] === $userId))) {
            return self::AUTHORIZATION_PERMIT;
        }

        $service->logAccessDenied(sprintf(
            'Denied user #%d on operation "%s": not a Journal editor, or the assigned Review Group Leader leading this form, for submission #%d (form #%d).',
            $userId,
            $operation,
            $submissionId,
            $formId
        ));

        return self::AUTHORIZATION_DENY;
    }
}
