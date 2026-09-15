<?php

namespace APP\plugins\generic\groupReview\classes\security\authorization;

use APP\plugins\generic\groupReview\classes\GroupReviewService;
use PKP\security\authorization\AuthorizationPolicy;

class InviteeRequiredPolicy extends AuthorizationPolicy
{
    private $request;

    public function __construct($request)
    {
        parent::__construct('plugins.generic.groupReview.authorization.invitee');
        $this->request = $request;
    }

    public function effect()
    {
        $context = $this->request->getContext();
        $user = $this->request->getUser();
        $pollId = (int) $this->request->getUserVar('pollId');
        if (!$context || !$user || !$pollId) {
            return self::AUTHORIZATION_DENY;
        }

        $service = new GroupReviewService();
        return $service->isInvited(
            (int) $context->getId(),
            $pollId,
            (int) $user->getId()
        )
            ? self::AUTHORIZATION_PERMIT
            : self::AUTHORIZATION_DENY;
    }
}

