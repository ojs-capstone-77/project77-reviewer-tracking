<?php

/**
 * @file classes/security/RequestValidation.php
 *
 * Reject malformed request shapes before authorization can coerce IDs or flags.
 */

namespace APP\plugins\generic\groupReview\classes\security;

use InvalidArgumentException;

class RequestValidation
{
    public static function validate($request): void
    {
        foreach (['pollId', 'formId', 'submissionId', 'reviewRoundId', 'slotId', 'reviewerId'] as $key) {
            $value = $request->getUserVar($key);
            if ($value !== null && !self::integer($value, 1)) {
                throw new InvalidArgumentException("Invalid {$key}.");
            }
        }
        foreach (['noMeeting', 'sendReminder', 'preview', 'multiple'] as $key) {
            $value = $request->getUserVar($key);
            if ($value !== null && !in_array($value, ['', '0', '1', 0, 1, false, true], true)) {
                throw new InvalidArgumentException("Invalid {$key}.");
            }
        }
        $limits = [
            'page' => 10, 'formAction' => 16, 'timezone' => 64, 'deadline' => 32,
            'duration' => 10, 'reminderHours' => 10, 'meetingUrl' => 2048,
            'notes' => 4000, 'selectedBody' => 100000, 'unselectedBody' => 100000,
            'generalComments' => 5000, 'submissionComment' => 5000, 'configurationToken' => 64,
            'letterContent' => 100000, 'fileName' => 255, 'name' => 255, 'label' => 255,
            'typeKey' => 64, 'valueKey' => 64, 'note' => 5000,
        ];
        foreach ($limits as $key => $limit) {
            $value = $request->getUserVar($key);
            if ($value !== null && (!is_string($value) && !is_int($value))) {
                throw new InvalidArgumentException("Invalid {$key}.");
            }
            if ($value !== null && mb_strlen((string) $value) > $limit) {
                throw new InvalidArgumentException("The {$key} is too long.");
            }
        }
        foreach (['page' => 0, 'duration' => 5, 'reminderHours' => 1] as $key => $minimum) {
            $value = $request->getUserVar($key);
            if ($value !== null && !self::integer($value, $minimum)) {
                throw new InvalidArgumentException("Invalid {$key}.");
            }
        }
        foreach (['memberIds', 'slotIds', 'selectedUserIds'] as $key) {
            $values = $request->getUserVar($key);
            if ($values !== null && (!is_array($values) || count($values) > 100
                || array_filter($values, fn ($value): bool => !self::integer($value, 1)))) {
                throw new InvalidArgumentException("Invalid {$key}.");
            }
        }
        $values = $request->getUserVar('values');
        if ($values !== null && (!is_array($values) || array_filter($values, fn ($value): bool => !is_string($value) || mb_strlen($value) > 255))) {
            throw new InvalidArgumentException('Invalid label values.');
        }
    }

    private static function integer(mixed $value, int $minimum): bool
    {
        return (is_string($value) || is_int($value))
            && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum]]) !== false;
    }
}
