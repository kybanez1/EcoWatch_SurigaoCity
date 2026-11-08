<?php

namespace App\Http\Controllers\Cgso;

use App\Http\Controllers\Controller;
use App\Models\Barangay;
use App\Models\Truck;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        return view('cgso.users', [
            'barangays' => Barangay::with('official')->orderBy('name')->get(),
            'users' => User::with('barangay')->orderBy('role')->orderBy('name')->paginate(15)->withQueryString(),
        ]);
    }

    public function storeBarangay(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:80|unique:barangays,name']);
        Barangay::create($data);
        return back()->with('success', "Barangay {$data['name']} added.");
    }

    public function updateBarangay(Request $request, Barangay $barangay)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80', Rule::unique('barangays', 'name')->ignore($barangay->id)]]);
        $barangay->update($data);
        return back()->with('success', 'Barangay renamed.');
    }

    public function destroyBarangay(Barangay $barangay)
    {
        if ($barangay->users()->exists() || $barangay->reports()->exists() || $barangay->schedules()->exists() || $barangay->routes()->exists()) {
            return back()->withErrors(['barangay' => "{$barangay->name} is still used by accounts, routes, schedules or reports and can't be removed."]);
        }
        $barangay->delete();
        return back()->with('success', 'Barangay removed.');
    }

    public function storeOfficial(Request $request)
    {
        $data = $request->validate([
            'barangay_id' => ['required', Rule::exists('barangays', 'id'), Rule::unique('users', 'barangay_id')->where('role', 'official')->where('is_active', true)],
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:150|unique:users,email',
            'password' => 'required|string|min:8',
        ], ['barangay_id.unique' => 'This barangay already has an active official account.']);

        // The CGSO gives a first password, so the official must choose a new one at the first login.
        User::create($data + ['role' => 'official', 'must_change_password' => true]);
        return back()->with('success', 'Barangay official account created. The official must change the password at the first login.');
    }

    public function storeCollector(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:150|unique:users,email',
            'password' => 'required|string|min:8',
            'phone' => 'nullable|string|max:30',
        ]);
        User::create($data + ['role' => 'collector', 'must_change_password' => true]);
        return back()->with('success', 'Collector account created. Assign the collector to a truck in Trucks.');
    }

    /** Edit name, email, phone and (for residents and officials) the barangay. The role cannot be changed. */
    public function update(Request $request, User $user)
    {
        if ($user->role === 'cgso') {
            return back()->withErrors(['user' => 'CGSO accounts are edited by their owner in My Account.']);
        }

        $rules = [
            'name' => 'required|string|max:100',
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => 'nullable|string|max:30',
        ];
        if ($user->role === 'resident') {
            $rules['barangay_id'] = 'required|exists:barangays,id';
        }
        if ($user->role === 'official') {
            $rules['barangay_id'] = ['required', 'exists:barangays,id', Rule::unique('users', 'barangay_id')->where('role', 'official')->where('is_active', true)->ignore($user->id)];
        }

        $data = $request->validate($rules, ['barangay_id.unique' => 'This barangay already has an active official account.']);
        $user->update($data);
        return back()->with('success', $user->name . ' was updated.');
    }

    /** Sets a temporary password. The user must choose a new password at the next login. */
    public function resetPassword(Request $request, User $user)
    {
        if ($user->role === 'cgso') {
            return back()->withErrors(['user' => 'CGSO accounts change their password in My Account.']);
        }

        $data = $request->validate(['password' => 'required|string|min:8|max:100']);

        $user->password = $data['password'];
        $user->must_change_password = true;
        $user->setRememberToken(Str::random(60));
        $user->save();
        $this->endSessionsOf($user);

        return back()->with('success', 'A temporary password was set for ' . $user->name . '. Give it to the user in person; they must change it at the next login.');
    }

    public function deactivate(Request $request, User $user)
    {
        if ($user->id === $request->user()->id || $user->role === 'cgso') {
            return back()->withErrors(['user' => 'CGSO accounts cannot be deactivated here.']);
        }
        if ($user->role === 'collector' && $user->openReportCount() > 0) {
            return back()->withErrors(['user' => $user->name . ' still has ' . $user->openReportCount() . ' open report(s). Reassign them first.']);
        }

        DB::beginTransaction();
        try {
            $user->update(['is_active' => false, 'deactivated_at' => now()]);
            // A deactivated collector no longer drives a truck. Old reports and notes keep the name.
            Truck::where('collector_id', $user->id)->update(['collector_id' => null]);
            DB::commit();
        } catch (\Exception $error) {
            DB::rollBack();
            return back()->withErrors(['user' => 'The account could not be deactivated. Please try again.']);
        }

        // Log the user out of every open session.
        $user->setRememberToken(Str::random(60));
        $user->save();
        $this->endSessionsOf($user);

        return back()->with('success', $user->name . ' was deactivated and can no longer log in.');
    }

    public function activate(User $user)
    {
        // An official can only be reactivated while the barangay has no other active official.
        if ($user->role === 'official') {
            $otherOfficial = User::where('role', 'official')->where('barangay_id', $user->barangay_id)->where('is_active', true)->where('id', '!=', $user->id)->exists();
            if ($otherOfficial) {
                return back()->withErrors(['user' => 'This barangay already has another active official.']);
            }
        }
        $user->update(['is_active' => true, 'deactivated_at' => null]);
        return back()->with('success', $user->name . ' is active again.');
    }

    /** Accounts with reports, notes or other history cannot be deleted. Deactivate them instead. */
    public function destroy(Request $request, User $user)
    {
        if ($user->id === $request->user()->id || $user->role === 'cgso') {
            return back()->withErrors(['user' => 'CGSO accounts cannot be deleted here.']);
        }
        if ($user->hasImportantRecords()) {
            return back()->withErrors(['user' => $user->name . ' has reports, notes or history, so the account cannot be deleted. Use Deactivate instead.']);
        }

        Truck::where('collector_id', $user->id)->update(['collector_id' => null]);
        $user->delete();
        return back()->with('success', 'Account deleted.');
    }

    /** Removes the open browser sessions of a user (when sessions are stored in the database). */
    private function endSessionsOf(User $user)
    {
        if (config('session.driver') === 'database' && Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }
    }
}
