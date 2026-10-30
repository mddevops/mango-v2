<?php

namespace App\Services;

use App\Models\IntegrationConnection;
use App\Models\TelephonyExtension;
use App\Models\User;
use App\Services\Concerns\HandlesUniqueConstraints;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Extensions of a Mango account. The number is a string and is unique within its
 * connection only; the same number in another account is a different extension.
 */
class TelephonyExtensionManager
{
    use HandlesUniqueConstraints;

    public const TAKEN = 'Этот внутренний номер уже назначен другому сотруднику в этом подключении.';

    public function __construct(private readonly AuditLogger $audit) {}

    public function create(IntegrationConnection $connection, string $extension, int $userId): TelephonyExtension
    {
        return $this->withUniqueField('telephony_extensions_connection_extension_unique', 'extension', self::TAKEN, fn () => DB::transaction(function () use ($connection, $extension, $userId) {
            $user = User::query()->whereKey($userId)->where('active', true)->first()
                ?? throw ValidationException::withMessages(['user_id' => 'Выберите активного сотрудника.']);

            $item = TelephonyExtension::create([
                'integration_connection_id' => $connection->id,
                'extension' => $extension,
                'user_id' => $user->id,
            ]);

            $this->audit->record('integration_connection.extension_added', 'integration_connection', $connection->id, $connection->name, 'Назначен внутренний номер Mango', [
                'extension' => $extension,
                'user' => $user->name,
            ], $connection->showrooms()->pluck('showrooms.id')->all());

            return $item;
        }));
    }

    public function delete(TelephonyExtension $item): void
    {
        DB::transaction(function () use ($item) {
            $connection = $item->connection()->firstOrFail();
            $user = $item->user()->value('name');
            $item->delete();

            $this->audit->record('integration_connection.extension_removed', 'integration_connection', $connection->id, $connection->name, 'Удалён внутренний номер Mango', [
                'extension' => $item->extension,
                'user' => $user,
            ], $connection->showrooms()->pluck('showrooms.id')->all());
        });
    }
}
