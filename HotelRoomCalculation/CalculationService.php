<?php

namespace App\Services;

use App\Exceptions\TravelDatesException;
use Carbon;

class CalculationService
{
    protected $roomType;
    protected $multiplier;
    protected $children;
    protected $adults;
    protected $priceUnit;
    protected $recalculatedAdults = false;
    protected $usedBedsIndices = [];

  /**
   * @param string $fromDate
   * @param string $toDate
   * @param \App\Models\RoomType $roomType
   * @param $priceRow
   * @param $catering
   * @param int $adults
   * @param array $children
   * @return bool|mixed
   */
    public function calculate(string $fromDate, string $toDate, \App\Models\RoomType $roomType, $priceRow, $catering, int $adults, array $children)
    {
      $duration = Carbon\Carbon::parse($fromDate)->diffInDays(Carbon\Carbon::parse($toDate));
      $this->priceUnit = $roomType->price_unit;
      $this->children = $children;
      $this->adults = $adults;
      $this->recalculatedAdults = false;
      $this->setMultiplier();

      switch ($roomType->range_type) {
        case 'flex':
          $calculatedPrice = $this->calculateFlexRoomPrices($roomType, $priceRow, $catering, $children);
          break;
        case 'fixed':
          $calculatedPrice = $this->calculateFixedRoomPrices($roomType, $priceRow, $catering, $children, $duration);
          break;
        case 'weekly':
          $calculatedPrice = $this->calculateWeeklyRoomPrices($roomType, $priceRow, $catering, $children, $fromDate, $toDate);
          break;
        default:
          $calculatedPrice = false;
      }

      $calculatedPrice = $this->addFee($roomType, $priceRow, $calculatedPrice);
      $calculatedPrice = $this->round($calculatedPrice);

      return $calculatedPrice;
    }

    /**
     * @param $calculatedPrice
     * @return mixed
     */
    private function round($calculatedPrice) {
      $calculatedPrice['calculated_price'] = floor($calculatedPrice['calculated_price']);
      return $calculatedPrice;
    }

    /**
     * @param $roomType
     * @param $priceRow
     * @param $calculatedPrice
     * @return mixed
     */
    private function addFee($roomType, $priceRow, $calculatedPrice)
    {
      switch ($roomType->range_type) {
        case 'flex':
        case 'weekly':
          $priceId = $priceRow['days'][0]['price_id'];
          break;
        case 'fixed':
          $priceId = $priceRow->id;
          break;
        default:
          $priceId = false;
      }

      $price = false;

      foreach($roomType->prices as $priceRow){
        if($priceRow['id'] == $priceId) {
          $price = $priceRow;
          break;
        }
      }

      if($price) {
        $calculatedPrice['calculated_price'] = $price->additional_fee_type == 'fixed' ? $calculatedPrice['calculated_price'] + $price->additional_fee_value
            : $calculatedPrice['calculated_price'] + ($calculatedPrice['calculated_price'] * $price->additional_fee_value) / 100;
      }

      return $calculatedPrice;
    }

