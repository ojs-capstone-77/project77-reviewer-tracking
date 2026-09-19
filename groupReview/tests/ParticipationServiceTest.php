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
    'contribution_types' => ['discussion', 'writing', 'discussion'],
    'contribution_comments' => '  Clear contribution notes  ',
    'shaping_feedback_types' => ['commented_on_draft'],
    'shaping_feedback_comments' => '',
    'other_contribution' => null,
    'status' => 'submitted',
];

test('valid records are normalized', function () use ($validate, $service, $base) {
    $record = $validate->invoke($service, 10, $base);

    assertSame(1, $record['session_id']);
    assertSame(['discussion', 'writing'], $record['contribution_types']);
    assertSame('Clear contribution notes', $record['contribution_comments']);
    assertSame(['commented_on_draft'], $record['shaping_feedback_types']);
    assertSame(null, $record['shaping_feedback_comments']);
    assertSame('submitted', $record['status']);
});

test('draft is the default status', function () use ($validate, $service, $base) {
    $data = $base;
    unset($data['status']);

    $record = $validate->invoke($service, 10, $data);

    assertSame('draft', $record['status']);
});

test('invalid attendance is rejected', function () use ($validate, $service, $base) {
    $data = array_merge($base, ['attendance' => 'late']);

    assertThrows(fn () => $validate->invoke($service, 10, $data), InvalidArgumentException::class);
});

test('invalid contribution type is rejected', function () use ($validate, $service, $base) {
    $data = array_merge($base, ['contribution_types' => ['discussion', 'invalid']]);

    assertThrows(fn () => $validate->invoke($service, 10, $data), InvalidArgumentException::class);
});

test('non-string list values are rejected', function () use ($validate, $service, $base) {
    $data = array_merge($base, ['shaping_feedback_types' => ['created_draft', 3]]);

    assertThrows(fn () => $validate->invoke($service, 10, $data), InvalidArgumentException::class);
});

test('overlong comments are rejected', function () use ($validate, $service, $base) {
    $data = array_merge($base, ['contribution_comments' => str_repeat('x', 5001)]);

    assertThrows(fn () => $validate->invoke($service, 10, $data), InvalidArgumentException::class);
});

test('hydration decodes json list fields', function () use ($hydrate, $service) {
    $record = $hydrate->invoke($service, [
        'participation_id' => 1,
        'contribution_types' => '["discussion","analysis"]',
        'shaping_feedback_types' => '["uploaded_notes"]',
    ]);

    assertSame(['discussion', 'analysis'], $record['contribution_types']);
    assertSame(['uploaded_notes'], $record['shaping_feedback_types']);
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
