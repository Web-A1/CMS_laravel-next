<?php

namespace App\Services\LegacyUsers;

use InvalidArgumentException;

/** Local source-owner proof built only from exported records and manifest ID proofs. */
final class LegacyUserOwnershipIndex
{
    /** @var array<int, true> */
    private array $sourceMemberIds;

    /** @var array<string, array<int, true>> */
    private array $emailOwners = [];

    /** @var array<int, array{status: string, member: ?int}> */
    private array $orderOwners = [];

    /** @var array<int, array{status: string, member: ?int}> */
    private array $ticketOwners = [];

    /** @var array<int, array{status: string, member: ?int}> */
    private array $pollAnswerOwners = [];

    /** @var array<int, array{status: string, member: ?int}> */
    private array $fingerprintOwners = [];

    /** @var array<int, array{status: string, member: ?int}> */
    private array $cartOwners = [];

    /** @var array<int, array{status: string, member: ?int}> */
    private array $taskOwners = [];

    /** @var array<int, ?int> */
    private array $chatMemberProofs = [];

    /** @var array<int, ?int> */
    private array $leadMagnetContactMemberProofs = [];

    /**
     * @param  array<int, true>  $sourceMemberIds
     */
    private function __construct(private readonly LegacyUserSourcePolicy $policy, array $sourceMemberIds)
    {
        $this->sourceMemberIds = $sourceMemberIds;
    }

    /**
     * @param  array<int, true>  $sourceMemberIds
     * @param  callable(string): iterable<int, array{source_id: int, source_member_id: ?int, ownership_status: string, row: array}>  $rows
     * @param  array<string, mixed>  $ownerProofs
     */
    public static function build(LegacyUserSourcePolicy $policy, array $sourceMemberIds, callable $rows, array $ownerProofs = []): self
    {
        $index = new self($policy, $sourceMemberIds);
        $index->loadOwnerProofs($ownerProofs);
        $index->loadEmailOwners($rows('member'));
        $index->loadOrderOwners($rows('order'));
        $index->ticketOwners = $index->directOwners($rows('ticket'));
        $index->pollAnswerOwners = $index->directOwners($rows('pollAnswer'));
        $index->loadFingerprintOwners($rows('fingerprint_member'));
        $index->loadTaskOwners($rows('task'), $rows('task_linked_item'));

        return $index;
    }

    /**
     * @param  array{source_id: int, source_member_id: ?int, ownership_status: string, row: array}  $record
     */
    public function assert(string $table, array $record): void
    {
        $owner = (string) $this->policy->table($table)['owner'];
        $expected = match (true) {
            str_starts_with($owner, 'order:') => $this->parentOwner($this->orderOwners, $record['row'][explode(':', $owner, 2)[1]] ?? null, $table),
            str_starts_with($owner, 'ticket:') => $this->parentOwner($this->ticketOwners, $record['row'][explode(':', $owner, 2)[1]] ?? null, $table),
            str_starts_with($owner, 'pollAnswer:') => $this->parentOwner($this->pollAnswerOwners, $record['row'][explode(':', $owner, 2)[1]] ?? null, $table),
            str_starts_with($owner, 'task:') => $this->parentOwner($this->taskOwners, $record['row'][explode(':', $owner, 2)[1]] ?? null, $table),
            $owner === 'task_links' => $this->taskOwners[$record['source_id']] ?? throw new InvalidArgumentException('Task owner is missing from the local ownership index.'),
            $owner === 'unique_email' => $this->uniqueEmailOwner($table, $record['row']),
            $owner === 'unique_fingerprint_owner' => $this->fingerprintOwner($table, $record),
            $owner === 'member_or_order' => $this->memberOrOrderOwner($table, $record['row']),
            $owner === 'order_cart' => $this->cartOwners[$record['source_id']] ?? ['status' => 'unlinked', 'member' => null],
            $owner === 'typed_item_member' => $this->typedItemMemberOwner($record['row']),
            default => null,
        };

        if ($expected === null) {
            return;
        }

        if ($record['ownership_status'] !== $expected['status'] || ($record['source_member_id'] ?? null) !== $expected['member']) {
            throw new InvalidArgumentException('Indirect source ownership is inconsistent.');
        }
    }

    /**
     * @param  array<string, mixed>  $ownerProofs
     */
    private function loadOwnerProofs(array $ownerProofs): void
    {
        $this->chatMemberProofs = $this->proofMap($ownerProofs['chat_member_ids'] ?? []);
        $this->leadMagnetContactMemberProofs = $this->proofMap($ownerProofs['lead_magnet_contact_member_ids'] ?? []);
    }

    /**
     * @return array<int, ?int>
     */
    private function proofMap(mixed $proof): array
    {
        if ($proof === null || $proof === []) {
            return [];
        }
        if (! is_array($proof)) {
            throw new InvalidArgumentException('Invalid source owner proof.');
        }

        $map = [];
        foreach ($proof as $sourceId => $memberId) {
            if (! preg_match('/^(0|[1-9]\d{0,9})$/D', (string) $sourceId)) {
                throw new InvalidArgumentException('Invalid source owner proof key.');
            }
            if ($memberId === null || $memberId === 0) {
                $map[(int) $sourceId] = null;

                continue;
            }
            if (! is_int($memberId) || $memberId < 0 || ! isset($this->sourceMemberIds[$memberId])) {
                throw new InvalidArgumentException('Source owner proof references a missing member.');
            }
            $map[(int) $sourceId] = $memberId;
        }

        return $map;
    }

