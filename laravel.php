<?php

namespace App\Http\Controllers;

use App\Models\Docs\ServiceStandardToCustom;
use App\Models\Docs\SpecializationStandard;
use App\Models\Docs\SpecializationStandardToCustom;
use App\Models\Docs\StandardService;
use App\Models\Ereg\Department;
use App\Models\Ereg\Doctor;
use App\Models\Ereg\DoctorSpecialization;
use App\Models\Ereg\InsuranceAdmin;
use App\Models\Ereg\InsurancePayer;
use App\Models\Ereg\MedicalProvider;
use App\Models\Ereg\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Http\Request;
use DB;

class IntervalsController extends Controller
{
    private $serviceIds;

    /**
     * @param Request $request
     * @return mixed
     */
    public function get(Request $request)
    {
        $providers = json_decode($request->providers, true);
        $doctors = json_decode($request->doctors, true);
        $specializations = $request->specializations;
        $services = $request->services;

        $providersArray = collect();
        $intervalsArray = [];

        if ($providers && count($providers) > 0) {
            foreach ($providers as $key => $provider) {
                $medicalProviderId = $provider['medical_provider_id'];
                $medicalProvider = MedicalProvider::where('medical_provider_id', $medicalProviderId)->first();

                $depsCollect = collect($provider['departments']);

                $departmentsArray = $depsCollect->map(function ($dep) use ($medicalProviderId, $request, $doctors, $specializations, $services, &$intervalsArray) {
                    $department = Department::where('medical_provider_id', $medicalProviderId)
                        ->where('meduchet_department_id', $dep['meduchet_department_id'])->first();

                    $q = WorkSchedule::select(
                        'ereg.work_schedule.work_schedule_id AS work_schedule_id',
                        'ereg.work_schedule.meduchet_schedule_id AS meduchet_schedule_id',
                        'ereg.work_schedule.meduchet_department_id AS meduchet_department_id',
                        'ereg.work_schedule.medical_provider_id AS medical_provider_id',
                        'ereg.work_schedule.meduchet_doctor_id AS meduchet_doctor_id',
                        'ereg.doctor.doctor_id AS doctor_id',
                        'ereg.doctor.last_name AS last_name',
                        'ereg.doctor.first_name AS first_name',
                        'ereg.doctor.middle_name AS middle_name',
                        DB::raw("CONCAT(doctor.last_name, ' ',doctor.first_name, ' ', doctor.middle_name) as doctor_name"),
                        'ereg.work_schedule.schedule_date AS schedule_date',
                        'ereg.work_schedule.schedule_time_from AS schedule_time_from'
                    );

                    if (!empty($doctors)) {
                        $q = $this->byDoctors($q, $doctors, $medicalProviderId, $dep['meduchet_department_id']);
                    }

                    if (!empty($specializations)) {
                        $q = $this->bySpecialization($q, $specializations, $medicalProviderId);
                    }

                    if (!empty($services)) {
                        $q = $this->byServices($q, $services, $medicalProviderId, $dep['meduchet_department_id']);
                    }

                    $q->where('work_schedule.medical_provider_id', $medicalProviderId);
                    $q->where('work_schedule.meduchet_department_id', $dep['meduchet_department_id']);
                    $q->whereNotNull('work_schedule.work_schedule_id');

                    $intervals = $q->whereDate('schedule_date', '>=', $request->dateFrom)
                        ->whereDate('schedule_date', '<=', Carbon::parse($request->dateFrom)->addDays(14)->format("Y-m-d"))
                        ->where('doctor.last_name', '!=', '')
                        ->whereRaw('ADDTIME(schedule_date, schedule_time_from) > NOW()')
                        ->whereNotNull('doctor.last_name')
                        ->groupBy('work_schedule_id')
                        ->groupBy('meduchet_schedule_id')
                        ->groupBy('meduchet_department_id')
                        ->groupBy('medical_provider_id')
                        ->groupBy('meduchet_doctor_id')
                        ->groupBy('doctor_id')
                        ->groupBy('doctor_name')
                        ->groupBy('middle_name')
                        ->groupBy('last_name')
                        ->groupBy('first_name')
                        ->groupBy('schedule_date')
                        ->groupBy('schedule_time_from')
                        ->orderBy('doctor_name')
                        ->orderBy('schedule_date')
                        ->orderBy('schedule_time_from')
                        ->get();

                    $doctors = [];

                    $intervals = $intervals->groupBy('doctor_name');
                    array_push($intervalsArray, $intervals);

                    if (count($intervals) > 0) {
                        foreach ($intervals as $key => $interval) {
                            if (count($interval) > 0) {

                                $days = $interval->groupBy('schedule_date');
                                $scheduleDays = [];

                                if (count($days) > 0) {
                                    foreach ($days as $k => $day) {
                                        array_push($scheduleDays, [
                                            'day' => $k,
                                            'intervals' => $day
                                        ]);
                                    }
                                }

                                array_push($doctors, [
                                    'doctor_name' => $interval[0]['doctor_name'],
                                    'last_name' => $interval[0]['last_name'],
                                    'first_name' => $interval[0]['first_name'],
                                    'middle_name' => $interval[0]['middle_name'],
                                    'meduchet_doctor_id' => $interval[0]['meduchet_doctor_id'],
                                    'days' => $scheduleDays,
                                    'intervalIndex' => 0,
                                    'dayIndex' => 0,
                                    'specializations' => DoctorSpecialization::select('specialization_name')->leftJoin('specialization', function ($q) {
                                        $q->on('specialization.medical_provider_id', 'doctor_specialization.medical_provider_id');
                                        $q->on('specialization.meduchet_specialization_id', 'doctor_specialization.meduchet_specialization_id');
                                    })
                                        ->where('doctor_specialization.medical_provider_id', $medicalProviderId)
                                        ->where('doctor_specialization.meduchet_doctor_id', $interval[0]['meduchet_doctor_id'])
                                        ->groupBy('specialization_name')
                                        ->distinct()
                                        ->get(),
                                    'services' => $this->getDoctorServices($services, $medicalProviderId, $request->admin_id)
                                ]);
                            }
                        }
                    }


                    $department['doctors'] = $doctors;
                    return $department;
                });

                $providersArray->prepend([
                    'medical_provider_id' => $medicalProviderId,
                    'medical_provider_name' => $medicalProvider->medical_provider_name,
                    'departments' => $departmentsArray,
                ], $key);
            }
        }

        return response()->json([
            'request' => $request->all(),
            'providers' => $providersArray,
            'intervals' => $intervalsArray
        ]);
    }

