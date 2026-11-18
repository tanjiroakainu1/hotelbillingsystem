<?php
declare(strict_types=1);

final class HousekeepingModel
{
    public function __construct(private mysqli $conn) {}

    public function createForCheckout(int $bookingId, int $roomId, int $actorId): void
    {
        $assignee = $this->conn->query(
            "SELECT u.id
             FROM users u
             LEFT JOIN housekeeping_tasks t
               ON t.assigned_to = u.id AND t.status IN ('pending','in_progress')
             WHERE u.role = 'housekeeping' AND u.status = 'active'
             GROUP BY u.id
             ORDER BY COUNT(t.id), u.id
             LIMIT 1"
        )->fetch_assoc();
        $assignedTo = $assignee ? (int) $assignee['id'] : null;

        $task = $this->conn->prepare(
            "INSERT INTO housekeeping_tasks (booking_id, room_id, assigned_to, assigned_by)
             VALUES (?, ?, ?, ?)"
        );
        $task->bind_param('iiii', $bookingId, $roomId, $assignedTo, $actorId);
        $task->execute();
        $taskId = (int) $this->conn->insert_id;

        $inventory = $this->conn->prepare(
            "INSERT IGNORE INTO room_amenities (room_id, amenity_id)
             SELECT ?, id FROM housekeeping_amenities WHERE status = 'active'"
        );
        $inventory->bind_param('i', $roomId);
        $inventory->execute();

        $checklist = $this->conn->prepare(
            "INSERT INTO housekeeping_task_items (task_id, room_amenity_id, item_name)
             SELECT ?, ra.id, ha.item_name
             FROM room_amenities ra
             JOIN housekeeping_amenities ha ON ha.id = ra.amenity_id
             WHERE ra.room_id = ? AND ra.status = 'active' AND ha.status = 'active'"
        );
        $checklist->bind_param('ii', $taskId, $roomId);
        $checklist->execute();

        $room = $this->conn->prepare("UPDATE rooms SET status = 'cleaning' WHERE id = ?");
        $room->bind_param('i', $roomId);
        $room->execute();

        audit($this->conn, $actorId, 'housekeeping_task_created', "Task #{$taskId} created for booking #{$bookingId}");
    }

    public function managementPage(): array
    {
        $tasks = $this->conn->query(
            "SELECT t.id, t.booking_id, t.room_id, t.assigned_to, t.assigned_by, t.status, t.created_at, t.completed_at,
                    r.room_number, r.status AS room_status, g.full_name AS guest_name,
                    b.check_out, b.check_out_time, assigned.full_name AS assigned_to_name,
                    assigning.full_name AS assigned_by_name
             FROM housekeeping_tasks t
             JOIN rooms r ON r.id = t.room_id
             JOIN bookings b ON b.id = t.booking_id
             JOIN guests g ON g.id = b.guest_id
             LEFT JOIN users assigned ON assigned.id = t.assigned_to
             LEFT JOIN users assigning ON assigning.id = t.assigned_by
             ORDER BY FIELD(t.status,'pending','in_progress','completed'), t.created_at DESC, t.id DESC"
        )->fetch_all(MYSQLI_ASSOC);
        foreach ($tasks as &$task) {
            $task['items'] = $this->taskItems((int) $task['id']);
        }
        unset($task);

        $housekeepers = $this->conn->query(
            "SELECT id, full_name, email FROM users
             WHERE role = 'housekeeping' AND status = 'active'
             ORDER BY full_name, id"
        )->fetch_all(MYSQLI_ASSOC);
        return ['tasks' => $tasks, 'housekeepers' => $housekeepers];
    }

