<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function form()
    {
        return view('auth.login', ['setup' => ! User::exists()]);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }
        $request->session()->regenerate();

        return redirect()->intended(route('catalog'));
    }

    public function setup(Request $request)
    {
        abort_if(User::exists(), 403, 'Initial setup has already been completed.');
        $data = $request->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:255|unique:users', 'password' => ['required', 'confirmed', Password::min(12)]]);
        $user = DB::transaction(function () use ($data) {
            DB::table('setup_locks')->insert(['key' => 'initial-admin']);
            $user = new User($data);
            $user->is_admin = true;
            $user->save();

            return $user;
        });
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('catalog')->with('success', 'Welcome home! Your admin account is ready.');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
