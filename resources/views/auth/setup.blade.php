@extends('layouts.app', ['title' => 'Complete Profile'])
@section('content')
<div class="min-h-screen flex items-center justify-center bg-gray-100">
    <div class="bg-white p-8 rounded shadow-md w-full max-w-md">
        <h2 class="text-2xl font-bold mb-4">Welcome! Complete your profile</h2>
        <form method="POST" action="{{ route('setup-profile') }}">
            @csrf
            <div class="mb-4">
                <label>Full Name</label>
                <input type="text" name="name" required class="w-full border p-2 rounded">
            </div>
            <div class="mb-4">
                <label>Role</label>
                <select name="role" class="w-full border p-2 rounded">
                    <option value="president">President</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded w-full">Save & Continue</button>
        </form>
    </div>
</div>
@endsection