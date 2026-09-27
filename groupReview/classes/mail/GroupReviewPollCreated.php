<?php

namespace APP\plugins\generic\groupReview\classes\mail;

use PKP\context\Context;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;

class GroupReviewPollCreated extends Mailable
{
    use Configurable;
    use EscapesMailableData;

    protected static ?string $name = 'plugins.generic.groupReview.pollCreated.name';
    protected static ?string $description = 'emails.groupReview.pollCreated.description';
    protected static ?string $emailTemplateKey = 'GROUP_REVIEW_POLL_CREATED';
    /** @var string[] */
    protected static array $groupIds = [self::GROUP_REVIEW];

    public function __construct(
        Context $context,
        string $recipientName,
        string $pollUrl,
        string $submissionTitle,
        string $deadline,
        string $leaderName
    ) {
        parent::__construct([$context]);
        $this->addData($this->escapeMailableData(compact(
            'recipientName',
            'pollUrl',
            'submissionTitle',
            'deadline',
            'leaderName'
        )));
    }

    public static function getDataDescriptions(): array
    {
        return array_merge(parent::getDataDescriptions(), [
            'recipientName' => __('plugins.generic.groupReview.emailVariable.recipientName'),
            'pollUrl' => __('plugins.generic.groupReview.emailVariable.pollUrl'),
            'submissionTitle' => __('plugins.generic.groupReview.emailVariable.submissionTitle'),
            'deadline' => __('plugins.generic.groupReview.emailVariable.deadline'),
            'leaderName' => __('plugins.generic.groupReview.emailVariable.leaderName'),
        ]);
    }
}
