<template>
    <transition name="modal">
      <div class="modal-mask">
        <div class="modal-wrapper">
          <div class="modal-container">

            <div class="modal-header">
              <slot name="header">
                <span v-if="!priceRow">{{tr.get('children.add_price_row')}}</span>
                <span v-else>{{tr.get('children.update_price_row')}}</span>
                <a href="#" class="fa fa-times text-dark" @click="$emit('close')"></a>
              </slot>
            </div>

            <div class="modal-body">
              <slot name="body">

                <div class="row">
                  <div class="col-6">
                    <div class="form-group">
                      <label>{{tr.get('From')}}</label>
                      <input type="date" v-model="start" @input="startChanged" class="form-control">
                    </div>
                  </div>
                  <div class="col-6">
                    <div class="form-group">
                      <label>{{tr.get('To')}}</label>
                      <input type="date" v-model="end" class="form-control">
                    </div>
                  </div>
                </div>

                <div class="form-group mt-2">
                  <label>{{tr.get('Price')}} / {{tr.get('Person')}} / {{tr.get('Day')}}</label>
                  <div class="input-group">
                    <input type="number" v-model.number="price" class="form-control">
                    <div class="input-group-append">
                      <span class="input-group-text">€</span>
                    </div>
                  </div>
                </div>

                <div class="form-group mt-2">
                  <label>{{tr.get('Additional Fee or Discount')}}</label>
                  <div class="row">
                    <div class="col">
                      <input class="form-control" type="number" v-model.number="additional_fee_value"/>
                    </div>
                    <div class="col-auto">
                      <div class="btn-group btn-group-toggle" data-toggle="buttons">
                        <label :class="[isFixed,'btn btn-secondary']" @click="changeFeeType('fixed')">
                          <input type="radio" value="fixed" v-model="additional_fee_type"> €
                        </label>
                        <label :class="[isPercentage, 'btn btn-secondary']" @click="changeFeeType('percentage')">
                          <input type="radio" value="percentage" v-model="additional_fee_type"> %
                        </label>
                      </div>
                    </div>
                  </div>
                </div>

                <hr class="my-5"  />

                <h3 class="mb-4">{{tr.get('Discounts and Additions for Children') }}</h3>

                <div class="children-prices">
                  <div v-for="(column, i) in columns" :key="column.id">
                    <label>
                      {{column.children}}. {{tr.get('child')}} /
                      {{column.from_age}}-{{column.to_age}} {{tr.get('years')}} /
                      {{column.bed}}. {{tr.get('bed')}}
                    </label>

                    <div class="row">
                      <div class="col-6">
                        <div class="input-group mb-3">
                          <input type="number" class="form-control" :ref="'fixed'+column.id" @input="updateFixed($event, column.id)">
                          <div class="input-group-append">
                            <span class="input-group-text">€</span>
                          </div>
                        </div>
                      </div>
                      <div class="col-6">
                        <div class="input-group mb-3">
                          <input type="number" class="form-control" :ref="'percentage'+column.id" @input="updatePercentage($event, column.id)">
                          <div class="input-group-append">
                            <span class="input-group-text">%</span>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

              </slot>
            </div>

            <div class="modal-footer">
              <slot name="footer">
                <button class="btn btn-block btn-primary btn-lg" @click="submitPrice">
                  <span v-if="!priceRow">{{tr.get('Create')}}</span>
                  <span v-else>{{tr.get('Save')}}</span>
                </button>
              </slot>
            </div>
          </div>
        </div>
      </div>
    </transition>
</template>

