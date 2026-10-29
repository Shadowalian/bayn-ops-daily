<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CodeSeries;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminCodeSeriesController extends Controller
{
    public function index()
    {
        $series = CodeSeries::query()
            ->orderBy('prefix')
            ->withCount('users')
            ->get()
            ->map(fn (CodeSeries $s) => $this->payload($s));

        return response()->json(['series' => $series]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'prefix' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9]+$/', 'unique:code_series,prefix'],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'access_role' => ['sometimes', Rule::in(['member', 'manager'])],
            'next_number' => ['sometimes', 'integer', 'min:1'],
            'pad_length' => ['sometimes', 'integer', 'min:1', 'max:6'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $prefix = strtoupper(trim($data['prefix']));
        $accessRole = $data['access_role'] ?? (str_starts_with($prefix, 'MGR') ? 'manager' : 'member');

        $series = CodeSeries::query()->create([
            'prefix' => $prefix,
            'name' => trim((string) ($data['name'] ?? '')) ?: $prefix,
            'access_role' => $accessRole,
            'next_number' => $data['next_number'] ?? 1,
            'pad_length' => $data['pad_length'] ?? 3,
            'active' => $data['active'] ?? true,
        ]);

        AuditLog::record($request->user()->id, 'code_series', $series->id, 'created', null, $series->prefix, 'Code series created');

        return response()->json($this->payload($series->loadCount('users')), 201);
    }

    public function update(Request $request, CodeSeries $code_series)
    {
        $data = $request->validate([
            'prefix' => ['sometimes', 'string', 'max:16', 'regex:/^[A-Za-z0-9]+$/', Rule::unique('code_series', 'prefix')->ignore($code_series->id)],
            'name' => ['sometimes', 'string', 'max:120'],
            'access_role' => ['sometimes', Rule::in(['member', 'manager'])],
            'next_number' => ['sometimes', 'integer', 'min:1'],
            'pad_length' => ['sometimes', 'integer', 'min:1', 'max:6'],
            'active' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['prefix'])) {
            $data['prefix'] = strtoupper(trim($data['prefix']));
        }

        $code_series->fill($data);
        $code_series->save();

        AuditLog::record($request->user()->id, 'code_series', $code_series->id, 'updated', null, $code_series->prefix, 'Code series updated');

        return response()->json($this->payload($code_series->fresh()->loadCount('users')));
    }

    public function destroy(Request $request, CodeSeries $code_series)
    {
        if ($code_series->users()->exists()) {
            throw ValidationException::withMessages([
                'series' => 'Cannot delete a series that still has employees. Reassign or delete those employees first.',
            ]);
        }

        $prefix = $code_series->prefix;
        $code_series->delete();
        AuditLog::record($request->user()->id, 'code_series', 0, 'deleted', $prefix, null, 'Code series deleted');

        return response()->json(['ok' => true, 'deleted' => $prefix]);
    }

    public function preview(CodeSeries $code_series)
    {
        return response()->json([
            'next_code' => $code_series->peekNextCode(),
            'series' => $this->payload($code_series->loadCount('users')),
        ]);
    }

    private function payload(CodeSeries $series): array
    {
        return [
            'id' => $series->id,
            'prefix' => $series->prefix,
            'name' => $series->name,
            'access_role' => $series->access_role,
            'next_number' => $series->next_number,
            'pad_length' => $series->pad_length,
            'active' => (bool) $series->active,
            'next_code' => $series->peekNextCode(),
            'users_count' => $series->users_count ?? $series->users()->count(),
        ];
    }
}
