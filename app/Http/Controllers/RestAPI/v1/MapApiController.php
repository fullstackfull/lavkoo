<?php

namespace App\Http\Controllers\RestAPI\v1;

use App\Http\Controllers\Controller;
use App\Utils\Helpers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class MapApiController extends Controller
{
    public function placeApiAutocomplete(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'search_text' => 'required',
        ]);

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-Goog-Api-Key' => getWebConfig(name: 'map_api_key_server'),
            'X-Goog-FieldMask' => '*'
        ])->post('https://places.googleapis.com/v1/places:autocomplete', [
            'input' => $request->input('search_text'),
        ]);

        return response()->json($response->json());
    }

    public function distanceApi(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'origin_lat' => 'required',
            'origin_lng' => 'required',
            'destination_lat' => 'required',
            'destination_lng' => 'required',
        ]);

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $origin = [
            "waypoint" => [
                "location" => [
                    "latLng" => [
                        "latitude" => $request['origin_lat'],
                        "longitude" => $request['origin_lng']
                    ]
                ]
            ]
        ];

        $destination = [
            "waypoint" => [
                "location" => [
                    "latLng" => [
                        "latitude" => $request['destination_lat'],
                        "longitude" => $request['destination_lng']
                    ]
                ]
            ]
        ];

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-Goog-Api-Key' => getWebConfig(name: 'map_api_key_server'),
            'X-Goog-FieldMask' => '*'
        ])->post('https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix', [
            "origins" => $origin,
            "destinations" => $destination,
            "travelMode" => "DRIVE",
            "routingPreference" => "TRAFFIC_AWARE"
        ]);

        return response()->json($response->json());
    }

    public function placeApiDetails(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'placeid' => 'required',
        ]);

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-Goog-Api-Key' => getWebConfig(name: 'map_api_key_server'),
            'X-Goog-FieldMask' => '*'
        ])->get('https://places.googleapis.com/v1/places/' . $request['placeid']);

        return response()->json($response->json());
    }

    public function geocode_api(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'lat' => 'required',
            'lng' => 'required',
        ]);

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $apiKey = getWebConfig(name: 'map_api_key_server');
        $response = Http::get('https://maps.googleapis.com/maps/api/geocode/json?latlng=' . $request['lat'] . ',' . $request['lng'] . '&key=' . $apiKey);
        return response()->json($response->json());
    }
}
