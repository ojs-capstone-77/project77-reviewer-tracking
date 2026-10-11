<?php

namespace APP\plugins\generic\groupReview\classes\forms;

use PKP\components\forms\FieldPreparedContent;
use PKP\components\forms\FormComponent;

define('FORM_GROUP_REVIEW_REFERENCE_TEMPLATE', 'groupReviewReferenceTemplate');

class ReferenceTemplateForm extends FormComponent
{
    public $id = FORM_GROUP_REVIEW_REFERENCE_TEMPLATE;
    public $method = 'PUT';

    public function __construct($action, $locales, $context)
    {
        $this->action = $action;
        $this->locales = $locales;

        $this->addField(new FieldPreparedContent('groupReviewReferenceTemplate', [
            'label' => __('plugins.generic.groupReview.reference.template.label'),
            'description' => __('plugins.generic.groupReview.reference.template.description'),
            'size' => 'large',
            'value' => $context->getData('groupReviewReferenceTemplate') ?: self::defaultTemplate(),
            'toolbar' => 'bold italic underline | bullist numlist | link',
            'plugins' => 'paste,link,lists',
            'preparedContent' => self::preparedContent(),
        ]));
    }

    public static function defaultTemplate(): string
    {
        return <<<'HTML'
<p>{$letterDate}</p>
<p>To whom it may concern.</p>
<p>{$reviewerName} has been a reviewer with {$journalName} since {$reviewerSince} and in {$year} undertook {$completedCount} reviews for the journal.</p>
<p>{$journalName} adopts a group reviewer model. Leading of these groups is an essential element of the review rigour. In {$year}, {$leaderCountForYear} reviewers were invited to lead the review groups as well as participating in the particular reviews. {$reviewerName} led {$ledCount} of the {$completedCount} reviews with which they were involved.</p>
<p>Their engagement with the journal reviewing process has involved</p>
{$contributionsList}
<p>{$reviewerName} has been a constructive contributor to the community of practice process. They are punctual and reliable in their engagement with the community of practice process.</p>
<p>We thank {$reviewerName} for their contributions to the {$journalName} review process.</p>
HTML;
    }

    private static function preparedContent(): array
    {
        $variables = [
            'reviewerName',
            'journalName',
            'letterDate',
            'reviewerSince',
            'year',
            'completedCount',
            'ledCount',
            'leaderCountForYear',
            'contributionsList',
        ];

        return array_map(fn (string $key) => [
            'key' => $key,
            'description' => __('plugins.generic.groupReview.reference.variable.' . $key),
            'value' => '{$' . $key . '}',
        ], $variables);
    }
}
