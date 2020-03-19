<template>
  <table class="table table-sm table-nowrap card-table">
    <thead>
    <tr>
      <th>
        {{tr.get('children.dates')}}
      </th>
      <th v-if="roomType.range_type == 'fixed'">
        {{tr.get('children.duration')}}
      </th>
      <th>
        <span class="text-muted">{{tr.get('children.adults')}}</span><br />
        {{roomType.price_unit}} / {{roomType.price_frame}}
      </th>

      <th v-for="column in columns" :key="column.id">
        <span v-html="prepareHeader(column)"></span>
      </th>
      <th>&nbsp;</th>
    </tr>
    </thead>
    <tbody is="transition-group" name="fade" mode="out-in">
      <price-item
          :columns="columns"
          v-for="(price,i) in prices"
          :key="price.id"
          :price="price"
          :showDuration="roomType.range_type == 'fixed'"
          @getPrices="getPrices"
      ></price-item>
    </tbody>
  </table>
</template>

<script type="text/babel">
  import PriceItem from './PriceItem.vue';
  import { eventBus } from '../app';

  export default {
    data() {
      return {
        prices: [],
        columns: []
      }
    },
    props: ['roomType'],
    methods: {
      getPrices() {
        this.prices = [];
        Vue.axios.post('getPrices', {id: this.roomType.id})
          .then((response) => {
            this.prices = response.data.prices;
            this.columns = response.data.columns;
        });
      },
      prepareHeader(column) {
        let prepared = '';
        if(column.children != null) {
          prepared += `${column.children}. ${this.tr.get('child')}<br/>`
        }

        if((column.from_age != null && column.from_age != 0) && column.to_age != null) {
          prepared += `${column.from_age}-${column.to_age} ${this.tr.get('years')}<br/>`
        } else if(column.from_age != null && column.to_age == null) {
          prepared += `min ${column.from_age} ${this.tr.get('years')}<br/>`
        } else if((column.from_age == null || column.from_age == 0) && column.to_age != null) {
          prepared += `max ${column.to_age} ${this.tr.get('years')}<br/>`
        }

        if(column.bed != null) {
          prepared += `${column.bed}. ${this.tr.get('bed')}<br/>`
        }

        return prepared;
      }
    },
    components: {
      'price-item': PriceItem
    },
    created() {
      this.getPrices();

      eventBus.$on('getPrices', () => {
        this.getPrices();
      });
    }
  }
</script>

<style scoped>

</style>
