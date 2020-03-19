<template>
  <div>
    <div class="card">
      <div class="card-body">
        <transition-group name="fade" mode="out-in">
          <children-price-column
              v-for="(column, index) in columns"
              :key="column.id"
              :column="column"
              :i="index"
              @updateColumns="getColumns"
          ></children-price-column>
        </transition-group>
      </div>
    </div>
    <a href="#" class="btn btn-link btn-block mt-4" @click.prevent="addColumn">{{tr.get('children.add_column')}}</a>
  </div>
</template>

<script  type="text/babel">
  import ChildrenPriceColumn from './ChildrenPriceColumn.vue';

  export default {
    data() {
      return {
        columns: []
      }
    },
    components: {
      'children-price-column': ChildrenPriceColumn
    },
    props: ['roomTypeId'],
    methods: {
      getColumns() {
        this.columns = [];

        Vue.axios.post('getColumns', {id: this.roomTypeId})
        .then((response) => {
          this.columns = response.data.columns;
        });
      },
      addColumn() {
        Vue.axios.post('addColumn', {roomtype_id: this.roomTypeId})
        .then((response) => {
          if(!response.data.result) {
            alert("Wasn't created");
          } else {
            this.getColumns();
          }
        })
        .catch(function (error) {
          console.log(error);
        });
      }
    },
    created() {
      this.getColumns();
    }
  }
</script>
