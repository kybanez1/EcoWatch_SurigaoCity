<?php

namespace App\Http\Controllers\Cgso;

use App\Http\Controllers\Controller;
use App\Models\CollectionNote;
use App\Models\TruckRoute;

/** The CGSO monitors collection progress and reads the collectors' notes here. */
class CollectionController extends Controller
{
    public function index()
    {
        $routes = TruckRoute::with(['truck.collector', 'barangay', 'areas'])->get();
        $notes = CollectionNote::with(['user', 'truck', 'route'])->latest('id')->paginate(15)->withQueryString();

        return view('cgso.collections', compact('routes', 'notes'));
    }
}
