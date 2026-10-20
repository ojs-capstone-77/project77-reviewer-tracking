<?php

/**
 * @file classes/ReviewerLabelService.php
 *
 * Per-journal reviewer label types and values, and the history of changes to a
 * reviewer's labels.
 */

namespace APP\plugins\generic\groupReview\classes;

use APP\facades\Repo;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReviewerLabelService
{
    private const DEFAULT_TYPES = [
        'experience_level' => [
            'name' => 'Experience level',
            'multiple' => false,
            'values' => [
                'novice' => 'Novice',
                'intermediate' => 'Intermediate',
                'experienced' => 'Experienced',
            ],
        ],
        'methodology' => [
            'name' => 'Methodology background',
            'multiple' => false,
            'values' => [
                'quantitative' => 'Quantitative',
                'qualitative' => 'Qualitative',
                'mixed_methods' => 'Mixed methods',
            ],
        ],
        'expertise' => [
            'name' => 'Expertise',
            'multiple' => true,
            'values' => [
                'education' => 'Education',
                'statistics' => 'Statistics',
            ],
        ],
    ];

    private array $typesCache = [];

    /**
     * This journal's label types, in display order, each with its values in
     * display order. Seeds the defaults the first time a journal is read.
     *
     * @return array<string, array{name:string, multiple:bool, values:array<string,string>}>
     */
    public function getTypes(int $contextId): array
    {
        if (isset($this->typesCache[$contextId])) {
            return $this->typesCache[$contextId];
        }

        $this->ensureDefaults($contextId);

        $typeRows = DB::table('group_review_label_types')
            ->where('context_id', $contextId)
            ->where('is_active', true)
            ->orderBy('seq')
            ->orderBy('type_id')
            ->get();

        $valueRows = DB::table('group_review_label_values')
            ->where('context_id', $contextId)
            ->where('is_active', true)
            ->orderBy('seq')
            ->orderBy('value_id')
            ->get();

        $valuesByType = [];
        foreach ($valueRows as $row) {
            $valuesByType[$row->type_key][(string) $row->value_key] = (string) $row->label;
        }

        $types = [];
        foreach ($typeRows as $row) {
            $types[(string) $row->type_key] = [
                'name' => (string) $row->name,
                'multiple' => (bool) $row->multiple,
                'values' => $valuesByType[$row->type_key] ?? [],
            ];
        }

        return $this->typesCache[$contextId] = $types;
    }

    /** Create the default label types and values for a journal that has none yet. */
    private function ensureDefaults(int $contextId): void
    {
        $exists = DB::table('group_review_label_types')->where('context_id', $contextId)->exists();
        if ($exists) {
            return;
        }

        DB::transaction(function () use ($contextId): void {
            $typeSeq = 0;
            foreach (self::DEFAULT_TYPES as $typeKey => $definition) {
                DB::table('group_review_label_types')->insert([
                    'context_id' => $contextId,
                    'type_key' => $typeKey,
                    'name' => $definition['name'],
                    'multiple' => $definition['multiple'],
                    'seq' => $typeSeq++,
                    'is_active' => true,
                ]);

                $valueSeq = 0;
                foreach ($definition['values'] as $valueKey => $label) {
                    DB::table('group_review_label_values')->insert([
                        'context_id' => $contextId,
                        'type_key' => $typeKey,
                        'value_key' => $valueKey,
                        'label' => $label,
                        'seq' => $valueSeq++,
                        'is_active' => true,
                    ]);
                }
            }
        });
    }

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
        $types = $this->getTypes($contextId);
        $labels = [];
        foreach ($userIds as $userId) {
            $labels[(int) $userId] = array_fill_keys(array_keys($types), []);
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
            if (isset($types[$row->label_type])) {
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
        $types = $this->getTypes($contextId);
        $new = $this->validate($types, $values);
        $note = $this->note($note);

        return DB::transaction(function () use ($contextId, $userId, $new, $note, $changedBy, $types): bool {
            $old = $this->getLabels($contextId, [$userId])[$userId];
            $summary = $this->summary($types, $old, $new);
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

    /** The display name of a label type or value. Empty string if either is unknown. */
    public function name(int $contextId, string $type, ?string $value = null): string
    {
        $types = $this->getTypes($contextId);
        if (!isset($types[$type])) {
            return '';
        }
        if ($value === null) {
            return $types[$type]['name'];
        }

        return $types[$type]['values'][$value] ?? '';
    }

    /**
     * Add a new label type. The single/multiple choice cannot be changed later.
     *
     * @throws InvalidArgumentException for an empty or duplicate name
     */
    public function addType(int $contextId, string $name, bool $multiple): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('A label type name is required.');
        }

        $this->getTypes($contextId);

        $duplicate = DB::table('group_review_label_types')
            ->where('context_id', $contextId)
            ->where('is_active', true)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();
        if ($duplicate) {
            throw new InvalidArgumentException('A label type with that name already exists.');
        }

        $nextSeq = (int) (DB::table('group_review_label_types')->where('context_id', $contextId)->max('seq') ?? -1) + 1;

        DB::table('group_review_label_types')->insert([
            'context_id' => $contextId,
            'type_key' => $this->uniqueTypeKey($contextId, $name),
            'name' => $name,
            'multiple' => $multiple,
            'seq' => $nextSeq,
            'is_active' => true,
        ]);

        unset($this->typesCache[$contextId]);
    }

    /** @throws InvalidArgumentException for an unknown type, an empty name, or a duplicate name */
    public function renameType(int $contextId, string $typeKey, string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('A label type name is required.');
        }

        $type = DB::table('group_review_label_types')
            ->where('context_id', $contextId)
            ->where('type_key', $typeKey)
            ->where('is_active', true)
            ->first();
        if (!$type) {
            throw new InvalidArgumentException('Unknown label type.');
        }

        $duplicate = DB::table('group_review_label_types')
            ->where('context_id', $contextId)
            ->where('is_active', true)
            ->where('type_key', '!=', $typeKey)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();
        if ($duplicate) {
            throw new InvalidArgumentException('A label type with that name already exists.');
        }

        DB::table('group_review_label_types')
            ->where('context_id', $contextId)
            ->where('type_key', $typeKey)
            ->update(['name' => $name]);

        unset($this->typesCache[$contextId]);
    }

    /** Values already assigned to reviewers stay in the database; the type just stops appearing. @throws InvalidArgumentException for an unknown type */
    public function removeType(int $contextId, string $typeKey): void
    {
        $updated = DB::table('group_review_label_types')
            ->where('context_id', $contextId)
            ->where('type_key', $typeKey)
            ->where('is_active', true)
            ->update(['is_active' => false]);
        if (!$updated) {
            throw new InvalidArgumentException('Unknown label type.');
        }

        unset($this->typesCache[$contextId]);
    }

    /** How many reviewers currently hold a value for this label type. */
    public function reviewerCountForType(int $contextId, string $typeKey): int
    {
        return DB::table('group_review_reviewer_labels')
            ->where('context_id', $contextId)
            ->where('label_type', $typeKey)
            ->distinct()
            ->count('user_id');
    }

    /** @throws InvalidArgumentException for an unknown type, an empty value, or a duplicate value */
    public function addValue(int $contextId, string $typeKey, string $label): void
    {
        $label = trim($label);
        if ($label === '') {
            throw new InvalidArgumentException('A value is required.');
        }

        $typeExists = DB::table('group_review_label_types')
            ->where('context_id', $contextId)
            ->where('type_key', $typeKey)
            ->where('is_active', true)
            ->exists();
        if (!$typeExists) {
            throw new InvalidArgumentException('Unknown label type.');
        }

        $duplicate = DB::table('group_review_label_values')
            ->where('context_id', $contextId)
            ->where('type_key', $typeKey)
            ->where('is_active', true)
            ->whereRaw('LOWER(label) = ?', [mb_strtolower($label)])
            ->exists();
        if ($duplicate) {
            throw new InvalidArgumentException('That value already exists for this label type.');
        }

        $nextSeq = (int) (DB::table('group_review_label_values')
            ->where('context_id', $contextId)
            ->where('type_key', $typeKey)
            ->max('seq') ?? -1) + 1;

        DB::table('group_review_label_values')->insert([
            'context_id' => $contextId,
            'type_key' => $typeKey,
            'value_key' => $this->uniqueValueKey($contextId, $typeKey, $label),
            'label' => $label,
            'seq' => $nextSeq,
            'is_active' => true,
        ]);

        unset($this->typesCache[$contextId]);
    }

    /** Reviewers who already hold this value keep it; it just stops appearing. @throws InvalidArgumentException for an unknown value */
    public function removeValue(int $contextId, string $typeKey, string $valueKey): void
    {
        $updated = DB::table('group_review_label_values')
            ->where('context_id', $contextId)
            ->where('type_key', $typeKey)
            ->where('value_key', $valueKey)
            ->where('is_active', true)
            ->update(['is_active' => false]);
        if (!$updated) {
            throw new InvalidArgumentException('Unknown label value.');
        }

        unset($this->typesCache[$contextId]);
    }

    /** @return array<string, string[]> every type, with validated, de-duplicated values */
    private function validate(array $types, array $values): array
    {
        $unknown = array_diff(array_keys($values), array_keys($types));
        if ($unknown) {
            throw new InvalidArgumentException('Unknown label type: ' . implode(', ', $unknown));
        }

        $clean = [];
        foreach ($types as $type => $definition) {
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
    private function summary(array $types, array $old, array $new): string
    {
        $sentences = [];
        foreach ($types as $type => $definition) {
            $before = $old[$type] ?? [];
            $after = $new[$type] ?? [];
            if ($before === $after) {
                continue;
            }
            $label = $definition['name'];
            $names = fn (array $list): string => implode(', ', array_map(fn ($v) => $definition['values'][$v] ?? $v, $list));

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

    /** A URL-safe key derived from $text, unique among this journal's type keys (active or removed). */
    private function uniqueTypeKey(int $contextId, string $text): string
    {
        $base = $this->slug($text);
        $key = $base;
        $suffix = 2;
        while (DB::table('group_review_label_types')->where('context_id', $contextId)->where('type_key', $key)->exists()) {
            $key = $base . '_' . $suffix++;
        }

        return $key;
    }

    /** A URL-safe key derived from $text, unique among this type's value keys (active or removed). */
    private function uniqueValueKey(int $contextId, string $typeKey, string $text): string
    {
        $base = $this->slug($text);
        $key = $base;
        $suffix = 2;
        while (DB::table('group_review_label_values')->where('context_id', $contextId)->where('type_key', $typeKey)->where('value_key', $key)->exists()) {
            $key = $base . '_' . $suffix++;
        }

        return $key;
    }

    private function slug(string $text): string
    {
        $slug = mb_strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9]+/u', '_', $slug);
        $slug = trim($slug, '_');

        return $slug !== '' ? mb_substr($slug, 0, 60) : 'type';
    }
}
