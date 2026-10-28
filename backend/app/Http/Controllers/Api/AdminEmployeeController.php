<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminEmployeeController extends Controller
{
    public function index()
    {
        $users = User::query()
            ->orderBy('role')
            ->orderBy('employee_id')
            ->get()
            ->map(fn (User $u) => $this->payload($u));

        return response()->json(['employees' => $users]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'string', 'max:32', 'unique:users,employee_id', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190', 'unique:users,email'],
            'role' => ['required', Rule::in(['member', 'manager'])],
            'password' => ['required', 'string', 'min:6', 'max:100'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $employeeId = strtoupper(trim($data['employee_id']));
        $email = $data['email'] ?? strtolower($employeeId).'@bayn.local';

        $user = User::query()->create([
            'employee_id' => $employeeId,
            'name' => $data['name'],
            'email' => $email,
            'role' => $data['role'],
            'password' => Hash::make($data['password']),
            'active' => $data['active'] ?? true,
        ]);

        AuditLog::record($request->user()->id, 'user', $user->id, 'created', null, $employeeId, 'Employee created');

        return response()->json($this->payload($user), 201);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'employee_id' => ['sometimes', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('users', 'employee_id')->ignore($user->id)],
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['sometimes', Rule::in(['member', 'manager'])],
            'password' => ['sometimes', 'nullable', 'string', 'min:6', 'max:100'],
            'active' => ['sometimes', 'boolean'],
            'telegram_chat_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'clear_telegram' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['employee_id'])) {
            $data['employee_id'] = strtoupper(trim($data['employee_id']));
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

        foreach (['employee_id', 'name', 'email', 'role', 'active', 'telegram_chat_id'] as $field) {
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
                'user' => 'You cannot deactivate your own account.',
            ]);
        }

        $user->update(['active' => false, 'telegram_chat_id' => null]);
        $user->tokens()->delete();

        AuditLog::record($request->user()->id, 'user', $user->id, 'active', true, false, 'Employee deactivated');

        return response()->json($this->payload($user->fresh()));
    }

    private function payload(User $user): array
    {
        return [
            'id' => $user->id,
            'employee_id' => $user->employee_id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'active' => (bool) $user->active,
            'telegram_chat_id' => $user->telegram_chat_id,
            'telegram_linked' => (bool) $user->telegram_chat_id,
        ];
    }
}