    /**
     * @param $roomType
     * @param $priceRow
     * @param $catering
     * @param $children
     * @param $fromDate
     * @param $toDate
     * @return mixed
     */
    private function calculateWeeklyRoomPrices($roomType, $priceRow, $catering, $children, $fromDate, $toDate)
    {
      $pricesPerDay = [];

      // Calculate base price for each day in split week and apply children prices (price frame: day or week)
      foreach ($priceRow['days'] as $dk => $day) {
        if($dk + 1 < count($priceRow['days'])) {
          $item['date'] = $day['date'];
          $item['base_price'] = $roomType->price_frame == 'day' ? $day['price'] : $day['price'] / 7;

          if($this->priceUnit == 'person') {
            $item['adult_prices'] = $this->calculateAdultPricesWithBedDiscount($item['base_price'], $this->getPriceRow($roomType, $day['price_id']));
            $item['children_prices'] = $this->calculateChildrenPrices($children, $item['base_price'], $this->getPriceRow($roomType, $day['price_id']), $roomType->max_babies_age);
          }

          array_push($pricesPerDay, $item);
        }
      }

      $priceRow['match'] =  $fromDate == $priceRow['start'] && $toDate == $priceRow['end'] ? 'full' : 'in_range';

      // Apply catering prices
      if($catering) {
        $calculatedPrice = $this->calculatePricesWithCatering($roomType, $priceRow, $catering, $pricesPerDay);
      } else {
        $adultPrice = 0;
        $childrenPrice = 0;
        $adultDiscountPricesPerBed = 0;

        foreach ($pricesPerDay as $key => &$dayPrice) {
          $adultPrice += $dayPrice['base_price'];

          if(!empty($dayPrice['adult_prices'])) {
            foreach ($dayPrice['adult_prices'] as $adult) {
              $adultDiscountPricesPerBed +=  $adult;
            }
          }

          if(!empty($dayPrice['children_prices'])) {
            foreach ($dayPrice['children_prices'] as &$childPrice) {
              $childrenPrice +=  $childPrice;
            }
          }
        }

        $calculatedPrice = $this->calculateTotalPricesForAdultsAndChildren($roomType, $priceRow, $catering, $adultPrice, $childrenPrice, 1, false, $adultDiscountPricesPerBed);
      }

      return $calculatedPrice;
    }

    /**
     * @param $roomType
     * @param $priceRow
     * @param $catering
     * @param $children
     * @return mixed
     */
    private function calculateFlexRoomPrices($roomType, $priceRow, $catering, $children)
    {
      $pricesPerDay = [];

      // Calculate base price for each day and apply children prices (price frame: day or week)
      foreach ($priceRow['days'] as $dk => $day) {
        if($dk + 1 < count($priceRow['days'])) {
          $item['date'] = $day['date'];
          $item['base_price'] = $roomType->price_frame == 'day' ? $day['price'] : $day['price'] / 7;

          if($this->priceUnit == 'person') {
            $item['adult_prices'] = $this->calculateAdultPricesWithBedDiscount($item['base_price'], $this->getPriceRow($roomType, $day['price_id']));
            $item['children_prices'] = $this->calculateChildrenPrices($children, $item['base_price'], $this->getPriceRow($roomType, $day['price_id']), $roomType->max_babies_age);
          }
          array_push($pricesPerDay, $item);
        }
      }

      // Apply catering for the base price
      if($catering) {
        $calculatedPrice = $this->calculatePricesWithCatering($roomType, $priceRow, $catering, $pricesPerDay);
      } else {
        $adultPrice = 0;
        $childrenPrice = 0;
        $adultDiscountPricesPerBed = 0;

        foreach ($pricesPerDay as $key => &$dayPrice) {
          $adultPrice += $dayPrice['base_price'];

          if(!empty($dayPrice['adult_prices'])) {
            foreach ($dayPrice['adult_prices'] as $adult) {
              $adultDiscountPricesPerBed +=  $adult;
            }
          }

          if(!empty($dayPrice['children_prices'])) {
            foreach ($dayPrice['children_prices'] as &$childPrice) {
              $childrenPrice +=  $childPrice;
            }
          }
        }

        $calculatedPrice = $this->calculateTotalPricesForAdultsAndChildren($roomType, $priceRow, $catering, $adultPrice, $childrenPrice, 1, false, $adultDiscountPricesPerBed);
      }

      return $calculatedPrice;
    }