    /**
     * @param $q
     * @param $specializations
     * @param $medicalProviderId
     * @return mixed
     */
    private function bySpecialization($q, $specializations, $medicalProviderId)
    {
        $q
            ->join('doctor', function ($query) {
                $query->on('doctor.medical_provider_id', 'work_schedule.medical_provider_id');
                $query->on('doctor.meduchet_doctor_id', 'work_schedule.meduchet_doctor_id');
            })
            ->join('doctor_specialization', function ($query) {
                $query->on('doctor_specialization.medical_provider_id', 'doctor.medical_provider_id');
                $query->on('doctor_specialization.meduchet_doctor_id', 'doctor.meduchet_doctor_id');
            })
            ->join('docs.specialization_standard_to_custom', function ($query) {
                $query->on('doctor_specialization.meduchet_specialization_id', 'docs.specialization_standard_to_custom.meduchet_specialization_id');
                $query->on('doctor_specialization.medical_provider_id', 'docs.specialization_standard_to_custom.medical_provider_id');
            })
            ->join('docs.specialization_standard', function ($query) {
                $query->on('docs.specialization_standard_to_custom.specialization_standard_id', 'docs.specialization_standard.id');
            })
            ->whereIn('docs.specialization_standard.id', $specializations)
            ->whereNotNull('doctor_specialization.doctor_specialization_id');

        return $q;
    }

    /**
     * @param $q
     * @param $doctors
     * @param $medicalProviderId
     */
    private function byDoctors($q, $doctors, $medicalProviderId, $departmentId)
    {
        $docs = collect($doctors);
        $doctorIds = [];

        $docs->each(function ($doc) use ($medicalProviderId, &$doctorIds) {
            if ($medicalProviderId == $doc['medical_provider_id']) {
                array_push($doctorIds, $doc['meduchet_doctor_id']);
            }
        });

        if (count($doctorIds) > 0) {
            $q
                ->join('doctor', function ($query) {
                    $query->on('doctor.medical_provider_id', 'work_schedule.medical_provider_id');
                    $query->on('doctor.meduchet_doctor_id', 'work_schedule.meduchet_doctor_id');
                })
                ->join('doctor_department', function ($query) {
                    $query->on('doctor.medical_provider_id', 'doctor_department.medical_provider_id');
                    $query->on('doctor.meduchet_doctor_id', 'doctor_department.meduchet_doctor_id');
                });

            $q->whereIn('doctor.meduchet_doctor_id', $doctorIds)
                ->where('doctor_department.meduchet_department_id', $departmentId);
        }
        return $q;
    }


