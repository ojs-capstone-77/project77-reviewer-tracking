<?php

namespace APP\plugins\generic\groupReview\classes\mail;

trait EscapesMailableData
{
    private function escapeMailableData(array $data): array
    {
        return array_map(
            fn($value): string => htmlspecialchars(
                (string) $value,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            ),
            $data
        );
    }
}
