<?php

/**
 * @file classes/security/authorization/EditorRequiredPolicy.php
 *
 * Permits the request only for a journal editor, or also an RGL where allowed.
 */

namespace APP\plugins\generic\groupReview\classes\security\authorization;

use APP\plugins\generic\groupReview\classes\GroupReviewService;
use PKP\security\authorization\AuthorizationPolicy;

class EditorRequiredPolicy extends AuthorizationPolicy
{
    private $request;

    public function __construct($request, private bool $allowLeaders = false)
    {
        parent::__construct('plugins.generic.groupReview.authorization.editor');
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

        return $service->isJournalEditorUser((int) $context->getId(), (int) $user->getId())
            || ($this->allowLeaders && $service->rglIsEligible((int) $context->getId(), (int) $user->getId()))
            ? self::AUTHORIZATION_PERMIT
            : self::AUTHORIZATION_DENY;
    }
}