    /**
     * @param $q
     * @param $services
     * @param $medicalProviderId
     * @param $departmentIds
     * @return mixed
     */
    private function byServices($q, $services, $medicalProviderId, $departmentIds)
    {
        if (!empty($services)) {

            $data = ServiceStandardToCustom::leftJoin('docs.clinic', 'docs.service_standard_to_custom.clinic_id', 'docs.clinic.id')
                ->whereIn('docs.service_standard_to_custom.standard_service_id', $services)
                ->where('docs.clinic.registration_id', $medicalProviderId)
                ->get();

            $serviceIds = $data->unique('clinic_service_simple_id')->pluck('clinic_service_simple_id');
            $this->serviceIds = $serviceIds;

            if (!empty($serviceIds)) {

                $q->join('doctor', function ($query) {
                    $query->on('work_schedule.medical_provider_id', 'doctor.medical_provider_id');
                    $query->on('work_schedule.meduchet_doctor_id', 'doctor.meduchet_doctor_id');
                });

                $q->join('ereg.department_service', function ($q) {
                    $q->on('ereg.department_service.medical_provider_id', 'ereg.work_schedule.medical_provider_id');
                    $q->on('ereg.department_service.meduchet_department_id', 'ereg.work_schedule.meduchet_department_id');
                });

                $q->join('ereg.doctor_service', function ($q) {
                    $q->on('ereg.doctor_service.medical_provider_id', 'doctor.medical_provider_id');
                    $q->on('ereg.doctor_service.meduchet_doctor_id', 'doctor.meduchet_doctor_id');
                })
                    ->where('department_service.meduchet_department_id', $departmentIds)
                    ->whereIn('ereg.doctor_service.meduchet_service_id', $serviceIds)
                    ->whereNotNull('ereg.doctor_service.doctor_service_id');
            }
        }

        return $q;
    }

    /**
     * @param $services
     * @param $medicalProviderId
     * @param $admin_id
     * @return array
     */
    private function getDoctorServices($services, $medicalProviderId, $admin_id)
    {
        $admin = InsuranceAdmin::find($admin_id);

        $payer = InsurancePayer::where('insurance_id', $admin->insurance_company_id)->first();

        $meduchet_payer_id = $payer ? $payer->meduchet_payer_id : 0;

        $servicesArray = [];
        if (!empty($services)) {
            $services = ServiceStandardToCustom::select('ereg.service.service_name', 'ereg.price_list.special_price',
                'ereg.service.service_id', 'ereg.service.medical_provider_id',
                'ereg.service.meduchet_service_id'
            )
                ->leftJoin('docs.clinic', 'docs.service_standard_to_custom.clinic_id', 'docs.clinic.id')
                ->leftJoin('ereg.service', function ($query) {
                    $query->on('ereg.service.medical_provider_id', 'docs.clinic.registration_id');
                    $query->on('ereg.service.meduchet_service_id', 'docs.service_standard_to_custom.clinic_service_simple_id');
                })
                ->leftJoin('ereg.price_list', function ($query) {
                    $query->on('price_list.medical_provider_id', 'service.medical_provider_id');
                    $query->on('price_list.meduchet_service_id', 'service.meduchet_service_id');
                })
                ->whereIn('docs.service_standard_to_custom.standard_service_id', $services)
                ->where('docs.clinic.registration_id', $medicalProviderId)
                ->where('price_list.service_allowed', -1)
                ->where('price_list.meduchet_payer_id', $meduchet_payer_id)
                ->groupBy('service.service_id')
                ->groupBy('service.service_name')
                ->groupBy('service.medical_provider_id')
                ->groupBy('service.meduchet_service_id')
                ->groupBy('price_list.special_price')
                ->orderBy('service.service_name')
                ->distinct()
                ->get();

            if (count($services) > 0) {
                $services->transform(function ($item) {
                    $item->selected = true;
                    $item->franchise = '';
                    return $item;
                });
            }

            return $services;
        }

        return $servicesArray;
    }

}
