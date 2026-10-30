<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'string', 'max:32'],
            'password' => ['required', 'string'],
        ]);

        $employeeId = strtoupper(trim($data['employee_id']));
        $user = User::query()->where('employee_id', $employeeId)->first();

        if (! $user || ! $user->active || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'employee_id' => 'Invalid employee ID or password.',
            ]);
        }

        if (! $user->isManager()) {
            throw ValidationException::withMessages([
                'employee_id' => 'Web login is for admins only. Employees use the BAYN OPS Telegram bot.',
            ]);
        }

        $user->tokens()->delete();
        $token = $user->createToken('web', ['*'], now()->addDays(30))->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => $this->userPayload($request->user()),
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        if (array_key_exists('name', $data)) {
            $name = trim($data['name']);
            if ($name === '') {
                throw ValidationException::withMessages(['name' => 'Name is required.']);
            }
            if ($user->name !== $name) {
                AuditLog::record($user->id, 'user', $user->id, 'name', $user->name, $name, 'Self profile update');
                $user->name = $name;
            }
        }

        if (array_key_exists('job_title', $data)) {
            $title = $data['job_title'] !== null ? (trim((string) $data['job_title']) ?: null) : null;
            if ((string) $user->job_title !== (string) $title) {
                AuditLog::record($user->id, 'user', $user->id, 'job_title', $user->job_title, $title, 'Self profile update');
                $user->job_title = $title;
            }
        }

        $user->save();

        return response()->json([
            'user' => $this->userPayload($user->fresh()),
            'message' => 'Profile updated',
        ]);
    }

    public function changePassword(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:6', 'max:100', 'confirmed'],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Current password is incorrect.',
            ]);
        }

        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'New password must be different from the current one.',
            ]);
        }

        $user->password = $data['password'];
        $user->save();
        AuditLog::record($user->id, 'user', $user->id, 'password', '***', '***', 'Self password change');

        $current = $user->currentAccessToken();
        $user->tokens()->where('id', '!=', $current->id)->delete();

        return response()->json([
            'ok' => true,
            'message' => 'Password updated',
            'user' => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'employee_id' => $user->employee_id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'job_title' => $user->job_title,
            'telegram_linked' => (bool) $user->telegram_chat_id,
        ];
    }
}
