<?php

namespace Platform\Whisper\Organization;

use Illuminate\Database\Eloquent\Builder;
use Platform\Organization\Contracts\EntityLinkProvider;
use Platform\Whisper\Models\WhisperSpeaker;

class WhisperSpeakerEntityLinkProvider implements EntityLinkProvider
{
    public function morphAliases(): array
    {
        return ['whisper_speaker'];
    }

    public function linkTypeConfig(): array
    {
        return [
            'whisper_speaker' => [
                'label' => 'Sprecher',
                'singular' => 'Sprecher',
                'icon' => 'user-voice',
                'route' => null,
            ],
        ];
    }

    public function applyEagerLoading(Builder $query, string $morphAlias, string $fqcn): void
    {
        // No eager loading needed
    }

    public function extractMetadata(string $morphAlias, mixed $model): array
    {
        return [
            'name' => $model->name,
            'source' => $model->source,
            'embedding_key' => $model->embedding_key,
        ];
    }

    public function metadataDisplayRules(): array
    {
        return [
            'whisper_speaker' => [
                ['field' => 'name', 'format' => 'text'],
                ['field' => 'source', 'format' => 'badge'],
            ],
        ];
    }

    public function timeTrackableCascades(): array
    {
        return [];
    }

    public function metrics(string $morphAlias, array $linksByEntity): array
    {
        return [];
    }

    public function activityChildren(string $morphAlias, array $linkableIds): array
    {
        return [];
    }
}