    /**
     * @param $roomType
     * @param $priceRow
     * @param $catering
     * @param $children
     * @param $duration
     * @return bool|mixed
     */
    private function calculateFixedRoomPrices($roomType, $priceRow, $catering, $children, $duration)
    {
      $pricesPerDay = [];

      $period = Carbon\CarbonPeriod::create($priceRow->start, Carbon\Carbon::parse($priceRow->end)->subDay()->format('Y-m-d'));

      // Calculate base price for each day and apply children prices (price frame: day or week)
      foreach ($period as $dk => $day) {
        $item['date'] = $day->format('Y-m-d');

        if($priceRow->match == 'full') {
          $item['base_price'] = $roomType->price_frame == 'day' ? $priceRow->price : $priceRow->price / 7;

          if($this->priceUnit == 'person') {
            $item['adult_prices'] = $this->calculateAdultPricesWithBedDiscount($item['base_price'], $priceRow);
            $item['children_prices'] = $this->calculateChildrenPrices($children, $item['base_price'], $priceRow, $roomType->max_babies_age);
          }
          array_push($pricesPerDay, $item);
        } else {
          foreach ($roomType->prices as $pricesItem) {
            if($pricesItem->start <= $item['date'] && $item['date'] <= $pricesItem->end) {
              $item['base_price'] = $roomType->price_frame == 'day' ? $pricesItem->price : $pricesItem->price / 7;

              if($this->priceUnit == 'person') {
                $item['adult_prices'] = $this->calculateAdultPricesWithBedDiscount($item['base_price'], $priceRow);
                $item['children_prices'] = $this->calculateChildrenPrices($children, $item['base_price'], $priceRow, $roomType->max_babies_age);
              }
            }
            continue;
          }

          if($priceRow->start <= $item['date'] && $item['date'] <= $priceRow->end) {
            array_push($pricesPerDay, $item);
          }
        }
      }

      if($catering) {
        $calculatedPrice = $this->calculatePricesWithCatering($roomType, $priceRow, $catering, $pricesPerDay);
      } else {
        $adultPrice = 0;
        $childrenPrice = 0;
        $adultDiscountPricesPerBed = 0;

        foreach ($pricesPerDay as $key => &$dayPrice) {
          $adultPrice += $dayPrice['base_price'];

          if(!empty($dayPrice['adult_prices'])) {
            foreach ($dayPrice['adult_prices'] as $adult) {
              $adultDiscountPricesPerBed +=  $adult;
            }
          }

          if(!empty($dayPrice['children_prices'])) {
            foreach ($dayPrice['children_prices'] as &$childPrice) {
              $childrenPrice +=  $childPrice;
            }
          }
        }

        $calculatedPrice = $this->calculateTotalPricesForAdultsAndChildren($roomType, $priceRow, $catering, $adultPrice, $childrenPrice, 1, false, $adultDiscountPricesPerBed);
      }

      return $calculatedPrice;
    }

    /**
     * @param $roomType
     * @param $priceRow
     * @param $catering
     * @param $pricesPerDay
     * @return mixed
     */
    private function calculatePricesWithCatering($roomType, $priceRow, $catering, $pricesPerDay)
    {

      $adultPriceWithCatering = 0;
      $childrenPricesWithCatering = 0;
      $adultDiscountPricesPerBed = 0;

      foreach ($pricesPerDay as $key => &$dayPrice) {
        $dayPrice['base_price'] = $catering->type == 'fixed' ? $dayPrice['base_price'] + $catering->value
          : $dayPrice['base_price'] + ($dayPrice['base_price'] * $catering->value) / 100;

        if(!empty($dayPrice['adult_prices'])) {
          foreach ($dayPrice['adult_prices'] as &$adult) {
            $adultDiscountPricesPerBed +=  $adult;
          }
        }

        if(!empty($dayPrice['children_prices'])) {
          foreach ($dayPrice['children_prices'] as &$childPrice) {
            $childrenPricesWithCatering += $catering->type == 'fixed' ? $childPrice + $catering->value : $childPrice + ($childPrice * $catering->value) / 100;;
          }
        }

        $adultPriceWithCatering += $dayPrice['base_price'];
      }

      $calculatedPrice = $this->calculateTotalPricesForAdultsAndChildren($roomType, $priceRow, $catering, $adultPriceWithCatering, $childrenPricesWithCatering, 1, false, $adultDiscountPricesPerBed);

      return $calculatedPrice;
    }

