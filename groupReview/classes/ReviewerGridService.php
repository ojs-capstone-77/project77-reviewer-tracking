<?php

namespace APP\plugins\generic\groupReview\classes;

use Illuminate\Support\Facades\DB;

class ReviewerGridService
{
    public const COLUMNS = [
        'invited' => 'plugins.generic.groupReview.monitoring.invited',
        'available' => 'plugins.generic.groupReview.monitoring.available',
        'availablePercent' => 'plugins.generic.groupReview.filtering.availablePercent',
        'selected' => 'plugins.generic.groupReview.monitoring.selected',
        'selectedPercent' => 'plugins.generic.groupReview.filtering.selectedPercent',
        'attended' => 'plugins.generic.groupReview.monitoring.attended',
        'attendedPercent' => 'plugins.generic.groupReview.filtering.attendedPercent',
        'completed' => 'plugins.generic.groupReview.monitoring.completed',
        'current' => 'plugins.generic.groupReview.monitoring.current',
    ];

    public function settings($request): array
    {
        $errors = [];
        $search = $request->getUserVar('search');
        if ($search !== null && (!is_string($search) || mb_strlen($search) > 100)) {
            $errors[] = __('plugins.generic.groupReview.filtering.invalidSearch');
            $search = '';
        }
        $search = trim($search ?? '');
        $labels = $request->getUserVar('labels') ?? [];
        $ranges = $request->getUserVar('ranges') ?? [];
        $columns = $request->getUserVar('columns');
        $selectedLabels = $selectedRanges = [];
        if (!is_array($labels) || array_diff(array_keys($labels), array_keys(ReviewerLabelService::TYPES))) {
            $errors[] = __('plugins.generic.groupReview.filtering.invalidLabels');
            $labels = [];
        }
        foreach (ReviewerLabelService::TYPES as $type => $definition) {
            $values = $labels[$type] ?? [];
            if (!is_array($values) || array_filter($values, fn ($value): bool => !is_string($value) || !isset($definition['values'][$value]))) {
                $errors[] = __('plugins.generic.groupReview.filtering.invalidLabels');
                $values = [];
            }
            $selectedLabels[$type] = array_values(array_unique($values));
        }
        if (!is_array($ranges) || array_diff(array_keys($ranges), array_keys(self::COLUMNS))) {
            $errors[] = __('plugins.generic.groupReview.filtering.invalidRange');
            $ranges = [];
        }
        foreach (self::COLUMNS as $column => $name) {
            $range = $ranges[$column] ?? [];
            $valid = is_array($range) && !array_diff(array_keys($range), ['min', 'max']);
            $selectedRanges[$column] = ['min' => '', 'max' => ''];
            if ($valid) {
                foreach (['min', 'max'] as $bound) {
                    $value = $range[$bound] ?? '';
                    if ($value === '') {
                        continue;
                    }
                    $limit = str_ends_with($column, 'Percent') ? 100 : PHP_INT_MAX;
                    $integer = is_string($value) || is_int($value)
                        ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => $limit]])
                        : false;
                    if ($integer === false) {
                        $valid = false;
                    } else {
                        $selectedRanges[$column][$bound] = $integer;
                    }
                }
                $bounds = $selectedRanges[$column];
                if ($bounds['min'] !== '' && $bounds['max'] !== '' && $bounds['min'] > $bounds['max']) {
                    $valid = false;
                }
            }
            if (!$valid) {
                $errors[] = __('plugins.generic.groupReview.filtering.invalidRange') . ' ' . __($name);
                $selectedRanges[$column] = ['min' => '', 'max' => ''];
            }
        }
        if ($columns === null) {
            $columns = array_keys(self::COLUMNS);
        } elseif (!is_array($columns) || array_filter($columns, fn ($column): bool => !is_string($column) || ($column !== '' && !isset(self::COLUMNS[$column])))) {
            $errors[] = __('plugins.generic.groupReview.filtering.invalidColumns');
            $columns = array_keys(self::COLUMNS);
        }
        $submissionId = $request->getUserVar('availableSubmissionId') ?? '';
        if ($submissionId !== '') {
            $submissionId = is_string($submissionId) || is_int($submissionId)
                ? filter_var($submissionId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                : false;
            if ($submissionId === false) {
                $errors[] = __('plugins.generic.groupReview.filtering.invalidSubmission');
                $submissionId = '';
            }
        }
        return [
            'search' => $search,
            'labels' => $selectedLabels,
            'ranges' => $selectedRanges,
            'columns' => array_values(array_intersect(array_keys(self::COLUMNS), $columns)),
            'availableSubmissionId' => $submissionId,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    public function parameters(array $settings): array
    {
        return [
            'search' => $settings['search'],
            'labels' => array_filter($settings['labels']),
            'ranges' => array_filter($settings['ranges'], fn ($range): bool => $range['min'] !== '' || $range['max'] !== ''),
            // A hidden empty value allows an explicit selection of no numeric columns.
            'columns' => $settings['columns'] ?: [''],
            'availableSubmissionId' => $settings['availableSubmissionId'],
        ];
    }

    /** OJS 3.4's router only accepts one-level arrays; flatten nested filters. */
    public static function urlParameters(array $parameters, string $prefix = ''): array
    {
        $flat = [];
        foreach ($parameters as $key => $value) {
            $name = $prefix === '' ? $key : "{$prefix}[{$key}]";
            if (is_array($value)) {
                $flat += self::urlParameters($value, $name);
            } else {
                $flat[$name] = $value;
            }
        }
        return $flat;
    }

    /** Only currently open, unexpired polls in this journal; no year constraint. */
    public function availableReviewerIds(int $contextId, int $submissionId): array
    {
        return DB::table('group_review_availability as a')
            ->join('group_review_sessions as s', 's.session_id', '=', 'a.session_id')
            ->join('group_review_members as m', function ($join) {
                $join->on('m.session_id', '=', 'a.session_id')->on('m.user_id', '=', 'a.user_id');
            })
            ->where('s.context_id', $contextId)
            ->where('s.submission_id', $submissionId)
            ->where('s.status', GroupReviewService::STATUS_OPEN)
            ->where('s.deadline_utc', '>', gmdate('Y-m-d H:i:s'))
            ->distinct()->pluck('a.user_id')->map(fn ($id): int => (int) $id)->all();
    }

    public function filter(array $rows, array $labels, array $settings, ?array $availableIds = null): array
    {
        if ($settings['errors']) {
            return [];
        }
        return array_values(array_filter($rows, function ($row) use ($labels, $settings, $availableIds): bool {
            if ($settings['search'] !== '' && mb_stripos($row['name'], $settings['search']) === false) {
                return false;
            }
            if ($availableIds !== null && !in_array($row['userId'], $availableIds, true)) {
                return false;
            }
            foreach ($settings['labels'] as $type => $values) {
                if (!$values) {
                    continue;
                }
                $held = $labels[$row['userId']][$type] ?? [];
                $matches = ReviewerLabelService::TYPES[$type]['multiple']
                    ? !array_diff($values, $held)
                    : (bool) array_intersect($values, $held);
                if (!$matches) {
                    return false;
                }
            }
            foreach ($settings['ranges'] as $column => $range) {
                if ($range['min'] === '' && $range['max'] === '') {
                    continue;
                }
                $value = $row[$column];
                if ($value === null || ($range['min'] !== '' && $value < $range['min'])
                    || ($range['max'] !== '' && $value > $range['max'])) {
                    return false;
                }
            }
            return true;
        }));
    }
}
