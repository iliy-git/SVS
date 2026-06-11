<?php

namespace App\Services;

use App\Models\Node;
use App\Models\Flag;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\Collection;

class NodeService
{
    /**
     * Правила валидации для ноды
     */
    protected function rules(?int $id = null): array
    {
        return [
            'name'    => 'required|min:2|max:255',
            'ip'      => 'required|ip',
            'port'    => 'required|numeric|min:1|max:65535',
            'api_key' => 'required|string',
            'flag_id' => 'required|exists:flags,id',
        ];
    }

    public function validate(array $data, ?int $id = null): array
    {
        return Validator::make($data, $this->rules($id))->validate();
    }

    /**
     * Получение списка нод с фильтрацией
     */
    public function getNodesForIndex(string $search = ''): Collection
    {
        return Node::with('flag')
            ->when($search, function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('ip', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->get();
    }

    /**
     * Проверка доступности ноды (Ping)
     */
    public function checkHealth(int $id): bool
    {
        $node = Node::findOrFail($id);

        try {
            $response = Http::withHeaders(['X-API-KEY' => $node->api_key])
                ->withoutVerifying()
                ->timeout(5)
                ->get("https://{$node->ip}:{$node->port}/ping");

            $isActive = ($response->ok() && $response->json('status') === 'ok');

            $node->update([
                'is_active' => $isActive,
                'last_seen' => now()
            ]);

            return $isActive;
        } catch (\Exception $e) {
            $node->update(['is_active' => false]);
            return false;
        }
    }

    /**
     * Массовая проверка всех нод (например, для крон-задачи)
     */
    public function checkAllNodes(): void
    {
        Node::all()->each(fn($node) => $this->checkHealth($node->id));
    }

    public function createNode(array $data): Node
    {
        return Node::create($data);
    }

    public function updateNode(int $id, array $data): bool
    {
        return Node::findOrFail($id)->update($data);
    }

    public function deleteNode(int $id): bool
    {
        return Node::findOrFail($id)->delete();
    }
}
