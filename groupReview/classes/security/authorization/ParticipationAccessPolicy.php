<?php

namespace APP\plugins\generic\groupReview\classes\security\authorization;

use APP\plugins\generic\groupReview\classes\GroupReviewService;
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

        // The review group list has no session; it is opened from a submission.
        $sessionId = (int) $this->request->getUserVar('sessionId');
        $session = $sessionId ? $service->get($contextId, $sessionId) : null;
        $submissionId = $sessionId
            ? (int) ($session['submission_id'] ?? 0)
            : (int) $this->request->getUserVar('submissionId');
        if (!$submissionId) {
            $service->logAccessDenied(sprintf(
                'Denied user #%d on operation "%s": session #%d does not resolve to a submission in context #%d.',
                $userId,
                $operation,
                $sessionId,
                $contextId
            ));

            return self::AUTHORIZATION_DENY;
        }

        // Journal editors see every form; an RGL only the forms they lead.
        if ($service->isJournalEditor($contextId, $submissionId, $userId)
            || ($service->isAssignedLeader($contextId, $submissionId, $userId)
                && (!$session || (int) $session['leader_user_id'] === $userId))) {
            return self::AUTHORIZATION_PERMIT;
        }

        $service->logAccessDenied(sprintf(
            'Denied user #%d on operation "%s": not a Journal editor, or the assigned Review Group Leader leading this form, for submission #%d (session #%d).',
            $userId,
            $operation,
            $submissionId,
            $sessionId
        ));

        return self::AUTHORIZATION_DENY;
    }
}
