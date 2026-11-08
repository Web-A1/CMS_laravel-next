<?php

namespace App\Exceptions;

use RuntimeException;

class LessonAssessmentException extends RuntimeException
{
    public const ASSESSMENT_NOT_FOUND = 'LEARNING_ASSESSMENT_NOT_FOUND';

    public const ATTEMPT_NOT_FOUND = 'LEARNING_ASSESSMENT_ATTEMPT_NOT_FOUND';

    public const ATTEMPT_CONFLICT = 'LEARNING_ASSESSMENT_ATTEMPT_CONFLICT';

    public const LOCKED_RESPONSE = 'LEARNING_ASSESSMENT_LOCKED_RESPONSE';

    public const INCOMPLETE = 'LEARNING_ASSESSMENT_INCOMPLETE';

    public const INVALID_RESPONSE = 'LEARNING_ASSESSMENT_RESPONSE_INVALID';

    public const SUGGESTION_FORBIDDEN = 'LEARNING_ASSESSMENT_SUGGESTION_FORBIDDEN';

    public const REVIEW_NOT_FOUND = 'ADMIN_ASSESSMENT_REVIEW_NOT_FOUND';

    public const REVIEW_FORBIDDEN = 'ADMIN_ASSESSMENT_REVIEW_FORBIDDEN';

    public const REVIEW_CONFLICT = 'ADMIN_ASSESSMENT_REVIEW_CONFLICT';

    public const REVIEW_INCOMPLETE = 'ADMIN_ASSESSMENT_REVIEW_INCOMPLETE';

    /** @param array<string, mixed> $meta */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
        public readonly array $meta = [],
    ) {
        parent::__construct($message);
    }
}
