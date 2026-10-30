<?php

namespace Tests\Feature\Sources\Concerns;

use App\Models\ContactChannel;
use App\Models\IntegrationConnection;
use App\Models\Showroom;
use App\Models\Source;
use App\Models\SourceClassification;
use App\Models\SourceKind;
use App\Support\IntegrationProvider;

trait BuildsSources
{
    protected function kind(string $code): SourceKind
    {
        return SourceKind::query()->where('code', $code)->firstOrFail();
    }

    protected function classification(string $code): SourceClassification
    {
        return SourceClassification::query()->where('code', $code)->firstOrFail();
    }

    /**
     * @return list<int>
     */
    protected function channels(string ...$codes): array
    {
        return ContactChannel::query()->whereIn('code', $codes)->orderBy('id')->pluck('id')->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function sourcePayload(Showroom $showroom, array $overrides = []): array
    {
        return [
            'name' => 'Сайт салона',
            'showroom_id' => $showroom->id,
            'source_kind_id' => $this->kind('site')->id,
            'source_classification_id' => $this->classification('advertising')->id,
            'agency_id' => null,
            'url' => 'https://example.ru',
            'note' => null,
            'channel_ids' => $this->channels('callback-request', 'direct-call'),
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function updateSourcePayload(Source $source, array $overrides = []): array
    {
        $source->refresh();

        return [
            'name' => $source->name,
            'showroom_id' => $source->showroom_id,
            'source_kind_id' => $source->source_kind_id,
            'source_classification_id' => $source->source_classification_id,
            'agency_id' => $source->agency_id,
            'url' => $source->url,
            'note' => $source->note,
            'channel_ids' => $source->channels()->pluck('contact_channels.id')->all(),
            'lock_version' => $source->lock_version,
            ...$overrides,
        ];
    }

    protected function makeSource(Showroom $showroom, array $overrides = []): Source
    {
        $channels = $overrides['channel_ids'] ?? $this->channels('callback-request', 'direct-call');
        unset($overrides['channel_ids']);

        $source = new Source([
            'name' => 'Источник '.uniqid(),
            'showroom_id' => $showroom->id,
            'source_kind_id' => $this->kind('site')->id,
            'source_classification_id' => $this->classification('advertising')->id,
            ...$overrides,
        ]);
        $source->code = 'source-'.uniqid();
        $source->save();
        $source->channels()->sync($channels);

        return $source->fresh();
    }

    /**
     * @param  list<Showroom>  $showrooms
     * @param  array<string, string>  $secrets
     */
    protected function makeConnection(array $showrooms, string $name = 'Mango основной', array $secrets = []): IntegrationConnection
    {
        $connection = new IntegrationConnection(['provider' => IntegrationProvider::MangoVpbx, 'name' => $name]);
        $connection->secrets = $secrets === [] ? null : $secrets;
        $connection->save();
        $connection->showrooms()->sync(collect($showrooms)->pluck('id'));

        return $connection->fresh();
    }
}
