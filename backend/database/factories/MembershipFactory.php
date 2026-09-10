<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RoleSlug;
use App\Models\Membership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A membership is written for whichever tenant is current, so a test states that
 * by establishing the tenant rather than by passing an identifier here.
 *
 * @extends Factory<Membership>
 */
final class MembershipFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'role_id' => fn (): int => $this->roleId(RoleSlug::Admin),
        ];
    }

    public function withRole(RoleSlug $slug): self
    {
        return $this->state(fn (): array => ['role_id' => $this->roleId($slug)]);
    }

    private function roleId(RoleSlug $slug): int
    {
        /** @var Role $role */
        $role = Role::query()->where('slug', $slug->value)->sole();

        return $role->id;
    }
}
