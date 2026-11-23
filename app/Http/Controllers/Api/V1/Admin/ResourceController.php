<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared list / show / create / update / delete for the admin modules that use the common
 * table-and-form screens (name, category, owner, status, notes).
 *
 * @template TModel of Model
 */
abstract class ResourceController extends Controller
{
    /** Statuses shown under each filter tab of the admin table. */
    private const TABS = [
        'active' => ['Active', 'Published', 'Live'],
        'pending' => ['Pending', 'Draft', 'Scheduled', 'Upcoming'],
        'archived' => ['Archived'],
    ];

    /**
     * @return class-string<TModel>
     */
    abstract protected function model(): string;

    /** Lower-case noun used in the activity log ("article"). */
    abstract protected function subject(): string;

    /**
     * @return array<int, string>
     */
    abstract protected function searchColumns(): array;

    /**
     * @param  TModel  $model
     * @return array<string, mixed>
     */
    abstract protected function record(Model $model): array;

    /**
     * @return array<string, mixed>
     */
    abstract protected function rules(Request $request): array;

    /**
     * Fill the model from the validated form data and save it.
     *
     * @param  TModel  $model
     * @param  array<string, mixed>  $data
     */
    abstract protected function save(Model $model, array $data): void;

    /**
     * Relations loaded for every row.
     *
     * @return array<int, string>
     */
    protected function relations(): array
    {
        return [];
    }

    /**
     * @param  TModel  $model
     */
    protected function label(Model $model): string
    {
        return (string) ($model->getAttribute('title') ?? $model->getAttribute('name'));
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 10), 1), 100);
        $search = trim((string) $request->query('q'));
        $tab = (string) $request->query('tab', 'all');

        $paginator = ($this->model())::query()
            ->with($this->relations())
            ->when(isset(self::TABS[$tab]), fn ($query) => $query->whereIn('status', self::TABS[$tab]))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($inner) use ($search): void {
                    foreach ($this->searchColumns() as $column) {
                        $inner->orWhere($column, 'like', "%{$search}%");
                    }
                });
            })
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (Model $model): array => $this->record($model))->values(),
            'meta' => ['total' => $paginator->total()],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->record(($this->model())::query()->with($this->relations())->findOrFail($id))]);
    }

    public function store(Request $request): JsonResponse
    {
        $model = new ($this->model());

        $this->save($model, $request->validate($this->rules($request)));
        ActivityLog::record($request->user(), 'created', $this->subject(), $this->label($model));

        return response()->json(['data' => $this->record($model->load($this->relations()))], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $model = ($this->model())::query()->findOrFail($id);

        $this->save($model, $request->validate($this->rules($request)));
        ActivityLog::record($request->user(), 'updated', $this->subject(), $this->label($model));

        return response()->json(['data' => $this->record($model->load($this->relations()))]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $model = ($this->model())::query()->findOrFail($id);

        ActivityLog::record($request->user(), 'deleted', $this->subject(), $this->label($model));
        $model->delete();

        return response()->json(null, 204);
    }
}
