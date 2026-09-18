<?php

declare(strict_types=1);

namespace App\Modules\Collection\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final class TenantScope implements Scope
{
    /**
     * Fail-closed by design: this always applies a filter, never a
     * conditional one. When nobody is authenticated, auth()->id() is null,
     * so the generated clause is `where user_id is null` — zero rows,
     * since `user_id` is NOT NULL. An unauthenticated context (a bug in a
     * route, a queued job with no session) therefore sees no tenant's
     * rows instead of every tenant's. Code that genuinely needs
     * cross-tenant access (seeders, scheduled jobs) must opt in explicitly
     * via `Model::withoutGlobalScope(TenantScope::class)` — never rely on
     * "nobody's logged in" to mean "show everything."
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->getTable().'.user_id', auth()->id());
    }
}
