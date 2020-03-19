<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\CalculationService;
use App\Models\RoomType;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class CalculationController extends Controller
{
    protected $adults;
    protected $children;
    protected $babies;

    /**
     * @param Request $request
     * @param CalculationService $calculator
     * @return \Illuminate\Http\JsonResponse
     */
    public function findRoomTypes(Request $request, CalculationService $calculator)
    {
      $form = $request->all();
      $dates = explode(' - ', $request->dates);
      $form['from_date'] = Carbon::parse($dates[0])->format('Y-m-d');
      $form['to_date'] = Carbon::parse($dates[1])->format('Y-m-d');
      $this->children = explode(',', $request->children);
      $this->adults = $request->adults;

      $roomTypes = RoomType::where('accommodation_id', $request->accommodation_id)
        ->with(['baseCatering', 'caterings.cateringType', 'prices.childrenPrices',
          'prices.childrenPrices.pricingColumn', 'childrenPricingColumns','prices' => function($query) {
            return $query->orderBy('start', 'asc')->orderBy('end', 'asc');
          }])
        ->where('beds', '>=', $form['adults'])->get();

      // Recalculate the number of adults and children
      if(!empty($roomTypes)) {
        foreach ($roomTypes as &$roomType) {
          $roomType = $this->recalculateChildrenAndBabies($roomType, $this->children, $this->adults);
        }
      }

      // Remove room type that doesn't fit beds and children params
      $roomTypes = $this->reduceRoomTypesByBedsAndChildren($roomTypes);

      // Remove room type that doesn't fit beds deviation condition
      $roomTypes = $this->reduceRoomTypesByBedsDeviation($roomTypes);

      // Filter prices due to rangeType and dates
      if(count($roomTypes) > 0) {
        foreach ($roomTypes as $key => &$roomType) {
          if(count($roomType->prices) > 0) {
            $full_match_indices = [];
            $nearest = [];

            foreach ($roomType->prices as $k => $price) {
              if($roomType->range_type == 'fixed') {
                if($price->start == $form['from_date'] && $price->end == $form['to_date']) {
                  // First we take price row with full match
                  $price->match = 'full';
                  array_push($full_match_indices, $k);

                  // Then we take nearest previous and nearest next price row
                  if(count($full_match_indices) == 1) {
                    if(isset($roomType->prices[$k-1])) {
                      array_push($nearest, $k-1);
                      $roomType->prices[$k-1]->match = 'nearest';
                    }
                    if(isset($roomType->prices[$k+1])) {
                      array_push($nearest, $k+1);
                      $roomType->prices[$k+1]->match = 'nearest';
                    }
                  }
                }
              }
            }

            if(!empty($full_match_indices)) {
              $roomType->calculatedPrices = $roomType->prices->filter(function ($value) {
                return isset($value->match);
              })->flatten();
            } else {
              /*In case we didn't find prices with 'full' match in dates -
               we are searching for prices for each room type*/
              if($roomType->range_type == 'fixed') {
                $roomType = $this->findPriceRowsFixed($roomType, $form);
              } else if ($roomType->range_type == 'flex') {
                $roomType = $this->findPriceRowsFlex($roomType, $form);
              } else {
                $roomType = $this->findPriceRowsWeekly($roomType, $form);
              }
            }

            /* Now we make calculation for each price row (for each catering variant in price row)
            Every calculated price consist of:
            - base price
            - price_unit (person or room)
            - price_frame (day or week)
            - catering variant (+- $/%)
            - children prices (+- $/%)
            */

            $roomType =  $this->getCalculatedPrices($calculator, $roomType, $form);
          }
        }
      }

      return response()->json([
        'request' => $request->all(),
        'roomTypes' => $roomTypes
      ]);
    }

    /**
     * @param $roomType
     * @param $form
     * @return mixed
     */
    private function findPriceRowsFixed($roomType, $form)
    {
      $match_indices = [];

      $difference = [];
      $min = false;
      $min_index = false;

      // Looking for the closest price row to the from_date (minimum difference in days)
      foreach ($roomType->prices as $k => $price) {
        $diff = Carbon::parse($price->start)->diffInDays(Carbon::parse($form['from_date']));

        if ($min === false || $diff < $min) {
          $min = $diff;
          $min_index = $k;
        }

        array_push($difference, Carbon::parse($form['from_date'])->diffInDays(Carbon::parse($price->start)));
      }

      // Than add the previous closest and next closest if found
      if ($min_index !== false) {
        array_push($match_indices, $min_index);
        $roomType->prices[$min_index]->match = 'nearest';

        if (isset($roomType->prices[$min_index + 1])) {
          array_push($match_indices, $min_index + 1);
          $roomType->prices[$min_index + 1]->match = 'nearest';
        }
      }

      if (!empty($match_indices)) {
        $roomType->calculatedPrices = $roomType->prices->filter(function ($value) {
          return isset($value->match);
        })->flatten();
      } else {
        $roomType->calculatedPrices = [];
      }

      return $roomType;
    }

    /**
     * @param $roomType
     * @param $form
     */
    private function findPriceRowsFlex($roomType, $form)
    {
      $matchingPriceRows = [];
      $matchingRowsIndices = [];

      // Find first and last indices in prices which cover form dates
      foreach ($roomType->prices as $k => &$price) {
        if (($price->start <= $form['from_date'] && $price->end >= $form['from_date']) || ($price->start <= $form['to_date'] && $price->end >= $form['to_date'])) {
          $matchingRowsIndices[] = $k;
        }
      }

      // Get all prices that includes and between found indices
      if(!empty($matchingRowsIndices)){
        for($i = $matchingRowsIndices[0]; $i <= $matchingRowsIndices[count($matchingRowsIndices)-1]; $i++) {
          $matchingPriceRows[] = $roomType->prices[$i];
        }
      }

      $min_date = false;
      $max_date = false;

      // Found the minimum and maximum dates in price rows
      if(!empty($matchingPriceRows)) {
        foreach ($matchingPriceRows as $k => $price) {
          if ($min_date === false || ($price->start < $min_date)) {
            $min_date = $price->start;
          }

          if ($max_date === false || ($price->end > $max_date)) {
            $max_date = $price->end;
          }
        }
      }

      $daysByPriceRow = [];

      // Split selected period by days with its own price
      if($min_date != false && $max_date != false) {
        $period = CarbonPeriod::create($min_date, $max_date);

        foreach ($period as $key => $date) {
          foreach ($matchingPriceRows as $priceRow) {
            if($priceRow->start <= $date->format('Y-m-d') && $date->format('Y-m-d') <= $priceRow->end) {
              if(!in_array($date->format('Y-m-d'), $daysByPriceRow)) {
                $daysByPriceRow[$date->format('Y-m-d')] = [
                  'date' => $date->format('Y-m-d'),
                  'price' => $priceRow->price,
                  'price_id' => $priceRow->id,
                  'match' => 'full'
                ];
              }
            }
          }
        }
      }

      $daysByPriceRow = array_values($daysByPriceRow);

      $matchingDays = [];
      $requestedPeriod = CarbonPeriod::create($form['from_date'], $form['to_date']);

      // Take only those days that fit form request
      if(!empty($daysByPriceRow)) {
        foreach ($requestedPeriod as $k => $formDay) {
          foreach ($daysByPriceRow as $dk => $day) {
            if($formDay->format('Y-m-d') == $day['date']) {
              if(!in_array($day['date'], $matchingDays)) {
                $matchingDays[$day['date']] = $day;
              }
            }
          }
        }
      }

      if (!empty($matchingDays) && count($requestedPeriod) == count($matchingDays)) {
        $matchingDays = array_values($matchingDays);
        $matchingDays = ['days' => $matchingDays, 'match' => 'full', 'start' => $form['from_date'], 'end' => $form['to_date']];

        $roomType->calculatedPrices = (object) [$matchingDays];
      } else {
        $roomType->calculatedPrices = [];
      }

      return $roomType;
    }

    /**
     * @param $roomType
     * @param $form
     * @return mixed
     */
    private function findPriceRowsWeekly($roomType, $form)
    {
      $matchingWeeks = [];

      $matchingPriceRows = [];
      $matchingRowsIndices = [];

      // Find first and last indices in prices which cover form dates
      foreach ($roomType->prices as $k => &$price) {
        if (($price->start <= $form['from_date'] && $price->end >= $form['from_date']) || ($price->start <= $form['to_date'] && $price->end >= $form['to_date'])) {
          $matchingRowsIndices[] = $k;
        }
      }

      // Add previous and next price row to be sure that we get full weeks
      if(!empty($matchingRowsIndices)){
        $firstIndex = $matchingRowsIndices[0];
        $lastIndex = $matchingRowsIndices[count($matchingRowsIndices)-1];

        if($firstIndex != 0 && isset($roomType->prices[$firstIndex-1])){
          array_unshift($matchingRowsIndices, $firstIndex-1);
        }

        if(isset($roomType->prices[$lastIndex+1])) {
          array_push($matchingRowsIndices, $lastIndex+1);
        }
      }

      // Get all prices that includes and between found indices
      if(!empty($matchingRowsIndices)){
        for($i = $matchingRowsIndices[0]; $i <= $matchingRowsIndices[count($matchingRowsIndices)-1]; $i++) {
          $matchingPriceRows[] = $roomType->prices[$i];
        }
      }

      $min_date = false;
      $max_date = false;

      // Found the minimum and maximum dates in matching price rows
      if(!empty($matchingPriceRows)) {
        foreach ($matchingPriceRows as $k => &$price){
          if ($min_date === false || ($price->start <= $form['from_date'] && $price->start < $min_date)) {
            $min_date = $price->start;
          }

          if ($max_date === false || ($price->end >= $form['to_date'] && $price->end > $max_date)) {
            $max_date = $price->end;
          }
        }
      }

      $daysByPriceRow = [];

      // Split selected period by days with its own price
      if($min_date != false && $max_date != false) {
        $period = CarbonPeriod::create($min_date, $max_date);

        foreach ($period as $key => $date) {
          foreach ($matchingPriceRows as $priceRow) {
            if($priceRow->start <= $date->format('Y-m-d') && $date->format('Y-m-d') <= $priceRow->end) {
              if(!in_array($date->format('Y-m-d'), $daysByPriceRow)) {
                $daysByPriceRow[$date->format('Y-m-d')] = [
                  'date' => $date->format('Y-m-d'),
                  'price' => $priceRow->price,
                  'price_id' => $priceRow->id
                ];
              }
            }
          }
        }
      }

      $daysByPriceRow = array_values($daysByPriceRow);

      // Split by week only those periods that are greater than 8 days;
      if(count($daysByPriceRow) > 8) {
        $splitByWeeks = $this->splitByWeeks($daysByPriceRow, $roomType->range_starts);
      } else {
        $splitByWeeks[0] = $daysByPriceRow;
      }

      foreach ($splitByWeeks as $wk => &$week) {
        if(count($week) != 8) {
          unset($splitByWeeks[$wk]);
          $splitByWeeks = array_values($splitByWeeks);
        }
      }

      // Form weeks for calculation depending on duration
      if(!empty($splitByWeeks)) {
        $matchingWeeks = $this->makeMatchingWeeks($splitByWeeks, $form);
      }

      $roomType->calculatedPrices = (object) $matchingWeeks;

      return $roomType;
    }

    /**
     * @param $splitByWeeks
     * @param $form
     * @return array
     */
    private function makeMatchingWeeks($splitByWeeks, $form)
    {
      $matchingWeeks = [];

      $weeks = [];

      // Select our weeks according to request days
      foreach ($splitByWeeks as $wk => $week) {
        if ($form['from_date'] <= $week[count($week) - 1]['date'] && $form['to_date'] >= $week[0]['date']) {
          array_push($weeks, $week);
        }
      }

      // Remove first and last week if $from/$to date are not in the middle of the found week
      foreach ($weeks as $k => $week) {
        if($k == 0 && $week[count($week) - 1]['date'] == $form['from_date']) {
          unset($weeks[$k]);
        }
      }
      $weeks = array_values($weeks);

      foreach ($weeks as $k => $week) {
        if($k == count($weeks) - 1 && $week[0]['date'] == $form['to_date'] ) {
          unset($weeks[$k]);
        }
      }

      $weeks = array_values($weeks);

      $options = array(array());

      foreach ($weeks as $element) {
        foreach ($options as $k => $combination) {
          array_push($options, array_merge(array($element), $combination));
        }
      }

      unset($options[0]);

      $options = array_values($options);

      foreach ($options as $k => &$option) {

        if(count($option) != 1) {
          asort($option);

          foreach ($option as $d => $dates) {
            if(isset($option[$d + 1])) {
              if($option[$d][0]['date'] != $option[$d+1][count($option[$d])-1]['date']) {
                unset($options[$k]);
                $options = array_values($options);
              }
            }
          }
        }
      }

      usort($options, function($a, $b) {
        return strcmp($a[count($a)-1][0]['date'], $b[count($b)-1][0]['date']);
      });

      foreach ($options as $weeks) {
        array_push($matchingWeeks, $this->fillDays($weeks, $form));
      }

      return $matchingWeeks;
    }

    /**
     * @param $weeks
     * @param $form
     * @return array
     */
    private function fillDays($weeks, $form)
    {
      $byDays = [];
      $daysIn = [];

      foreach ($weeks as $wk => $week) {
        foreach ($week as $day) {
          if ($form['from_date'] <= $week[count($week) - 1]['date'] && $form['to_date'] >= $week[0]['date']) {
            if(!in_array($day['date'], $daysIn)) {
              array_push($byDays, $day);
              array_push($daysIn, $day['date']);
            }
          }
        }
      }

      return ['days' => $byDays, 'start' => $byDays[0]['date'], 'end' => $byDays[count($byDays) - 1]['date']];
    }

    /**
     * @param $priceRow
     * @param $weekStarts
     * @return mixed
     */
    private function splitPriceRowByWeeks($priceRow, $weekStarts)
    {
      $period = CarbonPeriod::create($priceRow->start, $priceRow->end);

      $weeks = [];
      $start = Carbon::parse($priceRow->start);

      $weekStart = $start->firstOfMonth($weekStarts)->format("Y-m-d");
      $weekIndex = 0;

      foreach ($period as $key => $date) {
        if($date->format('Y-m-d') <= $weekStart){
          $weeks[$weekIndex][] = ['date' => $date->format('Y-m-d'), 'price' => $priceRow->price, 'price_id' => $priceRow->id];
        } else {
          $weekIndex++;
          $weeks[$weekIndex][] = ['date' => Carbon::parse($date->format('Y-m-d'))->subDay()->format('Y-m-d'), 'price' => $priceRow->price, 'price_id' => $priceRow->id];
          $weeks[$weekIndex][] = ['date' => $date->format('Y-m-d'), 'price' => $priceRow->price, 'price_id' => $priceRow->id];
          $weekStart = Carbon::parse($weekStart)->next();
        }
      }

      return $weeks;
    }

    /**
     * @param $days
     * @param $weekStarts
     * @return array
     */
    private function splitByWeeks($days, $weekStarts)
    {
      $weeks = [];
      $start = Carbon::parse($days[0]['date']);

      $weekStart = $start->firstOfMonth($weekStarts)->format("Y-m-d");
      $weekIndex = 0;

      foreach ($days as $key => $day) {
        if($day['date'] <= $weekStart){
          $weeks[$weekIndex][] = $day;
        } else {
          $weekIndex++;
          $weeks[$weekIndex][] = ['date' => Carbon::parse($day['date'])->subDay()->format('Y-m-d'), 'price' => $day['price'], 'price_id' => $day['price_id']];
          $weeks[$weekIndex][] = ['date' => $day['date'], 'price' => $day['price'], 'price_id' => $day['price_id']];
          $weekStart = Carbon::parse($weekStart)->next();
        }
      }

      return $weeks;
    }

    /**
     * @param CalculationService $calculator
     * @param $roomType
     * @param $form
     * @return mixed
     */
    private function getCalculatedPrices(CalculationService $calculator, $roomType, $form)
    {
      $groupedByCatering = [];

      if(!empty($roomType->calculatedPrices)) {
        foreach ($roomType->calculatedPrices as &$priceRow) {
          $groupedByCatering[$roomType->baseCatering->name][] = $calculator->calculate($form['from_date'], $form['to_date'], $roomType, $priceRow, false, $roomType->adults, $roomType->children);

          if(count($roomType->caterings) > 0) {
            foreach ($roomType->caterings as $catering) {
              $groupedByCatering[$catering->cateringType->name][] = $calculator->calculate($form['from_date'], $form['to_date'], $roomType, $priceRow, $catering , $roomType->adults, $roomType->children);
            }
          }
        }
      }

      $roomType->pricesByCatering = $groupedByCatering;

      return $roomType;
    }

    /**
     * @param $roomType
     * @param $children
     * @param $adults
     * @return mixed
     */
    private function recalculateChildrenAndBabies($roomType, $children, $adults)
    {
      $children_ages = $children;
      $babies = 0;

      // We should check if child is a baby
      if(!empty($children_ages)) {
        foreach ($children_ages as $k => $child) {
          if($child < $roomType->max_babies_age) {
            unset($children_ages[$k]);
            $babies++;
          }
        }
      }

      $children_ages = array_values($children_ages);

      $roomType->babies = $babies;
      $roomType->children = $children_ages;
      $roomType->adults = $adults;

      return $roomType;
    }

    /**
     * @param $roomTypes
     */
    private function recalculateAdultsAndChildren($roomTypes)
    {
      // If child age > than max_child_age we transform :) this child into and adult
      if(!empty($this->children)) {
        if(count($roomTypes) > 0) {
          foreach ($roomTypes as $key => $roomType) {
            foreach ($this->children as $k => $child) {
              if($roomType->max_babies_age < $child) {
                unset($this->children[$k]);

                $this->adults++;
              }
            }
          }
        }
      }
    }

    /**
     * @param $roomTypes
     * @return mixed
     */
    private function reduceRoomTypesByBedsAndChildren($roomTypes)
    {
      if(count($roomTypes) > 0) {
        foreach ($roomTypes as $key => $roomType) {
          if($roomType->beds < $roomType->adults + count($roomType->children)
              || $roomType->max_babies_count < $roomType->babies) {
            $roomTypes->forget($key);
          }
        }
      }

      return $roomTypes;
    }

    /**
     * @param $roomTypes
     * @return mixed
     */
    private function reduceRoomTypesByBedsDeviation($roomTypes)
    {
      if(count($roomTypes) > 0) {
        foreach ($roomTypes as $key => $roomType) {
            $guests = $roomType->adults + count($roomType->children);
            $bedsWithDeviation = $roomType->beds - $roomType->beds_deviation;

            if($guests < $bedsWithDeviation) {
              $roomTypes->forget($key);
            }
        }
      }

      return $roomTypes;
    }
}
