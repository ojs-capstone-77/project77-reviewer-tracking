<?php

namespace APP\plugins\generic\groupReview\classes\mail;

use PKP\context\Context;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;

class GroupReviewPollThankRgms extends Mailable
{
    use Configurable;
    use EscapesMailableData;

    protected static ?string $name = 'plugins.generic.groupReview.thanksRgms.name';
    protected static ?string $description = 'emails.groupReview.thanksRgms.description';
    protected static ?string $emailTemplateKey = 'GROUP_REVIEW_THANKS_RGMS';
    /** @var string[] */
    protected static array $groupIds = [self::GROUP_REVIEW];

    public function __construct(
        Context $context,
        string $recipientName,
        string $submissionTitle,
        string $meetingTime,
        string $meetingUrl,
        string $groupReviewUrl
    ) {
        parent::__construct([$context]);
        $this->addData($this->escapeMailableData(compact(
            'recipientName',
            'submissionTitle',
            'meetingTime',
            'meetingUrl',
            'groupReviewUrl'
        )));
    }

    public static function getDataDescriptions(): array
    {
        return array_merge(parent::getDataDescriptions(), [
            'recipientName' => __('plugins.generic.groupReview.emailVariable.recipientName'),
            'submissionTitle' => __('plugins.generic.groupReview.emailVariable.submissionTitle'),
            'meetingTime' => __('plugins.generic.groupReview.emailVariable.meetingTime'),
            'meetingUrl' => __('plugins.generic.groupReview.emailVariable.meetingUrl'),
            'groupReviewUrl' => __('plugins.generic.groupReview.emailVariable.pollUrl'),
        ]);
    }
}
