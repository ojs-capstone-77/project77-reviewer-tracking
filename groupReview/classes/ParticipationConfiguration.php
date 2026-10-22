<?php

/**
 * @file classes/ParticipationConfiguration.php
 *
 * Stores and resolves a journal's participation form configuration: which fields are
 * required, their names and descriptions, and the list of feedback response options.
 */

namespace APP\plugins\generic\groupReview\classes;

use APP\plugins\generic\groupReview\GroupReviewPlugin;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ParticipationConfiguration
{
    public const SETTING = 'participationConfiguration';
    public const FIELDS = [
        'attendance' => 'Meeting attendance',
        'contribution_comments' => 'Meeting comments',
        'shaping_feedback_types' => 'Feedback response',
        'shaping_feedback_comments' => 'Feedback response comments',
    ];
    public const TEXT_FIELDS = [
        'attendance', 'contribution_comments',
        'shaping_feedback_types', 'shaping_feedback_comments', 'other_contribution', 'general_comments',
    ];

    public static function defaults(): array
    {
        $fields = array_fill_keys(array_keys(self::FIELDS), false);
        $text = [];
        foreach (self::TEXT_FIELDS as $field) {
            $text[$field] = [
                'label' => __("plugins.generic.groupReview.participation.field.{$field}"),
                'description' => in_array($field, ['contribution_comments', 'shaping_feedback_comments'], true)
                    ? __("plugins.generic.groupReview.participation.field.{$field}.description")
                    : '',
            ];
        }
        return ['fields' => $fields, 'options' => ParticipationService::CONTRIBUTION_OPTIONS, 'text' => $text];
    }

    public function get(int $contextId): array
    {
        $config = (new GroupReviewPlugin())->getSetting($contextId, self::SETTING);
        return $config === null ? self::defaults() : $config + ['text' => self::defaults()['text']];
    }

    public function save(int $contextId, mixed $required, mixed $options, mixed $text): array
    {
        if (!is_array($required)) {
            throw new InvalidArgumentException('Select only the configurable participation fields.');
        }
        foreach ($required as $field) {
            if (!is_string($field) || !array_key_exists($field, self::FIELDS)) {
                throw new InvalidArgumentException('Select only the configurable participation fields.');
            }
        }
        if (!is_array($options) || !$options) {
            throw new InvalidArgumentException('Keep at least one feedback response option.');
        }
        $current = $this->get($contextId);
        $normalized = [];
        $names = [];
        foreach ($options as $row) {
            if (!is_array($row) || !is_string($row['label'] ?? null)) {
                throw new InvalidArgumentException('Each option needs a name.');
            }
            $label = trim($row['label']);
            $name = mb_strtolower($label);
            if ($label === '' || mb_strlen($label) > 150 || in_array($name, $names, true)) {
                throw new InvalidArgumentException('Option names must be unique and at most 150 characters.');
            }
            $names[] = $name;
            $id = $row['id'] ?? '';
            $defaultId = array_search($label, ParticipationService::CONTRIBUTION_OPTIONS, true);
            // Renames get a new identity; historical answer labels keep their meaning.
            $id = is_string($id) && ($current['options'][$id] ?? null) === $label
                ? $id : ($defaultId !== false ? $defaultId : 'custom_' . substr(hash('sha256', $label), 0, 24));
            $normalized[$id] = $label;
        }
        $normalizedText = [];
        foreach (self::TEXT_FIELDS as $field) {
            $label = is_string($text[$field]['label'] ?? null) ? trim($text[$field]['label']) : '';
            $description = is_string($text[$field]['description'] ?? null) ? trim($text[$field]['description']) : '';
            if ($label === '' || mb_strlen($label) > 150 || mb_strlen($description) > 1000) {
                throw new InvalidArgumentException('Each field needs a name of up to 150 characters, and a description of up to 1000.');
            }
            $normalizedText[$field] = ['label' => $label, 'description' => $description];
        }
        $config = [
            'fields' => array_fill_keys(array_keys(self::FIELDS), false),
            'options' => $normalized,
            'text' => $normalizedText,
        ];
        foreach ($required as $field) {
            $config['fields'][$field] = true;
        }
        (new GroupReviewPlugin())->updateSetting($contextId, self::SETTING, $config, 'object');
        return $config;
    }

    /**
     * Resolves which configuration a participation form should use: the snapshot
     * saved with that form if one exists, or the journal's current configuration
     * otherwise.
     */
    public function forForm(int $contextId, int $formId): array
    {
        $configuration = DB::table('group_review_participation_forms')
            ->where('context_id', $contextId)->where('form_id', $formId)->value('configuration');

        return $configuration ? json_decode($configuration, true, 512, JSON_THROW_ON_ERROR) : $this->get($contextId);
    }

    public static function token(array $config): string
    {
        return hash('sha256', json_encode($config, JSON_THROW_ON_ERROR));
    }
}
