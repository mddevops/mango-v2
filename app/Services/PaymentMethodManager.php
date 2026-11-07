<?php

namespace App\Services;

use App\Models\PaymentMethod;
use App\Services\Concerns\HandlesUniqueConstraints;
use App\Support\CodeGenerator;
use Illuminate\Support\Facades\DB;

class PaymentMethodManager
{
    use HandlesUniqueConstraints;

    public function __construct(private readonly AuditLogger $audit) {}

    public function create(string $name): PaymentMethod
    {
        return $this->withUniqueField('payment_methods_name_unique', 'name', PaymentMethod::NAME_TAKEN, fn () => $this->withGeneratedCode('payment_methods_code_unique', fn () => DB::transaction(function () use ($name) {
            $base = CodeGenerator::base($name, 'payment');
            $taken = PaymentMethod::query()->where('code', 'like', CodeGenerator::prefix($base).'%')->pluck('code');

            $method = PaymentMethod::create([
                'code' => CodeGenerator::firstFree($base, $taken),
                'name' => $name,
                'sort_order' => ReferenceOrder::next(PaymentMethod::class),
            ]);

            $this->audit->record(PaymentMethod::AUDIT_SUBJECT.'.created', PaymentMethod::AUDIT_SUBJECT, $method->id, $method->name, PaymentMethod::SINGULAR.' создан', [
                'code' => $method->code,
                'name' => $method->name,
            ]);

            return $method;
        })));
    }

    public function update(int $id, string $name, int $lockVersion): PaymentMethod
    {
        return $this->withUniqueField('payment_methods_name_unique', 'name', PaymentMethod::NAME_TAKEN, fn () => DB::transaction(function () use ($id, $name, $lockVersion) {
            $locked = PaymentMethod::query()->lockForUpdate()->findOrFail($id);
            $locked->ensureLockVersion($lockVersion);
            $locked->name = $name;

            if (! $locked->isDirty()) {
                return $locked;
            }

            $before = $locked->getOriginal('name');
            $locked->bumpLockVersion()->save();

            $this->audit->record(PaymentMethod::AUDIT_SUBJECT.'.updated', PaymentMethod::AUDIT_SUBJECT, $locked->id, $locked->name, PaymentMethod::SINGULAR.' сохранён', [
                'name' => [$before, $locked->name],
            ]);

            return $locked;
        }));
    }

    public function setActive(int $id, bool $active, mixed $lockVersion): PaymentMethod
    {
        return DB::transaction(function () use ($id, $active, $lockVersion) {
            $locked = PaymentMethod::query()->lockForUpdate()->findOrFail($id);
            $locked->ensureLockVersion($lockVersion);

            if ($locked->active === $active) {
                return $locked;
            }

            $locked->active = $active;
            $locked->bumpLockVersion()->save();

            $this->audit->record(
                PaymentMethod::AUDIT_SUBJECT.($active ? '.activated' : '.deactivated'),
                PaymentMethod::AUDIT_SUBJECT,
                $locked->id,
                $locked->name,
                PaymentMethod::SINGULAR.($active ? ' включён' : ' отключён'),
                ['active' => [! $active, $active]],
            );

            return $locked;
        });
    }
}
