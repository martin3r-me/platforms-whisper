<?php

namespace Platform\Whisper\Organization;

use Illuminate\Database\Eloquent\Builder;
use Platform\Organization\Contracts\EntityLinkProvider;
use Platform\Organization\Contracts\HasMetricDefinitions;
use Platform\Whisper\Models\WhisperRecording;

class WhisperEntityLinkProvider implements EntityLinkProvider, HasMetricDefinitions
{
    public function morphAliases(): array
    {
        return ['whisper_recording'];
    }

    public function linkTypeConfig(): array
    {
        return [
            'whisper_recording' => [
                'label' => 'Aufnahmen',
                'singular' => 'Aufnahme',
                'icon' => 'microphone',
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
            'title' => $model->title,
            'duration_seconds' => $model->duration_seconds,
            'status' => $model->status,
            'speakers_count' => $model->speakers_count,
        ];
    }

    public function metadataDisplayRules(): array
    {
        return [
            'whisper_recording' => [
                ['field' => 'title', 'format' => 'text'],
                ['field' => 'duration_seconds', 'format' => 'text', 'suffix' => 's'],
                ['field' => 'status', 'format' => 'badge'],
            ],
        ];
    }

    public function timeTrackableCascades(): array
    {
        return [];
    }

    public function activityChildren(string $morphAlias, array $linkableIds): array
    {
        return [];
    }

    public function metrics(string $morphAlias, array $linksByEntity): array
    {
        if ($morphAlias !== 'whisper_recording') {
            return [];
        }

        // Collect all recording IDs
        $allRecordingIds = [];
        foreach ($linksByEntity as $ids) {
            $allRecordingIds = array_merge($allRecordingIds, $ids);
        }
        $allRecordingIds = array_values(array_unique($allRecordingIds));

        if (empty($allRecordingIds)) {
            return [];
        }

        // Load completed recordings from current month
        $currentMonthStart = now()->startOfMonth();

        $recordings = WhisperRecording::whereIn('id', $allRecordingIds)
            ->where('status', WhisperRecording::STATUS_COMPLETED)
            ->where('created_at', '>=', $currentMonthStart)
            ->select('id', 'duration_seconds')
            ->get()
            ->keyBy('id');

        // Aggregate per entity
        $result = [];
        foreach ($linksByEntity as $entityId => $recordingIds) {
            $count = 0;
            $totalMinutes = 0;

            foreach ($recordingIds as $rid) {
                $rec = $recordings->get($rid);
                if (!$rec) {
                    continue;
                }
                $count++;
                $totalMinutes += round(($rec->duration_seconds ?? 0) / 60, 1);
            }

            $result[$entityId] = [
                'whisper_recordings_count' => $count,
                'whisper_total_minutes' => round($totalMinutes, 1),
            ];
        }

        return $result;
    }

    public function metricDefinitions(): array
    {
        return [
            'whisper_recordings_count' => [
                'label' => 'Gespräche (Monat)',
                'group' => 'whisper',
                'direction' => 'up',
                'unit' => 'count',
                'dimension' => 'energy',
                'type' => 'flow',
            ],
            'whisper_total_minutes' => [
                'label' => 'Gesprächszeit (Monat)',
                'group' => 'whisper',
                'direction' => 'up',
                'unit' => 'minutes',
                'dimension' => 'energy',
                'type' => 'flow',
            ],
        ];
    }
}
