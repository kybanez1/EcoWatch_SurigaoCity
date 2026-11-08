<?php

namespace App\Http\Controllers\Cgso;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    public function index()
    {
        return view('cgso.announcements', ['announcements' => Announcement::orderByDesc('published_on')->orderByDesc('id')->paginate(10)->withQueryString()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'category' => 'required|in:' . implode(',', Announcement::CATEGORIES),
            'title' => 'required|string|max:150',
            'body' => 'required|string|max:1500',
        ]);
        if (Announcement::where('title', $data['title'])->where('published_on', now()->toDateString())->exists()) {
            return back()->with('success', 'That announcement was already posted today.');
        }
        Announcement::create($data + ['published_on' => now()->toDateString()]);
        return back()->with('success', 'Announcement posted.');
    }

    public function update(Request $request, Announcement $announcement)
    {
        $data = $request->validate([
            'category' => 'required|in:' . implode(',', Announcement::CATEGORIES),
            'title' => 'required|string|max:150',
            'body' => 'required|string|max:1500',
        ]);
        $announcement->update($data);
        return back()->with('success', 'Announcement updated.');
    }

    public function destroy(Announcement $announcement)
    {
        $announcement->delete();
        return back()->with('success', 'Announcement deleted.');
    }
}
