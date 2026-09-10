<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Tenancy\CreateTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * How a tenant comes into existence on a self-hosted instance: the operator runs
 * this. There is no public registration endpoint, and the password is generated
 * here rather than accepted, so a first login credential is never something that
 * was typed into a shell and left in its history.
 */
final class CreateTenantCommand extends Command
{
    protected $signature = 'postbox:tenant
        {--name= : The tenant name}
        {--user= : The name of its first administrator}
        {--email= : The address that administrator signs in with}';

    protected $description = 'Create a tenant and its first administrator.';

    public function handle(CreateTenant $createTenant): int
    {
        $input = [
            'name' => $this->option('name'),
            'user' => $this->option('user'),
            'email' => $this->option('email'),
        ];

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'user' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::INVALID;
        }

        /** @var array{name: string, user: string, email: string} $valid */
        $valid = $validator->validated();

        $password = Str::password(24);

        $membership = $createTenant->handle($valid['name'], $valid['user'], $valid['email'], $password);

        $this->components->info('Tenant created.');

        $this->table(['', ''], [
            ['tenant', $membership->tenant->public_id.'  '.$membership->tenant->name],
            ['user', $membership->user->public_id.'  '.$membership->user->email],
            ['password', $password],
        ]);

        $this->components->warn('The password is shown once. It is stored as a hash and cannot be recovered.');

        return self::SUCCESS;
    }
}
