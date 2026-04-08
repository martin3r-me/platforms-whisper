<?php

namespace Platform\Whisper\Livewire;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\Organization\Models\OrganizationContext;
use Platform\Organization\Models\OrganizationEntity;
use Platform\Organization\Models\OrganizationEntityLink;
use Platform\Whisper\Models\WhisperRecording;

class Sidebar extends Component
{
    #[On('recording-saved')]
    public function refresh(): void
    {
        // re-render
    }

    public function render()
    {
        $user = Auth::user();
        $teamId = $user?->currentTeam?->id;

        if (!$user || !$teamId) {
            return view('whisper::livewire.sidebar', [
                'entityTypeGroups' => collect(),
                'unlinkedRecordings' => collect(),
            ]);
        }

        // 1. Letzte 30 Aufnahmen des Teams laden
        $recordings = WhisperRecording::query()
            ->where('team_id', $teamId)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        $recordingIds = $recordings->pluck('id')->toArray();

        // 2. Entity-Verknüpfungen aus beiden Quellen sammeln
        $entityRecordingMap = []; // entity_id => [recording_ids]
        $linkedRecordingIds = [];

        $morphTypes = ['whisper_recording', WhisperRecording::class];

        // a) OrganizationContext (UI-Verknüpfungen)
        if (!empty($recordingIds)) {
            $contexts = OrganizationContext::query()
                ->whereIn('contextable_type', $morphTypes)
                ->whereIn('contextable_id', $recordingIds)
                ->where('is_active', true)
                ->get();

            foreach ($contexts as $ctx) {
                if ($ctx->organization_entity_id) {
                    $entityRecordingMap[$ctx->organization_entity_id][] = $ctx->contextable_id;
                    $linkedRecordingIds[] = $ctx->contextable_id;
                }
            }

            // b) OrganizationEntityLink (LLM-Verknüpfungen)
            $links = OrganizationEntityLink::query()
                ->whereIn('linkable_type', $morphTypes)
                ->whereIn('linkable_id', $recordingIds)
                ->get();

            foreach ($links as $link) {
                $entityRecordingMap[$link->entity_id][] = $link->linkable_id;
                $linkedRecordingIds[] = $link->linkable_id;
            }
        }

        // Deduplizieren
        foreach ($entityRecordingMap as $eid => $rids) {
            $entityRecordingMap[$eid] = array_unique($rids);
        }
        $linkedRecordingIds = array_unique($linkedRecordingIds);

        // 3. Entities laden + nach EntityType gruppieren
        $entityTypeGroups = collect();
        $entityIds = array_keys($entityRecordingMap);

        if (!empty($entityIds)) {
            $entities = OrganizationEntity::with('type')
                ->whereIn('id', $entityIds)
                ->get()
                ->keyBy('id');

            $groupedByType = [];
            foreach ($entities as $entityId => $entity) {
                $type = $entity->type;
                if (!$type) {
                    continue;
                }

                $entityRecordings = collect($entityRecordingMap[$entityId] ?? [])
                    ->map(fn ($rid) => $recordings->firstWhere('id', $rid))
                    ->filter()
                    ->values();

                if ($entityRecordings->isEmpty()) {
                    continue;
                }

                $typeId = $type->id;
                if (!isset($groupedByType[$typeId])) {
                    $groupedByType[$typeId] = [
                        'type_id' => $typeId,
                        'type_name' => $type->name,
                        'type_icon' => $type->icon,
                        'sort_order' => $type->sort_order ?? 999,
                        'entities' => [],
                    ];
                }

                $groupedByType[$typeId]['entities'][] = [
                    'entity_id' => $entityId,
                    'entity_name' => $entity->name,
                    'recordings' => $entityRecordings,
                ];
            }

            $entityTypeGroups = collect($groupedByType)
                ->sortBy('sort_order')
                ->map(function ($group) {
                    $group['entities'] = collect($group['entities'])
                        ->sortBy('entity_name')
                        ->values();
                    return $group;
                })
                ->values();
        }

        // 4. Unverknüpfte Aufnahmen
        $unlinkedRecordings = $recordings
            ->filter(fn ($rec) => !in_array($rec->id, $linkedRecordingIds))
            ->values();

        return view('whisper::livewire.sidebar', [
            'entityTypeGroups' => $entityTypeGroups,
            'unlinkedRecordings' => $unlinkedRecordings,
        ]);
    }
}