    /**
     * @param $roomType
     * @param $priceRow
     * @param $catering
     * @param $adultPrice
     * @param $childrenPriceWithCatering
     * @param $duration
     * @param bool $applyCatering
     * @param int $adultDiscountPricesPerBed
     * @return mixed
     */
    private function calculateTotalPricesForAdultsAndChildren($roomType, $priceRow, $catering, $adultPrice, $childrenPriceWithCatering, $duration, $applyCatering = false, $adultDiscountPricesPerBed = 0)
    {
      $item['catering_name'] = $catering ? $catering->cateringType->name : $roomType->baseCatering->name;
      $item['room_catering_type_id'] = $catering ? $catering->id : null;
      $item['base_price'] = $adultPrice;
      $item['adult_price_with_catering'] = $applyCatering ? ($catering ? $catering->type == 'fixed' ? $adultPrice + $catering->value
          : $adultPrice + ($adultPrice * $catering->value) / 100 : $adultPrice) : $adultPrice;
      $item['children_price_with_catering'] = $childrenPriceWithCatering;
      $item['calculated_price'] = ($item['adult_price_with_catering'] * $duration * $this->multiplier) + $childrenPriceWithCatering + $adultDiscountPricesPerBed;
      $item['start'] = $priceRow['start'];
      $item['end'] = $priceRow['end'];
      $item['match'] = $priceRow['match'];
      $item['children'] = $this->children;
      $item['adults'] = $this->adults;

      return $item;
    }

    /**
     * @param $ageCombination
     * @return bool
     */
    private function findBedWithLowestPrice($ageCombination)
    {
      $optionWithLowestPrice = false;
      $lowestBedKey = false;

      foreach ($ageCombination as $bedKey => $bedNth) {
        if(!in_array($bedKey, $this->usedBedsIndices)) {
          $lowestPrice = false;

          foreach ($bedNth as $discountOptionKey => $discountOption) {
            if($discountOption['percentage'] != '0.00') {
              if($lowestPrice === false || $discountOption['percentage'] < $lowestPrice) {
                $lowestPrice = $discountOption['percentage'];

                $optionWithLowestPrice = $discountOption;
                $lowestBedKey = $bedKey;
              }
            }

            if($discountOption['fixed'] != '0.00') {
              if($lowestPrice === false || $discountOption['fixed'] < $lowestPrice) {
                $lowestPrice = $discountOption['fixed'];

                $optionWithLowestPrice = $discountOption;
                $lowestBedKey = $bedKey;
              }
            }
          }

        }
      }

      if($lowestBedKey !== false) {
        array_push($this->usedBedsIndices, $lowestBedKey);
      }

      return $optionWithLowestPrice;
    }

