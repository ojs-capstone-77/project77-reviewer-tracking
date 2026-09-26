<?php

/**
 * Unit tests for participation retrieval and its OJS JSON response.
 *
 * php /var/www/html/plugins/generic/groupReview/tests/ParticipationReadTest.php
 */

namespace Illuminate\Support\Facades {
    class DB
    {
        public static array $rows = [];
        public static array $groups = [];
        public static array $memberships = [];
        public static bool $fail = false;

        public static function table(string $table): FakeQuery
        {
            if (self::$fail) {
                throw new \RuntimeException('Database unavailable');
            }
            return new FakeQuery(match ($table) {
                'group_review_participation' => self::$rows,
                'user_groups' => self::$groups,
                'user_user_groups' => self::$memberships,
                default => throw new \RuntimeException("Unexpected table: {$table}"),
            });
        }
    }

    class FakeQuery
    {
        private array $filters = [];
        private array $orders = [];

        public function __construct(private array $rows)
        {
        }

        public function where(string $field, mixed $value): self
        {
            $this->filters[$field] = $value;
            return $this;
        }

        public function orderBy(string $field): self
        {
            $this->orders[] = $field;
            return $this;
        }

        public function get(): FakeCollection
        {
            $rows = array_values(array_filter($this->rows, fn ($row): bool => $this->matches($row)));
            usort($rows, function ($a, $b): int {
                foreach ($this->orders as $field) {
                    $difference = $a->$field <=> $b->$field;
                    if ($difference) {
                        return $difference;
                    }
                }
                return 0;
            });
            return new FakeCollection($rows);
        }

        public function exists(): bool
        {
            foreach ($this->rows as $row) {
                if ($this->matches($row)) {
                    return true;
                }
            }
            return false;
        }

        private function matches(object $row): bool
        {
            foreach ($this->filters as $field => $value) {
                if ($row->$field !== $value) {
                    return false;
                }
            }
            return true;
        }
    }

    class FakeCollection
    {
        public function __construct(private array $rows)
        {
        }

        public function map(callable $callback): self
        {
            return new self(array_map($callback, $this->rows));
        }

        public function all(): array
        {
            return $this->rows;
        }
    }
}

namespace APP\plugins\generic\groupReview\classes {
    class GroupReviewService
    {
        public bool $leader = false;
        public ?int $qualityEditorGroupId = null;

        public function getUserGroupIdByAbbreviation(int $contextId, string $abbreviation): ?int
        {
            return $this->qualityEditorGroupId;
        }

        public function isLeader(int $contextId, int $submissionId, int $userId): bool
        {
            return $this->leader;
        }
    }
}

namespace APP\handler {
    class Handler
    {
        public array $assignments = [];

        public function __construct()
        {
        }

        public function addRoleAssignment(mixed $roles, array $operations): void
        {
            $this->assignments[] = [$roles, $operations];
        }
    }
}

namespace APP\plugins\generic\groupReview {
    class GroupReviewPlugin
    {
    }
}

namespace APP\facades {
    class Repo
    {
        public static ?object $submission = null;

        public static function submission(): object
        {
            return new class {
                public function get(int $id): ?object
                {
                    return Repo::$submission;
                }
            };
        }
    }
}

namespace PKP\security {
    class Role
    {
        public const ROLE_ID_MANAGER = 16;
        public const ROLE_ID_SUB_EDITOR = 17;
        public const ROLE_ID_REVIEWER = 4096;
    }
}

namespace PKP\core {
    class JSONMessage
    {
        private array $attributes = [];

        public function __construct(public bool $status, public string $message)
        {
        }

        public function setAdditionalAttributes(array $attributes): void
        {
            $this->attributes = $attributes;
        }

        public function getAdditionalAttributes(): array
        {
            return $this->attributes;
        }
    }
}

namespace {
    require_once dirname(__DIR__) . '/classes/ParticipationService.php';
    require_once dirname(__DIR__) . '/pages/groupReview/GroupReviewHandler.php';

    use APP\facades\Repo;
    use APP\plugins\generic\groupReview\classes\GroupReviewService;
    use APP\plugins\generic\groupReview\classes\ParticipationService;
    use APP\plugins\generic\groupReview\pages\groupReview\GroupReviewHandler;
    use APP\plugins\generic\groupReview\GroupReviewPlugin;
    use Illuminate\Support\Facades\DB;

    function __($key): string
    {
        return $key;
    }

    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    function record(int $context, int $submission, int $reviewer, int $id): object
    {
        return (object) [
            'context_id' => $context,
            'submission_id' => $submission,
            'reviewer_user_id' => $reviewer,
            'participation_id' => $id,
            'contribution_types' => '["analysis"]',
            'shaping_feedback_types' => '["created_draft"]',
        ];
    }

