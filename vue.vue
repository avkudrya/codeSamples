<template>
  <div style="position:relative">

    <div v-if="providersArray.length > 0" role="tablist">

      <DayPeriodSwitcher @set-current-period="setCurrentPeriod" :intervals="intervals"/>

      <b-card
          no-body
          class="mb-1"
          v-for="(providery, i) in providersArray"
          :key="`${providery.medical_provider_id}`"
          v-if="showMedicalProvider(providery)"
      >
        <b-card-header header-tag="header" class="p-1 provider-header" role="tab">
          <div @click="setSelectedProvider(providery, i)" :id="`providery${i}`"
               :class="[providery.selected ? 'selected-provider' : 'not-selected-provider', providery.isLoading ? 'dimmed' : '']"
          />

          <a href="#"
             :ref="`#collapseExample${i}`"
             @click.prevent="clickedProviderHeader(i)"
             class="provider-button"
          >
            {{providery.medical_provider_name}}
            {{getServicePrice(providery)}}
          </a>
          <Loader class="float-right" width="150px" v-if="providery.isLoading"/>
        </b-card-header>

        <div class="provider-departments p-1 collapse" :id="`collapseExample${i}`">
          <div class="row p-1 department-row" v-for="(departmenty, index) in providery.departments"
               :key="`${departmenty.medical_provider_id}_${departmenty.meduchet_department_id}`"
               v-if="showDepartment(departmenty)"
          >

            <div class="col-3">
              <div class="p-1">
                <div @click="setSelectedDepartment(departmenty)" :id="`departmenty${index}`"
                     :class="[departmenty.selected ? 'selected-provider' : 'not-selected-provider', providery.isLoading ? 'dimmed' : '']"
                />
                <a href="#"
                   v-b-modal.google-map
                   @click.prevent="setCoord(departmenty)"
                   :id="`popover-target-${i}-${index}`">
                  {{departmenty.department_name}}
                </a>
              </div>
            </div>

            <div class="col-9">
              <Loader class="float-right" width="150px" v-if="departmenty.isLoading"/>

              <div class="doctor-row row no-gutters " v-for="(doctor, docIndex) in departmenty.doctors"
                   v-if="departmenty.doctors && showDoctorRow(doctor, docIndex)">
                <div class="col-5">
                  {{doctor.doctor_name}}
                  <span class="doctor-specializations" v-if="doctor.specializations && doctor.specializations.length > 0">{{doctorSpecializations(doctor.specializations)}}</span>
                  <span class="doctor-services" v-if="doctor.services && doctor.services.length > 0"
                        v-html="doctorServices(doctor.services)"/>
                </div>

                <div class="col-7 calendar-wrapper">

                  <div class="calendar-doctor">
                    <div class="switcher-wrapper">
                      <div v-if="showDayLeftArrow(doctor)">
                        <a class="day-switcher" href="#" style="text-align: right"
                           @click.prevent="changeDoctorDayIndex(doctor, departmenty.meduchet_department_id, 'left')">
                          <font-awesome-icon icon="caret-left"/>
                        </a>
                      </div>
                    </div>

                    <div class="calendar">
                      <div v-for="(day, i) in doctor.days" :key="i"
                           v-if="showThisDay(doctor, day.day, i)"
                      >
                        <Day
                            :doctor="doctor"
                            :day="day"
                            :dayIndices="dayIndices"
                            :currentPeriod="currentPeriod"
                            :departmenty="departmenty"
                        />
                      </div>
                    </div>

                    <div class="switcher-wrapper">
                      <div v-if="showDayRightArrow(doctor)">
                        <a class="day-switcher" href="#"
                           style="text-align: left"
                           @click.prevent="changeDoctorDayIndex(doctor, departmenty.meduchet_department_id, 'right')">
                          <font-awesome-icon icon="caret-right"/>
                        </a>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </b-card>
    </div>

    <Map
        :lat="lat"
        :lng="lng"
        @close-map="()=>this.mapsVisible = false"
        :mapsVisible="mapsVisible"
        :depAddress="depAddress"
    />

    <Loader width="100%" size="50px" v-if="isLoading"/>

  </div>
</template>