    /**
     * @param  iterable<int, array{source_id: int, source_member_id: ?int, ownership_status: string, row: array}>  $records
     */
    private function loadEmailOwners(iterable $records): void
    {
        foreach ($records as $record) {
            $email = $record['row']['email'] ?? null;
            if (is_string($email)) {
                $this->emailOwners[mb_strtolower(trim($email))][$record['source_id']] = true;
            }
        }
    }

    /**
     * @param  iterable<int, array{source_id: int, source_member_id: ?int, ownership_status: string, row: array}>  $records
     */
    private function loadOrderOwners(iterable $records): void
    {
        $cartMembers = [];
        foreach ($records as $record) {
            $this->orderOwners[$record['source_id']] = $this->ownerFromRecord($record);
            $cartId = $this->sourceReference($record['row']['cartId'] ?? null, 'order.cartId');
            $member = $record['source_member_id'] ?? null;
            if ($cartId !== null && $cartId > 0 && $record['ownership_status'] === 'linked' && $member !== null && $member > 0) {
                $cartMembers[$cartId][$member] = true;
            }
        }
        foreach ($cartMembers as $cartId => $members) {
            $this->cartOwners[$cartId] = $this->ownerFromMemberSet($members, 'unlinked');
        }
    }

    /**
     * @param  iterable<int, array{source_id: int, source_member_id: ?int, ownership_status: string, row: array}>  $records
     * @return array<int, array{status: string, member: ?int}>
     */
    private function directOwners(iterable $records): array
    {
        $owners = [];
        foreach ($records as $record) {
            $owners[$record['source_id']] = $this->ownerFromRecord($record);
        }

        return $owners;
    }

    /**
     * @param  iterable<int, array{source_id: int, source_member_id: ?int, ownership_status: string, row: array}>  $records
     */
    private function loadFingerprintOwners(iterable $records): void
    {
        $membersByFingerprint = [];
        foreach ($records as $record) {
            $fingerprintId = $this->sourceReference($record['row']['fingerprintId'] ?? null, 'fingerprint_member.fingerprintId');
            $memberId = $this->sourceReference($record['row']['memberId'] ?? null, 'fingerprint_member.memberId');
            if ($fingerprintId !== null && $fingerprintId > 0 && $memberId !== null && $memberId > 0 && isset($this->sourceMemberIds[$memberId])) {
                $membersByFingerprint[$fingerprintId][$memberId] = true;
            }
        }
        foreach ($membersByFingerprint as $fingerprintId => $members) {
            $this->fingerprintOwners[$fingerprintId] = $this->ownerFromMemberSet($members, 'anonymous_archive');
        }
    }

    /**
     * @param  iterable<int, array{source_id: int, source_member_id: ?int, ownership_status: string, row: array}>  $tasks
     * @param  iterable<int, array{source_id: int, source_member_id: ?int, ownership_status: string, row: array}>  $links
     */
    private function loadTaskOwners(iterable $tasks, iterable $links): void
    {
        $membersByTask = [];
        foreach ($tasks as $record) {
            $taskId = $record['source_id'];
            $membersByTask[$taskId] ??= [];
            $member = $this->taskTypedMember($record['row']['itemType'] ?? null, $record['row']['itemId'] ?? null);
            if ($member !== null) {
                $membersByTask[$taskId][$member] = true;
            }
        }
        foreach ($links as $record) {
            $taskId = $this->sourceReference($record['row']['taskId'] ?? null, 'task_linked_item.taskId');
            if ($taskId === null || ! array_key_exists($taskId, $membersByTask)) {
                continue;
            }
            $member = $this->taskTypedMember($record['row']['itemType'] ?? null, $record['row']['itemId'] ?? null);
            if ($member !== null) {
                $membersByTask[$taskId][$member] = true;
            }
        }
        foreach ($membersByTask as $taskId => $members) {
            $this->taskOwners[$taskId] = $this->ownerFromMemberSet($members, 'unlinked');
        }
    }

    private function taskTypedMember(mixed $itemType, mixed $itemId): ?int
    {
        if (! is_string($itemType)) {
            return null;
        }

        $id = $this->sourceReference($itemId, 'task.itemId');

        return match ($itemType) {
            'member' => $id !== null && $id > 0 && isset($this->sourceMemberIds[$id]) ? $id : null,
            'order' => $this->linkedParentMember($this->orderOwners, $id),
            'chat' => $this->provedTaskMember($this->chatMemberProofs, $id, 'chat'),
            'leadMagnetContact' => $this->provedTaskMember($this->leadMagnetContactMemberProofs, $id, 'leadMagnetContact'),
            default => null,
        };
    }

