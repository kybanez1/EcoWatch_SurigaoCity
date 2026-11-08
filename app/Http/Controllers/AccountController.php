<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\Barangay;
use Illuminate\Validation\Rule;

/** "My Account": every signed-in user can edit their own details and change their own password. */
class AccountController extends Controller
{
    public function edit(Request $request)
    {
        $user = $request->user();
        $layout = 'layouts.app';
        if ($user->role === 'cgso') {
            $layout = 'layouts.admin';
        }
        return view('account.edit', [
            'user' => $user,
            'layout' => $layout,
            'barangays' => Barangay::orderBy('name')->get(),
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $rules = [
            'name' => 'required|string|max:100',
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => 'nullable|string|max:30',
        ];
        // Only residents can move to another barangay. An official belongs to one barangay.
        if ($user->role === 'resident') {
            $rules['barangay_id'] = 'required|exists:barangays,id';
        }
        $data = $request->validate($rules);
        $user->update($data);
        return back()->with('success', 'Your account details were saved.');
    }

    public function updatePassword(Request $request)
    {
        $user = $request->user();
        $request->validate([
            'current_password' => 'required|string|max:255',
            'password' => 'required|string|min:8|max:255|confirmed|different:current_password',
        ], [
            'current_password.string' => 'The current password must be plain text.',
            'password.different' => 'The new password must be different from the current password.',
        ]);

        if (! Hash::check($request->current_password, $user->password)) {
            return back()->withErrors(['current_password' => 'The current password is not correct.'])->with('form', 'password');
        }

        $user->password = $request->password;
        $user->must_change_password = false;
        $user->setRememberToken(Str::random(60));
        $user->save();

        return redirect()->route('account.edit')->with('success', 'Your password was changed.');
    }
}
