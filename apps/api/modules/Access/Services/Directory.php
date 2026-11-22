<?php

declare(strict_types=1);

namespace Modules\Access\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Identity\Models\UserTenant;

/** People in the current provider, with their roles, for the users & roles screens. */
final class Directory
{
    /**
     * @param  list<string>|null  $centres  null = all centres
     * @return LengthAwarePaginator<int, UserTenant>
     */
    public function members(?string $search, ?string $role, ?array $centres, int $perPage = 25): LengthAwarePaginator
    {
        $query = UserTenant::query()
            ->with(['user'])
            ->join('users', 'users.id', '=', 'user_tenants.user_id')
            ->select('user_tenants.*')
            ->orderBy('users.last_name')
            ->orderBy('users.first_name')
            ->orderBy('user_tenants.id');

        if ($search !== null && trim($search) !== '') {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower(trim($search))).'%';
            $query->where(fn ($q) => $q
                ->whereRaw("lower(users.first_name || ' ' || users.last_name) like ?", [$term])
                ->orWhereRaw('lower(users.email) like ?', [$term])
                ->orWhere('users.phone_e164', 'like', $term));
        }

        if ($role !== null) {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('role_assignments as ra')
                ->whereColumn('ra.user_id', 'user_tenants.user_id')->where('ra.role', $role));
        }

        if ($centres !== null) {
            $query->whereIn('user_tenants.centre_id', $centres);
        }

        return $query->paginate(min(max($perPage, 1), 100));
    }
}