<script type="text/babel">
  import {mapGetters, mapMutations, mapActions} from 'vuex';
  import * as types from '../../store/types';
  import _ from 'lodash';
  import moment from 'moment';
  import DayPeriodSwitcher from '../Elements/DayPeriodSwitcher.vue'
  import Map from '../Elements/Map.vue'
  import Day from '../Elements/Day.vue'
  import intervals from '../../mixins/intervals'
  import providers from '../../mixins/providers'
  import departments from '../../mixins/departments'
  import doctors from '../../mixins/doctors'
  import day from '../../mixins/day'
  import services from '../../mixins/services'

  export default {
    data() {
      return {
        checkingCurrentInterval: null,
        checkingPendingIntervals: null,
        providers: [],
        selectedAll: false,
        comparePricesModalVisible: false,
        showAllIntervals: [],
        middayIndices: [],
        eveningIndices: [],
        dayIndices: [],
        dayFilteredIntervals: [],
        lat: null,
        lng: null,
        depAddress: '-',
        zoom: 14,
        mapsVisible: false
      }
    },
    props: [],
    mixins: [intervals, providers, departments, day, doctors, services],
    computed: {
      ...mapGetters({
        providersArray: 'providersArray',
        isLoading: 'isLoadingProviders',

        selectedProviders: 'selectedProviders',
        selectedServices: 'selectedServices',
        intervals: 'intervals',
        currentPeriod: 'currentPeriod',
        firstDay: 'firstDay',
        currentIndex: 'currentIndex',
        isLoadingIntervals: 'isLoadingIntervals',
        currentClinic: 'currentProvider',
        today: 'today',
        days: 'days',
        docDays: 'docDays',
        currentIntervalAvailable: 'currentIntervalAvailable'
      }),
      selectedProviders: {
        get() {
          return this.$store.state.providers.selectedProviders
        },
        set(value) {
          this.$store.commit(types.SET_SELECTED_PROVIDERS, value);
        }
      },

      currentPeriod: {
        get() {
          return this.$store.state.intervals.currentPeriod
        },
        set(value) {
          this.$store.commit(types.SET_CURRENT_PERIOD, value);
        }
      },

      providersArray: {
        get() {
          return this.$store.state.providers.providersArray
        },
        set(value) {
          this.$store.commit(types.SET_PROVIDERS, value);
        }
      },
      firstDay: {
        get() {
          return this.$store.state.intervals.firstDay
        },
        set(value) {
          this.$store.commit(types.CHANGE_FIRST_DAY, value);
        }
      },
      currentIndex: {
        get() {
          return this.$store.state.intervals.currentIndex
        },
        set(value) {
          this.$store.commit(types.CHANGE_CURRENT_DAY_INDEX, value);
        }
      },
    },
    filters: {
      ucfirst(value) {
        let string = value.toLowerCase();

        return string.charAt(0).toUpperCase() + string.slice(1)
      },
    },
    methods: {},
    components: {
      DayPeriodSwitcher,
      Map,
      Day
    },
    watch: {
      currentIntervalAvailable: function (val, oldVal) {
        if (val === false && oldVal === true) {
          this.$store.commit(types.SET_REQUEST_MODAL_STATUS, false);
          setTimeout(()=> {
            this.$store.commit(types.SET_DOCTOR_SELECTED_SERVICES, []);
            this.$store.commit(types.SET_DOCTOR_SERVICES_FETCHED, false);
            this.$store.commit(types.SET_CURRENT_INTERVAL, false);
            this.$store.commit(types.SET_DOCTOR_SERVICES, []);

            this.$store.dispatch('getIntervals');
          }, 500)
        }
      },
    },
    updated() {
      $(this.$refs.selectProviders).selectpicker('refresh');

      if (this.providersArray.length == 0) {
        this.$store.commit(types.SET_SELECTED_PROVIDERS, []);
        if (this.selectedProviders.length == 0) {
          this.selectedAll = false;
        }
      }
    },
    mounted() {
      this.$refs.mapRef && this.$refs.mapRef.$mapPromise.then((map) => {
        map.panTo({lat: 1.38, lng: 103.80})
      })
    },
    beforeDestroy () {
      clearInterval(this.checkingCurrentInterval)
    },
  }
</script>

<style scoped>

</style>