    DB::$rows = [
        record(10, 20, 4, 2),
        record(10, 20, 3, 1),
        record(10, 21, 3, 3),
        record(11, 20, 3, 4),
    ];
    Repo::$submission = new class {
        public function getContextId(): int
        {
            return 10;
        }
    };

    $service = new ParticipationService();
    $all = $service->getForSubmission(10, 20);
    check(array_column($all, 'participation_id') === [1, 2], 'Submission and journal filters or sorting failed');
    check($all[0]['contribution_types'] === ['analysis'], 'Records were not hydrated');
    check(array_column($service->getForSubmission(10, 20, 4), 'participation_id') === [2], 'Reviewer filter failed');
    check($service->getForSubmission(10, 22) === [], 'Empty result should be an array');
    check($service->readableReviewerId(null, 3, false) === 3, 'Own records must be enforced');
    check($service->readableReviewerId(4, 3, true) === 4, 'Managers can select a reviewer');
    try {
        $service->readableReviewerId(4, 3, false);
        throw new \RuntimeException('Another reviewer was allowed');
    } catch (\DomainException $e) {
    }

    $handler = new GroupReviewHandler(new GroupReviewPlugin());
    $reviewService = (new \ReflectionProperty(GroupReviewHandler::class, 'service'))->getValue($handler);
    check(
        in_array(
            [[16, 17, 4096], ['getParticipation']],
            $handler->assignments,
            true
        ),
        'Read endpoint must require an OJS journal role'
    );

    function request(array $vars, bool $manager = false, bool $post = false, int $contextId = 10): object
    {
        return new class ($vars, $manager, $post, $contextId) {
            public function __construct(
                private array $vars,
                private bool $manager,
                private bool $post,
                private int $contextId
            ) {
            }

            public function isPost(): bool
            {
                return $this->post;
            }

            public function getUserVar(string $key): mixed
            {
                return $this->vars[$key] ?? null;
            }

            public function getContext(): object
            {
                return new class ($this->contextId) {
                    public function __construct(private int $id)
                    {
                    }

                    public function getId(): int
                    {
                        return $this->id;
                    }
                };
            }

            public function getUser(): object
            {
                return new class ($this->manager) {
                    public function __construct(private bool $manager)
                    {
                    }

                    public function getId(): int
                    {
                        return 3;
                    }

                    public function hasRole(array $roles, int $contextId): bool
                    {
                        return $this->manager;
                    }
                };
            }
        };
    }

    $response = $handler->getParticipation([], request(['submissionId' => 20]));
    check($response->status && $response->getAdditionalAttributes()['code'] === 'ok', 'Own read failed');
    check(array_column($response->getAdditionalAttributes()['records'], 'participation_id') === [1], 'Own read leaked another reviewer');

    $response = $handler->getParticipation([], request(['submissionId' => 20], true));
    check(array_column($response->getAdditionalAttributes()['records'], 'participation_id') === [1, 2], 'Manager read failed');
    $response = $handler->getParticipation([], request(['submissionId' => 22], true));
    check($response->status && $response->getAdditionalAttributes()['records'] === [], 'Empty submission must succeed with an empty array');

    $reviewService->leader = true;
    $response = $handler->getParticipation([], request(['submissionId' => 20, 'reviewerUserId' => 4]));
    check(array_column($response->getAdditionalAttributes()['records'], 'participation_id') === [2], 'Assigned leader read failed');
    $reviewService->leader = false;

    $reviewService->qualityEditorGroupId = 7;
    DB::$groups = [(object) ['user_group_id' => 7, 'role_id' => 17]];
    DB::$memberships = [(object) ['user_group_id' => 7, 'user_id' => 3]];
    $response = $handler->getParticipation([], request(['submissionId' => 20]));
    check(count($response->getAdditionalAttributes()['records']) === 2, 'Quality editor read failed');
    DB::$groups = [(object) ['user_group_id' => 7, 'role_id' => 4096]];
    $response = $handler->getParticipation([], request(['submissionId' => 20]));
    check(count($response->getAdditionalAttributes()['records']) === 1, 'Non-editor QRE group read all records');
    $reviewService->qualityEditorGroupId = null;

    foreach ([
        [request(['submissionId' => 20, 'reviewerUserId' => 4]), 'forbidden'],
        [request(['submissionId' => '20x']), 'invalid_request'],
        [request(['submissionId' => 20, 'reviewerUserId' => 0]), 'invalid_request'],
        [request(['submissionId' => 20], false, true), 'invalid_request'],
        [request(['submissionId' => 20], true, false, 11), 'not_found'],
    ] as [$request, $code]) {
        $response = $handler->getParticipation([], $request);
        check(!$response->status && $response->getAdditionalAttributes()['code'] === $code, "Expected {$code}");
    }

    DB::$fail = true;
    $response = $handler->getParticipation([], request(['submissionId' => 20], true));
    check(!$response->status && $response->getAdditionalAttributes()['code'] === 'read_failed', 'Database errors must be reported');

    echo "Participation retrieval tests passed.\n";
}
