<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    /**
     * Keys that never reach the audit trail, however a caller builds the change set.
     */
    private const FORBIDDEN_KEYS = [
        'password', 'password_confirmation', 'current_password', 'remember_token',
        'two_factor_secret', 'two_factor_recovery_codes', 'token', '_token', 'cookie', 'secret',
        'secrets', 'api_key', 'api_salt',
    ];

    private ?User $actorOverride = null;

    private ?string $systemBasis = null;

    /**
     * Run a callback as the system rather than an employee, for example an incoming call
     * or a site request. The basis says why the system acted and is stored with each entry.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function asSystem(string $basis, callable $callback): mixed
    {
        [$previousActor, $previousBasis] = [$this->actorOverride, $this->systemBasis];
        [$this->actorOverride, $this->systemBasis] = [null, $basis];

        try {
            return $callback();
        } finally {
            [$this->actorOverride, $this->systemBasis] = [$previousActor, $previousBasis];
        }
    }

    /**
     * Run a callback on behalf of a specific actor (used by console commands).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function as(?User $actor, callable $callback): mixed
    {
        $previous = $this->actorOverride;
        $this->actorOverride = $actor;

        try {
            return $callback();
        } finally {
            $this->actorOverride = $previous;
        }
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  iterable<int|null>  $showroomIds
     */
    public function record(
        string $event,
        string $subjectType,
        ?int $subjectId,
        string $subjectLabel,
        string $description,
        array $changes = [],
        iterable $showroomIds = [],
    ): AuditLog {
        $actor = $this->systemBasis === null ? ($this->actorOverride ?? Auth::user()) : null;

        $log = AuditLog::create([
            'actor_id' => $actor?->id,
            'actor_name' => $actor->name ?? ($this->systemBasis === null ? 'Система (консоль)' : 'Система'),
            'actor_kind' => $actor === null ? AuditLog::ACTOR_SYSTEM : AuditLog::ACTOR_USER,
            'basis' => $actor === null ? ($this->systemBasis ?? 'console') : null,
            'event' => $event,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_label' => $subjectLabel,
            'description' => $description,
            'changes' => $this->sanitize($changes) ?: null,
        ]);

        $ids = collect($showroomIds)->filter()->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isNotEmpty()) {
            $log->showrooms()->attach($ids->all());
        }

        return $log;
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function sanitize(array $changes): array
    {
        $clean = Arr::except($changes, self::FORBIDDEN_KEYS);

        foreach ($clean as $key => $value) {
            if (is_array($value)) {
                $clean[$key] = $this->sanitize($value);
            }
        }

        return $clean;
    }
}
