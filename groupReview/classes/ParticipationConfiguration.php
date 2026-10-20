<?php

/**
 * @file classes/ParticipationConfiguration.php
 *
 * Stores and resolves a journal's participation form configuration: which fields are
 * shown, and the list of shaping-feedback options.
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
        'contribution_types' => 'Contribution types',
        'strengths' => 'Strengths demonstrated',
        'development_opportunities' => 'Development opportunities',
        'shaping_feedback_types' => 'Shaping feedback response',
        'contribution_comments' => 'Comments on contributions',
        'shaping_feedback_comments' => 'Comments on shaping feedback',
    ];
    public const CONTRIBUTION_TYPES = [
        'discussion' => 'Discussion', 'writing' => 'Writing', 'analysis' => 'Analysis',
        'editing' => 'Editing', 'other' => 'Other',
    ];

    public static function defaults(bool $legacy = false): array
    {
        $fields = array_fill_keys(array_keys(self::FIELDS), false);
        if ($legacy) {
            unset($fields['contribution_types'], $fields['strengths'], $fields['development_opportunities']);
        }
        return ['fields' => $fields, 'options' => ParticipationService::CONTRIBUTION_OPTIONS];
    }

    public function get(int $contextId): array
    {
        $config = (new GroupReviewPlugin())->getSetting($contextId, self::SETTING);
        return $config === null ? self::defaults() : $config;
    }

    public function save(int $contextId, mixed $required, mixed $options): array
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
            throw new InvalidArgumentException('At least one shaping feedback option must remain.');
        }
        $current = $this->get($contextId);
        $normalized = [];
        $names = [];
        foreach ($options as $row) {
            if (!is_array($row) || !is_string($row['label'] ?? null)) {
                throw new InvalidArgumentException('Each shaping feedback option needs a name.');
            }
            $label = trim($row['label']);
            $name = mb_strtolower($label);
            if ($label === '' || mb_strlen($label) > 150 || in_array($name, $names, true)) {
                throw new InvalidArgumentException('Option names must be unique, nonempty and no longer than 150 characters.');
            }
            $names[] = $name;
            $id = $row['id'] ?? '';
            $defaultId = array_search($label, ParticipationService::CONTRIBUTION_OPTIONS, true);
            // Renames get a new identity; historical answer labels keep their meaning.
            $id = is_string($id) && ($current['options'][$id] ?? null) === $label
                ? $id : ($defaultId !== false ? $defaultId : 'custom_' . substr(hash('sha256', $label), 0, 24));
            $normalized[$id] = $label;
        }
        $config = [
            'fields' => array_fill_keys(array_keys(self::FIELDS), false),
            'options' => $normalized,
        ];
        foreach ($required as $field) {
            $config['fields'][$field] = true;
        }
        (new GroupReviewPlugin())->updateSetting($contextId, self::SETTING, $config, 'object');
        return $config;
    }

    /**
     * Resolves which configuration a participation form should use: the snapshot
     * saved with that form if one exists, the pre-snapshot legacy defaults if the
     * session was already answered before snapshots were introduced, or the
     * journal's current configuration otherwise.
     */
    public function forForm(int $contextId, int $sessionId): array
    {
        $form = DB::table('group_review_participation_forms')
            ->where('context_id', $contextId)->where('session_id', $sessionId)->first();
        if ($form) {
            return empty($form->configuration)
                ? self::defaults(true)
                : json_decode($form->configuration, true, 512, JSON_THROW_ON_ERROR);
        }
        if (DB::table('group_review_participation')->where('context_id', $contextId)->where('session_id', $sessionId)->exists()) {
            return self::defaults(true);
        }
        return $this->get($contextId);
    }

    public static function token(array $config): string
    {
        return hash('sha256', json_encode($config, JSON_THROW_ON_ERROR));
    }
}
