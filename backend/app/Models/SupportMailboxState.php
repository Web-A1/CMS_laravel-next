<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportMailboxState extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['mailbox', 'folder', 'status', 'activated_at', 'baseline_uid', 'baseline_uidvalidity', 'uidvalidity', 'cursor', 'recovery_epoch', 'recovery_cursor', 'last_attempt_at', 'last_success_at', 'error_code', 'activation_window_started_at', 'activation_window_finished_at'];

    protected function casts(): array
    {
        return ['activated_at' => 'datetime', 'last_attempt_at' => 'datetime', 'last_success_at' => 'datetime', 'activation_window_started_at' => 'datetime', 'activation_window_finished_at' => 'datetime'];
    }
}
