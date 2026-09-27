<?php

namespace APP\plugins\generic\groupReview\classes\mail;

use PKP\context\Context;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;

class GroupReviewParticipationSubmitted extends Mailable
{
    use Configurable;
    use EscapesMailableData;

    protected static ?string $name = 'plugins.generic.groupReview.participationSubmitted.name';
    protected static ?string $description = 'emails.groupReview.participationSubmitted.description';
    protected static ?string $emailTemplateKey = 'GROUP_REVIEW_PARTICIPATION_SUBMITTED';
    /** @var string[] */
    protected static array $groupIds = [self::GROUP_REVIEW];

    public function __construct(
        Context $context,
        string $recipientName,
        string $submissionTitle,
        int $round,
        string $submitterName,
        string $comment,
        string $formUrl
    ) {
        parent::__construct([$context]);
        $this->addData($this->escapeMailableData(compact(
            'recipientName',
            'submissionTitle',
            'round',
            'submitterName',
            'comment',
            'formUrl'
        )));
    }

    public static function getDataDescriptions(): array
    {
        return array_merge(parent::getDataDescriptions(), [
            'recipientName' => __('plugins.generic.groupReview.emailVariable.recipientName'),
            'submissionTitle' => __('plugins.generic.groupReview.emailVariable.submissionTitle'),
            'round' => __('plugins.generic.groupReview.emailVariable.round'),
            'submitterName' => __('plugins.generic.groupReview.emailVariable.submitterName'),
            'comment' => __('plugins.generic.groupReview.emailVariable.comment'),
            'formUrl' => __('plugins.generic.groupReview.emailVariable.formUrl'),
        ]);
    }
}
