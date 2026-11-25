<?php

namespace App\Http\Controllers;

use App\Services\Tours\TourDefinitions;
use App\Services\Tours\TourService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Remembers what a user did with a guided tour (see TourService). The browser sends nothing else: no clicks,
 * no pages, no timing.
 */
class TourController extends Controller
{
    public function finish(Request $request, TourService $tours, string $tour): Response
    {
        abort_unless(isset(TourDefinitions::all()[$tour]), 404);

        $tours->finish($request->user(), $tour);

        return response()->noContent();
    }

    public function skip(Request $request, TourService $tours, string $tour): Response
    {
        abort_unless(isset(TourDefinitions::all()[$tour]), 404);

        $tours->skip($request->user(), $tour, $request->boolean('dont_show'));

        return response()->noContent();
    }

    /** "Restart all tours": every tour is offered again. */
    public function reset(Request $request, TourService $tours): Response
    {
        $tours->reset($request->user());

        return response()->noContent();
    }
}
