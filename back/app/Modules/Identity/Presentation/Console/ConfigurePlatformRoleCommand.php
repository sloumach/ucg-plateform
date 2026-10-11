<?php

namespace App\Modules\Identity\Presentation\Console;

use App\Exceptions\DomainException;
use App\Modules\Identity\Application\Services\PlatformRoleService;
use App\Modules\Identity\Domain\PlatformRoleType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ConfigurePlatformRoleCommand extends Command
{
    protected $signature = 'identity:platform-role {user} {--role=superadmin} {--actor=} {--revoke} {--bootstrap} {--reason=}';

    protected $description = 'Attribuer ou révoquer explicitement un rôle plateforme depuis un accès opérateur au serveur.';

    public function handle(PlatformRoleService $roles, Factory $validation): int
    {
        try {
            $input = ['user' => $this->argument('user'), 'role' => $this->option('role'), 'actor' => $this->option('actor'),
                'reason' => $this->option('reason')];
            $validation->make($input, ['user' => ['required', 'integer', 'min:1'], 'role' => ['required', Rule::enum(PlatformRoleType::class)],
                'actor' => $this->option('bootstrap') ? ['prohibited'] : ['required', 'integer', 'min:1'],
                'reason' => ['required', 'string', 'min:10', 'max:500']])->validate();
            $roles->configure((int) $input['user'], PlatformRoleType::from((string) $input['role']), ! $this->option('revoke'),
                $input['actor'] === null ? null : (int) $input['actor'], (string) $input['reason'], (string) Str::uuid(), (bool) $this->option('bootstrap'));
            $this->info(__('platform.role_saved'));

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            $this->error(__('tenancy.console.invalid', ['fields' => implode(', ', array_keys($exception->errors()))]));
        } catch (AuthorizationException) {
            $this->error(__('platform.forbidden'));
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());
        }

        return self::FAILURE;
    }
}