    /**
     * @param $children
     * @param $base_price
     * @param $price
     * @param $max_babies_age
     * @return int
     */
    private function calculateChildrenPrices($children, $base_price, $price, $max_babies_age)
    {
      if(count($children) == 1 && $children[0] === '') {
        $children = [];
      }

      $childrenPrices = [];

      if(!empty($children)) {
        $childrenArray = $children;
        if(count($price->childrenPrices) > 0) {
          // First we group children prices by bed
          $groupedByBeds = [];
          $selectedBeds = [];

          // Select only those beds that match our combination of adults and children
          // If we have 1 adult and 2 kids - we select 3 and 4 bed
          foreach ($price->childrenPrices as $child_price) {
            if(!$child_price->pricingColumn->from_age && !$child_price->pricingColumn->to_age && !$child_price->pricingColumn->children) {
//              dd("EMPTY");
            } else {
              if($child_price->pricingColumn->bed > $this->adults && $child_price->pricingColumn->bed <= $this->adults + count($children)) {
                if(!in_array($child_price->pricingColumn->bed, $selectedBeds)) {
                  $selectedBeds[] = $child_price->pricingColumn->bed;
                  $groupedByBeds[] = [
                      'bed' => $child_price->pricingColumn->bed
                  ];
                }
              }
            }

          }

          if(!empty($groupedByBeds)) {
            foreach ($groupedByBeds as $bedKey => &$bedNTH) {
              foreach ($price->childrenPrices as $child_price) {
                if ($bedNTH['bed'] == $child_price->pricingColumn->bed) {
                  if(!$child_price->pricingColumn->from_age && !$child_price->pricingColumn->to_age && !$child_price->pricingColumn->children) {

                  } else {
                    $item['from_age'] = $child_price->pricingColumn->from_age != null ? $child_price->pricingColumn->from_age : 0;
                    $item['to_age'] = $child_price->pricingColumn->to_age != null ? $child_price->pricingColumn->to_age : $max_babies_age;
                    $item['fixed'] = $child_price->fixed;
                    $item['percentage'] = $child_price->percentage;
                    $item['bed'] = $bedNTH['bed'];

                    $bedNTH['price_options'][] = $item;
                  }
                }
              }
            }

            // Now we check child nth against bed nth and look for the match age
            $agesCombination = [];
            $agesCombinationIndices = [];

            foreach ($children as $key => $childAge) {
              foreach ($groupedByBeds as $bedKey => &$bedNTH) {
                foreach ($bedNTH['price_options'] as $ak => $ageRange) {
                  if($ageRange['from_age'] <= $childAge && $childAge < $ageRange['to_age']) {

                    $agesCombination[$key][$bedKey][$ak] = [
                      'age_range' => $ageRange['from_age']."-".$ageRange['to_age'],
                      'age' => $childAge,
                      'bed' => $bedNTH['bed'],
                      'fixed' => $ageRange['fixed'],
                      'percentage' => $ageRange['percentage'],
                    ];
                    $agesCombinationIndices[] = "{$bedKey}_{$ak}";
                  }
                }
              }
            }

            //If for some children we didn't find his ages range we have to make it again
            // but add this child to adults and to shift the bed number
            if(count($agesCombination) < count($children)) {
              foreach ($children as $key =>  $ck) {
                if(!array_key_exists($key, $agesCombination)) {
                  unset($children[$key]);
                  $this->adults++;
                  $children = array_values($children);
                }
              }

              if(!$this->recalculatedAdults) {
                $this->setMultiplier();
                $this->recalculatedAdults = true;

                if(count($children) > 0) {
                  $this->calculateChildrenPrices($children, $base_price, $price, $max_babies_age);
                } else {
                  return $childrenPrices;
                }
              }
            }

            // Make all possible combinations for all children with the lowest price for each bed
            $allCombinations = [];

            for($i=0; $i < count($children); $i++) {
              $combination = [];
              $this->usedBedsIndices = [];

              foreach ($agesCombination as $ageKey => $ageCombination) {
                $selectedOption = $this->findBedWithLowestPrice($ageCombination);
                $combination[] = $selectedOption;
              }

              $first_elem = array_shift($agesCombination);
              array_push($agesCombination, $first_elem);

              $allCombinations[] = $combination;
            }

            // Calculate prices for each combination
            $calculatedPricesForCombinations = [];

            if(!empty($allCombinations)) {
              foreach ($allCombinations as $key => $children) {
                $calculated = [];
                foreach ($children as $child) {
                  if($child === false){
                    unset($allCombinations[$key]);
                    $allCombinations = array_values($allCombinations);
                  } else {
                    if ($child['fixed'] != '0.00') {
                      $childPrice = $base_price + $child['fixed'];
                      $calculated[] = $childPrice;
                    }

                    if ($child['percentage'] != '0.00') {
                      $childPrice = $base_price + ($base_price * $child['percentage']) / 100;
                      $calculated[] = $childPrice;
                    }
                  }
                }
                $calculatedPricesForCombinations[] = $calculated;
              }
            }

            // Find the combination with the lowest total price
            $lowestPrice = false;
            $lowestPricesIndex = false;

            foreach ($calculatedPricesForCombinations as $key => $row) {
              $total = 0;

              foreach ($row as $price) {
                $total += $price;
              }

              if($lowestPrice === false || $total < $lowestPrice) {
                $lowestPrice = $total;
                $lowestPricesIndex = $key;
              }
            }

            $childrenPrices = $calculatedPricesForCombinations[$lowestPricesIndex];
          } else {
              if(!$this->recalculatedAdults) {
                $this->setAdults($children);
                $this->setMultiplier();
                $this->recalculatedAdults = true;
              }
          }
        } else {
          if(!$this->recalculatedAdults) {
            $this->setAdults($childrenArray);
            $this->setMultiplier();

            $this->recalculatedAdults = true;
          }
        }
      }

      return $childrenPrices;
    }

