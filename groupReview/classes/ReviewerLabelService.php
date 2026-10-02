<?php

/**
 * @file classes/ReviewerLabelService.php
 *
 * Reviewer labels (experience level, methodology background, expertise) and
 * their change history. The label types and values are fixed in code.
 */

namespace APP\plugins\generic\groupReview\classes;

use APP\facades\Repo;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReviewerLabelService
{
    /**
     * Label types, in display order. Names are locale keys; "multiple" means a
     * reviewer can hold several values of that type.
     */
    public const TYPES = [
        'experience_level' => [
            'name' => 'plugins.generic.groupReview.labels.experienceLevel',
            'multiple' => false,
            'values' => [
                'novice' => 'plugins.generic.groupReview.labels.novice',
                'intermediate' => 'plugins.generic.groupReview.labels.intermediate',
                'experienced' => 'plugins.generic.groupReview.labels.experienced',
            ],
        ],
        'methodology' => [
            'name' => 'plugins.generic.groupReview.labels.methodology',
            'multiple' => false,
            'values' => [
                'quantitative' => 'plugins.generic.groupReview.labels.quantitative',
                'qualitative' => 'plugins.generic.groupReview.labels.qualitative',
                'mixed_methods' => 'plugins.generic.groupReview.labels.mixedMethods',
            ],
        ],
        'expertise' => [
            'name' => 'plugins.generic.groupReview.labels.expertise',
            'multiple' => true,
            'values' => [
                'education' => 'plugins.generic.groupReview.labels.education',
                'statistics' => 'plugins.generic.groupReview.labels.statistics',
            ],
        ],
    ];

    /**
     * Return each reviewer's current values, keyed by user ID then label type.
     * Every type is present for every requested reviewer; an empty list means Not set.
     *
     * @param int[] $userIds
     *
     * @return array<int, array<string, string[]>>
     */
    public function getLabels(int $contextId, array $userIds): array
    {
        $labels = [];
        foreach ($userIds as $userId) {
            $labels[(int) $userId] = array_fill_keys(array_keys(self::TYPES), []);
        }
        if (!$labels) {
            return [];
        }

        $rows = DB::table('group_review_reviewer_labels')
            ->where('context_id', $contextId)
            ->whereIn('user_id', array_keys($labels))
            ->orderBy('label_id')
            ->get();
        foreach ($rows as $row) {
            if (isset(self::TYPES[$row->label_type])) {
                $labels[(int) $row->user_id][$row->label_type][] = (string) $row->value;
            }
        }

        return $labels;
    }

    /**
     * Replace a reviewer's labels and record the change. Types missing from
     * $values are cleared. Nothing is written when nothing changed and there's
     * no note.
     *
     * @param array<string, string[]> $values label type => values
     *
     * @throws InvalidArgumentException for an unknown type or value, or several
     *   values for a single-value type
     *
     * @return bool whether anything was saved
     */
    public function setLabels(int $contextId, int $userId, array $values, ?string $note, int $changedBy): bool
    {
        $new = $this->validate($values);
        $note = $this->note($note);

        return DB::transaction(function () use ($contextId, $userId, $new, $note, $changedBy): bool {
            $old = $this->getLabels($contextId, [$userId])[$userId];
            $summary = $this->summary($old, $new);
            if ($summary === '' && $note === null) {
                return false;
            }

            if ($summary !== '') {
                DB::table('group_review_reviewer_labels')
                    ->where('context_id', $contextId)
                    ->where('user_id', $userId)
                    ->delete();
                foreach ($new as $type => $typeValues) {
                    foreach ($typeValues as $value) {
                        DB::table('group_review_reviewer_labels')->insert([
                            'context_id' => $contextId,
                            'user_id' => $userId,
                            'label_type' => $type,
                            'value' => $value,
                        ]);
                    }
                }
            }

            DB::table('group_review_reviewer_label_history')->insert([
                'context_id' => $contextId,
                'user_id' => $userId,
                'changed_by' => $changedBy,
                'changed_at' => gmdate('Y-m-d H:i:s'),
                'summary' => $summary !== '' ? $summary : __('plugins.generic.groupReview.labels.history.noteOnly'),
                'note' => $note,
            ]);

            return true;
        });
    }

    /**
     * A reviewer's label changes, newest first.
     *
     * @return array<int, array{changedAt:string, changedByName:string, summary:string, note:?string}>
     */
    public function getHistory(int $contextId, int $userId): array
    {
        $rows = DB::table('group_review_reviewer_label_history')
            ->where('context_id', $contextId)
            ->where('user_id', $userId)
            ->orderByDesc('changed_at')
            ->orderByDesc('history_id')
            ->get();

        $names = [];
        $history = [];
        foreach ($rows as $row) {
            $changedBy = $row->changed_by === null ? null : (int) $row->changed_by;
            if ($changedBy !== null && !array_key_exists($changedBy, $names)) {
                $user = Repo::user()->get($changedBy, true);
                $names[$changedBy] = $user ? $user->getFullName() : '';
            }
            $history[] = [
                'changedAt' => (string) $row->changed_at,
                'changedByName' => $changedBy !== null ? $names[$changedBy] : '',
                'summary' => (string) $row->summary,
                'note' => $row->note === null ? null : (string) $row->note,
            ];
        }

        return $history;
    }

    /** The display name of a label type or value. */
    public function name(string $type, ?string $value = null): string
    {
        return __($value === null ? self::TYPES[$type]['name'] : self::TYPES[$type]['values'][$value]);
    }

    /** @return array<string, string[]> every type, with validated, de-duplicated values */
    private function validate(array $values): array
    {
        $unknown = array_diff(array_keys($values), array_keys(self::TYPES));
        if ($unknown) {
            throw new InvalidArgumentException('Unknown label type: ' . implode(', ', $unknown));
        }

        $clean = [];
        foreach (self::TYPES as $type => $definition) {
            $typeValues = $values[$type] ?? [];
            if (!is_array($typeValues)) {
                throw new InvalidArgumentException("The {$type} values must be a list.");
            }
            foreach ($typeValues as $value) {
                if (!is_string($value)) {
                    throw new InvalidArgumentException("The {$type} values must be strings.");
                }
            }
            $typeValues = array_values(array_unique(array_filter(array_map('strval', $typeValues), fn ($v) => $v !== '')));
            foreach ($typeValues as $value) {
                if (!isset($definition['values'][$value])) {
                    throw new InvalidArgumentException("Unknown {$type} value: {$value}");
                }
            }
            if (!$definition['multiple'] && count($typeValues) > 1) {
                throw new InvalidArgumentException("Only one {$type} value is allowed.");
            }
            // Keep the defined order so comparisons and summaries are stable.
            $clean[$type] = array_values(array_intersect(array_keys($definition['values']), $typeValues));
        }

        return $clean;
    }

    private function note(?string $note): ?string
    {
        $note = trim((string) $note);
        if ($note === '') {
            return null;
        }
        if (mb_strlen($note) > 5000) {
            throw new InvalidArgumentException('The note must be 5000 characters or fewer.');
        }

        return $note;
    }

    /**
     * Describe what changed, one sentence per label type, for example
     * "Experience level changed from Novice to Intermediate. Expertise: added Statistics."
     *
     * @param array<string, string[]> $old
     * @param array<string, string[]> $new
     */
    private function summary(array $old, array $new): string
    {
        $sentences = [];
        foreach (self::TYPES as $type => $definition) {
            $before = $old[$type] ?? [];
            $after = $new[$type] ?? [];
            if ($before === $after) {
                continue;
            }
            $label = $this->name($type);
            $names = fn (array $list): string => implode(', ', array_map(fn ($v) => $this->name($type, $v), $list));

            if ($definition['multiple']) {
                $added = array_values(array_diff($after, $before));
                $removed = array_values(array_diff($before, $after));
                $parts = [];
                if ($added) {
                    $parts[] = __('plugins.generic.groupReview.labels.history.added', ['values' => $names($added)]);
                }
                if ($removed) {
                    $parts[] = __('plugins.generic.groupReview.labels.history.removed', ['values' => $names($removed)]);
                }
                $sentences[] = __('plugins.generic.groupReview.labels.history.multiple', ['label' => $label, 'changes' => implode('; ', $parts)]);
            } elseif (!$before) {
                $sentences[] = __('plugins.generic.groupReview.labels.history.set', ['label' => $label, 'to' => $names($after)]);
            } elseif (!$after) {
                $sentences[] = __('plugins.generic.groupReview.labels.history.cleared', ['label' => $label, 'from' => $names($before)]);
            } else {
                $sentences[] = __('plugins.generic.groupReview.labels.history.changed', ['label' => $label, 'from' => $names($before), 'to' => $names($after)]);
            }
        }

        return implode(' ', $sentences);
    }
}
