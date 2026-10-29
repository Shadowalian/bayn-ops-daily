<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CodeSeries;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminEmployeeController extends Controller
{
    public function index()
    {
        $users = User::query()
            ->with('codeSeries')
            ->orderBy('role')
            ->orderBy('employee_id')
            ->get()
            ->map(fn (User $u) => $this->payload($u));

        return response()->json(['employees' => $users]);
    }

    public function nextCode(Request $request)
    {
        if ($request->filled('series_id')) {
            $series = CodeSeries::query()->findOrFail((int) $request->query('series_id'));

            return response()->json([
                'employee_id' => $series->peekNextCode(),
                'prefix' => $series->prefix,
                'series_id' => $series->id,
                'access_role' => $series->access_role,
            ]);
        }

        $access = $request->query('role', 'member');
        abort_unless(in_array($access, ['member', 'manager'], true), 422);
        $prefix = strtoupper((string) $request->query('prefix', $access === 'manager' ? 'MGR' : 'EMP'));
        $prefix = preg_replace('/[^A-Z0-9]/', '', $prefix) ?: ($access === 'manager' ? 'MGR' : 'EMP');

        return response()->json([
            'employee_id' => $this->suggestCode($prefix),
            'prefix' => $prefix,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code_series_id' => ['required', 'integer', 'exists:code_series,id'],
            'employee_id' => ['sometimes', 'nullable', 'string', 'max:32', 'unique:users,employee_id', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:120'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'password' => ['sometimes', 'nullable', 'string', 'min:6', 'max:100'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $series = CodeSeries::query()->findOrFail($data['code_series_id']);
        if (! $series->active) {
            throw ValidationException::withMessages([
                'code_series_id' => 'That code series is inactive.',
            ]);
        }

        $employeeId = ! empty($data['employee_id'])
            ? strtoupper(trim($data['employee_id']))
            : $series->allocateNextCode();

        if (User::query()->where('employee_id', $employeeId)->exists()) {
            throw ValidationException::withMessages([
                'employee_id' => 'That employee code already exists.',
            ]);
        }

        $password = $data['password'] ?? Str::password(24);

        $user = User::query()->create([
            'employee_id' => $employeeId,
            'code_series_id' => $series->id,
            'name' => $data['name'],
            'email' => strtolower($employeeId).'@bayn.local',
            'role' => $series->access_role,
            'job_title' => isset($data['job_title']) ? trim($data['job_title']) : null,
            'password' => Hash::make($password),
            'active' => $data['active'] ?? true,
        ]);

        AuditLog::record($request->user()->id, 'user', $user->id, 'created', null, $employeeId, 'Employee created');

        return response()->json($this->payload($user->load('codeSeries')), 201);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'employee_id' => ['sometimes', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('users', 'employee_id')->ignore($user->id)],
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['sometimes', Rule::in(['member', 'manager'])],
            'code_series_id' => ['sometimes', 'nullable', 'integer', 'exists:code_series,id'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'password' => ['sometimes', 'nullable', 'string', 'min:6', 'max:100'],
            'active' => ['sometimes', 'boolean'],
            'telegram_chat_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'clear_telegram' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['employee_id'])) {
            $data['employee_id'] = strtoupper(trim($data['employee_id']));
        }

        if (array_key_exists('job_title', $data) && $data['job_title'] !== null) {
            $data['job_title'] = trim((string) $data['job_title']) ?: null;
        }

        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
            AuditLog::record($request->user()->id, 'user', $user->id, 'password', '***', '***', 'Password reset');
            unset($data['password']);
        } else {
            unset($data['password']);
        }

        if (! empty($data['clear_telegram'])) {
            $data['telegram_chat_id'] = null;
        }
        unset($data['clear_telegram']);

        if (! empty($data['code_series_id'])) {
            $series = CodeSeries::query()->find($data['code_series_id']);
            if ($series) {
                $data['role'] = $series->access_role;
            }
        }

        foreach (['employee_id', 'name', 'email', 'role', 'job_title', 'code_series_id', 'active', 'telegram_chat_id'] as $field) {
            if (array_key_exists($field, $data) && (string) $user->{$field} !== (string) ($data[$field] ?? '')) {
                AuditLog::record(
                    $request->user()->id,
                    'user',
                    $user->id,
                    $field,
                    $user->{$field},
                    $data[$field],
                    'Employee updated'
                );
                $user->{$field} = $data[$field];
            }
        }

        $user->save();

        return response()->json($this->payload($user->fresh()));
    }

    public function destroy(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages([
                'user' => 'You cannot delete your own account.',
            ]);
        }

        $hard = $request->boolean('hard');
        $code = $user->employee_id;
        $user->tokens()->delete();

        if ($hard) {
            AuditLog::record($request->user()->id, 'user', $user->id, 'deleted', $code, null, 'Employee permanently deleted');
            $user->delete();

            return response()->json(['ok' => true, 'deleted' => $code]);
        }

        $user->update(['active' => false, 'telegram_chat_id' => null]);
        AuditLog::record($request->user()->id, 'user', $user->id, 'active', true, false, 'Employee deactivated');

        return response()->json($this->payload($user->fresh()));
    }

    private function suggestCode(string $prefix): string
    {
        $ids = User::query()
            ->where('employee_id', 'like', $prefix.'%')
            ->pluck('employee_id');

        $max = 0;
        foreach ($ids as $id) {
            if (preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/', (string) $id, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return sprintf('%s%03d', $prefix, $max + 1);
    }

    private function payload(User $user): array
    {
        return [
            'id' => $user->id,
            'employee_id' => $user->employee_id,
            'code_series_id' => $user->code_series_id,
            'code_series' => $user->codeSeries ? [
                'id' => $user->codeSeries->id,
                'prefix' => $user->codeSeries->prefix,
                'name' => $user->codeSeries->name,
            ] : null,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'job_title' => $user->job_title,
            'active' => (bool) $user->active,
            'telegram_chat_id' => $user->telegram_chat_id,
            'telegram_linked' => (bool) $user->telegram_chat_id,
        ];
    }
}