    /**
     * @param $base_price
     * @param $price
     * @return array
     */
    private function calculateAdultPricesWithBedDiscount($base_price, $price)
    {
      $childrenPrices = [];

      if(count($price->childrenPrices) > 0) {
        $groupedByBeds = [];
        $selectedBeds = [];

        // Select only those beds that match our adults
        foreach ($price->childrenPrices as $child_price) {
          if(!$child_price->pricingColumn->from_age && !$child_price->pricingColumn->to_age && !$child_price->pricingColumn->children) {
            if($this->adults >= $child_price->pricingColumn->bed) {
              if(!in_array($child_price->pricingColumn->bed, $selectedBeds)) {
                $selectedBeds[] = $child_price->pricingColumn->bed;
                $groupedByBeds[] = [
                    'bed' => $child_price->pricingColumn->bed
                ];
              }
            }
          }
        }

        // Get price for every selected bed
        if(!empty($groupedByBeds)) {
          foreach ($groupedByBeds as $bedKey => &$bedNTH) {
            foreach ($price->childrenPrices as $child_price) {
              if ($bedNTH['bed'] == $child_price->pricingColumn->bed) {
                if(!$child_price->pricingColumn->from_age && !$child_price->pricingColumn->to_age && !$child_price->pricingColumn->children) {
                  $item['fixed'] = $child_price->fixed;
                  $item['percentage'] = $child_price->percentage;
                  $item['bed'] = $bedNTH['bed'];
                  $bedNTH['price_options'][] = $item;
                }
              }
            }
          }

          // Calculate discount for every bed
          foreach ($groupedByBeds as $bed) {
            if ($bed['price_options'][0]['fixed'] != '0.00') {
              $childrenPrices[] = intval($bed['price_options'][0]['fixed']);
            }
            if ($bed['price_options'][0]['percentage'] != '0.00') {
              $childrenPrices[] = ($base_price * $bed['price_options'][0]['percentage']) / 100;
            }
          }
        }
      }

      return $childrenPrices;
    }

    /**
     *
     */
    private function setMultiplier()
    {
      $this->multiplier = $this->priceUnit == 'room' ? 1 : $this->adults;
    }

    /**
     * @param $children
     * @return mixed
     */
    private function setAdults($children)
    {
      for ($i = 0; $i < count($children); $i++) {
        $this->adults++;
      }

      // TODO throw an exception if number of adults will exceed max beds - adults
    }

    /**
     * @param $roomType
     * @param $priceId
     * @return bool
     */
    private function getPriceRow($roomType, $priceId)
    {
      if(!empty($roomType->prices)) {
        foreach ($roomType->prices as $priceRow) {
          if($priceRow->id == $priceId) {
            return $priceRow;
          }
        }
      }

      return false;
    }
}
