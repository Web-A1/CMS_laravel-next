<?php

namespace App\Services\Learning;

use App\Models\LessonAssessment;
use App\Models\LessonAssessmentAttempt;
use App\Models\LessonAssessmentVersion;
use App\Models\User;

final class LessonAssessmentStateSelector
{
    /** @return array{version: LessonAssessmentVersion, attempt: LessonAssessmentAttempt|null}|null */
    public function select(User $user, LessonAssessment $assessment): ?array
    {
        $attempt = LessonAssessmentAttempt::query()
            ->where('user_id', $user->id)
            ->where('lesson_assessment_id', $assessment->id)
            ->whereIn('status', [
                LessonAssessmentAttempt::STATUS_IN_PROGRESS,
                LessonAssessmentAttempt::STATUS_PENDING_REVIEW,
            ])
            ->orderBy('id')
            ->first();
        if ($attempt instanceof LessonAssessmentAttempt) {
            $version = $this->attemptVersion($attempt, $assessment);
            if ($version instanceof LessonAssessmentVersion) {
                return ['version' => $version, 'attempt' => $attempt];
            }

            return null;
        }

        $attempt = LessonAssessmentAttempt::query()
            ->where('user_id', $user->id)
            ->where('lesson_assessment_id', $assessment->id)
            ->whereIn('status', [
                LessonAssessmentAttempt::STATUS_FAILED,
                LessonAssessmentAttempt::STATUS_PASSED,
            ])
            ->orderByDesc('id')
            ->first();
        if ($attempt instanceof LessonAssessmentAttempt) {
            $version = $this->attemptVersion($attempt, $assessment);
            if ($version instanceof LessonAssessmentVersion) {
                return ['version' => $version, 'attempt' => $attempt];
            }

            return null;
        }

        $version = LessonAssessmentVersion::query()
            ->where('lesson_assessment_id', $assessment->id)
            ->where('status', LessonAssessmentVersion::STATUS_PUBLISHED)
            ->first();

        return $version instanceof LessonAssessmentVersion
            ? ['version' => $version, 'attempt' => null]
            : null;
    }

    private function attemptVersion(
        LessonAssessmentAttempt $attempt,
        LessonAssessment $assessment,
    ): ?LessonAssessmentVersion {
        return LessonAssessmentVersion::query()
            ->whereKey($attempt->lesson_assessment_version_id)
            ->where('lesson_assessment_id', $assessment->id)
            ->first();
    }
}