    public function staffPage(int $userId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT t.id, t.booking_id, t.room_id, t.assigned_to, t.assigned_by, t.status, t.created_at, t.completed_at,
                    r.room_number, r.status AS room_status, g.full_name AS guest_name,
                    b.check_out, b.check_out_time, assigned.full_name AS assigned_to_name,
                    assigning.full_name AS assigned_by_name
             FROM housekeeping_tasks t
             JOIN rooms r ON r.id = t.room_id
             JOIN bookings b ON b.id = t.booking_id
             JOIN guests g ON g.id = b.guest_id
             LEFT JOIN users assigned ON assigned.id = t.assigned_to
             LEFT JOIN users assigning ON assigning.id = t.assigned_by
             WHERE t.assigned_to = ? OR t.assigned_to IS NULL
             ORDER BY FIELD(t.status,'pending','in_progress','completed'), t.created_at, t.id"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($tasks as &$task) {
            $task['items'] = $this->taskItems((int) $task['id']);
        }
        unset($task);
        return ['tasks' => $tasks, 'user_id' => $userId];
    }

    public function assign(int $taskId, int $assigneeId, int $actorId): void
    {
        $task = $this->findTask($taskId);
        if (!$task || $task['status'] === 'completed') {
            throw new InvalidArgumentException('This housekeeping task is unavailable or already complete.');
        }

        $staff = $this->conn->prepare(
            "SELECT id FROM users WHERE id = ? AND role = 'housekeeping' AND status = 'active' LIMIT 1"
        );
        $staff->bind_param('i', $assigneeId);
        $staff->execute();
        if (!$staff->get_result()->fetch_assoc()) {
            throw new InvalidArgumentException('Choose an active housekeeping staff account.');
        }

        $stmt = $this->conn->prepare('UPDATE housekeeping_tasks SET assigned_to = ?, assigned_by = ? WHERE id = ?');
        $stmt->bind_param('iii', $assigneeId, $actorId, $taskId);
        $stmt->execute();
        audit($this->conn, $actorId, 'housekeeping_task_assigned', "Task #{$taskId} assigned to user #{$assigneeId}");
    }

    public function claim(int $taskId, int $userId): void
    {
        $stmt = $this->conn->prepare(
            "UPDATE housekeeping_tasks SET assigned_to = ?, assigned_by = ?
             WHERE id = ? AND assigned_to IS NULL AND status <> 'completed'"
        );
        $stmt->bind_param('iii', $userId, $userId, $taskId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            throw new InvalidArgumentException('This task has already been assigned or completed. Refresh the page.');
        }
        audit($this->conn, $userId, 'housekeeping_task_claimed', "Claimed task #{$taskId}");
    }

    public function saveInspection(int $taskId, int $userId, array $items, bool $complete): void
    {
        $task = $this->findTask($taskId);
        if (!$task || (int) ($task['assigned_to'] ?? 0) !== $userId) {
            throw new InvalidArgumentException('This task is not assigned to your account.');
        }
        if ($task['status'] === 'completed') {
            throw new InvalidArgumentException('This inspection has already been completed.');
        }

        $expected = $this->taskItems($taskId);
        $expectedItemsById = [];
        foreach ($expected as $expectedItem) {
            $expectedItemsById[(int) $expectedItem['id']] = $expectedItem;
        }
        $expectedIds = array_map(static fn(array $item): int => (int) $item['id'], $expected);
        $submittedIds = [];
        $chargesByItemId = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('The room checklist is invalid.');
            }
            $id = (int) ($item['id'] ?? 0);
            $condition = (string) ($item['condition_status'] ?? '');
            $rawCharge = $item['charge_amount'] ?? 0;
            if (!$id
                || !in_array($condition, ['pending', 'ok', 'damaged', 'stained', 'missing'], true)
                || !is_numeric($rawCharge)
                || !is_finite((float) $rawCharge)
                || (float) $rawCharge < 0
                || (float) $rawCharge > 99999999.99) {
                throw new InvalidArgumentException('Every checklist row needs a valid condition.');
            }
            $chargeAmount = $condition === 'ok' ? 0.0 : round((float) $rawCharge, 2);
            if ($complete && in_array($condition, ['damaged', 'stained', 'missing'], true) && $chargeAmount <= 0) {
                throw new InvalidArgumentException('Enter a charge amount for every damaged, stained, or missing item.');
            }
            $submittedIds[] = $id;
            $chargesByItemId[$id] = $chargeAmount;
        }
        sort($expectedIds);
        sort($submittedIds);
        if ($expectedIds !== $submittedIds) {
            throw new InvalidArgumentException('The checklist changed. Refresh the page and try again.');
        }
        if ($complete && array_filter($items, static fn(array $item): bool => ($item['condition_status'] ?? '') === 'pending')) {
            throw new InvalidArgumentException('Select a condition for every room item before completing the inspection.');
        }

        $this->conn->begin_transaction();
        try {
            $lockedTask = $this->findTaskForUpdate($taskId);
            if (!$lockedTask || (int) ($lockedTask['assigned_to'] ?? 0) !== $userId) {
                throw new InvalidArgumentException('This task is not assigned to your account.');
            }
            if ($lockedTask['status'] === 'completed') {
                throw new InvalidArgumentException('This inspection has already been completed.');
            }
            $update = $this->conn->prepare(
                'UPDATE housekeeping_task_items SET condition_status = ?, charge_amount = ?, notes = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND task_id = ?'
            );
            foreach ($items as $item) {
                $condition = (string) $item['condition_status'];
                $chargeAmount = $chargesByItemId[(int) $item['id']];
                $notes = trim((string) ($item['notes'] ?? ''));
                $itemId = (int) $item['id'];
                $update->bind_param('sdsii', $condition, $chargeAmount, $notes, $itemId, $taskId);
                $update->execute();
            }

            if ($complete) {
                $issue = $this->conn->prepare(
                    "SELECT COUNT(*) AS count FROM housekeeping_task_items
                     WHERE task_id = ? AND condition_status <> 'ok'"
                );
                $issue->bind_param('i', $taskId);
                $issue->execute();
                $hasIssues = (int) $issue->get_result()->fetch_assoc()['count'] > 0;
                $billItems = [];
                foreach ($items as $item) {
                    $condition = (string) $item['condition_status'];
                    $chargeAmount = $chargesByItemId[(int) $item['id']];
                    if (in_array($condition, ['damaged', 'stained', 'missing'], true) && $chargeAmount > 0) {
                        $billItems[] = [
                            'id' => (int) $item['id'],
                            'name' => (string) $expectedItemsById[(int) $item['id']]['item_name'],
                            'condition' => $condition,
                            'amount' => $chargeAmount,
                        ];
                    }
                }
                if ($billItems) {
                    $bill = (new BillModel($this->conn))->activeByBooking((int) $lockedTask['booking_id']);
                    if (!$bill) {
                        throw new InvalidArgumentException('No active guest bill was found for this checked-out booking.');
                    }
                    $billId = (int) $bill['id'];
                    $billItemInsert = $this->conn->prepare(
                        "INSERT INTO bill_items (bill_id, item_type, reference_id, description, quantity, unit_price, amount)
                         VALUES (?, 'other', ?, ?, 1, ?, ?)"
                    );
                    foreach ($billItems as $billItem) {
                        $description = sprintf(
                            'Room %s inspection · %s · %s · Task #%d',
                            (string) $lockedTask['room_number'],
                            $billItem['condition'],
                            $billItem['name'],
                            $taskId
                        );
                        $amount = $billItem['amount'];
                        $billItemInsert->bind_param('iisdd', $billId, $billItem['id'], $description, $amount, $amount);
                        $billItemInsert->execute();
                        audit(
                            $this->conn,
                            $userId,
                            'housekeeping_damage_charge',
                            "Bill #{$billId} · {$description} · " . money($amount)
                        );
                    }
                    recalculate_bill($this->conn, $billId);
                }

                $taskUpdate = $this->conn->prepare(
                    "UPDATE housekeeping_tasks SET status = 'completed', completed_at = CURRENT_TIMESTAMP WHERE id = ?"
                );
                $taskUpdate->bind_param('i', $taskId);
                $taskUpdate->execute();
                $roomStatus = $hasIssues ? 'maintenance' : 'available';
                $roomUpdate = $this->conn->prepare('UPDATE rooms SET status = ? WHERE id = ?');
                $roomUpdate->bind_param('si', $roomStatus, $lockedTask['room_id']);
                $roomUpdate->execute();
                audit(
                    $this->conn,
                    $userId,
                    'housekeeping_inspection_completed',
                    "Completed task #{$taskId}; room set to {$roomStatus}"
                );
            } else {
                $taskUpdate = $this->conn->prepare(
                    "UPDATE housekeeping_tasks SET status = 'in_progress' WHERE id = ?"
                );
                $taskUpdate->bind_param('i', $taskId);
                $taskUpdate->execute();
                audit($this->conn, $userId, 'housekeeping_inspection_updated', "Updated task #{$taskId}");
            }
            $this->conn->commit();
        } catch (Throwable $error) {
            $this->conn->rollback();
            throw $error;
        }
    }

    private function findTask(int $taskId): ?array
    {
        $stmt = $this->conn->prepare('SELECT * FROM housekeeping_tasks WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $taskId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private function findTaskForUpdate(int $taskId): ?array
    {
        $stmt = $this->conn->prepare(
            'SELECT t.*, r.room_number FROM housekeeping_tasks t
             JOIN rooms r ON r.id = t.room_id
             WHERE t.id = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->bind_param('i', $taskId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private function taskItems(int $taskId): array
    {
        $stmt = $this->conn->prepare(
            'SELECT i.id, i.room_amenity_id, i.item_name, i.condition_status, i.charge_amount, i.notes,
                    COALESCE(a.stained_charge, 0.00) AS stained_charge,
                    COALESCE(a.damaged_charge, 0.00) AS damaged_charge,
                    COALESCE(a.missing_charge, 0.00) AS missing_charge
             FROM housekeeping_task_items i
             LEFT JOIN room_amenities ra ON ra.id = i.room_amenity_id
             LEFT JOIN housekeeping_amenities a ON a.id = ra.amenity_id
             WHERE i.task_id = ? ORDER BY i.id'
        );
        $stmt->bind_param('i', $taskId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}
