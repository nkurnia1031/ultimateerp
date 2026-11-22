<?php

namespace App\Services;

use App\Business;
use App\BusinessLocation;
use App\Contact;
use App\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class UserBusinessImportService
{
    private const PROFILE_FIELDS = [
        'surname',
        'first_name',
        'last_name',
        'email',
        'language',
        'status',
        'dob',
        'gender',
        'marital_status',
        'blood_group',
        'contact_number',
        'alt_number',
        'family_number',
        'fb_link',
        'twitter_link',
        'social_media_1',
        'social_media_2',
        'custom_field_1',
        'custom_field_2',
        'custom_field_3',
        'custom_field_4',
        'guardian_name',
        'id_proof_name',
        'id_proof_number',
        'permanent_address',
        'current_address',
        'bank_details',
        'cmmsn_percent',
        'max_sales_discount_percent',
        'is_enable_service_staff_pin',
        'service_staff_pin',
    ];

    public function businessesForImport(int $targetBusinessId)
    {
        return Business::query()
            ->where('id', '!=', $targetBusinessId)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function searchSourceUsers(int $sourceBusinessId, int $targetBusinessId, string $term)
    {
        $this->assertDifferentBusinesses($sourceBusinessId, $targetBusinessId);

        return User::query()
            ->where('business_id', $sourceBusinessId)
            ->user()
            ->where('is_cmmsn_agnt', 0)
            ->where(function ($query) use ($term) {
                $like = '%' . addcslashes(trim($term), '%_\\') . '%';
                $query->where('username', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('surname', 'like', $like);
            })
            ->orderBy('first_name')
            ->limit(30)
            ->get(['id', 'username', 'surname', 'first_name', 'last_name', 'email', 'allow_login', 'status'])
            ->map(function (User $user) {
                return [
                    'id' => $user->id,
                    'text' => $this->fullName($user) . ($user->username ? ' (' . $user->username . ')' : ''),
                    'username' => $user->username,
                    'name' => $this->fullName($user),
                    'email' => $user->email,
                    'allow_login' => (bool) $user->allow_login,
                    'status' => $user->status,
                ];
            });
    }

    public function preview(int $sourceBusinessId, int $targetBusinessId, int $sourceUserId): array
    {
        $this->assertDifferentBusinesses($sourceBusinessId, $targetBusinessId);

        $sourceUser = $this->sourceUserQuery($sourceBusinessId)
            ->with(['roles', 'contactAccess'])
            ->findOrFail($sourceUserId);
        $targetRoles = Role::query()->where('business_id', $targetBusinessId)->orderBy('name')->get();
        $sourceRoleMappings = $sourceUser->roles->map(function (Role $sourceRole) use ($targetRoles, $sourceBusinessId) {
            $normalizedName = $this->normalizeRoleName($sourceRole->name, $sourceBusinessId);
            $matches = $targetRoles->filter(function (Role $targetRole) use ($normalizedName, $sourceBusinessId) {
                return $this->normalizeRoleName($targetRole->name, (int) $targetRole->business_id) === $normalizedName;
            })->values();

            return [
                'source_role_id' => $sourceRole->id,
                'source_role_name' => $normalizedName,
                'suggested_target_role_id' => $matches->count() === 1 ? $matches->first()->id : null,
                'matches' => $matches->map(fn (Role $role) => [
                    'id' => $role->id,
                    'name' => $this->normalizeRoleName($role->name, (int) $role->business_id),
                    'has_all_locations' => $role->hasPermissionTo('access_all_locations'),
                ])->all(),
            ];
        })->values()->all();

        $sourceLocationAccess = $this->sourceLocationAccess($sourceUser, $sourceBusinessId);
        $targetLocations = BusinessLocation::query()
            ->where('business_id', $targetBusinessId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'location_id']);

        $locations = collect($sourceLocationAccess['locations'])->map(function (array $location) use ($targetLocations) {
            $matches = $targetLocations->filter(function (BusinessLocation $targetLocation) use ($location) {
                if (! empty($location['location_id']) && ! empty($targetLocation->location_id)) {
                    return mb_strtolower(trim($targetLocation->location_id)) === mb_strtolower(trim($location['location_id']));
                }

                return mb_strtolower(trim($targetLocation->name)) === mb_strtolower(trim($location['name']));
            })->values();

            return $location + [
                'suggested_target_location_id' => $matches->count() === 1 ? $matches->first()->id : null,
                'matches' => $matches->map(fn (BusinessLocation $targetLocation) => [
                    'id' => $targetLocation->id,
                    'name' => $targetLocation->name,
                    'location_id' => $targetLocation->location_id,
                ])->all(),
            ];
        })->values()->all();

        $contactMappings = [];
        if ($sourceUser->selected_contacts) {
            $sourceContacts = $sourceUser->contactAccess
                ->where('business_id', $sourceBusinessId)
                ->values();
            foreach ($sourceContacts as $sourceContact) {
                $matches = $this->findContactMatches($sourceContact, $targetBusinessId);
                $contactMappings[] = [
                    'source_contact_id' => $sourceContact->id,
                    'source_contact_name' => $sourceContact->name,
                    'source_email' => $sourceContact->email,
                    'source_mobile' => $sourceContact->mobile,
                    'suggested_target_contact_id' => count($matches) === 1 ? $matches[0]['id'] : null,
                    'matches' => $matches,
                ];
            }
        }

        return [
            'source_user' => [
                'id' => $sourceUser->id,
                'name' => $this->fullName($sourceUser),
                'email' => $sourceUser->email,
                'username' => $sourceUser->username,
                'allow_login' => (bool) $sourceUser->allow_login,
                'status' => $sourceUser->status,
                'selected_contacts' => (bool) $sourceUser->selected_contacts,
                'profile_summary' => [
                    'contact_number' => $sourceUser->contact_number,
                    'language' => $sourceUser->language,
                ],
            ],
            'destination_username' => $sourceUser->allow_login
                ? $this->destinationUsername($sourceUser->username, $sourceBusinessId, $targetBusinessId)
                : null,
            'roles' => $sourceRoleMappings,
            'target_role_options' => $targetRoles->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $this->normalizeRoleName($role->name, (int) $role->business_id),
                'has_all_locations' => $role->hasPermissionTo('access_all_locations'),
            ])->values()->all(),
            'all_locations' => $sourceLocationAccess['all'],
            'locations' => $locations,
            'target_location_options' => $targetLocations->map(fn (BusinessLocation $location) => [
                'id' => $location->id,
                'name' => $location->name,
                'location_id' => $location->location_id,
            ])->values()->all(),
            'selected_contacts' => (bool) $sourceUser->selected_contacts,
            'contacts' => $contactMappings,
        ];
    }

    public function import(int $sourceBusinessId, int $targetBusinessId, int $sourceUserId, array $mapping, ?int $batchId = null): User
    {
        $this->assertDifferentBusinesses($sourceBusinessId, $targetBusinessId);

        $sourceUser = $this->sourceUserQuery($sourceBusinessId)
            ->with(['roles', 'contactAccess'])
            ->findOrFail($sourceUserId);

        $username = null;
        if ($sourceUser->allow_login) {
            $username = trim((string) ($mapping['username'] ?? ''));
            if ($username === '') {
                throw ValidationException::withMessages(['username' => 'Username tujuan wajib diisi untuk user yang boleh login.']);
            }
            if (mb_strlen($username) > 191) {
                throw ValidationException::withMessages(['username' => 'Username maksimal 191 karakter.']);
            }
            if (User::withTrashed()->where('username', $username)->exists()) {
                throw ValidationException::withMessages(['username' => 'Username tujuan sudah digunakan. Pilih username lain.']);
            }
        }

        $roleMap = (array) ($mapping['role_map'] ?? []);
        $targetRoles = collect();
        foreach ($sourceUser->roles as $sourceRole) {
            $targetRoleId = (int) ($roleMap[$sourceRole->id] ?? 0);
            $targetRole = Role::query()
                ->where('business_id', $targetBusinessId)
                ->whereKey($targetRoleId)
                ->first();
            if (! $targetRole) {
                throw ValidationException::withMessages([
                    'role_map.' . $sourceRole->id => 'Pilih role tujuan untuk role ' . $this->normalizeRoleName($sourceRole->name, $sourceBusinessId) . '.',
                ]);
            }
            $targetRoles->push($targetRole);
        }

        $sourceLocationAccess = $this->sourceLocationAccess($sourceUser, $sourceBusinessId);
        $locationMap = (array) ($mapping['location_map'] ?? []);
        $targetLocationIds = [];
        if (! $sourceLocationAccess['all']) {
            foreach ($sourceLocationAccess['locations'] as $sourceLocation) {
                $sourceLocationId = (int) $sourceLocation['id'];
                $targetLocationId = (int) ($locationMap[$sourceLocationId] ?? 0);
                $validTargetLocation = BusinessLocation::query()
                    ->where('business_id', $targetBusinessId)
                    ->active()
                    ->whereKey($targetLocationId)
                    ->exists();
                if (! $validTargetLocation) {
                    throw ValidationException::withMessages([
                        'location_map.' . $sourceLocationId => 'Pilih lokasi tujuan untuk ' . $sourceLocation['name'] . '.',
                    ]);
                }
                $targetLocationIds[] = $targetLocationId;
            }
        }

        $targetContactIds = [];
        if ($sourceUser->selected_contacts) {
            $sourceContacts = $sourceUser->contactAccess
                ->where('business_id', $sourceBusinessId)
                ->values();
            $contactMap = (array) ($mapping['contact_map'] ?? []);
            foreach ($sourceContacts as $sourceContact) {
                $sourceContactId = (int) $sourceContact->id;
                $targetContactId = (int) ($contactMap[$sourceContactId] ?? 0);
                $validTargetContact = Contact::query()
                    ->where('business_id', $targetBusinessId)
                    ->whereKey($targetContactId)
                    ->exists();
                if (! $validTargetContact) {
                    throw ValidationException::withMessages([
                        'contact_map.' . $sourceContactId => 'Pilih kontak tujuan untuk ' . $sourceContact->name . '.',
                    ]);
                }
                $targetContactIds[] = $targetContactId;
            }
        }

        $attributes = Arr::only($sourceUser->getAttributes(), self::PROFILE_FIELDS);
        $attributes['business_id'] = $targetBusinessId;
        $attributes['user_type'] = 'user';
        $attributes['is_cmmsn_agnt'] = 0;
        // The users schema keeps its account relation as a non-null 0 sentinel.
        $attributes['account_id'] = 0;
        $attributes['crm_contact_id'] = null;
        $attributes['allow_login'] = (bool) $sourceUser->allow_login;
        $attributes['username'] = $sourceUser->allow_login ? $username : null;
        // The password hash is intentionally copied; hashing it again would prevent password reuse.
        $attributes['password'] = $sourceUser->allow_login ? $sourceUser->password : null;
        $attributes['selected_contacts'] = (bool) $sourceUser->selected_contacts;

        try {
            return DB::transaction(function () use ($attributes, $targetRoles, $sourceLocationAccess, $targetLocationIds, $targetContactIds, $batchId, $sourceUserId) {
                if (! empty($attributes['username']) && User::withTrashed()->where('username', $attributes['username'])->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['username' => 'Username tujuan sudah digunakan. Pilih username lain.']);
                }

                if ($batchId) {
                    $attributes['user_import_batch_id'] = $batchId;
                    $attributes['user_import_source_id'] = $sourceUserId;
                }

                $newUser = User::create($attributes);
                if ($targetRoles->isNotEmpty()) {
                    $newUser->syncRoles($targetRoles);
                }

                if ($sourceLocationAccess['all']) {
                    $newUser->givePermissionTo('access_all_locations');
                } elseif (! empty($targetLocationIds)) {
                    $newUser->givePermissionTo(array_map(fn ($id) => 'location.' . $id, array_values(array_unique($targetLocationIds))));
                }

                if ($attributes['selected_contacts']) {
                    $newUser->contactAccess()->sync(array_values(array_unique($targetContactIds)));
                }

                return $newUser;
            });
        } catch (QueryException $exception) {
            $databaseMessage = strtolower($exception->getMessage());
            $usernameConflict = str_contains($databaseMessage, 'users_username_unique')
                || str_contains($databaseMessage, 'unique constraint failed: users.username');
            if (($exception->errorInfo[0] ?? null) === '23000' && $usernameConflict) {
                throw ValidationException::withMessages(['username' => 'Username tujuan baru saja digunakan. Pilih username lain.']);
            }
            throw $exception;
        }
    }

    public function destinationUsername(?string $sourceUsername, int $sourceBusinessId, int $targetBusinessId): ?string
    {
        if (blank($sourceUsername)) {
            return null;
        }

        $sourceSuffix = $this->businessSuffix($sourceBusinessId);
        $baseUsername = str_ends_with($sourceUsername, $sourceSuffix)
            ? substr($sourceUsername, 0, -strlen($sourceSuffix))
            : $sourceUsername;

        return $baseUsername . $this->businessSuffix($targetBusinessId);
    }

    public function searchTargetContacts(int $targetBusinessId, string $term): array
    {
        $like = '%' . addcslashes(trim($term), '%_\\') . '%';

        return Contact::query()
            ->where('business_id', $targetBusinessId)
            ->where(function ($query) use ($like) {
                $query->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('mobile', 'like', $like)
                    ->orWhere('supplier_business_name', 'like', $like);
            })
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'name', 'email', 'mobile', 'supplier_business_name'])
            ->map(fn (Contact $contact) => [
                'id' => $contact->id,
                'text' => trim($contact->name . ($contact->supplier_business_name ? ' · ' . $contact->supplier_business_name : ''))
                    . ($contact->email ? ' · ' . $contact->email : '')
                    . ($contact->mobile ? ' · ' . $contact->mobile : ''),
            ])->all();
    }

    public function businessSuffix(int $businessId): string
    {
        return '-' . str_pad((string) $businessId, 2, '0', STR_PAD_LEFT);
    }

    private function sourceUserQuery(int $businessId)
    {
        return User::query()
            ->where('business_id', $businessId)
            ->user()
            ->where('is_cmmsn_agnt', 0);
    }

    private function assertDifferentBusinesses(int $sourceBusinessId, int $targetBusinessId): void
    {
        if ($sourceBusinessId === $targetBusinessId) {
            throw ValidationException::withMessages(['source_business_id' => 'Business sumber dan tujuan harus berbeda.']);
        }

        if (! Business::whereKey($sourceBusinessId)->exists() || ! Business::whereKey($targetBusinessId)->exists()) {
            throw ValidationException::withMessages(['source_business_id' => 'Business sumber atau tujuan tidak ditemukan.']);
        }
    }

    private function sourceLocationAccess(User $user, int $sourceBusinessId): array
    {
        $permissions = $user->getAllPermissions()->pluck('name');
        if ($permissions->contains('access_all_locations')) {
            return ['all' => true, 'locations' => []];
        }

        $locationIds = $permissions->map(function ($name) {
            return preg_match('/^location\.(\d+)$/', $name, $matches) ? (int) $matches[1] : null;
        })->filter()->unique()->values();

        $locations = BusinessLocation::query()
            ->where('business_id', $sourceBusinessId)
            ->whereIn('id', $locationIds)
            ->orderBy('name')
            ->get(['id', 'name', 'location_id'])
            ->map(fn (BusinessLocation $location) => [
                'id' => $location->id,
                'name' => $location->name,
                'location_id' => $location->location_id,
            ])->all();

        return ['all' => false, 'locations' => $locations];
    }

    private function findContactMatches(Contact $sourceContact, int $targetBusinessId): array
    {
        $query = Contact::query()->where('business_id', $targetBusinessId);
        $clauses = [];
        if (! blank($sourceContact->email)) {
            $clauses[] = ['email', '=', trim($sourceContact->email)];
        }
        if (! blank($sourceContact->mobile)) {
            $digits = preg_replace('/\D+/', '', $sourceContact->mobile);
            if ($digits !== '') {
                $phoneTail = substr($digits, -7);
                $clauses[] = ['mobile', 'like', '%' . $phoneTail . '%'];
            }
        }
        if (! blank($sourceContact->name)) {
            $clauses[] = ['name', '=', trim($sourceContact->name)];
        }

        if (empty($clauses)) {
            return [];
        }

        $query->where(function ($candidateQuery) use ($clauses) {
            foreach ($clauses as [$column, $operator, $value]) {
                $candidateQuery->orWhere($column, $operator, $value);
            }
        });

        return $query->orderBy('name')->limit(20)->get(['id', 'name', 'email', 'mobile'])
            ->map(fn (Contact $contact) => [
                'id' => $contact->id,
                'name' => $contact->name,
                'email' => $contact->email,
                'mobile' => $contact->mobile,
            ])->all();
    }

    private function normalizeRoleName(string $name, int $businessId): string
    {
        $suffix = '#' . $businessId;

        return str_ends_with($name, $suffix) ? substr($name, 0, -strlen($suffix)) : $name;
    }

    private function fullName(User $user): string
    {
        return trim(implode(' ', array_filter([
            $user->surname,
            $user->first_name,
            $user->last_name,
        ], fn ($part) => trim((string) $part) !== '')));
    }
}
