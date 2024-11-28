<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CargoCalculationController extends Controller
{
    private $variants;

    public function __construct()
    {
        $this->variants = json_decode(file_get_contents(storage_path('app/variants.json')), true);
    }

    public function calculate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type_of_transportation' => 'required|in:sea,road',
            'cargo_list' => 'required|array',
            'cargo_list.*.weight' => 'required|numeric',
            'cargo_list.*.width' => 'required|numeric',
            'cargo_list.*.height' => 'required|numeric',
            'cargo_list.*.length' => 'required|numeric',
            'cargo_list.*.quantity' => 'required|integer',
            'cargo_list.*.stacking_parameters' => 'required|array',
            'cargo_list.*.stacking_parameters.not_stacking' => 'required|boolean',
            'cargo_list.*.stacking_parameters.lower_tier' => 'required|boolean',
            'cargo_list.*.stacking_parameters.upper_tier' => 'required|boolean',
            'cargo_list.*.stacking_parameters.among_themselves' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $type = $request->input('type_of_transportation');
        $cargoList = $request->input('cargo_list');

        $result = $this->calculateOptimalLoading($type, $cargoList);

        return response()->json($result);
    }

    private function calculateOptimalLoading($type, $cargoList)
    {
        $containers = $this->variants[$type];
        $neededContainers = [];

        // Sort cargo by stacking parameters
        usort($cargoList, function($a, $b) {
            if ($a['stacking_parameters']['not_stacking'] && !$b['stacking_parameters']['not_stacking']) return -1;
            if (!$a['stacking_parameters']['not_stacking'] && $b['stacking_parameters']['not_stacking']) return 1;
            if ($a['stacking_parameters']['lower_tier'] && !$b['stacking_parameters']['lower_tier']) return -1;
            if (!$a['stacking_parameters']['lower_tier'] && $b['stacking_parameters']['lower_tier']) return 1;
            return 0;
        });

        foreach ($containers as $containerType => $containerSpecs) {
            $containerVolume = $containerSpecs['length'] * $containerSpecs['width'] * $containerSpecs['height'];
            $containerMaxLoad = $containerSpecs['maxload'];
            $containerCount = 0;
            $remainingVolume = $containerVolume;
            $remainingWeight = $containerMaxLoad;
            $lowerTierVolume = 0;
            $upperTierVolume = 0;

            foreach ($cargoList as $cargo) {
                $cargoVolume = $cargo['length'] * $cargo['width'] * $cargo['height'];
                $cargoTotalVolume = $cargoVolume * $cargo['quantity'];
                $cargoTotalWeight = $cargo['weight'] * $cargo['quantity'];

                while ($cargoTotalVolume > 0 && $cargoTotalWeight > 0) {
                    if ($cargo['stacking_parameters']['not_stacking']) {
                        if ($cargoVolume <= $remainingVolume && $cargo['weight'] <= $remainingWeight) {
                            $remainingVolume -= $cargoVolume;
                            $remainingWeight -= $cargo['weight'];
                            $cargoTotalVolume -= $cargoVolume;
                            $cargoTotalWeight -= $cargo['weight'];
                        } else {
                            $containerCount++;
                            $remainingVolume = $containerVolume;
                            $remainingWeight = $containerMaxLoad;
                        }
                    } elseif ($cargo['stacking_parameters']['lower_tier']) {
                        if ($cargoVolume <= $remainingVolume - $upperTierVolume && $cargo['weight'] <= $remainingWeight) {
                            $lowerTierVolume += $cargoVolume;
                            $remainingVolume -= $cargoVolume;
                            $remainingWeight -= $cargo['weight'];
                            $cargoTotalVolume -= $cargoVolume;
                            $cargoTotalWeight -= $cargo['weight'];
                        } else {
                            $containerCount++;
                            $remainingVolume = $containerVolume;
                            $remainingWeight = $containerMaxLoad;
                            $lowerTierVolume = 0;
                            $upperTierVolume = 0;
                        }
                    } elseif ($cargo['stacking_parameters']['upper_tier']) {
                        if ($cargoVolume <= $remainingVolume && $cargo['weight'] <= $remainingWeight) {
                            $upperTierVolume += $cargoVolume;
                            $remainingVolume -= $cargoVolume;
                            $remainingWeight -= $cargo['weight'];
                            $cargoTotalVolume -= $cargoVolume;
                            $cargoTotalWeight -= $cargo['weight'];
                        } else {
                            $containerCount++;
                            $remainingVolume = $containerVolume;
                            $remainingWeight = $containerMaxLoad;
                            $lowerTierVolume = 0;
                            $upperTierVolume = 0;
                        }
                    }
                }
            }

            if ($containerCount > 0) {
                $neededContainers[$containerType] = $containerCount;
                break;
            }
        }

        $response = "You'll need ";
        foreach ($neededContainers as $containerType => $count) {
            $response .= "$count $containerType" . ($count > 1 ? "s" : "") . " ";
        }
        $response .= "for that transportation";

        return ['message' => $response];
    }
}