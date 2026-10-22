<?php

/**
 * @file classes/mail/EscapesMailableData.php
 *
 * Escapes template variables before they reach a Mailable's HTML email body.
 */

namespace APP\plugins\generic\groupReview\classes\mail;

trait EscapesMailableData
{
    private function escapeMailableData(array $data): array
    {
        return array_map(
            fn ($value): string => htmlspecialchars(
                (string) $value,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            ),
            $data
        );
    }
}
