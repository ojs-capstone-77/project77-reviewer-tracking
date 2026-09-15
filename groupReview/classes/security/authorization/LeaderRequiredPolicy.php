<?php

namespace APP\plugins\generic\groupReview\classes\security\authorization;

use APP\plugins\generic\groupReview\classes\GroupReviewService;
use PKP\security\authorization\AuthorizationPolicy;

class LeaderRequiredPolicy extends AuthorizationPolicy
{
    private $request;

    public function __construct($request)
    {
        parent::__construct('plugins.generic.groupReview.authorization.leader');
        $this->request = $request;
    }

    public function effect()
    {
        $context = $this->request->getContext();
        $user = $this->request->getUser();
        if (!$context || !$user) {
            return self::AUTHORIZATION_DENY;
        }

        $service = new GroupReviewService();
        $pollId = (int) $this->request->getUserVar('pollId');
        if ($pollId) {
            $poll = $service->get((int) $context->getId(), $pollId);
            if (!$poll) {
                return self::AUTHORIZATION_DENY;
            }
            $submissionId = (int) $poll['submission_id'];
        } else {
            $submissionId = (int) $this->request->getUserVar('submissionId');
        }

        return $submissionId && $service->isLeader(
            (int) $context->getId(),
            $submissionId,
            (int) $user->getId()
        )
            ? self::AUTHORIZATION_PERMIT
            : self::AUTHORIZATION_DENY;
    }
}