<script type="text/babel">
  import moment from 'moment';
  import { eventBus } from '../app';

  export default {
    data() {
      return {
        price_id: false,
        start: '',
        end: '',
        additional_fee_value: 0,
        additional_fee_type: 'fixed',
        price: 0.00,
        columns: [],
        fixed: [],
        percentage: [],
        shouldFillColumns: false
      }
    },
    props: ['roomTypeId', 'priceRow'],
    computed: {
      isFixed() {
        return this.additional_fee_type == 'fixed' ? 'active' : ''
      },
      isPercentage() {
        return this.additional_fee_type == 'percentage' ? 'active' : ''
      }
    },
    methods: {
      getChildrenColumns() {
        this.columns = [];

        Vue.axios.post('getColumns', {id: this.roomTypeId})
        .then((response) => {
          this.columns = response.data.columns;
          if (this.priceRow) {
            this.shouldFillColumns = true;
          }
        });
      },
      startChanged() {
        if(!this.end) {
          this.end = moment(this.start).add(1, 'days').format('YYYY-MM-DD')
        }
      },
      changeFeeType(type) {
        this.additional_fee_type = type
      },
      updateFixed(elem,id) {
        this.fixed[id] = elem.target.value && parseInt(elem.target.value) != 0 ? elem.target.value : '0.00';
      },
      updatePercentage(elem,id) {
        this.percentage[id] = elem.target.value && parseInt(elem.target.value) != 0 ? elem.target.value : '0.00';
      },
      submitPrice() {
        if(!this.start || !this.end) {
          return;
        }

        this.$emit('close');

        Vue.axios.post('submitPrice', {
          roomtype_id: this.roomTypeId,
          price: this.price,
          start: this.start,
          end: this.end,
          fixed: this.fixed,
          percentage: this.percentage,
          additional_fee_value: this.additional_fee_value,
          additional_fee_type: this.additional_fee_type,
          id: this.price_id
        })
        .then((response) => {
          eventBus.updatePrices();
        });
      },
      fillColumns(){
        if (this.priceRow.children_prices.length != 0) {
          this.priceRow.children_prices.map(price => {
            this.fixed[price.children_pricing_column_id] = price.fixed;
            this.percentage[price.children_pricing_column_id] = price.percentage;

            for (var ref in this.$refs) {
              if (this.$refs.hasOwnProperty(ref)) {
                if (ref == 'fixed'+price.children_pricing_column_id && parseInt(price.fixed) != 0) {
                  this.$refs[ref][0].value = price.fixed
                }

                if (ref == 'percentage'+price.children_pricing_column_id && parseInt(price.percentage) != 0) {
                  this.$refs[ref][0].value = price.percentage
                }
              }
            }
          });
        }
      },
      fillModal() {
        this.price_id = this.priceRow.id;
        this.price = this.priceRow.price;
        this.additional_fee_value = this.priceRow.additional_fee_value;
        this.additional_fee_type = this.priceRow.additional_fee_type;
        this.start = moment(this.priceRow.start).format("YYYY-MM-DD");
        this.end = moment(this.priceRow.end).format("YYYY-MM-DD");
      }
    },
    created() {
      this.getChildrenColumns();

      if (this.priceRow) {
        this.fillModal();
      }
    },
    updated() {
      if (this.shouldFillColumns) {
        this.fillColumns();
      }
    },
    beforeDestroy(){
      this.$root.$data.priceRow = false;
      this.price_id = false;
    }
  }
</script>

<style scoped>
  .modal-mask {
    position: fixed;
    z-index: 9998;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, .5);
    display: table;
    transition: opacity .3s ease;
  }

  .form-group {
    margin-bottom: 0.5rem;
  }

  .modal-body {
    padding: 1rem;
    max-height: 35vw;
    overflow: auto;
  }

  .modal-wrapper {
    display: table-cell;
    vertical-align: middle;
  }

  .modal-container {
    width: 500px;
    margin: 0 auto;
    padding: 25px 30px;
    background-color: #fff;
    border-radius: 5px;
    box-shadow: 0 2px 45px -10px rgba(0, 0, 0, 1);
    transition: all .3s ease;
  }

  .modal-header {
    padding: 10px ;
    font-weight: bold;
  }

  .modal-header h3 {
    margin-top: 0;
    color: #42b983;
  }

  .modal-body {
    margin: 0;
  }

  .modal-enter {
    opacity: 0;
  }

  .modal-leave-active {
    opacity: 0;
  }

  .modal-footer {
    padding: 1rem;
  }

  .modal-enter .modal-container,
  .modal-leave-active .modal-container {
    -webkit-transform: scale(1.1);
    transform: scale(1.1);
  }
</style>
