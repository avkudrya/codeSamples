<template>
  <div class="row d-flex align-items-center">

    <div class="row position-relative">
      <div class="col-12 col-lg-4">
        <div class="form-group">
          <select class="form-control" v-model.number="bed" @change="updateColumn">
            <option value="null">-</option>
            <option
                v-for="(type, i) in [tr.get('children.first'), tr.get('children.second'), tr.get('children.third'), tr.get('children.fourth'), tr.get('children.fifth')]"
                :key="type"
                :value="i+1"
            >{{type}} {{tr.get('children.bed')}}</option>
          </select>
        </div>
      </div>

      <div class="col-12 col-lg-4">
        <div class="form-group">
          <select class="form-control" v-model.number="children" @change="updateColumn">
            <option value="null">-</option>
            <option
                v-for="(type, i) in [tr.get('children.first'), tr.get('children.second'), tr.get('children.third'), tr.get('children.fourth'), tr.get('children.fifth')]"
                :key="type"
                :value="i+1"
            >{{type}} {{tr.get('children.child')}}</option>
          </select>
        </div>
      </div>

      <div class="col-12 col-lg-4 last-child">
        <div class="input-group">
          <input class="form-control" type="number" v-model.number="from_age" @input="updateColumn"/>
          <input class="form-control" type="number" v-model.number="to_age" @input="updateColumn"/>
        </div>
      </div>

      <div class="position-absolute trash-div">
        <a class="fe fe-trash text-danger" href="#" @click.prevent="deleteColumn"></a>
      </div>
    </div>
  </div>
</template>

<script type="text/babel">
  export default {
    data() {
      return {
        bed: this.column.bed,
        from_age: this.column.from_age,
        to_age: this.column.to_age,
        children: this.column.children
      }
    },
    props: ['roomTypeId', 'i', 'column'],

    methods: {
      deleteColumn() {
        Vue.axios.post('deleteColumn', {
          id: this.column.id
        })
        .then((response) => {
          if(!response.data.result){
            alert("Wasn't deleted")
          } else {
            this.$emit('updateColumns');
          }
        });
      },
      updateColumn() {
        Vue.axios.post('updateColumn', {
          id: this.column.id,
          roomtype_id: this.roomTypeId,
          bed: this.bed,
          from_age: this.from_age,
          to_age: this.to_age,
          children: this.children
        })
        .then((response) => {
//          console.log(response.data.request)
        });
      }
    }
  }
</script>

<style scoped>
  .last-child {
    padding-right: 20px;
  }
  .trash-div {
    top: 8px;
    right: 2px;
  }
</style>