    /**
     * @param  array<int, ?int>  $proofs
     */
    private function provedTaskMember(array $proofs, ?int $id, string $type): ?int
    {
        if ($id === null || $id === 0) {
            return null;
        }
        if (! array_key_exists($id, $proofs)) {
            throw new InvalidArgumentException('Missing '.$type.' task owner proof.');
        }

        return $proofs[$id];
    }

    /**
     * @param  array<int, array{status: string, member: ?int}>  $parents
     */
    private function linkedParentMember(array $parents, ?int $id): ?int
    {
        if ($id === null || ! isset($parents[$id])) {
            return null;
        }

        return $parents[$id]['status'] === 'linked' ? $parents[$id]['member'] : null;
    }

    /**
     * @param  array<int, array{status: string, member: ?int}>  $parents
     * @return array{status: string, member: ?int}
     */
    private function parentOwner(array $parents, mixed $parentId, string $table): array
    {
        $id = $this->sourceReference($parentId, $table.'.owner');
        if ($id === null || ! isset($parents[$id])) {
            return ['status' => 'orphan', 'member' => null];
        }

        $owner = $parents[$id];
        if ($owner['status'] === 'linked') {
            return ['status' => 'linked', 'member' => $owner['member']];
        }
        if ($owner['status'] === 'ambiguous') {
            return ['status' => 'ambiguous', 'member' => null];
        }
        if ($owner['status'] === 'reference') {
            return ['status' => 'reference', 'member' => null];
        }

        return ['status' => $owner['status'], 'member' => $owner['member']];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{status: string, member: ?int}
     */
    private function uniqueEmailOwner(string $table, array $row): array
    {
        $field = $table === 'coupon' ? 'emailBind' : 'email';
        $value = $row[$field] ?? '';
        if ($value === null) {
            $value = '';
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException('Invalid unique-email owner field.');
        }

        $email = mb_strtolower(trim($value));
        if ($table === 'coupon' && $email === '') {
            return ['status' => 'reference', 'member' => null];
        }

        return $this->ownerFromMemberSet($this->emailOwners[$email] ?? [], 'unlinked');
    }

    /**
     * @param  array{source_id: int, source_member_id: ?int, ownership_status: string, row: array}  $record
     * @return array{status: string, member: ?int}
     */
    private function fingerprintOwner(string $table, array $record): array
    {
        $fingerprintId = $table === 'fingerprint'
            ? $record['source_id']
            : $this->sourceReference($record['row']['fingerprintId'] ?? null, $table.'.fingerprintId');

        return $this->fingerprintOwners[$fingerprintId] ?? ['status' => 'anonymous_archive', 'member' => null];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{status: string, member: ?int}
     */
    private function memberOrOrderOwner(string $table, array $row): array
    {
        $directMember = $this->sourceReference($row['memberId'] ?? null, $table.'.memberId');
        $orderField = $table === 'lottery_ticket' ? 'orderId' : 'buyAfterId';
        $orderMember = $this->linkedParentMember($this->orderOwners, $this->sourceReference($row[$orderField] ?? null, $table.'.'.$orderField));
        $validDirectMember = $directMember !== null && $directMember > 0 && isset($this->sourceMemberIds[$directMember]) ? $directMember : null;

        if ($validDirectMember !== null && $orderMember !== null && $validDirectMember !== $orderMember) {
            return ['status' => 'ambiguous', 'member' => null];
        }

        $member = $validDirectMember ?? $orderMember;
        if ($member !== null) {
            return ['status' => 'linked', 'member' => $member];
        }
        if ($directMember !== null && $directMember > 0) {
            return ['status' => 'orphan', 'member' => $directMember];
        }

        return ['status' => 'unlinked', 'member' => null];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{status: string, member: ?int}
     */
    private function typedItemMemberOwner(array $row): array
    {
        if (($row['itemType'] ?? null) !== 'member') {
            return ['status' => 'reference', 'member' => null];
        }

        $member = $this->sourceReference($row['itemId'] ?? null, 'typed_item_member.itemId');
        if ($member !== null && $member > 0 && isset($this->sourceMemberIds[$member])) {
            return ['status' => 'linked', 'member' => $member];
        }

        return ['status' => 'orphan', 'member' => $member];
    }

    /**
     * @param  array{source_id: int, source_member_id: ?int, ownership_status: string, row: array}  $record
     * @return array{status: string, member: ?int}
     */
    private function ownerFromRecord(array $record): array
    {
        return ['status' => $record['ownership_status'], 'member' => $record['source_member_id'] ?? null];
    }

    /**
     * @param  array<int, true>  $members
     * @return array{status: string, member: ?int}
     */
    private function ownerFromMemberSet(array $members, string $emptyStatus): array
    {
        $ids = array_keys($members);
        sort($ids);

        return match (count($ids)) {
            0 => ['status' => $emptyStatus, 'member' => null],
            1 => ['status' => 'linked', 'member' => (int) $ids[0]],
            default => ['status' => 'ambiguous', 'member' => null],
        };
    }

    private function sourceReference(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException('Invalid source ownership reference: '.$field.'.');
        }

        return $value;
    }
}
