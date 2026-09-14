<?php

namespace App\Http\Controllers\Api\V1\Admin\Concerns;

use App\Enums\ContentStatus;
use App\Exceptions\NotReadyToPublish;
use App\Jobs\RevalidateWebsite;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Publish, unpublish and reorder for CMS lists with a draft | published status and a sort_order.
 * New items always start as drafts; publishing is an explicit action.
 */
trait ManagesPublishedList
{
    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** Website cache tag refreshed after a change. */
    abstract protected function cacheTag(): string;

    abstract protected function present(Model $model): array;

    /** Audit action prefix, e.g. "cms.review". */
    abstract protected function auditName(): string;

    /** @return list<string> messages; empty when the item may be published */
    protected function publishProblems(Model $model): array
    {
        return [];
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        $model = $this->modelClass()::query()->findOrFail($id);

        NotReadyToPublish::throwIfAny($this->publishProblems($model));

        return $this->setStatus($request, $model, ContentStatus::Published);
    }

    public function unpublish(Request $request, int $id): JsonResponse
    {
        return $this->setStatus($request, $this->modelClass()::query()->findOrFail($id), ContentStatus::Draft);
    }

    /** Body: { "ids": [3, 1, 2] } — the full list in its new order. */
    public function reorder(Request $request): JsonResponse
    {
        $table = (new ($this->modelClass()))->getTable();
        $ids = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct', "exists:{$table},id"],
        ])['ids'];

        DB::transaction(function () use ($ids) {
            foreach ($ids as $position => $id) {
                $this->modelClass()::query()->whereKey($id)->update(['sort_order' => $position + 1]);
            }
        });
        RevalidateWebsite::dispatch([$this->cacheTag()]);

        return response()->json(['data' => ['ids' => $ids]]);
    }

    protected function setStatus(Request $request, Model $model, ContentStatus $status): JsonResponse
    {
        $model->forceFill(['status' => $status])->save();
        app(AuditLogger::class)->record("{$this->auditName()}.{$status->value}", $request->user('staff'), $model);
        RevalidateWebsite::dispatch([$this->cacheTag()]);

        return response()->json(['data' => $this->present($model->refresh())]);
    }

    protected function saved(Request $request, Model $model, string $action, int $status = 200): JsonResponse
    {
        app(AuditLogger::class)->record("{$this->auditName()}.{$action}", $request->user('staff'), $model,
            $action === 'deleted' ? null : ['fields' => array_keys($model->getChanges() ?: $model->getAttributes())]);
        RevalidateWebsite::dispatch([$this->cacheTag()]);

        return response()->json(['data' => $action === 'deleted' ? null : $this->present($model)], $status);
    }
}
