<?php

namespace App\Http\Controllers\Web;

use App\Domain\Device\Models\Device;
use App\Domain\Student\Models\Student;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class LandingController extends Controller
{
    /**
     * Render the public architectural landing page.
     */
    public function index(Request $request)
    {
        $stats = [
            'total_members' => Student::where('is_active', true)->count(),
            'total_devices' => Device::count(),
            'online_devices' => Device::where('status', 'online')->count(),
        ];

        return view('landing.index', compact('stats'));
    }
}
