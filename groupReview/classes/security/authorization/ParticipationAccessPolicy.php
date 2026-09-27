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

        if ($service->isManager($contextId, $userId)) {
            return self::AUTHORIZATION_PERMIT;
        }

        // The review group list has no session; it is opened from a submission.
        $sessionId = (int) $this->request->getUserVar('sessionId');
        $submissionId = $sessionId
            ? $service->getSessionSubmissionId($contextId, $sessionId)
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

        if ($service->isAssignedEditor($contextId, $submissionId, $userId)
            || $service->isLeader($contextId, $submissionId, $userId)) {
            return self::AUTHORIZATION_PERMIT;
        }

        $service->logAccessDenied(sprintf(
            'Denied user #%d on operation "%s": not the Manager, an assigned Journal editor, or the assigned Review Group Leader for submission #%d (session #%d).',
            $userId,
            $operation,
            $submissionId,
            $sessionId
        ));

        return self::AUTHORIZATION_DENY;
    }
}
