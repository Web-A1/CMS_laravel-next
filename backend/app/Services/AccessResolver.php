<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserEntitlementGrant;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class AccessResolver
{
    public const ACCESS_FULL = 'full';

    public const ACCESS_INACTIVE = 'inactive';

    public const ACCESS_NONE = 'none';

    public const ACCESS_NOT_FOUND = 'not_found';

    public const ACCESS_PARTIAL = 'partial';

    public const ENTITY_BOX = 'box';

    public const ENTITY_COURSE = 'course';

    public const ENTITY_GUIDE = 'guide';

    public const ENTITY_INTENSIVE = 'intensive';

    public const ENTITY_SUBJECT = 'subject';

    public const ENTITY_LESSON = 'lesson';

    public const ENTITY_TYPES = [
        self::ENTITY_LESSON,
        self::ENTITY_GUIDE,
        self::ENTITY_COURSE,
        self::ENTITY_INTENSIVE,
        self::ENTITY_SUBJECT,
        self::ENTITY_BOX,
    ];

    /**
     * @param  array<int, array{kind:string,id:int|string}>  $atoms
     * @return array{
     *     entity_type:string,
     *     entity_id:int|string|null,
     *     access:string,
     *     required_atoms_count:int,
     *     owned_atoms_count:int,
     *     required_atoms:array<int, array{kind:string,id:int}>,
     *     owned_atoms:array<int, array{kind:string,id:int}>,
     *     missing_atoms:array<int, array{kind:string,id:int}>
     * }
     */
    public function check(User|int $user, string $entityType, int|string|null $entityId = null, array $atoms = []): array
    {
        $userId = $user instanceof User ? (int) $user->id : $user;
        $entityType = $this->normalizeEntityType($entityType);
        $resolution = $this->requiredAtomsFor($entityType, $entityId, $atoms);

        if ($resolution['access'] !== null) {
            return $this->emptyResult($entityType, $entityId, $resolution['access']);
        }

        return $this->resultForAtoms($userId, $entityType, $entityId, $resolution['atoms']);
    }

    /**
     * @param  array<int, array{kind:string,id:int|string}>  $atoms
     * @return array<int, array{kind:string,id:int}>
     */
    public function normalizeAtoms(array $atoms): array
    {
        $normalized = [];

        foreach ($atoms as $atom) {
            $kind = (string) ($atom['kind'] ?? $atom['atom_kind'] ?? '');
            $id = (int) ($atom['id'] ?? $atom['atom_id'] ?? 0);
            $this->assertAtom($kind, $id);

            $normalized[UserEntitlementGrant::atomKeyFor($kind, $id)] = [
                'kind' => $kind,
                'id' => $id,
            ];
        }

        return array_values($normalized);
    }

    /**
     * @param  array<int, array{kind:string,id:int}>  $requiredAtoms
     * @return array{
     *     entity_type:string,
     *     entity_id:int|string|null,
     *     access:string,
     *     required_atoms_count:int,
     *     owned_atoms_count:int,
     *     required_atoms:array<int, array{kind:string,id:int}>,
     *     owned_atoms:array<int, array{kind:string,id:int}>,
     *     missing_atoms:array<int, array{kind:string,id:int}>
     * }
     */
    public function resultForAtoms(int $userId, string $entityType, int|string|null $entityId, array $requiredAtoms): array
    {
        $requiredAtoms = $this->normalizeAtoms($requiredAtoms);
        $requiredKeys = array_fill_keys(array_map(
            fn (array $atom): string => UserEntitlementGrant::atomKeyFor($atom['kind'], $atom['id']),
            $requiredAtoms
        ), true);

        if ($requiredKeys === []) {
            return [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'access' => self::ACCESS_NONE,
                'required_atoms_count' => 0,
                'owned_atoms_count' => 0,
                'required_atoms' => [],
                'owned_atoms' => [],
                'missing_atoms' => [],
            ];
        }

        $ownedKeys = UserEntitlementGrant::query()
            ->forUser($userId)
            ->effectiveActive()
            ->whereIn('atom_kind', array_unique(array_column($requiredAtoms, 'kind')))
            ->whereIn('atom_id', array_unique(array_column($requiredAtoms, 'id')))
            ->get(['atom_kind', 'atom_id'])
            ->mapWithKeys(fn (UserEntitlementGrant $grant): array => [$grant->atomKey() => true])
            ->intersectByKeys($requiredKeys)
            ->all();

        return $this->resultWithOwnedKeys($entityType, $entityId, $requiredAtoms, $ownedKeys);
    }

    /** Resolve a page of courses with two shared queries, using the same atom rules as check(). */
    public function coursesForUser(int $userId, Collection $courses): array
    {
        $courses->load(['lessons' => fn ($query) => $query->active()]);
        $lessonIds = $courses->flatMap(fn ($course) => $course->lessons->modelKeys())->unique();
        $ownedKeys = UserEntitlementGrant::query()->forUser($userId)->effectiveActive()
            ->where('atom_kind', UserEntitlementGrant::ATOM_LESSON)->whereIn('atom_id', $lessonIds)
            ->get(['atom_kind', 'atom_id'])->mapWithKeys(fn ($grant) => [$grant->atomKey() => true])->all();

        return $courses->mapWithKeys(function (Course $course) use ($ownedKeys): array {
            $result = $course->is_active
                ? $this->resultWithOwnedKeys(self::ENTITY_COURSE, $course->id, $this->lessonRowsToAtoms($course->lessons), $ownedKeys)
                : $this->emptyResult(self::ENTITY_COURSE, $course->id, self::ACCESS_INACTIVE);

            return [$course->id => array_intersect_key($result, array_flip(['access', 'required_atoms_count', 'owned_atoms_count']))];
        })->all();
    }

    private function resultWithOwnedKeys(string $entityType, int|string|null $entityId, array $requiredAtoms, array $ownedKeys): array
    {
        $ownedAtoms = array_values(array_filter(
            $requiredAtoms,
            fn (array $atom): bool => isset($ownedKeys[UserEntitlementGrant::atomKeyFor($atom['kind'], $atom['id'])])
        ));
        $missingAtoms = array_values(array_filter(
            $requiredAtoms,
            fn (array $atom): bool => ! isset($ownedKeys[UserEntitlementGrant::atomKeyFor($atom['kind'], $atom['id'])])
        ));

        $ownedCount = count($ownedAtoms);
        $requiredCount = count($requiredAtoms);
        $access = match (true) {
            $ownedCount === 0 => self::ACCESS_NONE,
            $ownedCount === $requiredCount => self::ACCESS_FULL,
            default => self::ACCESS_PARTIAL,
        };

        return [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'access' => $access,
            'required_atoms_count' => $requiredCount,
            'owned_atoms_count' => $ownedCount,
            'required_atoms' => $requiredAtoms,
            'owned_atoms' => $ownedAtoms,
            'missing_atoms' => $missingAtoms,
        ];
    }

    /**
     * @param  array<int, array{kind:string,id:int|string}>  $atoms
     * @return array{access:string|null,atoms:array<int, array{kind:string,id:int}>}
     */
    private function requiredAtomsFor(string $entityType, int|string|null $entityId, array $atoms): array
    {
        return match ($entityType) {
            self::ENTITY_LESSON => $this->lessonAtoms($entityId),
            self::ENTITY_GUIDE => ['access' => null, 'atoms' => $this->normalizeAtoms([[
                'kind' => UserEntitlementGrant::ATOM_GUIDE,
                'id' => $this->positiveEntityId($entityId),
            ]])],
            self::ENTITY_COURSE, self::ENTITY_INTENSIVE => $this->courseAtoms($entityId),
            self::ENTITY_SUBJECT => $this->subjectAtoms($entityId),
            self::ENTITY_BOX => ['access' => null, 'atoms' => $this->normalizeAtoms($atoms)],
            default => throw new InvalidArgumentException("Unsupported entity type [{$entityType}]."),
        };
    }

    /**
     * @return array{access:string|null,atoms:array<int, array{kind:string,id:int}>}
     */
    private function lessonAtoms(int|string|null $lessonId): array
    {
        $lesson = Lesson::query()->find($this->positiveEntityId($lessonId));

        if (! $lesson) {
            return ['access' => self::ACCESS_NOT_FOUND, 'atoms' => []];
        }

        if (! $lesson->is_active) {
            return ['access' => self::ACCESS_INACTIVE, 'atoms' => []];
        }

        return ['access' => null, 'atoms' => [[
            'kind' => UserEntitlementGrant::ATOM_LESSON,
            'id' => (int) $lesson->id,
        ]]];
    }

    /**
     * @return array{access:string|null,atoms:array<int, array{kind:string,id:int}>}
     */
    private function courseAtoms(int|string|null $courseId): array
    {
        $course = Course::query()
            ->with(['lessons' => fn ($query) => $query->active()])
            ->find($this->positiveEntityId($courseId));

        if (! $course) {
            return ['access' => self::ACCESS_NOT_FOUND, 'atoms' => []];
        }

        if (! $course->is_active) {
            return ['access' => self::ACCESS_INACTIVE, 'atoms' => []];
        }

        return ['access' => null, 'atoms' => $this->lessonRowsToAtoms($course->lessons)];
    }

    /**
     * @return array{access:string|null,atoms:array<int, array{kind:string,id:int}>}
     */
    private function subjectAtoms(int|string|null $subjectId): array
    {
        $subject = Subject::query()
            ->with(['courses' => fn ($query) => $query->active()->with(['lessons' => fn ($lessonQuery) => $lessonQuery->active()])])
            ->find($this->positiveEntityId($subjectId));

        if (! $subject) {
            return ['access' => self::ACCESS_NOT_FOUND, 'atoms' => []];
        }

        if (! $subject->is_active) {
            return ['access' => self::ACCESS_INACTIVE, 'atoms' => []];
        }

        $atoms = [];
        foreach ($subject->courses as $course) {
            foreach ($this->lessonRowsToAtoms($course->lessons) as $atom) {
                $atoms[] = $atom;
            }
        }

        return ['access' => null, 'atoms' => $atoms];
    }

    private function normalizeEntityType(string $entityType): string
    {
        $entityType = strtolower(trim($entityType));

        if (! in_array($entityType, self::ENTITY_TYPES, true)) {
            throw new InvalidArgumentException("Unsupported entity type [{$entityType}].");
        }

        return $entityType;
    }

    private function positiveEntityId(int|string|null $entityId): int
    {
        $id = (int) $entityId;

        if ($id < 1) {
            throw new InvalidArgumentException('Entity id must be a positive integer.');
        }

        return $id;
    }

    private function assertAtom(string $kind, int $id): void
    {
        if (! in_array($kind, UserEntitlementGrant::ATOM_KINDS, true)) {
            throw new InvalidArgumentException("Unsupported atom kind [{$kind}].");
        }

        if ($id < 1) {
            throw new InvalidArgumentException('Atom id must be a positive integer.');
        }
    }

    /**
     * @param  iterable<Lesson>  $lessons
     * @return array<int, array{kind:string,id:int}>
     */
    private function lessonRowsToAtoms(iterable $lessons): array
    {
        $atoms = [];

        foreach ($lessons as $lesson) {
            $atoms[] = [
                'kind' => UserEntitlementGrant::ATOM_LESSON,
                'id' => (int) $lesson->id,
            ];
        }

        return $this->normalizeAtoms($atoms);
    }

    /**
     * @return array{
     *     entity_type:string,
     *     entity_id:int|string|null,
     *     access:string,
     *     required_atoms_count:int,
     *     owned_atoms_count:int,
     *     required_atoms:array<int, array{kind:string,id:int}>,
     *     owned_atoms:array<int, array{kind:string,id:int}>,
     *     missing_atoms:array<int, array{kind:string,id:int}>
     * }
     */
    private function emptyResult(string $entityType, int|string|null $entityId, string $access): array
    {
        return [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'access' => $access,
            'required_atoms_count' => 0,
            'owned_atoms_count' => 0,
            'required_atoms' => [],
            'owned_atoms' => [],
            'missing_atoms' => [],
        ];
    }
}
