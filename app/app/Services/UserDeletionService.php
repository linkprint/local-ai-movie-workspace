<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class UserDeletionService
{
    public function delete(User $user, User $actor): bool
    {
        if (! $actor->isAdmin()) {
            throw new DomainException('Only an administrator can delete users.');
        }

        if ($actor->is($user)) {
            throw new DomainException('You cannot delete your own account.');
        }

        return DB::transaction(function () use ($user): bool {
            $locked = User::query()->lockForUpdate()->find($user->getKey());

            if (! $locked) {
                return false;
            }

            $reservationCount = $locked->reservations()->count();

            if ($reservationCount > 0) {
                throw new DomainException("This user has {$reservationCount} reservation record(s). Keep the account to preserve reservation history.");
            }

            AuditEvent::record('admin.user.deleted', $locked, [
                'name' => $locked->name,
                'email' => $locked->email,
                'role' => $locked->role->value,
                'workspace_profile' => $locked->workspaceProfile()->exists(),
                'workspace_projects' => $locked->workspaceProjects()->count(),
            ]);

            return (bool) $locked->delete();
        });
    }
}
