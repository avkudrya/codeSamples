<template>
  <div :class="[row, 'row d-flex align-items-center']">
    <div class="col-auto">
      <select class="form-control" v-model="cateringTypeId" @change="updateSelectedVariant">
        <option
            v-for="(type, i) in cateringTypes"
            :key="i"
            :value="type['id']"
        >{{type['name']}}</option>
      </select>
    </div>

    <div class="col">
      <input class="form-control" type="number" v-model.number="value" @input="updateSelectedVariant"/>
    </div>

    <div class="col-auto">
      <div class="btn-group btn-group-toggle" data-toggle="buttons">
        <label :class="[isFixed,'btn btn-secondary']" @click="changeType('fixed')">
          <input type="radio" value="fixed" v-model="type"> €
        </label>
        <label :class="[isPercentage, 'btn btn-secondary']" @click="changeType('percentage')">
          <input type="radio" value="percentage" v-model="type"> %
        </label>
      </div>
    </div>
    <div class="col-auto">
      <a class="fe fe-trash text-danger" href="#" @click.prevent="deleteVariant"></a>
    </div>
  </div>
</template>

<script type="text/babel">
    export default {
        data() {
          return {
            value: this.variant.value,
            cateringTypeId: this.variant.catering_type_id,
            type: this.variant.type
          }
        },
        props: ['cateringTypes', 'roomTypeId', 'i', 'variant'],
        computed: {
          row() {
            return this.i != 0 ? "mt-2" : "";
          },
          isFixed() {
            return this.variant.type == 'fixed' ? 'active' : ''
          },
          isPercentage() {
            return this.variant.type == 'percentage' ? 'active' : ''
          }
        },
        methods: {
          deleteVariant() {
            Vue.axios.post('deleteRoomCatering', {
              id: this.variant.id
            })
            .then((response) => {
              if(!response.data.result){
                alert("Wasn't deleted")
              } else {
                this.$emit('updateVariants');
              }
            });
          },
          changeType(type) {
            this.variant.type = type;
            this.type = type;
            this.updateSelectedVariant()
          },
          updateSelectedVariant() {
            if(!isNaN(this.value)){
              Vue.axios.post('updateRoomCatering', {
                id: this.variant.id,
                room_type_id: this.roomTypeId,
                value: this.value,
                type: this.type,
                catering_type_id: this.cateringTypeId
              })
              .then((response) => {
//                console.log(response.data.request)
              });
            }
          }
        }
    }
</script>
