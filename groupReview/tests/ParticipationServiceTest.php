<?php

/**
 * Lightweight unit tests for ParticipationService validation and hydration.
 *
 * Run inside the OJS app container:
 * php /var/www/html/plugins/generic/groupReview/tests/ParticipationServiceTest.php
 */

require_once dirname(__DIR__) . '/classes/ParticipationService.php';

use APP\plugins\generic\groupReview\classes\ParticipationService;

$service = new ParticipationService();
$validate = new ReflectionMethod($service, 'validate');
$validate->setAccessible(true);
$hydrate = new ReflectionMethod($service, 'hydrate');
$hydrate->setAccessible(true);

$base = [
    'session_id' => 1,
    'review_round_id' => 2,
    'submission_id' => 3,
    'reviewer_user_id' => 4,
    'leader_user_id' => 5,
    'attendance' => ParticipationService::ATTENDANCE_ATTENDED,
    'contribution_comments' => '  Clear contribution notes  ',
    'shaping_feedback_types' => ['commented_on_draft', 'uploaded_notes', 'commented_on_draft'],
    'shaping_feedback_comments' => '',
    'other_contribution' => null,
];

test('valid records are normalized', function () use ($validate, $service, $base) {
    $record = $validate->invoke($service, 10, $base);

    assertSame(1, $record['session_id']);
    assertSame('Clear contribution notes', $record['contribution_comments']);
    assertSame(['commented_on_draft', 'uploaded_notes'], $record['shaping_feedback_types']);
    assertSame(null, $record['shaping_feedback_comments']);
    assertSame(false, array_key_exists('status', $record));
    assertSame(false, array_key_exists('contribution_types', $record));
});

test('missing optional fields use empty defaults', function () use ($validate, $service, $base) {
    $data = array_diff_key($base, array_flip([
        'contribution_comments', 'shaping_feedback_types', 'shaping_feedback_comments', 'other_contribution',
    ]));
    $record = $validate->invoke($service, 10, $data);
    assertSame([], $record['shaping_feedback_types']);
    assertSame(null, $record['contribution_comments']);
    assertSame(null, $record['other_contribution']);
});

test('invalid attendance is rejected', function () use ($validate, $service, $base) {
    $data = array_merge($base, ['attendance' => 'late']);

    assertThrows(fn () => $validate->invoke($service, 10, $data), InvalidArgumentException::class);
});

test('non-integer identifiers are rejected', function () use ($validate, $service, $base) {
    $data = array_merge($base, ['reviewer_user_id' => '4reviewer']);

    assertThrows(fn () => $validate->invoke($service, 10, $data), InvalidArgumentException::class);
});

test('zero identifiers are rejected', function () use ($validate, $service, $base) {
    $data = array_merge($base, ['session_id' => 0]);

    assertThrows(fn () => $validate->invoke($service, 10, $data), InvalidArgumentException::class);
});

test('invalid shaping feedback type is rejected', function () use ($validate, $service, $base) {
    $data = array_merge($base, ['shaping_feedback_types' => ['created_draft', 'invalid']]);

    assertThrows(fn () => $validate->invoke($service, 10, $data), InvalidArgumentException::class);
});

test('non-string list values are rejected', function () use ($validate, $service, $base) {
    $data = array_merge($base, ['shaping_feedback_types' => ['created_draft', 3]]);

    assertThrows(fn () => $validate->invoke($service, 10, $data), InvalidArgumentException::class);
});

test('non-array list values are rejected', function () use ($validate, $service, $base) {
    $data = array_merge($base, ['shaping_feedback_types' => 'created_draft']);

    assertThrows(fn () => $validate->invoke($service, 10, $data), InvalidArgumentException::class);
});

test('invalid form status is rejected before database access', function () use ($service, $base) {
    assertThrows(
        fn () => $service->saveForSession(10, 1, 5, $base + ['status' => 'published']),
        InvalidArgumentException::class
    );
});

test('overlong comments are rejected', function () use ($validate, $service, $base) {
    $data = array_merge($base, ['contribution_comments' => str_repeat('x', 5001)]);

    assertThrows(fn () => $validate->invoke($service, 10, $data), InvalidArgumentException::class);
});

test('hydration decodes json list fields', function () use ($hydrate, $service) {
    $record = $hydrate->invoke($service, [
        'participation_id' => 1,
        'shaping_feedback_types' => '["uploaded_notes"]',
    ]);

    assertSame(['uploaded_notes'], $record['shaping_feedback_types']);
});

test('malformed stored JSON is reported', function () use ($hydrate, $service) {
    assertThrows(
        fn () => $hydrate->invoke($service, ['shaping_feedback_types' => '{broken']),
        JsonException::class
    );
});

test('all attendance options and other details are normalized', function () use ($validate, $service, $base) {
    foreach (array_keys(ParticipationService::ATTENDANCE_OPTIONS) as $attendance) {
        $record = $validate->invoke($service, 10, array_merge($base, [
            'attendance' => $attendance, 'attendance_other' => '  Joined late  ',
        ]));
        assertSame($attendance, $record['attendance']);
        assertSame($attendance === ParticipationService::ATTENDANCE_OTHER ? 'Joined late' : null, $record['attendance_other']);
    }
});

test('every identifier must be present and positive', function () use ($validate, $service, $base) {
    assertThrows(fn () => $validate->invoke($service, 0, $base), InvalidArgumentException::class);
    foreach (['session_id', 'review_round_id', 'submission_id', 'reviewer_user_id', 'leader_user_id'] as $key) {
        foreach ([null, -1, [], '2bad'] as $value) {
            assertThrows(
                fn () => $validate->invoke($service, 10, array_merge($base, [$key => $value])),
                InvalidArgumentException::class
            );
        }
    }
});

test('every comment field rejects invalid shapes and excessive length', function () use ($validate, $service, $base) {
    foreach (['attendance_other', 'contribution_comments', 'shaping_feedback_comments', 'other_contribution'] as $key) {
        foreach ([[], 123, str_repeat('x', 5001)] as $value) {
            assertThrows(
                fn () => $validate->invoke($service, 10, array_merge($base, [
                    'attendance' => ParticipationService::ATTENDANCE_OTHER, $key => $value,
                ])),
                InvalidArgumentException::class
            );
        }
    }
});

echo "ParticipationService tests passed.\n";

function test(string $name, callable $callback): void
{
    try {
        $callback();
        echo ". {$name}\n";
    } catch (Throwable $error) {
        fwrite(STDERR, "F {$name}: {$error->getMessage()}\n");
        exit(1);
    }
}

function assertSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

function assertThrows(callable $callback, string $expectedClass): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        if ($error instanceof ReflectionException && $error->getPrevious()) {
            $error = $error->getPrevious();
        }
        if ($error instanceof $expectedClass) {
            return;
        }
        throw new RuntimeException(
            'Expected ' . $expectedClass . ', got ' . get_class($error) . ': ' . $error->getMessage()
        );
    }

    throw new RuntimeException('Expected ' . $expectedClass . ' to be thrown.');
}
