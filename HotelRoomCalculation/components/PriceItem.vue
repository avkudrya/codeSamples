<template>
  <tr>
    <td>{{dates}}</td>
    <td v-if="showDuration">{{nights}} nights</td>
    <td>€ {{price.price | fixed}} <span v-if="price.additional_fee_value && price.additional_fee_value != '0.00'" class="text-muted">{{ formattedFee }}</span>
    </td>
    <td v-for="column in columns" :key="column.id">
      {{ getPrice(column.id) ? calculatePrice(getPrice(column.id)) : '-' }}

      <!--{{child_price.fixed != 0.00 ? "€ "+child_price.fixed : child_price.percentage+" %"}}-->
    </td>
    <td class="text-right">
      <a href="#"><span class="fe fe-edit text-muted d-inline-block mr-2" @click.prevent="updatePrice"></span></a>
      <a href="#"><span class="fe fe-copy text-muted d-inline-block mr-2" @click.prevent="duplicatePrice"></span></a>
      <a href="#"><span class="fe fe-trash text-danger d-inline-block mr-2" @click.prevent="deletePrice"></span></a>
    </td>
  </tr>
</template>

<script type="text/babel">
  import moment from 'moment';

  export default {
    data() {
      return {}
    },
    computed: {
      nights() {
        var duration = moment.duration(moment(this.price.end).diff(moment(this.price.start)));
        return duration.asDays()
      },
      dates() {
        return `${moment(this.price.start).format('DD/MM/YYYY')}-${moment(this.price.end).format('DD/MM/YYYY')}`
      },
      formattedFee() {
        const sign = this.price.additional_fee_value > 0 ? '+' : '-';
        const value = this.price.additional_fee_type == 'fixed' ? '€' + this.$options.filters.fixed(Math.abs(this.price.additional_fee_value)) :
            this.$options.filters.percent(Math.abs(this.price.additional_fee_value)) + '%';

        return `${sign}${value}`;
      }
    },
    props: ['price', 'showDuration','columns'],
    filters: {
      percent(value) {
        return new Intl.NumberFormat('de-DE').format(value)
      },
      fixed(value) {
        return new Intl.NumberFormat('de-DE', { style: 'decimal', minimumFractionDigits: 2 }).format(value)
      },
      dateFormat(value) {
        return moment(value).format('DD/MM/YYYY');
      }
    },
    methods: {
      getPrice(columnId) {
        for (var i = 0; i < this.price.children_prices.length; i++) {
          if(columnId == this.price.children_prices[i].children_pricing_column_id) return this.price.children_prices[i];
        }
        return null;
      },
      calculatePrice(child_price) {
        // Bot are set?
        if (child_price.percentage != '0.00' && child_price.fixed != '0.00') {
          return `${this.$options.filters.percent(child_price.percentage)}% / € ${this.$options.filters.fixed(child_price.fixed)}`
        } else if (child_price.percentage != '0.00') {
          // Percentage is set
          if (parseInt(child_price.percentage) == '-100') {
            return 'GRATIS';
          } else {
            return `${this.$options.filters.percent(child_price.percentage)}%`;
          }
        } else if (child_price.fixed != 0.00) {
          // Fixed is set
          return `€ ${this.$options.filters.fixed(child_price.fixed)}`
        } else return '-';

      },
      updatePrice() {
        this.$root.$data.showModal = true;
        this.$root.$data.priceRow = this.price;
      },
      duplicatePrice() {
          Vue.axios.post('duplicatePrice', {price:this.price})
          .then((response) => {
            if(response.data.result) {
              this.$emit('getPrices');
            }
          });

      },
      deletePrice() {
        Vue.axios.post('deletePrice', {id:this.price.id})
        .then((response) => {
          if(response.data.result) {
            this.$emit('getPrices');
          }
        });
      }
    }
  }
</script>

<style scoped>

</style>
